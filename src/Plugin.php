<?php

namespace manubing\DrupalModuleHandler;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Installer\PackageEvents;
use Composer\Installer\PackageEvent;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\DrupalKernel;
use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;


class Plugin implements PluginInterface, EventSubscriberInterface
{
  const DEFAULT_APP_ROOT = "web";
  const DEFAULT_SITE_PATH = "sites/default";

  /**
   * @var \Composer\Composer
   */
  protected $composer;

  /**
   * @var \Composer\IO\IOInterface
   */
  protected $io;

  /**
   * The Drupal root, usually a sibling of vendor directory.
   * Defaults to DEFAULT_APP_ROOT.
   *
   * @var string
   */
  protected $app_root;

  /**
   * The Drupal site path, defaults to DEFAULT_SITE_PATH.
   * @var string
   */
  protected $site_path;

  /**
   * @param Composer $composer
   * @param IOInterface $io
   */
  public function activate(Composer $composer, IOInterface $io)
  {
    $this->composer = $composer;
    $this->io = $io;
  }

  /**
   * @inheritdoc
   */
  public static function getSubscribedEvents()
  {
    return array(
      PackageEvents::PRE_PACKAGE_UNINSTALL => array(
        array('prePackageUninstall', 0)
      )
    );
  }

  /**
   * Listens the "pre-package-install" event.
   * If a drupal module is being removed, tries to uninstall it before.
   *
   * @param PackageEvent $event
   * @throws \Exception
   */
  public function prePackageUninstall(PackageEvent $event)
  {
    /** @var \Composer\DependencyResolver\Operation\UninstallOperation $operation */
    $operation = $event->getOperation();
    $package = $operation->getPackage();

    if ($operation->getPackage()->getType() === 'drupal-module') {
      // We might need to change the current directory, so keep track.
      $previous_dir = getcwd();

      try {
        $vendor_dir = $this
          ->composer
          ->getConfig()
          ->get('vendor-dir');

        $this->setPaths($vendor_dir . '/../');

        if ($this->bootstrapDrupal()) {
          list(,$module) = explode('/', $package->getName());
          $this->uninstallModule($module);
        }
      }
      catch (\Exception $e) {
        $this->io->writeError($e->getMessage());
      }

      // Restores the previous working directory, Composer expects this.
      chdir($previous_dir);
    }
  }

  /**
   * Tries to bootstrap Drupal the cleanest way, given that Drupal is still highly tied to a Request.
   *
   * @return bool
   * @throws \Exception
   */
  public function bootstrapDrupal()
  {
    // Changes the current directory to the Drupal root.
    chdir($this->app_root);

    $classloader = require $this->app_root . '/autoload.php';

    // Bootstraps Drupal.
    $kernel = new DrupalKernel('prod', $classloader, false);
    $kernel::bootEnvironment();
    $kernel->setSitePath($this->site_path);
    Settings::initialize($kernel->getAppRoot(), $kernel->getSitePath(), $classloader);
    $kernel->boot();

    // We need to mimic a Request otherwise system module is not loaded.
    $request = Request::createFromGlobals();
    $kernel->prepareLegacyRequest($request);

    return !empty(Database::getConnectionInfo());
  }

  /**
   * Sets app and site paths depending on config or defaults.
   *
   * @param $root
   *   Project root path.
   * @throws \Exception
   */
  protected function setPaths($root) {
    $extra = $this->composer->getPackage()->getExtra();

    if (!isset($extra['drupal-project']['app-root'])) {
      $this->io->write(
        sprintf('"drupal-project[app-root]" extra key is not present in composer.json, using default value "%s".', static::DEFAULT_APP_ROOT),
        true,
        IOInterface::VERBOSE
      );
    }

    $this->app_root = isset($extra['drupal-project']['app-root']) ? $root . $extra['drupal-project']['app-root'] : $root . static::DEFAULT_APP_ROOT;
    $this->io->write(sprintf('App root set to %s', $this->app_root), true , IOInterface::VERBOSE);

    if (!file_exists($this->app_root)) {
      throw new \Exception(sprintf('Invalid Drupal app root %s', $this->app_root));
    }


    if (!isset($extra['drupal-project']['site-path'])) {
      $this->io->write(
        sprintf('"drupal-project[site-path]" extra key is not present in composer.json, using default value "%s".', static::DEFAULT_SITE_PATH),
        true,
        IOInterface::VERBOSE
      );
    }

    $this->site_path = isset($extra['drupal-project']['site-path']) ? $extra['drupal-project']['site-path'] : static::DEFAULT_SITE_PATH;
    $this->io->write(sprintf('Site path set to %s', $this->site_path));

    if (!file_exists($this->app_root . '/' . $this->site_path)) {
      throw new \Exception(sprintf('Invalid Drupal site path %s', $this->app_root . '/' . $this->site_path));
    }
  }

  /**
   * Uninstalls a Drupal module.
   *
   * @param $module
   */
  protected function uninstallModule($module)
  {
    $this->io->write(sprintf('Uninstalling module %s using Drupal...', $module));

    // We can't really use the return value.
    \Drupal::service('module_installer')->uninstall([$module]);
  }
}
<?php
$symfony_lib = "/var/www/lib/sf1.5";
require_once $symfony_lib.'/lib/autoload/sfCoreAutoload.class.php';
sfCoreAutoload::register();

class ProjectConfiguration extends sfProjectConfiguration
{
    public function setup()
    {
        $this->enablePlugins(
          'sfDoctrinePlugin',
          'sfDoctrineGuardPlugin',
          'sfDoctrineActAsSignablePlugin',
          'sfJqueryTreeDoctrineManagerPlugin',
          'sfWebBrowserPlugin',
          'csDoctrineActAsSortablePlugin',
          'sfImageTransformPlugin',
          'sfFormExtraPlugin',
          'sfMPDFPlugin',
          'gwUserMenuPlugin',
          'sfDependentSelectPlugin',
          'gwRatesPlugin',
          'gwDescriptionablePlugin',
          'gwImageblePlugin',
          'gwPricerPlugin',
          'gwQRCodePlugin',
          'gwMessageblePlugin',
          'gwDocumentPartialPlugin');
        $this->dispatcher->connect('doctrine.configure', array($this, 'configureDoctrineEvent'));
    }

    public function configureDoctrineEvent(sfEvent $event)
    {
        $manager = $event->getSubject();

        // configure what ever you want on the doctrine manager
        $manager->setAttribute(Doctrine_Core::ATTR_USE_DQL_CALLBACKS, true);

        if(extension_loaded('memcache'))
        {
            //echo "memcached!";
            $servers = array(
              'host' => 'localhost',
              'port' => '11211',
              'presistent' => true
            );

            $cacheDriver = new Doctrine_Cache_Memcache( array(
              'servers' => $servers,
              'compression' => false
            ));
            $manager->setAttribute(Doctrine::ATTR_QUERY_CACHE, $cacheDriver);
            $manager->setAttribute(Doctrine::ATTR_RESULT_CACHE, $cacheDriver);
        } //else {echo "no memcache :(";}
    }
}

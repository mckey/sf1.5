<?php
/**
 * Memcached cache driver
 *
 * @package     Doctrine
 * @subpackage  Cache
 * @license     http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link        www.doctrine-project.org
 * @since       1.0
 * @version     $Revision: 7490 $
 * @author      Konsta Vesterinen <kvesteri@cc.hut.fi>
 * @author      Jonathan H. Wage <jonwage@gmail.com>
 */

class Doctrine_Cache_Memcached extends Doctrine_Cache_Driver
{
    /**
     * @var Memcached|null $_memcached memcached object
     */
    protected ?Memcached $_memcached = null;

    /**
     * constructor
     *
     * @param array $options associative array of cache driver options
     *
     * @throws Doctrine_Cache_Exception
     */
    public function __construct($options = [])
    {
        if (!extension_loaded('memcached')) {
            throw new Doctrine_Cache_Exception(
                'In order to use Memcached driver, the memcached extension must be loaded.'
            );
        }

        parent::__construct($options);

        if (isset($options['servers'])) {
            $value = $options['servers'];

            if (isset($value['host'])) {
                // in this case, $value seems to be a simple associative array (one server only)
                $value = [0 => $value]; // let's transform it into a classical array of associative arrays
            }

            $this->setOption('servers', $value);
        }

        $this->_memcached = new Memcached($options['persistent_id']);

        foreach ($this->_options['servers'] as $server) {
            if (!array_key_exists('port', $server)) {
                $server['port'] = 11211;
            }

            $active_servers = $this->_memcached->getServerList();

            if (is_array($active_servers) && count($active_servers)) {
                foreach ($active_servers as $active_server) {
                    if ($active_server['host'] != $server['host'] || $active_server['port'] != $server['port']) {
                        $this->_memcached->addServer($server['host'], $server['port']);
                    }
                }
            } else {
                $this->_memcached->addServer($server['host'], $server['port']);
            }
        }
    }

    /**
     * Test if a cache record exists for the passed id
     *
     * @param string $id cache id
     *
     * @return mixed  Returns either the cached data or false
     */
    protected function _doFetch($id, $testCacheValidity = true)
    {
        return $this->_memcached->get($id);
    }

    /**
     * Test if a cache is available or not (for the given id)
     *
     * @param string $id cache id
     *
     * @return mixed false (a cache is not available) or "last modified" timestamp (int) of the available cache record
     */
    protected function _doContains($id) : bool
    {
        return (bool)$this->_memcached->get($id);
    }

    /**
     * Save a cache record directly. This method is implemented by the cache
     * drivers and used in Doctrine_Cache_Driver::save()
     *
     * @param string    $id       cache id
     * @param string    $data     data to cache
     * @param int|false $lifeTime if != false, set a specific lifetime for this cache record (null => infinite lifeTime)
     *
     * @return bool true if no problem
     */
    protected function _doSave($id, $data, $lifeTime = false) : bool
    {
        if ($lifeTime === false) {
            $lifeTime = 0;
        }

        return $this->_memcached->set($id, $data, $lifeTime);
    }

    /**
     * Remove a cache record directly. This method is implemented by the cache
     * drivers and used in Doctrine_Cache_Driver::delete()
     *
     * @param string $id cache id
     *
     * @return bool true if no problem
     */
    protected function _doDelete($id) : bool
    {
        return $this->_memcached->delete($id);
    }

    /**
     * Fetch an array of all keys stored in cache
     *
     * @return array|false Returns the array of cache keys
     */
    protected function _getCacheKeys()
    {
        return $this->_memcached->getAllKeys();
    }
}

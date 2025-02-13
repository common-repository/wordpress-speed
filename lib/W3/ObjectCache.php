<?php

/**
 * W3 Object Cache object
 */
class W3_ObjectCache {
    /**
     * Internal cache array
     *
     * @var array
     */
    var $cache = array();

    /**
     * Array of global groups
     *
     * @var array
     */
    var $global_groups = array();

    /**
     * List of non-persistent groups
     *
     * @var array
     */
    var $nonpersistent_groups = array();

    /**
     * Total count of calls
     *
     * @var integer
     */
    var $cache_total = 0;

    /**
     * Cache hits count
     *
     * @var integer
     */
    var $cache_hits = 0;

    /**
     * Cache misses count
     *
     * @var integer
     */
    var $cache_misses = 0;

    /**
     * Total time
     *
     * @var integer
     */
    var $time_total = 0;

    /**
     * Store debug information of w3tc using
     *
     * @var array
     */
    var $debug_info = array();

    /**
     * Key cache
     *
     * @var array
     */
    var $_key_cache = array();

    /**
     * Config
     *
     * @var W3_Config
     */
    var $_config = null;

    /**
     * Caching flag
     *
     * @var boolean
     */
    var $_caching = false;

    /**
     * Cache reject reason
     *
     * @var string
     */
    var $_cache_reject_reason = '';

    /**
     * Lifetime
     *
     * @var integer
     */
    var $_lifetime = null;

    /**
     * Debug flag
     *
     * @var boolean
     */
    var $_debug = false;

    /**
     * Returns instance. for backward compatibility with 0.9.2.3 version of /wp-content files
     *
     * @return W3_ObjectCache
     */
    function &instance() {
        return w3_instance('W3_ObjectCache');
    }

    /**
     * PHP5 style constructor
     */
    function __construct() {
        global $_wp_using_ext_object_cache;

        $this->_config = & w3_instance('W3_Config');
        $this->_lifetime = $this->_config->get_integer('objectcache.lifetime');
        $this->_debug = $this->_config->get_boolean('objectcache.debug');
        $this->_caching = $_wp_using_ext_object_cache = $this->_can_cache();

        $this->global_groups = $this->_config->get_array('objectcache.groups.global');
        $this->nonpersistent_groups = $this->_config->get_array('objectcache.groups.nonpersistent');

        if ($this->_can_ob()) {
            ob_start(array(
                          &$this,
                          'ob_callback'
                     ));
        }
    }

    /**
     * PHP4 style constructor
     */
    function W3_ObjectCache() {
        $this->__construct();
    }

    /**
     * Get from the cache
     *
     * @param string $id
     * @param string $group
     * @return mixed
     */
    function get($id, $group = 'default') {
        if ($this->_debug) {
            $time_start = w3_microtime();
        }

        $key = $this->_get_cache_key($id, $group);
        $internal = isset($this->cache[$key]);

        if ($internal) {
            $value = $this->cache[$key];
        } elseif ($this->_caching && !in_array($group, $this->nonpersistent_groups)) {
            $cache = & $this->_get_cache();
            $value = $cache->get($key);
        } else {
            $value = false;
        }

        if ($value === null) {
            $value = false;
        }

        if (is_object($value)) {
            $value = wp_clone($value);
        }

        $this->cache[$key] = $value;
        $this->cache_total++;

        if ($value !== false) {
            $cached = true;
            $this->cache_hits++;
        } else {
            $cached = false;
            $this->cache_misses++;
        }

        /**
         * Add debug info
         */
        if ($this->_debug) {
            $time = w3_microtime() - $time_start;
            $this->time_total += $time;

            if (!$group) {
                $group = 'default';
            }

            $this->debug_info[] = array(
                'id' => $id,
                'group' => $group,
                'cached' => $cached,
                'internal' => $internal,
                'data_size' => ($value ? strlen(serialize($value)) : 0),
                'time' => $time
            );
        }

        return $value;
    }

    /**
     * Set to the cache
     *
     * @param string $id
     * @param mixed $data
     * @param string $group
     * @param integer $expire
     * @return boolean
     */
    function set($id, $data, $group = 'default', $expire = 0) {
        $key = $this->_get_cache_key($id, $group);

        if (is_object($data)) {
            $data = wp_clone($data);
        }

        $this->cache[$key] = $data;

        if ($this->_caching && !in_array($group, $this->nonpersistent_groups)) {
            $cache = & $this->_get_cache();

            return $cache->set($key, $data, ($expire ? $expire : $this->_lifetime));
        }

        return true;
    }

    /**
     * Delete from the cache
     *
     * @param string $id
     * @param string $group
     * @param bool $force
     * @return boolean
     */
    function delete($id, $group = 'default', $force = false) {
        if (!$force && $this->get($id, $group) === false) {
            return false;
        }

        $key = $this->_get_cache_key($id, $group);

        unset($this->cache[$key]);

        if ($this->_caching && !in_array($group, $this->nonpersistent_groups)) {
            $cache = & $this->_get_cache();

            return $cache->delete($key);
        }

        return true;
    }

    /**
     * Add to the cache
     *
     * @param string $id
     * @param mixed $data
     * @param string $group
     * @param integer $expire
     * @return boolean
     */
    function add($id, $data, $group = 'default', $expire = 0) {
        if ($this->get($id, $group) !== false) {
            return false;
        }

        return $this->set($id, $data, $group, $expire);
    }

    /**
     * Replace in the cache
     *
     * @param string $id
     * @param mixed $data
     * @param string $group
     * @param integer $expire
     * @return boolean
     */
    function replace($id, $data, $group = 'default', $expire = 0) {
        if ($this->get($id, $group) === false) {
            return false;
        }

        return $this->set($id, $data, $group, $expire);
    }

    /**
     * Reset keys
     *
     * @return boolean
     */
    function reset() {
        global $_wp_using_ext_object_cache;

        $_wp_using_ext_object_cache = $this->_caching;

        return true;
    }

    /**
     * Flush cache
     *
     * @return boolean
     */
    function flush() {
        $this->cache = array();

        if ($this->_caching) {
            $cache = & $this->_get_cache();

            return $cache->flush();
        }

        return true;
    }

    /**
     * Add global groups
     *
     * @param array $groups
     * @return void
     */
    function add_global_groups($groups) {
        if (!is_array($groups)) {
            $groups = (array) $groups;
        }

        $this->global_groups = array_merge($this->global_groups, $groups);
        $this->global_groups = array_unique($this->global_groups);
    }

    /**
     * Add non-persistent groups
     *
     * @param array $groups
     * @return void
     */
    function add_nonpersistent_groups($groups) {
        if (!is_array($groups)) {
            $groups = (array) $groups;
        }

        $this->nonpersistent_groups = array_merge($this->nonpersistent_groups, $groups);
        $this->nonpersistent_groups = array_unique($this->nonpersistent_groups);
    }

    /**
     * Output buffering callback
     *
     * @param string $buffer
     * @return string
     */
    function ob_callback(&$buffer) {
        if ($buffer != '' && w3_is_xml($buffer)) {
            $buffer .= "\r\n\r\n" . $this->_get_debug_info();
        }

        return $buffer;
    }

    /**
     * Print Object Cache stats
     *
     * @return void
     */
    function stats()
    {
        echo '<h2>Summary</h2>';
        echo '<p>';
        echo '<strong>Engine</strong>: ' . w3_get_engine_name($this->_config->get_string('objectcache.engine')) . '<br />';
        echo '<strong>Caching</strong>: ' . ($this->_caching ? 'enabled' : 'disabled') . '<br />';

        if (!$this->_caching) {
            echo '<strong>Reject reason</strong>: ' . $this->_cache_reject_reason . '<br />';
        }

        echo '<strong>Total calls</strong>: ' . $this->cache_total . '<br />';
        echo '<strong>Cache hits</strong>: ' . $this->cache_hits . '<br />';
        echo '<strong>Cache misses</strong>: ' . $this->cache_misses . '<br />';
        echo '<strong>Total time</strong>: '. round($this->time_total, 4) . 's';
        echo '</p>';

        echo '<h2>Cache info</h2>';

        if ($this->_debug) {
            echo '<table cellpadding="0" cellspacing="3" border="1">';
            echo '<tr><td>#</td><td>Status</td><td>Source</td><td>Data size (b)</td><td>Query time (s)</td><td>ID:Group</td></tr>';

            foreach ($this->debug_info as $index => $debug) {
                echo '<tr>';
                echo '<td>' . ($index + 1) . '</td>';
                echo '<td>' . ($debug['cached'] ? 'cached' : 'not cached') . '</td>';
                echo '<td>' . ($debug['internal'] ? 'internal' : 'persistent') . '</td>';
                echo '<td>' . $debug['data_size'] . '</td>';
                echo '<td>' . round($debug['time'], 4) . '</td>';
                echo '<td>' . sprintf('%s:%s', $debug['id'], $debug['group']) . '</td>';
                echo '</tr>';
            }

            echo '</table>';
        } else {
            echo '<p>Enable debug mode.</p>';
        }
    }

    /**
     * Returns cache key
     *
     * @param string $id
     * @param string $group
     * @return string
     */
    function _get_cache_key($id, $group = 'default') {
        if (!$group) {
            $group = 'default';
        }

        $blog_id = w3_get_blog_id();
        $key_cache_id = $blog_id . $group . $id;

        if (isset($this->_key_cache[$key_cache_id])) {
            $key = $this->_key_cache[$key_cache_id];
        } else {
            $host = w3_get_host();

            if (in_array($group, $this->global_groups)) {
                $host_id = $host;
            } else {
                $host_id = sprintf('%s_%d', $host, $blog_id);
            }

            $key = sprintf('w3tc_%s_object_%s', $host_id, md5($group . $id));

            $this->_key_cache[$key_cache_id] = $key;
        }

        /**
         * Allow to modify cache key by W3TC plugins
         */
        $key = w3tc_do_action('w3tc_objectcache_cache_key', $key);

        return $key;
    }

    /**
     * Returns cache object
     *
     * @return W3_Cache_Base
     */
    function &_get_cache() {
        static $cache = array();

        if (!isset($cache[0])) {
            $engine = $this->_config->get_string('objectcache.engine');

            switch ($engine) {
                case 'memcached':
                    $engineConfig = array(
                        'servers' => $this->_config->get_array('objectcache.memcached.servers'),
                        'persistant' => $this->_config->get_boolean('objectcache.memcached.persistant')
                    );
                    break;

                case 'file':
                    $engineConfig = array(
                        'cache_dir' => W3TC_CACHE_FILE_OBJECTCACHE_DIR,
                        'locking' => $this->_config->get_boolean('objectcache.file.locking'),
                        'flush_timelimit' => $this->_config->get_integer('timelimit.cache_flush')
                    );
                    break;

                default:
                    $engineConfig = array();
            }

            require_once W3TC_LIB_W3_DIR . '/Cache.php';
            @$cache[0] = & W3_Cache::instance($engine, $engineConfig);
        }

        return $cache[0];
    }

    /**
     * Check if caching allowed on init
     *
     * @return boolean
     */
    function _can_cache() {
        /**
         * Skip if disabled
         */
        if (!$this->_config->get_boolean('objectcache.enabled')) {
            $this->_cache_reject_reason = 'Object caching is disabled';

            return false;
        }

        /**
         * Check for DONOTCACHEOBJECT constant
         */
        if (defined('DONOTCACHEOBJECT') && DONOTCACHEOBJECT) {
            $this->_cache_reject_reason = 'DONOTCACHEOBJECT constant is defined';

            return false;
        }

        return true;
    }

    /**
     * Check if we can start OB
     *
     * @return boolean
     */
    function _can_ob() {
        /**
         * Object cache should be enabled
         */
        if (!$this->_config->get_boolean('objectcache.enabled')) {
            return false;
        }

        /**
         * Debug should be enabled
         */
        if (!$this->_debug) {
            return false;
        }

        /**
         * Skip if doing AJAX
         */
        if (defined('DOING_AJAX')) {
            return false;
        }

        /**
         * Skip if doing cron
         */
        if (defined('DOING_CRON')) {
            return false;
        }

        /**
         * Skip if APP request
         */
        if (defined('APP_REQUEST')) {
            return false;
        }

        /**
         * Skip if XMLRPC request
         */
        if (defined('XMLRPC_REQUEST')) {
            return false;
        }

        /**
         * Check for WPMU's and WP's 3.0 short init
         */
        if (defined('SHORTINIT') && SHORTINIT) {
            return false;
        }

        /**
         * Check User Agent
         */
        if (isset($_SERVER['HTTP_USER_AGENT']) && stristr($_SERVER['HTTP_USER_AGENT'], W3TC_POWERED_BY) !== false) {
            return false;
        }

        return true;
    }

    /**
     * Returns debug info
     *
     * @return string
     */
    function _get_debug_info() {
        $debug_info = "<!-- WordPress Speed: Object Cache debug info:\r\n";
        $debug_info .= sprintf("%s%s\r\n", str_pad('Engine: ', 20), w3_get_engine_name($this->_config->get_string('objectcache.engine')));
        $debug_info .= sprintf("%s%s\r\n", str_pad('Caching: ', 20), ($this->_caching ? 'enabled' : 'disabled'));

        if (!$this->_caching) {
            $debug_info .= sprintf("%s%s\r\n", str_pad('Reject reason: ', 20), $this->_cache_reject_reason);
        }

        $debug_info .= sprintf("%s%d\r\n", str_pad('Total calls: ', 20), $this->cache_total);
        $debug_info .= sprintf("%s%d\r\n", str_pad('Cache hits: ', 20), $this->cache_hits);
        $debug_info .= sprintf("%s%d\r\n", str_pad('Cache misses: ', 20), $this->cache_misses);
        $debug_info .= sprintf("%s%.4f\r\n", str_pad('Total time: ', 20), $this->time_total);

        $debug_info .= "W3TC Object Cache info:\r\n";
        $debug_info .= sprintf("%s | %s | %s | %s | %s | %s\r\n",
                               str_pad('#', 5, ' ', STR_PAD_LEFT),
                               str_pad('Status', 15, ' ', STR_PAD_BOTH),
                               str_pad('Source', 15, ' ', STR_PAD_BOTH),
                               str_pad('Data size (b)', 13, ' ', STR_PAD_LEFT),
                               str_pad('Query time (s)', 14, ' ', STR_PAD_LEFT),
                               'ID:Group');

        foreach ($this->debug_info as $index => $debug) {
            $debug_info .= sprintf("%s | %s | %s | %s | %s | %s\r\n",
                                   str_pad($index + 1, 5, ' ', STR_PAD_LEFT),
                                   str_pad(($debug['cached'] ? 'cached' : 'not cached'), 15, ' ', STR_PAD_BOTH),
                                   str_pad(($debug['internal'] ? 'internal' : 'persistent'), 15, ' ', STR_PAD_BOTH),
                                   str_pad($debug['data_size'], 13, ' ', STR_PAD_LEFT),
                                   str_pad(round($debug['time'], 4), 14, ' ', STR_PAD_LEFT),
                                   sprintf('%s:%s', $debug['id'], $debug['group']));
        }

        $debug_info .= '-->';

        return $debug_info;
    }
}

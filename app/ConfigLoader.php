<?php
defined('APP_ROOT')
    || define('APP_ROOT', realpath(dirname(__FILE__) . '/..'));

/**
 * Sendmail Wrapper by Onlime Webhosting
 * https://github.com/onlime/sendmail-wrapper
 *
 * @copyright  Copyright (c) 2007-2014 Onlime Webhosting (http://www.onlime.ch)
 */
class ConfigLoader
{
    /**
     * @var StdClass
     */
    protected $_conf;

    /**
     * Constructor
     */
    public function __construct()
    {
        $globalConfig = APP_ROOT . '/config.ini';
        $extraConfigs = array(
            APP_ROOT . '/config.local.ini',
            APP_ROOT . '/config.private.ini'
        );

        // load global config
        $config = parse_ini_file($globalConfig, true);

        // load extra configs
        foreach ($extraConfigs as $configFile) {
            if (is_readable($configFile)) {
                $config = array_replace_recursive(
                    $config,
                    parse_ini_file($configFile, true)
                );
            }
        }

        $this->_conf = $this->_arrayToObject($config);

        $this->init();
    }

    /**
     * Initialize
     */
    public function init()
    {
        // assure the default timezone is set
        if (!ini_get('date.timezone')) {
            date_default_timezone_set($this->_conf->global->defaultTZ);
        }
    }

    /**
     * Get configuration object.
     *
     * @return StdClass
     */
    public function getConfig()
    {
        return $this->_conf;
    }

    /**
     * Recursively converts an array to a StdClass object.
     *
     * @param array $arr
     * @return StdClass
     * @link http://onli.me/array2object
     */
    protected function _arrayToObject($arr)
    {
        if (is_array($arr)) {
            /*
            * Return array converted to object
            * Using __FUNCTION__ (Magic constant)
            * for recursive call
            */
            return (object)array_map(array(self, __FUNCTION__), $arr);
        } else {
            // Return object
            return $arr;
        }
    }
}

<?php

defined('APP_ROOT')
    || define('APP_ROOT', realpath(dirname(__FILE__) . '/..'));

/**
 * Sendmail Wrapper by Onlime GmbH webhosting services
 * https://github.com/onlime/sendmail-wrapper
 *
 * @copyright Copyright (c) Onlime GmbH (https://www.onlime.ch)
 */
class ConfigLoader
{
    protected StdClass $conf;

    public function __construct()
    {
        $globalConfig = APP_ROOT . '/config.ini';
        $extraConfigs = [
            APP_ROOT . '/config.local.ini',
            APP_ROOT . '/config.private.ini',
        ];

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

        // recursively convert array to object
        $this->conf = json_decode(json_encode($config), false);

        $this->init();
    }

    public function init()
    {
        // assure the default timezone is set
        if (! ini_get('date.timezone')) {
            date_default_timezone_set($this->conf->global->defaultTZ);
        }
    }

    /**
     * Get configuration object.
     */
    public function getConfig(): StdClass
    {
        return $this->conf;
    }
}

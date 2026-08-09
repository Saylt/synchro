<?php

namespace Tygh\Addons\Synchro;

use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Tygh\Addons\Synchro\HookHandlers\LoggingHookHandler;
use Tygh\Registry;
use Tygh\Tygh;

/**
 * Registers the synchro add-on services in the application container.
 */
class ServiceProvider implements ServiceProviderInterface
{
    /**
     * @inheritDoc
     */
    public function register(Container $app)
    {
        $app['addons.synchro.cron_manager'] = static function (Container $app) {
            return new CronManager(
                $app['db'],
                (string) Registry::get('config.dir.root'),
                (string) Registry::get('config.admin_index'),
                (string) Registry::get('config.customer_index'),
                (string) Registry::get('settings.Security.cron_password')
            );
        };

        $app['addons.synchro.hook_handlers.logging'] = static function () {
            return new LoggingHookHandler();
        };
    }

    /**
     * @return \Tygh\Addons\Synchro\CronManager
     */
    public static function getCronManager()
    {
        return Tygh::$app['addons.synchro.cron_manager'];
    }
}

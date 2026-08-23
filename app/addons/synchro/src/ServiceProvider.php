<?php

namespace Tygh\Addons\Synchro;

use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Tygh\Addons\Synchro\Commands\ImportDataCommand;
use Tygh\Addons\Synchro\Commands\ImportDataCommandHandler;
use Tygh\Addons\Synchro\Convertors\CategoryConvertor;
use Tygh\Addons\Synchro\Convertors\ManufacturerConvertor;
use Tygh\Addons\Synchro\Convertors\ProductConvertor;
use Tygh\Addons\Synchro\Convertors\ProductFeatureConvertor;
use Tygh\Addons\Synchro\Convertors\ProductFeatureVariantConvertor;
use Tygh\Addons\Synchro\Convertors\WarehouseConvertor;
use Tygh\Addons\Synchro\HookHandlers\LoggingHookHandler;
use Tygh\Addons\Synchro\Repository\ImportEntityRepository;
use Tygh\Addons\Synchro\Repository\ProductFeatureMappingRepository;
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
                $app['lock.factory'],
                (string) Registry::get('config.dir.root'),
                (string) Registry::get('config.admin_index'),
                (string) Registry::get('config.customer_index'),
                (string) Registry::get('settings.Security.cron_password')
            );
        };

        $app['addons.synchro.hook_handlers.logging'] = static function () {
            return new LoggingHookHandler();
        };

        $app['addons.synchro.repository.import_entity'] = static function (Container $app) {
            return new ImportEntityRepository($app['db']);
        };

        $app['addons.synchro.repository.product_feature_mapping'] = static function (Container $app) {
            return new ProductFeatureMappingRepository($app['db']);
        };

        $app['addons.synchro.convertors.product'] = static function (Container $app) {
            return new ProductConvertor(
                $app['addons.synchro.repository.import_entity'],
                fn_get_runtime_company_id(),
                $app['addons.synchro.convertors.product_feature']
            );
        };

        $app['addons.synchro.convertors.category'] = static function (Container $app) {
            return new CategoryConvertor(
                $app['addons.synchro.repository.import_entity'],
                fn_get_runtime_company_id()
            );
        };

        $app['addons.synchro.convertors.manufacturer'] = static function (Container $app) {
            return new ManufacturerConvertor(
                $app['addons.synchro.repository.import_entity'],
                fn_get_runtime_company_id()
            );
        };

        $app['addons.synchro.convertors.product_feature'] = static function (Container $app) {
            return new ProductFeatureConvertor(
                $app['addons.synchro.repository.import_entity'],
                fn_get_runtime_company_id()
            );
        };

        $app['addons.synchro.convertors.product_feature_variant'] = static function () {
            return new ProductFeatureVariantConvertor();
        };

        $app['addons.synchro.convertors.warehouse'] = static function () {
            return new WarehouseConvertor();
        };

        $app['addons.synchro.commands.import_data_handler'] = static function (Container $app) {
            return new ImportDataCommandHandler([
                ImportDataCommand::ENTITY_PRODUCTS         => $app['addons.synchro.convertors.product'],
                ImportDataCommand::ENTITY_CATEGORIES       => $app['addons.synchro.convertors.category'],
                ImportDataCommand::ENTITY_MANUFACTURERS    => $app['addons.synchro.convertors.manufacturer'],
                ImportDataCommand::ENTITY_FEATURES         => $app['addons.synchro.convertors.product_feature'],
                ImportDataCommand::ENTITY_FEATURE_VARIANTS => $app['addons.synchro.convertors.product_feature_variant'],
                ImportDataCommand::ENTITY_WAREHOUSES       => $app['addons.synchro.convertors.warehouse'],
            ]);
        };

        $app['addons.synchro.command_bus'] = static function () {
            return new CommandBus(fn_get_schema('synchro', 'commands'));
        };
    }

    /**
     * @return \Tygh\Addons\Synchro\CronManager
     */
    public static function getCronManager()
    {
        return Tygh::$app['addons.synchro.cron_manager'];
    }

    /**
     * @return \Tygh\Addons\Synchro\CommandBus
     */
    public static function getCommandBus()
    {
        return Tygh::$app['addons.synchro.command_bus'];
    }

    /**
     * @return \Tygh\Addons\Synchro\Commands\ImportDataCommandHandler
     */
    public static function getImportDataCommandHandler()
    {
        return Tygh::$app['addons.synchro.commands.import_data_handler'];
    }

    /**
     * @return \Tygh\Addons\Synchro\Repository\ImportEntityRepository
     */
    public static function getImportEntityRepository()
    {
        return Tygh::$app['addons.synchro.repository.import_entity'];
    }

    /**
     * @return \Tygh\Addons\Synchro\Repository\ProductFeatureMappingRepository
     */
    public static function getProductFeatureMappingRepository()
    {
        return Tygh::$app['addons.synchro.repository.product_feature_mapping'];
    }
}

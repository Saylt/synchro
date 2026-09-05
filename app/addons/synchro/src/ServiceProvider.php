<?php

namespace Tygh\Addons\Synchro;

use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Tygh\Addons\Synchro\Commands\ImportDataCommand;
use Tygh\Addons\Synchro\Commands\ImportDataCommandHandler;
use Tygh\Addons\Synchro\Convertors\CategoryConvertor;
use Tygh\Addons\Synchro\Convertors\ManufacturerConvertor;
use Tygh\Addons\Synchro\Convertors\ProductConvertor;
use Tygh\Addons\Synchro\Convertors\ProductFeatureConvertor;
use Tygh\Addons\Synchro\Convertors\ProductFeatureVariantConvertor;
use Tygh\Addons\Synchro\Convertors\WarehouseConvertor;
use Tygh\Addons\Synchro\HookHandlers\LoggingHookHandler;
use Tygh\Addons\Synchro\Repository\ImportEntityMapRepository;
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
     * Registers add-on services.
     *
     * @param \Pimple\Container $pimple Application container
     */
    public function register(Container $pimple): void
    {
        $pimple['addons.synchro.cron_manager'] = static function (Container $app) {
            $php_binary_finder = new PhpExecutableFinder();

            return new CronManager(
                $app['db'],
                $app['lock.factory'],
                (string) Registry::get('config.dir.root'),
                (string) Registry::get('config.admin_index'),
                (string) Registry::get('settings.Security.cron_password'),
                fn_get_schema('synchro', 'cron_tasks'),
                $php_binary_finder->find() ?: 'php'
            );
        };

        $pimple['addons.synchro.product_import_range_builder'] = static function () {
            return new ProductImportRangeBuilder();
        };

        $pimple['addons.synchro.import_process_manager'] = static function (Container $app) {
            $php_binary_finder = new PhpExecutableFinder();

            return new ImportProcessManager(
                $app['addons.synchro.repository.import_entity'],
                $app['addons.synchro.product_import_range_builder'],
                $app['addons.synchro.cron_manager'],
                $app['lock.factory'],
                (string) Registry::get('config.dir.root'),
                (string) Registry::get('config.admin_index'),
                (string) Registry::get('settings.Security.cron_password'),
                $php_binary_finder->find() ?: 'php'
            );
        };

        $pimple['addons.synchro.hook_handlers.logging'] = static function () {
            return new LoggingHookHandler();
        };

        $pimple['addons.synchro.repository.import_entity'] = static function (Container $app) {
            return new ImportEntityRepository($app['db']);
        };

        $pimple['addons.synchro.repository.import_entity_map'] = static function (Container $app) {
            return new ImportEntityMapRepository($app['db']);
        };

        $pimple['addons.synchro.repository.product_feature_mapping'] = static function (Container $app) {
            return new ProductFeatureMappingRepository($app['db']);
        };

        $pimple['addons.synchro.imported_product_feature_reader'] = static function (Container $app) {
            return new ImportedProductFeatureReader($app['addons.synchro.repository.import_entity']);
        };

        $pimple['addons.synchro.convertors.product'] = static function (Container $app) {
            return new ProductConvertor(
                $app['addons.synchro.repository.import_entity'],
                fn_get_runtime_company_id(),
                $app['addons.synchro.convertors.product_feature'],
                $app['addons.synchro.cron_manager'],
                $app['addons.synchro.import_process_manager']
            );
        };

        $pimple['addons.synchro.convertors.category'] = static function (Container $app) {
            return new CategoryConvertor(
                $app['addons.synchro.repository.import_entity'],
                fn_get_runtime_company_id(),
                $app['addons.synchro.cron_manager']
            );
        };

        $pimple['addons.synchro.convertors.manufacturer'] = static function (Container $app) {
            return new ManufacturerConvertor(
                $app['addons.synchro.repository.import_entity'],
                fn_get_runtime_company_id(),
                $app['addons.synchro.cron_manager']
            );
        };

        $pimple['addons.synchro.convertors.product_feature'] = static function (Container $app) {
            return new ProductFeatureConvertor(
                $app['addons.synchro.repository.import_entity'],
                fn_get_runtime_company_id()
            );
        };

        $pimple['addons.synchro.convertors.product_feature_variant'] = static function () {
            return new ProductFeatureVariantConvertor();
        };

        $pimple['addons.synchro.convertors.warehouse'] = static function () {
            return new WarehouseConvertor();
        };

        $pimple['addons.synchro.commands.import_data_handler'] = static function (Container $app) {
            return new ImportDataCommandHandler([
                ImportDataCommand::ENTITY_PRODUCTS         => $app['addons.synchro.convertors.product'],
                ImportDataCommand::ENTITY_CATEGORIES       => $app['addons.synchro.convertors.category'],
                ImportDataCommand::ENTITY_MANUFACTURERS    => $app['addons.synchro.convertors.manufacturer'],
                ImportDataCommand::ENTITY_FEATURES         => $app['addons.synchro.convertors.product_feature'],
                ImportDataCommand::ENTITY_FEATURE_VARIANTS => $app['addons.synchro.convertors.product_feature_variant'],
                ImportDataCommand::ENTITY_WAREHOUSES       => $app['addons.synchro.convertors.warehouse'],
            ]);
        };

        $pimple['addons.synchro.command_bus'] = static function () {
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
     * @return \Tygh\Addons\Synchro\ImportProcessManager
     */
    public static function getImportProcessManager()
    {
        return Tygh::$app['addons.synchro.import_process_manager'];
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
     * @return \Tygh\Addons\Synchro\Repository\ImportEntityMapRepository
     */
    public static function getImportEntityMapRepository()
    {
        return Tygh::$app['addons.synchro.repository.import_entity_map'];
    }

    /**
     * @return \Tygh\Addons\Synchro\Repository\ProductFeatureMappingRepository
     */
    public static function getProductFeatureMappingRepository()
    {
        return Tygh::$app['addons.synchro.repository.product_feature_mapping'];
    }

    /**
     * @return \Tygh\Addons\Synchro\ImportedProductFeatureReader
     */
    public static function getImportedProductFeatureReader()
    {
        return Tygh::$app['addons.synchro.imported_product_feature_reader'];
    }
}

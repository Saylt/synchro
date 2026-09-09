<?php

namespace Tygh\Addons\Synchro;

use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Tygh\Addons\Synchro\Application\CategoryApplicationManager;
use Tygh\Addons\Synchro\Application\ProductApplicationManager;
use Tygh\Addons\Synchro\Api\ApiClient;
use Tygh\Addons\Synchro\Commands\ImportDataCommand;
use Tygh\Addons\Synchro\Commands\ImportDataCommandHandler;
use Tygh\Addons\Synchro\Convertors\CategoryConvertor;
use Tygh\Addons\Synchro\Convertors\ManufacturerConvertor;
use Tygh\Addons\Synchro\Convertors\ProductConvertor;
use Tygh\Addons\Synchro\Convertors\ProductFeatureConvertor;
use Tygh\Addons\Synchro\Convertors\ProductFeatureVariantConvertor;
use Tygh\Addons\Synchro\Convertors\WarehouseConvertor;
use Tygh\Addons\Synchro\HookHandlers\LoggingHookHandler;
use Tygh\Addons\Synchro\Importers\CategoryImporter;
use Tygh\Addons\Synchro\Importers\ImageImporter;
use Tygh\Addons\Synchro\Importers\ProductImporter;
use Tygh\Addons\Synchro\Importers\ProductFeatureImporter;
use Tygh\Addons\Synchro\Importers\ProductStockUpdater;
use Tygh\Addons\Synchro\Importers\WarehouseImporter;
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
     * @param \Pimple\Container $app Application container
     *
     * @psalm-suppress ParamNameMismatch
     */
    public function register(Container $app): void
    {
        $app['addons.synchro.api.client'] = static function () {
            return new ApiClient((string) Registry::get('addons.synchro.api_key'));
        };

        $app['addons.synchro.cron_manager'] = static function (Container $app) {
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

        $app['addons.synchro.product_import_range_builder'] = static function () {
            return new ProductImportRangeBuilder();
        };

        $app['addons.synchro.import_process_manager'] = static function (Container $app) {
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

        $app['addons.synchro.hook_handlers.logging'] = static function () {
            return new LoggingHookHandler();
        };

        $app['addons.synchro.repository.import_entity'] = static function (Container $app) {
            return new ImportEntityRepository($app['db']);
        };

        $app['addons.synchro.repository.import_entity_map'] = static function (Container $app) {
            return new ImportEntityMapRepository($app['db']);
        };

        $app['addons.synchro.repository.product_feature_mapping'] = static function (Container $app) {
            return new ProductFeatureMappingRepository($app['db']);
        };

        $app['addons.synchro.product_feature_mapping_manager'] = static function (Container $app) {
            return new ProductFeatureMappingManager(
                $app['db'],
                $app['addons.synchro.repository.product_feature_mapping']
            );
        };

        $app['addons.synchro.importers.image'] = static function (Container $app) {
            return new ImageImporter($app['db']);
        };

        $app['addons.synchro.importers.warehouse'] = static function (Container $app) {
            return new WarehouseImporter(
                $app['db'],
                $app['addons.synchro.repository.import_entity_map']
            );
        };

        $app['addons.synchro.importers.product_stock'] = static function (Container $app) {
            return new ProductStockUpdater($app['db'], $app['addons.warehouses.manager']);
        };

        $app['addons.synchro.importers.product'] = static function (Container $app) {
            return new ProductImporter(
                $app['db'],
                $app['addons.synchro.repository.import_entity_map'],
                $app['addons.synchro.repository.product_feature_mapping'],
                $app['addons.synchro.importers.warehouse'],
                $app['addons.synchro.importers.product_stock'],
                $app['addons.synchro.importers.image']
            );
        };

        $app['addons.synchro.importers.category'] = static function (Container $app) {
            return new CategoryImporter(
                $app['db'],
                $app['addons.synchro.repository.import_entity_map'],
                $app['addons.synchro.importers.image']
            );
        };

        $app['addons.synchro.importers.product_feature'] = static function (Container $app) {
            return new ProductFeatureImporter(
                $app['db'],
                $app['addons.synchro.repository.product_feature_mapping'],
                $app['addons.synchro.repository.import_entity_map']
            );
        };

        $app['addons.synchro.imported_product_feature_reader'] = static function (Container $app) {
            return new ImportedProductFeatureReader($app['addons.synchro.repository.import_entity']);
        };

        $app['addons.synchro.product_application_manager'] = static function (Container $app) {
            return new ProductApplicationManager(
                $app['addons.synchro.repository.import_entity'],
                $app['addons.synchro.imported_product_feature_reader'],
                $app['addons.synchro.importers.product_feature'],
                $app['addons.synchro.importers.product'],
                $app['addons.synchro.repository.import_entity_map'],
                $app['addons.synchro.cron_manager']
            );
        };

        $app['addons.synchro.category_application_manager'] = static function (Container $app) {
            return new CategoryApplicationManager(
                $app['addons.synchro.repository.import_entity'],
                $app['addons.synchro.importers.category'],
                $app['addons.synchro.repository.import_entity_map'],
                $app['addons.synchro.cron_manager']
            );
        };

        $app['addons.synchro.convertors.product'] = static function (Container $app) {
            return new ProductConvertor(
                $app['addons.synchro.repository.import_entity'],
                fn_get_runtime_company_id(),
                $app['addons.synchro.convertors.product_feature'],
                $app['addons.synchro.cron_manager'],
                $app['addons.synchro.import_process_manager']
            );
        };

        $app['addons.synchro.convertors.category'] = static function (Container $app) {
            return new CategoryConvertor(
                $app['addons.synchro.repository.import_entity'],
                fn_get_runtime_company_id(),
                $app['addons.synchro.cron_manager']
            );
        };

        $app['addons.synchro.convertors.manufacturer'] = static function (Container $app) {
            return new ManufacturerConvertor(
                $app['addons.synchro.repository.import_entity'],
                fn_get_runtime_company_id(),
                $app['addons.synchro.cron_manager']
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
     * @return \Tygh\Addons\Synchro\Api\ApiClient
     */
    public static function getApiClient()
    {
        return Tygh::$app['addons.synchro.api.client'];
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
     * @return \Tygh\Addons\Synchro\ProductFeatureMappingManager
     */
    public static function getProductFeatureMappingManager()
    {
        return Tygh::$app['addons.synchro.product_feature_mapping_manager'];
    }

    /**
     * @return \Tygh\Addons\Synchro\Importers\ProductFeatureImporter
     */
    public static function getProductFeatureImporter()
    {
        return Tygh::$app['addons.synchro.importers.product_feature'];
    }

    /**
     * @return \Tygh\Addons\Synchro\Importers\ProductImporter
     */
    public static function getProductImporter()
    {
        return Tygh::$app['addons.synchro.importers.product'];
    }

    /**
     * @return \Tygh\Addons\Synchro\Application\ProductApplicationManager
     */
    public static function getProductApplicationManager()
    {
        return Tygh::$app['addons.synchro.product_application_manager'];
    }

    /**
     * @return \Tygh\Addons\Synchro\Application\CategoryApplicationManager
     */
    public static function getCategoryApplicationManager()
    {
        return Tygh::$app['addons.synchro.category_application_manager'];
    }

    /**
     * @return \Tygh\Addons\Synchro\ImportedProductFeatureReader
     */
    public static function getImportedProductFeatureReader()
    {
        return Tygh::$app['addons.synchro.imported_product_feature_reader'];
    }
}

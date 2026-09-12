<?php

namespace Tygh\Addons\Synchro;

use Tygh\Addons\Synchro\Application\EntityApplicationPlanBuilder;
use Tygh\Addons\Synchro\Dto\ProductDto;
use Tygh\Addons\Synchro\Repository\ImportEntityRepository;

class ProductArchivingManager
{
    const SCRIPT = 'synchro_import.archive_products';

    /** @var \Tygh\Addons\Synchro\Repository\ImportEntityRepository */
    private $repository;

    /** @var \Tygh\Addons\Synchro\CronManager */
    private $cron_manager;

    /** @var \Tygh\Addons\Synchro\Logging */
    private $logging;

    /**
     * @param \Tygh\Addons\Synchro\Repository\ImportEntityRepository $repository   Import repository
     * @param \Tygh\Addons\Synchro\CronManager                       $cron_manager Cron task manager
     * @param \Tygh\Addons\Synchro\Logging                           $logging      Synchro journal
     */
    public function __construct(ImportEntityRepository $repository, CronManager $cron_manager, Logging $logging)
    {
        $this->repository = $repository;
        $this->cron_manager = $cron_manager;
        $this->logging = $logging;
    }

    /**
     * Queues archiving after a completed full product application.
     *
     * @param array<string, int|string> $process Application child process
     *
     * @return bool
     */
    public function queueAfterApplication(array $process)
    {
        if ($process['process_stage'] !== EntityApplicationPlanBuilder::STAGE_FINALIZE) {
            return true;
        }

        $parent = $this->repository->findImport($process['parent_import_id']);
        $script = $this->cron_manager->getCronScriptData($process['cron_script_id']);
        if (
            !$parent || !$script || $parent['status'] !== ImportEntityRepository::STATUS_COMPLETED
            || $parent['entity_type'] !== ProductDto::ENTITY_TYPE || $script['script'] !== 'synchro_import.apply_products'
        ) {
            $this->logging->error(self::SCRIPT, __('synchro.product_archiving_not_queued'));

            return false;
        }

        return $this->cron_manager->queuePostProcess(
            $process['cron_script_id'],
            $parent['import_id'],
            ImportEntityRepository::SOURCE_TYPE_FULL,
            ImportEntityRepository::STATUS_COMPLETED,
            self::SCRIPT
        );
    }
}

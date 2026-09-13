<?php

namespace Tygh\Addons\Synchro;

use Tygh\Addons\InstallerInterface;
use Tygh\Core\ApplicationInterface;

/**
 * Provides instructions to install and uninstall the synchro add-on.
 */
class Installer implements InstallerInterface
{
    /**
     * Allows administrators to import data from the external API.
     */
    const IMPORT_PRIVILEGE = 'manage_synchro_import';

    /**
     * @inheritDoc
     */
    public static function factory(ApplicationInterface $app)
    {
        return new self();
    }

    /**
     * @inheritDoc
     */
    public function onBeforeInstall()
    {
    }

    /**
     * @inheritDoc
     */
    public function onInstall()
    {
        $this->createCronScriptsTable();
        $this->createImportsTable();
        $this->createImportEntitiesTable();
        $this->createImportEntityMapTable();
        $this->createProductFeatureMappingsTable();
        $this->createLogsTable();
        $this->addImportPrivilege();
    }

    /**
     * @inheritDoc
     */
    public function onUninstall()
    {
        db_query('DROP TABLE IF EXISTS ?:synchro_cron_scripts');
        db_query('DROP TABLE IF EXISTS ?:synchro_product_feature_mappings');
        db_query('DROP TABLE IF EXISTS ?:synchro_import_entity_map');
        db_query('DROP TABLE IF EXISTS ?:synchro_import_entities');
        db_query('DROP TABLE IF EXISTS ?:synchro_imports');
        db_query('DROP TABLE IF EXISTS ?:synchro_logs');

        $this->removeImportPrivilege();
    }

    /**
     * Adds the privilege required to import data from the external API.
     *
     * @return void
     */
    protected function addImportPrivilege()
    {
        db_query('REPLACE INTO ?:privileges ?e', [
            'privilege'  => self::IMPORT_PRIVILEGE,
            'is_default' => 'Y',
            'section_id' => 'addons',
            'group_id'   => 'synchro',
            'is_view'    => 'N',
        ]);
    }

    /**
     * Removes the privilege required to import data from the external API.
     *
     * @return void
     */
    protected function removeImportPrivilege()
    {
        db_query('DELETE FROM ?:privileges WHERE privilege = ?s', self::IMPORT_PRIVILEGE);
    }

    /**
     * Creates the table that stores cron scripts and their schedules.
     *
     * @return void
     */
    protected function createCronScriptsTable()
    {
        $query = <<<'SQL'
CREATE TABLE IF NOT EXISTS ?:synchro_cron_scripts (
    script_id int(11) unsigned NOT NULL AUTO_INCREMENT,
    script varchar(255) NOT NULL DEFAULT '',
    description text NOT NULL DEFAULT '',
    status char(1) NOT NULL DEFAULT 'A',
    run_mode enum('periodic', 'once') NOT NULL DEFAULT 'periodic',
    inner_status enum(
        'scheduled', 'queued', 'in_progress', 'waiting_children', 'stopping',
        'completed', 'partial_success', 'failed', 'cancelled'
    ) NOT NULL DEFAULT 'scheduled',
    progress_status varchar(255) DEFAULT NULL,
    use_portions char(1) NOT NULL DEFAULT 'N',
    pages_per_portion int(11) unsigned NOT NULL DEFAULT '100',
    page_limit int(11) unsigned NOT NULL DEFAULT '200',
    entities_per_portion int(11) unsigned NOT NULL DEFAULT '30',
    max_parallel_processes int(11) unsigned NOT NULL DEFAULT '3',
    is_test_import char(1) NOT NULL DEFAULT 'N',
    test_page int(11) unsigned NOT NULL DEFAULT '1',
    post_process varchar(64) NOT NULL DEFAULT '',
    runtime_import_id int(11) unsigned NOT NULL DEFAULT '0',
    created int(11) NOT NULL DEFAULT '0',
    last_launch int(11) NOT NULL DEFAULT '0',
    period_month_days set(
        '1', '2', '3', '4', '5', '6', '7', '8', '9', '10',
        '11', '12', '13', '14', '15', '16', '17', '18', '19', '20',
        '21', '22', '23', '24', '25', '26', '27', '28', '29', '30', '31'
    ),
    period_week_days set(
        'monday', 'tuesday', 'wednesday', 'thursday',
        'friday', 'saturday', 'sunday'
    ),
    period_hours_begin tinyint unsigned NOT NULL DEFAULT 0,
    period_hours_end tinyint unsigned NOT NULL DEFAULT 0,
    refresh_hours tinyint unsigned NOT NULL DEFAULT 0,
    refresh_minutes tinyint unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (script_id),
    UNIQUE KEY script (script)
) ENGINE=InnoDB DEFAULT CHARSET=UTF8
SQL;

        db_query($query);
    }

    /**
     * Creates the table that stores import runs.
     *
     * @return void
     */
    protected function createImportsTable()
    {
        $query = <<<'SQL'
CREATE TABLE IF NOT EXISTS ?:synchro_imports (
    import_id int(11) unsigned NOT NULL AUTO_INCREMENT,
    parent_import_id int(11) unsigned NOT NULL DEFAULT '0',
    staging_import_id int(11) unsigned NOT NULL DEFAULT '0',
    cron_script_id int(11) unsigned NOT NULL DEFAULT '0',
    company_id int(11) unsigned NOT NULL DEFAULT '0',
    entity_type varchar(64) NOT NULL DEFAULT '',
    source_type enum('full', 'test') NOT NULL DEFAULT 'full',
    process_group int(11) unsigned NOT NULL DEFAULT '0',
    process_stage enum('fetch', 'prepare', 'apply', 'finalize') NOT NULL DEFAULT 'fetch',
    status enum(
        'queued', 'processing', 'completed', 'partial_success',
        'failed', 'stopping', 'cancelled'
    ) NOT NULL DEFAULT 'processing',
    page_from int(11) unsigned NOT NULL DEFAULT '0',
    page_to int(11) unsigned NOT NULL DEFAULT '0',
    current_page int(11) unsigned NOT NULL DEFAULT '0',
    page_limit int(11) unsigned NOT NULL DEFAULT '0',
    total_items int(11) unsigned NOT NULL DEFAULT '0',
    total_pages int(11) unsigned NOT NULL DEFAULT '0',
    max_parallel_processes int(11) unsigned NOT NULL DEFAULT '1',
    processed_items int(11) unsigned NOT NULL DEFAULT '0',
    error_message text NOT NULL,
    created_at int(11) unsigned NOT NULL DEFAULT '0',
    started_at int(11) unsigned NOT NULL DEFAULT '0',
    updated_at int(11) unsigned NOT NULL DEFAULT '0',
    completed_at int(11) unsigned NOT NULL DEFAULT '0',
    PRIMARY KEY (import_id),
    KEY idx_import (company_id, entity_type, status, import_id),
    KEY idx_parent_status (parent_import_id, status, process_group, page_from),
    KEY idx_cron_parent (cron_script_id, parent_import_id, import_id)
) ENGINE=InnoDB DEFAULT CHARSET=UTF8
SQL;

        db_query($query);
    }

    /**
     * Creates the table that stores normalized entities before importing them into CS-Cart.
     *
     * @return void
     */
    protected function createImportEntitiesTable()
    {
        $query = <<<'SQL'
CREATE TABLE IF NOT EXISTS ?:synchro_import_entities (
    import_id int(11) unsigned NOT NULL DEFAULT '0',
    company_id int(11) unsigned NOT NULL DEFAULT '0',
    entity_id varchar(128) NOT NULL DEFAULT '',
    entity_type varchar(64) NOT NULL DEFAULT '',
    application_level int(11) unsigned NOT NULL DEFAULT '0',
    entity mediumblob NOT NULL,
    created_at int(11) unsigned NOT NULL DEFAULT '0',
    updated_at int(11) unsigned NOT NULL DEFAULT '0',
    PRIMARY KEY (import_id, entity_type, entity_id),
    KEY idx_entity_type (company_id, entity_type, import_id),
    KEY idx_application_batch (import_id, entity_type, application_level, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=UTF8
SQL;

        db_query($query);
    }

    /**
     * Creates the table that maps external entities to CS-Cart entities.
     *
     * @return void
     */
    protected function createImportEntityMapTable()
    {
        $query = <<<'SQL'
CREATE TABLE IF NOT EXISTS ?:synchro_import_entity_map (
    company_id int(11) unsigned NOT NULL DEFAULT '0',
    entity_type varchar(64) NOT NULL DEFAULT '',
    external_id varchar(128) NOT NULL DEFAULT '',
    local_id int(11) unsigned NOT NULL DEFAULT '0',
    entity_name varchar(255) NOT NULL DEFAULT '',
    full_updated_timestamp int(11) unsigned NOT NULL DEFAULT '0',
    actualized_timestamp int(11) unsigned NOT NULL DEFAULT '0',
    needs_archiving char(1) NOT NULL DEFAULT 'N',
    PRIMARY KEY (company_id, entity_type, external_id),
    KEY idx_local_entity (company_id, entity_type, local_id)
) ENGINE=InnoDB DEFAULT CHARSET=UTF8
SQL;

        db_query($query);
    }

    /**
     * Creates the table that stores mappings between imported and local product features.
     *
     * @return void
     */
    protected function createProductFeatureMappingsTable()
    {
        $query = <<<'SQL'
CREATE TABLE IF NOT EXISTS ?:synchro_product_feature_mappings (
    company_id int(11) unsigned NOT NULL DEFAULT '0',
    external_feature_id varchar(128) NOT NULL DEFAULT '',
    local_feature_id int(11) unsigned NOT NULL DEFAULT '0',
    PRIMARY KEY (company_id, external_feature_id),
    KEY idx_local_feature (company_id, local_feature_id)
) ENGINE=InnoDB DEFAULT CHARSET=UTF8
SQL;

        db_query($query);
    }

    /**
     * Creates the table that stores Synchro journal entries.
     *
     * @return void
     */
    protected function createLogsTable()
    {
        $query = <<<'SQL'
CREATE TABLE IF NOT EXISTS ?:synchro_logs (
    log_id int(11) unsigned NOT NULL AUTO_INCREMENT,
    timestamp int(11) unsigned NOT NULL DEFAULT '0',
    level enum('info', 'warning', 'error') NOT NULL DEFAULT 'info',
    source varchar(255) NOT NULL DEFAULT '',
    message text NOT NULL,
    context mediumtext,
    PRIMARY KEY (log_id),
    KEY idx_timestamp (timestamp),
    KEY idx_level (level),
    KEY idx_source (source)
) ENGINE=InnoDB DEFAULT CHARSET=UTF8
SQL;

        db_query($query);
    }
}

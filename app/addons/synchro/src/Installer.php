<?php

namespace Tygh\Addons\Synchro;

use Tygh\Addons\InstallerInterface;
use Tygh\Addons\Synchro\Enum\Logging;
use Tygh\Core\ApplicationInterface;
use Tygh\Languages\Languages;
use Tygh\Settings;

/**
 * Provides instructions to install and uninstall the synchro add-on.
 */
class Installer implements InstallerInterface
{
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
        $this->addLoggingSetting();
    }

    /**
     * @inheritDoc
     */
    public function onUninstall()
    {
        db_query('DROP TABLE IF EXISTS ?:cron_scripts');
        db_query('DELETE FROM ?:logs WHERE type = ?s', Logging::LOG_TYPE_CRON_MANAGER);

        $this->removeLoggingSetting();
    }

    /**
     * Creates the table that stores cron scripts and their schedules.
     *
     * @return void
     */
    protected function createCronScriptsTable()
    {
        $query = <<<'SQL'
CREATE TABLE IF NOT EXISTS ?:cron_scripts (
    script_id int(11) unsigned NOT NULL AUTO_INCREMENT,
    script_type enum(
        'from_admin_area',
        'from_customer_area',
        'custom_command'
    ) NOT NULL DEFAULT 'from_admin_area',
    script varchar(255) NOT NULL DEFAULT '',
    description text NOT NULL DEFAULT '',
    status char(1) NOT NULL DEFAULT 'A',
    inner_status enum('scheduled', 'in_progress') NOT NULL DEFAULT 'scheduled',
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
    ) NOT NULL,
    period_hours_begin enum(
        '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11',
        '12', '13', '14', '15', '16', '17', '18', '19', '20', '21', '22', '23'
    ) NOT NULL DEFAULT '0',
    period_hours_end enum(
        '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11',
        '12', '13', '14', '15', '16', '17', '18', '19', '20', '21', '22', '23'
    ) NOT NULL DEFAULT '0',
    refresh_hours enum(
        '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11',
        '12', '13', '14', '15', '16', '17', '18', '19', '20', '21', '22', '23'
    ) NOT NULL DEFAULT '0',
    refresh_minutes enum(
        '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11',
        '12', '13', '14', '15', '16', '17', '18', '19', '20', '21', '22', '23',
        '24', '25', '26', '27', '28', '29', '30', '31', '32', '33', '34', '35',
        '36', '37', '38', '39', '40', '41', '42', '43', '44', '45', '46', '47',
        '48', '49', '50', '51', '52', '53', '54', '55', '56', '57', '58', '59'
    ) NOT NULL DEFAULT '0',
    PRIMARY KEY (script_id)
) ENGINE=InnoDB DEFAULT CHARSET=UTF8
SQL;

        db_query($query);
    }

    /**
     * Adds cron manager logging settings.
     *
     * @return void
     */
    protected function addLoggingSetting()
    {
        $settings = Settings::instance();
        $setting_name = 'log_type_' . Logging::LOG_TYPE_CRON_MANAGER;
        $setting = $settings->getSettingDataByName($setting_name);
        $logging_section = $settings->getSectionByName('Logging');

        if ($setting || empty($logging_section['section_id'])) {
            return;
        }

        $setting = [
            'name'           => $setting_name,
            'section_id'     => $logging_section['section_id'],
            'section_tab_id' => 0,
            'type'           => 'N',
            'position'       => 20,
            'is_global'      => 'Y',
            'edition_type'   => 'ROOT',
        ];

        $lang_codes = array_keys(Languages::getAll());
        $descriptions = [];
        foreach ($lang_codes as $lang_code) {
            $descriptions[] = [
                'object_type' => Settings::SETTING_DESCRIPTION,
                'lang_code'   => $lang_code,
                'value'       => __('synchro.log_type_crons_manager', [], $lang_code),
            ];
        }

        $setting_id = $settings->update($setting, null, $descriptions, true);
        if (!$setting_id) {
            return;
        }

        $actions = Logging::getActions();
        foreach ($actions as $action) {
            $variant_id = $settings->updateVariant([
                'object_id' => $setting_id,
                'name'      => $action,
                'position'  => 5,
            ]);

            foreach ($lang_codes as $lang_code) {
                $settings->updateDescription([
                    'object_id'   => $variant_id,
                    'object_type' => Settings::VARIANT_DESCRIPTION,
                    'lang_code'   => $lang_code,
                    'value'       => __('synchro.log_action_' . $action, [], $lang_code),
                ]);
            }
        }

        $settings->updateValue($setting_name, $actions, 'Logging');
    }

    /**
     * Removes cron manager logging settings.
     *
     * @return void
     */
    protected function removeLoggingSetting()
    {
        $settings = Settings::instance();
        $setting = $settings->getSettingDataByName('log_type_' . Logging::LOG_TYPE_CRON_MANAGER);

        if (!$setting) {
            return;
        }

        $settings->removeById($setting['object_id']);
    }
}

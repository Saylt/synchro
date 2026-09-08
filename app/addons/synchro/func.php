<?php

use Tygh\Registry;

defined('BOOTSTRAP') or die('Access denied');

/**
 * Gets information on setting up the cron runner.
 *
 * @return string
 */
function fn_clear_cron_info()
{
    $root_directory = rtrim((string) Registry::get('config.dir.root'), '/');
    $admin_index = (string) Registry::get('config.admin_index');
    $cron_password = (string) Registry::get('settings.Security.cron_password');
    $cron_set_string = '* * * * * php'
        . ' ' . $root_directory . '/' . $admin_index
        . ' --dispatch=cron_script_manager.launcher'
        . ' --cron_password=' . $cron_password;
    $replacements = [
        '[admin_index]'     => $admin_index,
        '[cron_password]'   => $cron_password,
        '[cron_set_string]' => $cron_set_string,
    ];

    return str_replace(
        array_keys($replacements),
        array_values($replacements),
        nl2br(trim((string) __('synchro.cron_script_manager_run_info')))
    );
}

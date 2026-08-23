<?php
/***************************************************************************
 *                                                                          *
 *    Copyright (c) 2004 Simbirsk Technologies Ltd. All rights reserved.    *
 *                                                                          *
 * This  is  commercial  software,  only  users  who have purchased a valid *
 * license  and  accept  to the terms of the  License Agreement can install *
 * and use this program.                                                    *
 *                                                                          *
 ****************************************************************************
 * PLEASE READ THE FULL TEXT  OF THE SOFTWARE  LICENSE   AGREEMENT  IN  THE *
 * "copyright.txt" FILE PROVIDED WITH THIS DISTRIBUTION PACKAGE.            *
 ****************************************************************************/


defined('BOOTSTRAP') or die('Access denied');

/** @var array<string, array> $schema */

$schema['cron_script_manager']['permissions'] = 'manage_cron_scripts';
$schema['synchro_import']['permissions'] = 'manage_synchro_import';
$schema['sync_data']['modes']['update']['param_permissions']['sync_provider_id']['synchro'] =
    'manage_synchro_import';

return $schema;

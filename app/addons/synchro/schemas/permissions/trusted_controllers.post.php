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


defined('AREA') || die('Access denied');

$schema['cron_script_manager'] = [
    'allow' => [
        'launcher' => true,
    ],
];

return $schema;
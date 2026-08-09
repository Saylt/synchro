<?php

namespace Tygh\Addons\Synchro\Enum;

/**
 * Contains cron manager logging constants.
 */
class Logging
{
    const LOG_TYPE_CRON_MANAGER = 'crons_manager';

    const ACTION_LAUNCH = 'launch';

    const ACTION_ERRORS = 'errors';

    /**
     * @return array<string>
     */
    public static function getActions()
    {
        return [
            self::ACTION_LAUNCH,
            self::ACTION_ERRORS,
        ];
    }
}

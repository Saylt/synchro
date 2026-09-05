<?php

namespace Tygh\Addons\Synchro\HookHandlers;

use Tygh\Addons\Synchro\Enum\Logging;

/**
 * Handles cron manager log events.
 */
class LoggingHookHandler
{
    /**
     * The "save_log" hook handler.
     *
     * @param string                                       $type       Log type
     * @param string                                       $action     Log action
     * @param array<string, array<int, string>|int|string> $data       Log data
     * @param int                                          $user_id    User identifier
     * @param array<string, array<int, string>|int|string> $content    Log content
     * @param string                                       $event_type Event type
     *
     * @return void
     *
     * @see \fn_log_event()
     */
    public function onSaveLog($type, $action, array &$data, $user_id, array &$content, &$event_type)
    {
        if ($type !== Logging::LOG_TYPE_CRON_MANAGER) {
            return;
        }

        $content = ['script' => $data['script']];

        if ($action === Logging::ACTION_LAUNCH) {
            $execution_seconds = (int) $data['execution_time'];
            $execution_minutes = (int) floor($execution_seconds / 60);
            if ($execution_minutes) {
                $execution_seconds -= $execution_minutes * 60;
            }

            $content['synchro.execution_time'] = ($execution_minutes ? $execution_minutes . ' |minutes|' : '')
                . $execution_seconds . ' |seconds|';

            if (!empty($data['output']) && is_array($data['output'])) {
                $backtrace = [];
                foreach (array_values($data['output']) as $position => $line) {
                    $backtrace[] = [
                        'file' => $position + 1,
                        'line' => $line,
                    ];
                }
                $data['backtrace'] = serialize($backtrace);
            }
        } elseif ($action === Logging::ACTION_ERRORS) {
            $event_type = 'E';
            $content['error'] = $data['error'];
        }
    }
}

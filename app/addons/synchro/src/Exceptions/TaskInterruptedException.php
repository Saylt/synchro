<?php

namespace Tygh\Addons\Synchro\Exceptions;

use RuntimeException;

/**
 * Indicates that a cron task must stop at the current safe interruption point.
 */
class TaskInterruptedException extends RuntimeException
{
}

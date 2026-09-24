<?php

namespace App\Queries;

use InvalidArgumentException;

/**
 * A history request that can't be served, with a user-presentable message.
 */
class HistoryQueryException extends InvalidArgumentException {}

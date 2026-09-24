<?php

namespace App\Agent\Exceptions;

use RuntimeException;

/**
 * A frame analysis failed. The message is safe to show to the user.
 */
class AnalysisFailed extends RuntimeException {}

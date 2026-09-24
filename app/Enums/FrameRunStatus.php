<?php

namespace App\Enums;

/**
 * Outcome of one agent run over a frame.
 */
enum FrameRunStatus: string
{
    case Completed = 'completed';
    case Failed = 'failed';
}

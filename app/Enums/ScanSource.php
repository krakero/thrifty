<?php

namespace App\Enums;

/**
 * Where a scan session's frames come from.
 */
enum ScanSource: string
{
    case Camera = 'camera';
    case Video = 'video';
    case Image = 'image';
}

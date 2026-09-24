<?php

namespace App\Support;

use Carbon\CarbonInterface;
use DateTimeZone;
use Thrifty\Camera\Facades\ThriftyCamera;
use Throwable;

/**
 * Displays stored (UTC) timestamps in the device's timezone, like the web app's `toLocaleString()`.
 *
 * The on-device PHP runtime has no system timezone, so it is read once per PHP context from the native side.
 */
class LocalTime
{
    private static ?string $timezone = null;

    public static function format(?CarbonInterface $moment, string $format = 'M j, Y, g:i A'): string
    {
        if ($moment === null) {
            return '—';
        }

        return $moment->setTimezone(self::timezone())->format($format);
    }

    /**
     * The device timezone, cached once detected. A failed detection is not cached, so it is retried next time.
     */
    public static function timezone(): string
    {
        if (self::$timezone !== null) {
            return self::$timezone;
        }

        $detected = self::detectTimezone();

        if ($detected !== null) {
            self::$timezone = $detected;
        }

        return $detected ?? config('app.timezone');
    }

    /**
     * Override the detected timezone (tests), or pass null to detect again.
     */
    public static function useTimezone(?string $timezone): void
    {
        self::$timezone = $timezone;
    }

    private static function detectTimezone(): ?string
    {
        try {
            $timezone = ThriftyCamera::deviceTimezone();
        } catch (Throwable) {
            $timezone = null;
        }

        if (is_string($timezone) && in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            return $timezone;
        }

        return null;
    }
}

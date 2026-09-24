<?php

namespace Thrifty\Camera;

use DateTimeZone;
use Illuminate\Support\Str;

class ThriftyCamera
{
    /**
     * Capture the current live preview frame. Asynchronous: the frame arrives
     * as a FrameCaptured event with source "snapshot".
     */
    public function snapshot(string $directory): void
    {
        $this->call('ThriftyCamera.Snapshot', ['directory' => $directory]);
    }

    /**
     * Play a video file and sample a frame in real time: the first at 0.35s,
     * then one every $intervalSeconds of playback, until the video ends.
     * Asynchronous: emits FrameCaptured (source "video", with the run id)
     * per frame, then VideoFramesExtracted on every exit path (end, failure
     * or cancel).
     *
     * @return string The run id carried by this extraction's events.
     */
    public function extractVideoFrames(string $videoPath, int $intervalSeconds, string $directory): string
    {
        $runId = (string) Str::uuid();

        $this->call('ThriftyCamera.ExtractVideoFrames', [
            'videoPath' => $videoPath,
            'intervalSeconds' => max(1, $intervalSeconds),
            'directory' => $directory,
            'runId' => $runId,
        ]);

        return $runId;
    }

    /**
     * Stop a running video extraction. It still ends with VideoFramesExtracted.
     */
    public function cancelVideoExtraction(string $runId): void
    {
        $this->call('ThriftyCamera.CancelVideoExtraction', ['runId' => $runId]);
    }

    /**
     * Whether the extraction is still running and how many frames it has
     * emitted so far. Asks the device (ThriftyCamera.VideoRunStatus) and
     * falls back to the event journal when the bridge gives no answer.
     *
     * @return array{active: bool, framesEmitted: int}
     */
    public function videoRunStatus(string $runId): array
    {
        $response = $this->call('ThriftyCamera.VideoRunStatus', ['runId' => $runId]);
        $status = is_string($response) ? json_decode($response, true) : null;

        if (is_array($status) && array_key_exists('active', $status)) {
            return [
                'active' => (bool) $status['active'],
                'framesEmitted' => (int) ($status['framesEmitted'] ?? 0),
            ];
        }

        $journaled = $this->journal()->status($runId);

        return [
            'active' => $journaled['known'] && ! $journaled['finished'],
            'framesEmitted' => $journaled['framesEmitted'],
        ];
    }

    /**
     * Remove and return every event of this video run that the Scan screen
     * hasn't consumed yet, oldest first: FrameCaptured (video),
     * CameraFailed and the final VideoFramesExtracted. Events are journaled
     * whichever screen is active, before any #[On] handler runs, so this
     * also returns the event currently being handled.
     *
     * @return list<Events\FrameCaptured|Events\VideoFramesExtracted|Events\CameraFailed>
     */
    public function takeVideoEvents(string $runId): array
    {
        return $this->journal()->take($runId);
    }

    /**
     * Stop tracking a run (after cancelling or replacing it) and delete the
     * JPEGs of any of its frames that were never taken.
     */
    public function forgetVideoRun(string $runId): void
    {
        $this->journal()->forget($runId);
    }

    /**
     * Normalize a picked image (HEIC/HEIF/PNG/JPEG/WebP) into a JPEG frame:
     * EXIF orientation applied, at most 960px wide, quality 0.82 (the web's
     * upload compression).
     * Asynchronous: emits FrameCaptured with source "image", or CameraFailed.
     */
    public function importImage(string $imagePath, string $directory): void
    {
        $this->call('ThriftyCamera.ImportImage', [
            'imagePath' => $imagePath,
            'directory' => $directory,
        ]);
    }

    /**
     * Play the snapshot feedback: a short shutter click and a light haptic.
     */
    public function shutter(): void
    {
        $this->call('ThriftyCamera.Shutter');
    }

    /**
     * The device's IANA timezone identifier (e.g. "Europe/London"), or null
     * when it isn't available (tests, or outside the app).
     */
    public function deviceTimezone(): ?string
    {
        $response = $this->call('ThriftyCamera.DeviceTimezone');
        $timezone = is_string($response) ? (json_decode($response, true)['timezone'] ?? null) : null;

        if (! is_string($timezone) || ! in_array($timezone, timezone_identifiers_list(DateTimeZone::ALL_WITH_BC), true)) {
            return null;
        }

        return $timezone;
    }

    /**
     * Play the find chime together with a success haptic.
     */
    public function chime(): void
    {
        $this->call('ThriftyCamera.Chime');
    }

    /**
     * Render a branded find card image and open the iOS share sheet.
     *
     * `boxes` holds every find in the frame (the selected one is drawn
     * strongest); `box` is kept for callers that only know one find.
     *
     * @param  array{title: string, subtitle: string, imagePath: string, box?: array{xMin: int|float, yMin: int|float, xMax: int|float, yMax: int|float}|null, boxes?: list<array{xMin: int|float, yMin: int|float, xMax: int|float, yMax: int|float, selected?: bool}>, rows: list<array{label: string, value: string}>, summary: string}  $card
     */
    public function shareFindCard(array $card): void
    {
        $this->call('ThriftyCamera.ShareFindCard', [
            'title' => (string) ($card['title'] ?? ''),
            'subtitle' => (string) ($card['subtitle'] ?? ''),
            'imagePath' => (string) ($card['imagePath'] ?? ''),
            'box' => $card['box'] ?? null,
            'boxes' => array_values(array_map(
                fn (array $box): array => [
                    'xMin' => $box['xMin'],
                    'yMin' => $box['yMin'],
                    'xMax' => $box['xMax'],
                    'yMax' => $box['yMax'],
                    'selected' => (bool) ($box['selected'] ?? false),
                ],
                $card['boxes'] ?? [],
            )),
            'rows' => array_values(array_map(
                fn (array $row): array => ['label' => (string) $row['label'], 'value' => (string) $row['value']],
                $card['rows'] ?? [],
            )),
            'summary' => (string) ($card['summary'] ?? ''),
        ]);
    }

    protected function journal(): VideoRunJournal
    {
        return app(VideoRunJournal::class);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    protected function call(string $method, array $parameters = []): mixed
    {
        if (! function_exists('nativephp_call')) {
            return null;
        }

        return nativephp_call($method, json_encode((object) $parameters));
    }
}

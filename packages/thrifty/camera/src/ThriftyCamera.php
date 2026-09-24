<?php

namespace Thrifty\Camera;

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
     * Sample a video every $intervalSeconds. Asynchronous: emits one
     * FrameCaptured (source "video") per frame, then VideoFramesExtracted.
     */
    public function extractVideoFrames(string $videoPath, int $intervalSeconds, string $directory): void
    {
        $this->call('ThriftyCamera.ExtractVideoFrames', [
            'videoPath' => $videoPath,
            'intervalSeconds' => max(1, $intervalSeconds),
            'directory' => $directory,
        ]);
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
     * @param  array{title: string, subtitle: string, imagePath: string, box: array{xMin: int|float, yMin: int|float, xMax: int|float, yMax: int|float}|null, rows: list<array{label: string, value: string}>, summary: string}  $card
     */
    public function shareFindCard(array $card): void
    {
        $this->call('ThriftyCamera.ShareFindCard', [
            'title' => (string) ($card['title'] ?? ''),
            'subtitle' => (string) ($card['subtitle'] ?? ''),
            'imagePath' => (string) ($card['imagePath'] ?? ''),
            'box' => $card['box'] ?? null,
            'rows' => array_values(array_map(
                fn (array $row): array => ['label' => (string) $row['label'], 'value' => (string) $row['value']],
                $card['rows'] ?? [],
            )),
            'summary' => (string) ($card['summary'] ?? ''),
        ]);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    protected function call(string $method, array $parameters = []): void
    {
        if (function_exists('nativephp_call')) {
            nativephp_call($method, json_encode((object) $parameters));
        }
    }
}

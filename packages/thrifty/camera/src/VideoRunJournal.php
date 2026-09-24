<?php

namespace Thrifty\Camera;

use Illuminate\Support\Facades\File;
use Thrifty\Camera\Events\CameraFailed;
use Thrifty\Camera\Events\FrameCaptured;
use Thrifty\Camera\Events\VideoFramesExtracted;

/**
 * Records every event that belongs to a video extraction run, whichever
 * screen is active when it arrives, so the Scan screen can catch up on
 * frames delivered while a find or Settings was pushed on top of it.
 *
 * Plugin events broadcast globally (BroadcastsGlobally), and NativePHP
 * dispatches them through Laravel before the active screen's #[On] handler
 * runs, so an event is always journaled before any handler sees it.
 *
 * Backed by a small JSON file so it survives the PHP context switching
 * between screens.
 */
class VideoRunJournal
{
    /** Journaled runs are dropped after a day without new events. */
    public const StaleAfterSeconds = 86_400;

    public function __construct(protected string $path) {}

    public function record(FrameCaptured|VideoFramesExtracted|CameraFailed $event): void
    {
        if ($event->runId === null || $event->runId === '') {
            return;
        }

        $this->mutate(function (array $runs) use ($event): array {
            $run = $runs[$event->runId] ?? ['events' => [], 'framesEmitted' => 0, 'finished' => false];

            $run['events'][] = ['type' => $event::class, 'payload' => get_object_vars($event)];
            $run['updatedAt'] = time();

            if ($event instanceof FrameCaptured) {
                $run['framesEmitted']++;
            }

            if ($event instanceof VideoFramesExtracted) {
                $run['finished'] = true;
            }

            $runs[$event->runId] = $run;

            return $runs;
        });
    }

    /**
     * Remove and return the run's undelivered events, oldest first.
     *
     * @return list<FrameCaptured|VideoFramesExtracted|CameraFailed>
     */
    public function take(string $runId): array
    {
        $taken = [];

        $this->mutate(function (array $runs) use ($runId, &$taken): array {
            if (! isset($runs[$runId])) {
                return $runs;
            }

            $taken = $runs[$runId]['events'];
            $runs[$runId]['events'] = [];

            // A finished run has nothing left to deliver.
            if ($runs[$runId]['finished']) {
                unset($runs[$runId]);
            }

            return $runs;
        });

        return array_values(array_filter(array_map(fn (array $entry): ?object => $this->hydrate($entry), $taken)));
    }

    /**
     * Drop a run (e.g. after cancelling it), deleting the JPEGs of frames
     * that were never delivered.
     */
    public function forget(string $runId): void
    {
        foreach ($this->take($runId) as $event) {
            if ($event instanceof FrameCaptured) {
                File::delete($event->path);
            }
        }

        $this->mutate(function (array $runs) use ($runId): array {
            unset($runs[$runId]);

            return $runs;
        });
    }

    /**
     * @return array{known: bool, finished: bool, framesEmitted: int, pending: int}
     */
    public function status(string $runId): array
    {
        $run = $this->read()[$runId] ?? null;

        return [
            'known' => $run !== null,
            'finished' => (bool) ($run['finished'] ?? false),
            'framesEmitted' => (int) ($run['framesEmitted'] ?? 0),
            'pending' => count($run['events'] ?? []),
        ];
    }

    /**
     * @return array<string, array{events: list<array{type: class-string, payload: array<string, mixed>}>, framesEmitted: int, finished: bool, updatedAt?: int}>
     */
    protected function read(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $runs = json_decode((string) file_get_contents($this->path), true);

        return is_array($runs) ? $runs : [];
    }

    protected function mutate(callable $change): void
    {
        File::ensureDirectoryExists(dirname($this->path));

        $handle = fopen($this->path, 'c+');
        if ($handle === false) {
            return;
        }

        try {
            flock($handle, LOCK_EX);

            $runs = json_decode((string) stream_get_contents($handle), true);
            $runs = $change(is_array($runs) ? $runs : []);

            $cutoff = time() - self::StaleAfterSeconds;
            $runs = array_filter($runs, fn (array $run): bool => ($run['updatedAt'] ?? time()) >= $cutoff);

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($runs));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param  array{type: class-string, payload: array<string, mixed>}  $entry
     */
    protected function hydrate(array $entry): FrameCaptured|VideoFramesExtracted|CameraFailed|null
    {
        if (! in_array($entry['type'], [FrameCaptured::class, VideoFramesExtracted::class, CameraFailed::class], true)) {
            return null;
        }

        return new $entry['type'](...$entry['payload']);
    }
}

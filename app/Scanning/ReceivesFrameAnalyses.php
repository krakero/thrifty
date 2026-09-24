<?php

namespace App\Scanning;

use App\NativeComponents\Scan;
use Native\Mobile\Attributes\On;

/**
 * Lets a screen settle frame analyses whose shared result arrives while it is the active component.
 *
 * Async results are only delivered to the active screen, so any screen that can sit on top of Scan while
 * analyses are in flight should use this; otherwise the result is only recovered later from its FrameRun.
 */
trait ReceivesFrameAnalyses
{
    /**
     * @param  array<string, mixed>|null  $result
     */
    #[On(Scan::FrameAnalyzedEvent)]
    public function receiveFrameAnalysis(string $id, string $status, mixed $result = null, ?string $exceptionClass = null, ?string $message = null): void
    {
        app(FrameResults::class)->handle($id, $status, $result, $exceptionClass, $message);
    }
}

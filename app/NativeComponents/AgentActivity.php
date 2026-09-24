<?php

namespace App\NativeComponents;

use App\Enums\FrameRunStatus;
use App\Models\FrameRun;
use App\Models\Item;
use App\NativeComponents\Layouts\TabsLayout;
use App\Scanning\ReceivesFrameAnalyses;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * The persisted agent run behind a find: what the model was told, what it did and what it returned.
 */
class AgentActivity extends NativeComponent
{
    use ReceivesFrameAnalyses;

    /** Longer audit values are cut so a huge raw response can't stall the native renderer. */
    public const MAX_BLOCK_LENGTH = 20000;

    public string $itemId = '';

    public string $from = 'history';

    /**
     * Blocks the user has flipped away from their default open/closed state.
     *
     * @var list<string>
     */
    public array $toggled = [];

    public function mount(): void
    {
        $this->itemId = (string) $this->param('id');
        $this->from = TabsLayout::tabFor($this->param('tab') ?? $this->data('from'));
    }

    public function navTitle(): string
    {
        return 'Agent activity';
    }

    public function toggle(string $block): void
    {
        $this->toggled = in_array($block, $this->toggled, true)
            ? array_values(array_diff($this->toggled, [$block]))
            : [...$this->toggled, $block];
    }

    public function render(): View
    {
        $item = Item::query()->with('frameRun')->find($this->itemId);
        $run = $item?->frameRun;

        return view('native.agent-activity', [
            'item' => $item,
            'run' => $run !== null && $run->instructions !== null ? $run : null,
            'blocks' => $run !== null && $run->instructions !== null ? $this->blocks($run) : [],
        ]);
    }

    /**
     * The audit trail in the web app's order: instructions and input (open), each event (open), then raw responses,
     * output and usage (closed).
     *
     * @return list<array{key: string, title: string, index: int|null, body: string, open: bool}>
     */
    private function blocks(FrameRun $run): array
    {
        $blocks = [
            $this->block('instructions', 'Agent instructions', $run->instructions, true),
            $this->block('input', 'Input', $run->input_json, true),
        ];

        foreach (array_values($run->events_json ?? []) as $position => $event) {
            $sequence = is_array($event) && is_int($event['sequence'] ?? null) ? $event['sequence'] : $position;
            $title = is_array($event) && is_string($event['title'] ?? null) ? $event['title'] : 'Event';

            $blocks[] = [
                ...$this->block("event-{$position}", $title, is_array($event) ? ($event['data'] ?? null) : $event, true),
                'index' => $sequence + 1,
            ];
        }

        $rawResponses = $run->raw_responses_json ?? [];

        return [
            ...$blocks,
            $this->block('raw', 'Raw model responses · '.count($rawResponses), $rawResponses, false),
            $this->block('output', 'Final structured output', $run->output_json, false),
            $this->block('usage', 'Usage', $run->usage_json, false),
        ];
    }

    /**
     * @return array{key: string, title: string, index: int|null, body: string, open: bool}
     */
    private function block(string $key, string $title, mixed $value, bool $openByDefault): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'index' => null,
            'body' => $this->pretty($value),
            'open' => $openByDefault xor in_array($key, $this->toggled, true),
        ];
    }

    private function pretty(mixed $value): string
    {
        $text = is_string($value) ? $value : $this->prettyJson($value);

        if (mb_strlen($text) <= self::MAX_BLOCK_LENGTH) {
            return $text;
        }

        $hidden = mb_strlen($text) - self::MAX_BLOCK_LENGTH;

        return mb_substr($text, 0, self::MAX_BLOCK_LENGTH)."\n… ".number_format($hidden).' more characters';
    }

    /**
     * JSON with two-space indentation, like the web app's `JSON.stringify(value, null, 2)`.
     */
    private function prettyJson(mixed $value): string
    {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: 'null';

        return preg_replace_callback('/^(?: {4})+/m', fn (array $match): string => str_repeat(' ', strlen($match[0]) / 2), $json) ?? $json;
    }

    /**
     * @return array{label: string, tone: string}
     */
    public static function statusBadge(FrameRun $run): array
    {
        return $run->status === FrameRunStatus::Failed
            ? ['label' => 'Failed', 'tone' => 'bg-theme-destructive/20 text-theme-destructive']
            : ['label' => 'Completed', 'tone' => 'bg-theme-accent/20 text-theme-accent'];
    }
}

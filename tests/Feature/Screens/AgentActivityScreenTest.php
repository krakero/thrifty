<?php

use App\Enums\FrameRunStatus;
use App\Models\FrameRun;
use App\Models\Item;
use App\NativeComponents\AgentActivity;
use App\NativeComponents\Layouts\TabsLayout;
use App\Support\LocalTime;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    LocalTime::useTimezone('America/New_York');
});

afterEach(function () {
    LocalTime::useTimezone(null);
});

function agentActivity(Item|string $item)
{
    return Native::test(AgentActivity::class, ['tab' => 'history', 'id' => $item instanceof Item ? $item->id : $item], [], TabsLayout::class);
}

function recordedRun(array $attributes = []): FrameRun
{
    return FrameRun::factory()->create([
        'model' => 'gpt-5.6-luna',
        'latency_ms' => 12345,
        'item_count' => 2,
        'model_calls' => 3,
        'searches_performed' => 4,
        'captured_at' => '2026-09-16 12:00:00',
        'completed_at' => '2026-09-16 12:00:12',
        'instructions' => 'Inspect a single frame from a yard sale.',
        'input_json' => ['role' => 'user', 'content' => '[frame stored on device]'],
        'events_json' => [
            ['sequence' => 0, 'type' => 'tool_call', 'title' => 'Web search', 'data' => ['query' => 'brass <lamp> value']],
            ['sequence' => 1, 'type' => 'tool_output', 'title' => 'Tool result', 'data' => ['results' => 3]],
        ],
        'raw_responses_json' => [['id' => 'resp_1'], ['id' => 'resp_2']],
        'output_json' => ['items' => [['name' => 'Brass lamp']]],
        'usage_json' => ['total_tokens' => 4321],
        ...$attributes,
    ]);
}

it('shows the run summary and audit trail', function () {
    $item = Item::factory()->for(recordedRun())->create();

    $screen = agentActivity($item)
        ->assertNavTitle('Agent activity')
        ->assertSee('Completed')
        ->assertSee('gpt-5.6-luna')
        ->assertSee('12.3s')
        ->assertSee('Sep 16, 2026, 8:00:00 AM')
        ->assertSee('Sep 16, 2026, 8:00:12 AM')
        ->assertSee('Agent instructions')
        ->assertSee('Inspect a single frame from a yard sale.')
        ->assertSee('[frame stored on device]')
        ->assertSee('Web search')
        ->assertSee('"query": "brass <lamp> value"')
        ->assertSee('Tool result')
        ->assertSee('Raw model responses · 2')
        ->assertSee('Final structured output')
        ->assertSee('Usage')
        ->assertDontSee('resp_1')
        ->assertDontSee('total_tokens');

    expect(json_encode($screen->tree()))->toContain('\n  \"query\"');
});

it('expands and collapses audit blocks', function () {
    $item = Item::factory()->for(recordedRun())->create();

    agentActivity($item)
        ->tap('audit-raw')
        ->assertSee('resp_1')
        ->tap('audit-raw')
        ->assertDontSee('resp_1')
        ->tap('audit-instructions')
        ->assertDontSee('Inspect a single frame from a yard sale.');
});

it('shows a failed run with its error and no events', function () {
    $item = Item::factory()->for(recordedRun([
        'status' => FrameRunStatus::Failed,
        'error' => 'The model did not return valid findings.',
        'events_json' => [],
        'completed_at' => null,
    ]))->create();

    agentActivity($item)
        ->assertSee('Failed')
        ->assertSee('Failed at')
        ->assertSee('The model did not return valid findings.')
        ->assertSee('No agent events were recorded.');
});

it('truncates very long audit values', function () {
    $item = Item::factory()->for(recordedRun(['instructions' => str_repeat('a', AgentActivity::MAX_BLOCK_LENGTH + 10)]))->create();

    agentActivity($item)->assertSee('10 more characters');
});

it('explains when no run was recorded', function () {
    agentActivity(Item::factory()->create())
        ->assertSee('No run recorded')
        ->assertSee('Agent activity was not recorded for this older find.');

    agentActivity(Item::factory()->for(recordedRun(['instructions' => null]))->create())
        ->assertSee('No run recorded');
});

it('handles an unknown find', function () {
    agentActivity('missing')
        ->assertSee('Find not found.')
        ->tap('go-back')
        ->assertWentBack();
});

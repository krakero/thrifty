<?php

use App\Agent\EbayListings;
use App\Agent\Exceptions\AnalysisFailed;
use App\Agent\Exceptions\MissingApiKey;
use App\Agent\FrameAgent;
use App\Agent\FrameAnalysisSchema;
use App\Agent\FrameAnalyzer;
use App\Agent\OpenAiResponses;
use App\Agent\PreviousScans;
use App\Async\AnalyzeFrame;
use App\Enums\FrameRunStatus;
use App\Enums\ValuationSourceType;
use App\Models\AppStat;
use App\Models\FrameRun;
use App\Models\Item;
use App\Models\ScanSession;
use App\Services\AppSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Native\Mobile\AsyncTask;

beforeEach(function () {
    Storage::fake('local');
    app(AppSettings::class)->set(AppSettings::OpenAiApiKey, 'sk-test-key');
    $this->session = ScanSession::factory()->create();
});

afterEach(fn () => AsyncTask::clearFake());

/**
 * Write a real 400x300 JPEG frame to the fake local disk.
 */
function agentFrame(string $path = 'frames/frame-1.jpg'): string
{
    $image = imagecreatetruecolor(400, 300);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 120, 80));
    ob_start();
    imagejpeg($image);
    Storage::disk('local')->put($path, ob_get_clean());

    return $path;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function agentCandidate(array $overrides = []): array
{
    return array_replace([
        'fingerprint' => 'sony walkman wm fx195 cassette player',
        'name' => 'Sony Walkman WM-FX195',
        'category' => 'Electronics',
        'brand' => 'Sony',
        'model' => 'WM-FX195',
        'description' => 'Yellow portable cassette player with a visible $8 tag',
        'condition' => 'Good',
        'confidence' => 0.91,
        'boundingBox' => ['xMin' => 100, 'yMin' => 200, 'xMax' => 500, 'yMax' => 700],
        'observedPriceCents' => 800,
        'currency' => 'USD',
        'estimatedLowCents' => 4000,
        'estimatedHighCents' => 7000,
        'retailPriceCents' => 12900,
        'activePriceCents' => 6500,
        'soldPriceCents' => 5500,
        'valueSummary' => 'Working examples sell for $40-70.',
        'previousMatchId' => null,
        'comparables' => [
            ['title' => 'Sony WM-FX195 on eBay', 'url' => 'https://ebay.com/itm/1', 'priceCents' => 6500, 'currency' => 'USD', 'type' => 'active'],
            ['title' => 'Sony retail listing', 'url' => null, 'priceCents' => 12900, 'currency' => 'USD', 'type' => 'retail'],
        ],
    ], $overrides);
}

/**
 * @param  list<array<string, mixed>>  $output
 * @return array<string, mixed>
 */
function agentResponse(string $id, array $output, string $status = 'completed'): array
{
    return [
        'id' => $id,
        'object' => 'response',
        'status' => $status,
        'output' => $output,
        'usage' => [
            'input_tokens' => 1000,
            'input_tokens_details' => ['cached_tokens' => 100],
            'output_tokens' => 200,
            'output_tokens_details' => ['reasoning_tokens' => 50],
            'total_tokens' => 1200,
        ],
    ];
}

/**
 * @param  list<array<string, mixed>>  $items
 * @return array<string, mixed>
 */
function agentFinalResponse(array $items, string $id = 'resp_final'): array
{
    return agentResponse($id, [
        ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'encrypted_content' => 'gAAAAsecret'],
        ['type' => 'message', 'id' => 'msg_1', 'role' => 'assistant', 'status' => 'completed', 'content' => [
            ['type' => 'output_text', 'text' => json_encode(['items' => $items]), 'annotations' => []],
        ]],
    ]);
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function agentFunctionCall(string $name, array $arguments, string $callId): array
{
    return ['type' => 'function_call', 'id' => 'fc_'.$callId, 'call_id' => $callId, 'name' => $name, 'arguments' => json_encode($arguments), 'status' => 'completed'];
}

/**
 * @param  list<array<string, mixed>>  $responses
 */
function fakeOpenAi(array $responses): void
{
    $sequence = Http::sequence();

    foreach ($responses as $response) {
        $sequence->push($response['httpBody'] ?? $response, $response['httpStatus'] ?? 200);
    }

    Http::fake([OpenAiResponses::Url => $sequence]);
}

/**
 * @return list<array<string, mixed>>
 */
function openAiRequests(): array
{
    return Http::recorded(fn (Request $request) => $request->url() === OpenAiResponses::Url)
        ->map(fn (array $pair) => $pair[0]->data())
        ->values()
        ->all();
}

function analyze(string $sessionId, string $framePath = 'frames/frame-1.jpg', string $capturedAt = '2026-09-23T15:00:00.000Z', ?string $frameRunId = null, string $findCriteria = ''): array
{
    return app(FrameAnalyzer::class)->analyze($sessionId, $framePath, $capturedAt, $frameRunId ?? (string) Str::ulid(), $findCriteria);
}

it('requires an OpenAI API key before analyzing', function () {
    app(AppSettings::class)->set(AppSettings::OpenAiApiKey, null);
    agentFrame();
    Http::fake();

    expect(fn () => analyze($this->session->id))
        ->toThrow(MissingApiKey::class, 'Add your OpenAI API key in Settings to start scanning.');

    expect(new MissingApiKey)->toBeInstanceOf(AnalysisFailed::class);
    expect(FrameRun::count())->toBe(0);
    Http::assertNothingSent();
});

it('runs the tool loop, persists finds and records a sanitized audit', function () {
    app(AppSettings::class)->set(AppSettings::EbayClientId, 'ebay-id');
    app(AppSettings::class)->set(AppSettings::EbayClientSecret, 'ebay-secret');
    agentFrame();

    $previous = ['candidates' => [[
        'fingerprint' => 'sony walkman wm fx195 cassette player', 'name' => 'Sony Walkman', 'category' => 'Electronics',
        'brand' => 'Sony', 'model' => 'WM-FX195', 'description' => 'Yellow cassette player',
    ]]];

    Http::fake([
        EbayListings::TokenUrl => Http::response(['access_token' => 'ebay-token', 'expires_in' => 7200]),
        EbayListings::SearchUrl.'*' => Http::response(['total' => 1, 'itemSummaries' => [[
            'title' => 'Sony WM-FX195 Walkman', 'price' => ['value' => '65.00', 'currency' => 'USD'],
            'condition' => 'Used', 'itemWebUrl' => 'https://ebay.com/itm/1',
            'shippingOptions' => [['shippingCost' => ['value' => '5.50', 'currency' => 'USD']]],
        ]]]),
        OpenAiResponses::Url => Http::sequence()
            ->push(agentResponse('resp_1', [
                ['type' => 'web_search_call', 'id' => 'ws_1', 'status' => 'completed', 'action' => ['type' => 'search', 'query' => 'sony wm-fx195 price']],
                agentFunctionCall('check_previous_scans', $previous, 'call_prev'),
                agentFunctionCall('search_ebay_active_listings', ['query' => 'sony wm-fx195 walkman', 'limit' => null], 'call_ebay'),
            ]))
            ->push(agentFinalResponse([agentCandidate()])),
    ]);

    $result = analyze($this->session->id, findCriteria: 'Vintage electronics');

    $item = Item::sole();
    $run = FrameRun::sole();

    expect($result)
        ->frameRunId->toBe($run->id)
        ->itemIds->toBe([$item->id])
        ->newItemIds->toBe([$item->id])
        ->stats->toBe(['framesProcessed' => 1, 'itemsIdentified' => 1, 'searchesPerformed' => 2, 'modelCalls' => 2])
        ->and($result['run']['modelCalls'])->toBe(2)
        ->and($result['run']['searchesPerformed'])->toBe(2);

    expect($item)
        ->fingerprint->toBe('sony walkman wm fx195 cassette player')
        ->scan_session_id->toBe($this->session->id)
        ->frame_run_id->toBe($run->id)
        ->seen_count->toBe(1)
        ->retail_price_cents->toBe(12900)
        ->thumbnail_path->toBe("thumbs/{$item->id}.jpg")
        ->and($item->boundingBox())->toBe(['xMin' => 100, 'yMin' => 200, 'xMax' => 500, 'yMax' => 700])
        ->and($item->first_seen_at->toIso8601ZuluString())->toBe('2026-09-23T15:00:00Z');
    expect($item->valuationSources()->pluck('source_type')->all())->toEqualCanonicalizing([ValuationSourceType::Active, ValuationSourceType::Retail]);

    Storage::disk('local')->assertExists(["thumbs/{$item->id}.jpg", 'frames/frame-1.jpg']);
    [$width, $height] = getimagesizefromstring(Storage::disk('local')->get("thumbs/{$item->id}.jpg"));
    expect([$width, $height])->toBe([218, 204]);

    expect($run)
        ->status->toBe(FrameRunStatus::Completed)
        ->frame_path->toBe('frames/frame-1.jpg')
        ->item_count->toBe(1)
        ->model_calls->toBe(2)
        ->searches_performed->toBe(2)
        ->model->toBe('gpt-5.6-luna')
        ->instructions->toBe(FrameAgent::Instructions);
    expect(collect($run->events_json)->pluck('title')->all())->toBe([
        'Web search', 'Tool call · check_previous_scans', 'Tool call · search_ebay_active_listings',
        'Tool result', 'Tool result', 'Reasoning summary', 'Assistant response',
    ]);
    expect(collect($run->events_json)->pluck('sequence')->all())->toBe(range(0, 6));
    expect($run->input_json['content'][1])->toBe(['type' => 'input_image', 'image_url' => '[frame stored on device]', 'detail' => 'high']);
    expect($run->input_json['content'][0]['text'])->toContain("<find_criteria>\nVintage electronics\n</find_criteria>");
    expect($run->raw_responses_json)->toHaveCount(2);
    expect($run->usage_json)->toMatchArray(['requests' => 2, 'inputTokens' => 2000, 'outputTokens' => 400, 'totalTokens' => 2400]);
    expect(json_encode([$run->events_json, $run->raw_responses_json]))
        ->not->toContain('encrypted_content')
        ->not->toContain('gAAAAsecret')
        ->not->toContain('data:image');

    $ebayResult = collect($run->events_json)
        ->where('type', 'tool_call_output_item')
        ->firstWhere('data.rawItem.name', 'search_ebay_active_listings')['data']['output'];
    expect($ebayResult['listings'][0])->toBe([
        'title' => 'Sony WM-FX195 Walkman', 'priceCents' => 6500, 'shippingCents' => 550,
        'currency' => 'USD', 'condition' => 'Used', 'url' => 'https://ebay.com/itm/1',
    ]);

    [$first, $second] = openAiRequests();
    expect($first['model'])->toBe('gpt-5.6-luna')
        ->and($first['instructions'])->toBe(FrameAgent::Instructions)
        ->and($first['input'][0]['content'][1]['image_url'])->toStartWith('data:image/jpeg;base64,')
        ->and($first['input'][0]['content'][1]['detail'])->toBe('high')
        ->and(collect($first['tools'])->map(fn ($tool) => $tool['name'] ?? $tool['type'])->all())
        ->toBe(['check_previous_scans', 'web_search', 'search_ebay_active_listings'])
        ->and($first['tools'][1])->toMatchArray(['search_context_size' => 'low'])
        ->and($first['text']['format'])->toMatchArray(['type' => 'json_schema', 'name' => 'output', 'strict' => true])
        ->and($first)->not->toHaveKey('previous_response_id');
    expect($second['previous_response_id'])->toBe('resp_1')
        ->and(collect($second['input'])->pluck('call_id')->all())->toBe(['call_prev', 'call_ebay'])
        ->and(collect($second['input'])->pluck('type')->unique()->all())->toBe(['function_call_output']);

    $previousScans = json_decode($second['input'][0]['output'], true);
    expect($previousScans)->toHaveKeys(['activeSessionId', 'lookups', 'recentCandidates'])
        ->and($previousScans['activeSessionId'])->toBe($this->session->id);
});

it('only offers the eBay tool when credentials are saved', function () {
    agentFrame();
    fakeOpenAi([agentFinalResponse([])]);

    analyze($this->session->id);

    expect(collect(openAiRequests()[0]['tools'])->pluck('name')->filter()->values()->all())->toBe(['check_previous_scans']);
});

it('returns saved and recent candidates from check_previous_scans', function () {
    $older = Item::factory()->for($this->session)->create([
        'fingerprint' => 'jon josef pointed toe flats', 'name' => 'Jon Josef pointed-toe flats',
        'description' => 'Mint-green pointed-toe slip-on flats', 'last_seen_at' => now()->subHour(),
    ]);
    Item::factory()->for($this->session)->create(['fingerprint' => 'cast iron skillet', 'name' => 'Skillet', 'description' => 'Pan', 'category' => 'Kitchen', 'brand' => null]);

    $result = app(PreviousScans::class)->check($this->session->id, [[
        'fingerprint' => 'mint green pointed toe pumps', 'name' => 'Mint green pointed-toe pumps', 'category' => 'Shoes',
        'brand' => null, 'model' => null, 'description' => 'Pair of mint green pointed-toe pumps',
    ]]);

    expect($result['recentCandidates'])->toHaveCount(2)
        ->and($result['lookups'][0]['similarCandidates'])->toHaveCount(1)
        ->and($result['lookups'][0]['similarCandidates'][0]['id'])->toBe($older->id)
        ->and($result['lookups'][0]['similarCandidates'][0]['retrievalScore'])->toBeGreaterThan(0);
});

it('dedupes a repeat by exact fingerprint', function () {
    $existing = Item::factory()->for($this->session)->create([
        'fingerprint' => 'sony walkman wm fx195 cassette player', 'first_seen_at' => '2026-09-01 10:00:00', 'seen_count' => 1,
    ]);
    agentFrame();
    fakeOpenAi([agentFinalResponse([agentCandidate(['fingerprint' => 'Sony Walkman WM-FX195 cassette player!'])])]);

    $result = analyze(ScanSession::factory()->create()->id);

    expect(Item::count())->toBe(1)
        ->and($result['itemIds'])->toBe([$existing->id])
        ->and($result['newItemIds'])->toBe([]);
    expect($existing->fresh())
        ->seen_count->toBe(2)
        ->scan_session_id->toBe($this->session->id)
        ->name->toBe('Sony Walkman WM-FX195')
        ->and($existing->fresh()->first_seen_at->toDateTimeString())->toBe('2026-09-01 10:00:00')
        ->and($existing->fresh()->last_seen_at->toIso8601ZuluString())->toBe('2026-09-23T15:00:00Z');
});

it('dedupes a repeat by the agent previousMatchId', function () {
    $existing = Item::factory()->for($this->session)->create(['fingerprint' => 'mint green pointed toe pumps']);
    agentFrame();
    fakeOpenAi([agentFinalResponse([agentCandidate(['fingerprint' => 'jon josef pointed toe flats', 'previousMatchId' => $existing->id])])]);

    $result = analyze($this->session->id);

    expect(Item::count())->toBe(1)
        ->and($result['newItemIds'])->toBe([])
        ->and($existing->fresh()->seen_count)->toBe(2)
        ->and($existing->fresh()->fingerprint)->toBe('mint green pointed toe pumps');
});

it('dedupes a repeat by conservative token overlap', function () {
    $existing = Item::factory()->for($this->session)->create(['fingerprint' => 'unbranded black metal glass console table']);
    agentFrame();
    fakeOpenAi([agentFinalResponse([
        agentCandidate(['fingerprint' => 'unbranded black glass top console table']),
        agentCandidate(['fingerprint' => 'pink oval wall mirror']),
    ])]);

    $result = analyze($this->session->id);

    expect(Item::count())->toBe(2)
        ->and($result['itemIds'][0])->toBe($existing->id)
        ->and($result['newItemIds'])->toHaveCount(1)
        ->and($existing->fresh()->seen_count)->toBe(2);
});

it('ignores an unknown previousMatchId and saves a new find', function () {
    agentFrame();
    fakeOpenAi([agentFinalResponse([agentCandidate(['previousMatchId' => 'not-a-real-id'])])]);

    $result = analyze($this->session->id);

    expect($result['newItemIds'])->toHaveCount(1)->and(Item::sole()->seen_count)->toBe(1);
});

it('merges repeats across back-to-back frames and within one frame', function () {
    agentFrame('frames/a.jpg');
    agentFrame('frames/b.jpg');
    fakeOpenAi([
        agentFinalResponse([agentCandidate(), agentCandidate(['fingerprint' => 'sony walkman wm-fx195 cassette player'])]),
        agentFinalResponse([agentCandidate()]),
    ]);

    $first = analyze($this->session->id, 'frames/a.jpg');
    $second = analyze($this->session->id, 'frames/b.jpg', '2026-09-23T15:00:05Z');

    $item = Item::sole();
    expect($first['itemIds'])->toBe([$item->id])
        ->and($first['newItemIds'])->toBe([$item->id])
        ->and($second['newItemIds'])->toBe([])
        ->and($item->seen_count)->toBe(3)
        ->and($item->frame_run_id)->toBe($second['frameRunId'])
        ->and(AppStat::current()->items_identified)->toBe(3);
});

it('deletes the frame when nothing is found', function () {
    agentFrame();
    fakeOpenAi([agentFinalResponse([])]);

    $result = analyze($this->session->id);

    expect($result['itemIds'])->toBe([])
        ->and(FrameRun::sole())->status->toBe(FrameRunStatus::Completed)->frame_path->toBeNull()->item_count->toBe(0)
        ->and(AppStat::current())->frames_processed->toBe(1)->model_calls->toBe(1);
    Storage::disk('local')->assertMissing('frames/frame-1.jpg');
});

it('creates the scan session when it does not exist yet', function () {
    agentFrame();
    fakeOpenAi([agentFinalResponse([agentCandidate()])]);

    analyze('01K5ZZZZZZZZZZZZZZZZZZZZZZ');

    expect(ScanSession::find('01K5ZZZZZZZZZZZZZZZZZZZZZZ'))->not->toBeNull();
});

it('asks once for a repair when the structured output is invalid', function () {
    agentFrame();
    $invalid = agentResponse('resp_bad', [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"items":[{"name":"x"}]}']]]]);
    fakeOpenAi([$invalid, agentFinalResponse([agentCandidate()])]);

    $result = analyze($this->session->id);

    expect($result['itemIds'])->toHaveCount(1)->and($result['run']['modelCalls'])->toBe(2);
    expect(openAiRequests()[1]['input'][0]['content'][0]['text'])->toContain('did not match the schema');
});

it('records a failed frame run when the output stays invalid', function () {
    agentFrame();
    $invalid = agentResponse('resp_bad', [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'not json']]]]);
    fakeOpenAi([$invalid, $invalid]);

    expect(fn () => analyze($this->session->id))->toThrow(AnalysisFailed::class, 'Luna returned an invalid analysis.');

    expect(FrameRun::sole())->status->toBe(FrameRunStatus::Failed);
});

it('rejects out-of-range values like the Zod schema', function (array $overrides) {
    expect(fn () => FrameAnalysisSchema::parse(json_encode(['items' => [agentCandidate($overrides)]])))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'negative cents' => [['retailPriceCents' => -1]],
    'fractional cents' => [['estimatedLowCents' => 10.5]],
    'confidence above 1' => [['confidence' => 1.2]],
    'box out of range' => [['boundingBox' => ['xMin' => 0, 'yMin' => 0, 'xMax' => 1001, 'yMax' => 10]]],
    'unknown comparable type' => [['comparables' => [['title' => 'x', 'url' => null, 'priceCents' => null, 'currency' => 'USD', 'type' => 'auction']]]],
    'too many comparables' => [['comparables' => array_fill(0, 9, ['title' => 'x', 'url' => null, 'priceCents' => null, 'currency' => 'USD', 'type' => 'sold'])]],
    'missing field' => [['valueSummary' => null]],
]);

it('records a failed frame run, counts the frame and deletes it when OpenAI fails', function () {
    agentFrame();
    fakeOpenAi([['httpStatus' => 401, 'httpBody' => ['error' => ['message' => 'Incorrect API key provided: sk-test-key']]]]);

    expect(fn () => analyze($this->session->id))->toThrow(AnalysisFailed::class, 'OpenAI rejected your API key. Check it in Settings.');

    expect(FrameRun::sole())
        ->status->toBe(FrameRunStatus::Failed)
        ->error->toBe('OpenAI rejected your API key. Check it in Settings.')
        ->frame_path->toBeNull()
        ->model_calls->toBe(0)
        ->instructions->toBe(FrameAgent::Instructions)
        ->events_json->toBe([])
        ->and(FrameRun::sole()->input_json['content'][1]['image_url'])->toBe('[frame stored on device]');
    expect(AppStat::current())->frames_processed->toBe(1)->model_calls->toBe(0);
    expect(Item::count())->toBe(0);
    Storage::disk('local')->assertMissing('frames/frame-1.jpg');
});

it('fails when the agent exceeds its turn budget', function () {
    agentFrame();
    $loop = agentResponse('resp_loop', [agentFunctionCall('check_previous_scans', ['candidates' => [agentCandidate()]], 'call_1')]);
    fakeOpenAi(array_fill(0, FrameAgent::MaxTurns, $loop));

    expect(fn () => analyze($this->session->id))->toThrow(AnalysisFailed::class, 'Max turns (10) exceeded');
    expect(openAiRequests())->toHaveCount(FrameAgent::MaxTurns);
});

it('reports tool errors back to the model instead of failing', function () {
    agentFrame();
    fakeOpenAi([
        agentResponse('resp_1', [agentFunctionCall('search_ebay_active_listings', ['query' => 'walkman', 'limit' => 8], 'call_1')]),
        agentFinalResponse([]),
    ]);

    analyze($this->session->id);

    expect(openAiRequests()[1]['input'][0]['output'])->toContain('Tool search_ebay_active_listings not found.');
});

it('sends the request the Agents SDK sent, chaining later turns on the previous response', function () {
    agentFrame();
    fakeOpenAi([
        agentResponse('resp_1', [agentFunctionCall('check_previous_scans', ['candidates' => [agentCandidate()]], 'call_1')]),
        agentFinalResponse([]),
    ]);

    analyze($this->session->id);

    [$first, $second] = openAiRequests();
    expect(array_keys($first))->toBe(['model', 'instructions', 'input', 'tools', 'reasoning', 'text'])
        ->and($first['tools'][1])->toBe(['type' => 'web_search', 'search_context_size' => 'low', 'external_web_access' => true])
        ->and($first['reasoning'])->toBe(['effort' => 'none'])
        ->and($first['text']['verbosity'])->toBe('low')
        ->and($first['text']['format'])->toMatchArray(['type' => 'json_schema', 'name' => 'output', 'strict' => true])
        ->and($first)->not->toHaveKeys(['store', 'include', 'parallel_tool_calls']);
    expect($second['previous_response_id'])->toBe('resp_1')
        ->and($second['instructions'])->toBe(FrameAgent::Instructions)
        ->and($second['tools'])->toBe($first['tools'])
        ->and($second['input'])->toHaveCount(1)
        ->and(array_keys($second['input'][0]))->toBe(['type', 'call_id', 'output'])
        ->and($second['input'][0]['output'])->toBeString();
});

it('dispatches as an async task with a long timeout and returns a JSON-safe result', function () {
    AsyncTask::fake();
    agentFrame();
    fakeOpenAi([agentFinalResponse([agentCandidate()])]);
    $received = null;

    $pending = AnalyzeFrame::dispatch($this->session->id, 'frames/frame-1.jpg', '2026-09-23T15:00:00Z', (string) Str::ulid(), '')
        ->finished(function (array $result) use (&$received) {
            $received = $result;
        });
    $pending->start();

    expect((new ReflectionProperty($pending, 'timeout'))->getValue($pending))->toBe(AnalyzeFrame::TimeoutSeconds)
        ->and($received['itemIds'])->toBe([Item::sole()->id])
        ->and($received['stats']['framesProcessed'])->toBe(1);
});

it('hands the user-facing message to the failed callback', function () {
    AsyncTask::fake();
    app(AppSettings::class)->set(AppSettings::OpenAiApiKey, null);
    agentFrame();
    $error = null;

    AnalyzeFrame::dispatch($this->session->id, 'frames/frame-1.jpg', '2026-09-23T15:00:00Z', (string) Str::ulid(), '')
        ->failed(function (Throwable $exception) use (&$error) {
            $error = $exception;
        })
        ->start();

    expect($error->getMessage())->toBe('Add your OpenAI API key in Settings to start scanning.')
        ->and($error->originalClass())->toBe(MissingApiKey::class);
});

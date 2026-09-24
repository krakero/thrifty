<?php

use App\Models\AppStat;
use App\Models\FrameRun;
use App\Models\Item;
use App\Models\ValuationSource;
use App\Services\AppSettings;
use App\Support\LocalTime;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Thrifty\Camera\Facades\ThriftyCamera;

it('seeds the singleton stats row and accumulates run counts', function () {
    AppStat::record(frames: 1, items: 2, searches: 3, modelCalls: 4);
    AppStat::record(frames: 1, items: 0, searches: 1, modelCalls: 2);

    expect(AppStat::current())
        ->frames_processed->toBe(2)
        ->items_identified->toBe(2)
        ->searches_performed->toBe(4)
        ->model_calls->toBe(6)
        ->last_updated->not->toBeNull();
});

it('links items to their session, frame and valuation sources', function () {
    $frame = FrameRun::factory()->create();
    $item = Item::factory()->for($frame->scanSession)->create(['frame_run_id' => $frame->id]);
    ValuationSource::factory()->count(2)->for($item)->create();

    expect($item->frameRun->is($frame))->toBeTrue()
        ->and($item->valuationSources)->toHaveCount(2)
        ->and($frame->items)->toHaveCount(1)
        ->and($item->boundingBox())->toBe(['xMin' => 100, 'yMin' => 120, 'xMax' => 600, 'yMax' => 800]);
});

it('stores settings encrypted and clamps scan tuning', function () {
    $settings = app(AppSettings::class);

    $settings->set(AppSettings::OpenAiApiKey, 'sk-test');
    $settings->set(AppSettings::MaxConcurrentFrames, '99');

    expect($settings->openAiApiKey())->toBe('sk-test')
        ->and(DB::table('settings')->where('key', AppSettings::OpenAiApiKey)->value('value'))->not->toBe('sk-test')
        ->and($settings->maxConcurrentFrames())->toBe(AppSettings::MaxConcurrentFramesLimit)
        ->and($settings->scanIntervalSeconds())->toBe(AppSettings::DefaultScanIntervalSeconds)
        ->and($settings->ebayCredentials())->toBeNull();

    $settings->set(AppSettings::OpenAiApiKey, '');

    expect($settings->openAiApiKey())->toBeNull();
});

it('formats cents and resale ranges', function () {
    $item = Item::factory()->make(['estimated_low_cents' => 2000, 'estimated_high_cents' => 3550, 'currency' => 'USD']);

    expect(Money::format(null))->toBe('—')
        ->and(Money::format(1999))->toBe('$19.99')
        ->and(Money::format(4000, 'GBP'))->toBe('£40')
        ->and(Money::format(150000, 'JPY'))->toBe('¥1,500')
        ->and(Money::format(500, 'CHF'))->toBe('CHF 5')
        ->and(Money::resaleRange($item))->toBe('$20–$35.50')
        ->and(Money::resaleRange(Item::factory()->make(['estimated_low_cents' => null, 'estimated_high_cents' => null])))->toBe('Value pending')
        ->and(Money::resaleRange(Item::factory()->make(['estimated_low_cents' => null, 'estimated_high_cents' => 900])))->toBe('$0–$9')
        ->and(Money::resaleRange(Item::factory()->make(['estimated_low_cents' => 900, 'estimated_high_cents' => null])))->toBe('$9');
});

it('shows stored timestamps in the device timezone', function () {
    LocalTime::useTimezone('America/Toronto');

    expect(LocalTime::format(CarbonImmutable::parse('2026-09-23 18:30:00', 'UTC')))->toBe('Sep 23, 2026, 2:30 PM')
        ->and(LocalTime::format(null))->toBe('—');

    LocalTime::useTimezone(null);

    expect(LocalTime::timezone())->toBe(config('app.timezone'));
});

it('accepts backward-compatible timezone names and retries failed detection', function () {
    LocalTime::useTimezone(null);
    ThriftyCamera::shouldReceive('deviceTimezone')->once()->andReturn(null);
    ThriftyCamera::shouldReceive('deviceTimezone')->once()->andReturn('Asia/Calcutta');

    expect(LocalTime::timezone())->toBe(config('app.timezone'))
        ->and(LocalTime::timezone())->toBe('Asia/Calcutta')
        ->and(LocalTime::timezone())->toBe('Asia/Calcutta');

    LocalTime::useTimezone(null);
});

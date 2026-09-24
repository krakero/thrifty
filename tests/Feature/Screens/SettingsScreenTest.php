<?php

use App\Models\Item;
use App\NativeComponents\Settings;
use App\Services\AppSettings;
use Illuminate\Support\Facades\Storage;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    Storage::fake('local');
});

it('loads saved settings', function () {
    $settings = app(AppSettings::class);
    $settings->set(AppSettings::FindCriteria, 'Cast iron');
    $settings->set(AppSettings::MaxConcurrentFrames, '3');
    $settings->set(AppSettings::ScanIntervalSeconds, '12');
    $settings->set(AppSettings::OpenAiApiKey, 'sk-test');

    Native::test(Settings::class)
        ->assertSet('findCriteria', 'Cast iron')
        ->assertSet('maxConcurrentFrames', 3)
        ->assertSet('scanIntervalSeconds', 12)
        ->assertSet('openAiApiKey', 'sk-test')
        ->assertSee('Concurrent processing')
        ->assertSee('Live scan frequency')
        ->assertSee('12s');
});

it('persists find criteria, truncated to 1000 characters', function () {
    Native::test(Settings::class)->set('findCriteria', str_repeat('a', 1200));

    expect(app(AppSettings::class)->findCriteria())->toHaveLength(1000);
});

it('applies and clears presets', function () {
    $component = Native::test(Settings::class)
        ->tap('preset-2')
        ->assertSet('findCriteria', AppSettings::FindCriteriaPresets[2]['value'])
        ->assertSee('Clear');

    expect(app(AppSettings::class)->findCriteria())->toBe(AppSettings::FindCriteriaPresets[2]['value']);

    $component->tap('clear-criteria')->assertSet('findCriteria', '');

    expect(app(AppSettings::class)->get(AppSettings::FindCriteria))->toBeNull();
});

it('persists and clamps the sliders', function () {
    Native::test(Settings::class)
        ->slide('concurrent-processing', 3)
        ->assertSet('maxConcurrentFrames', 3)
        ->slide('scan-frequency', 45)
        ->assertSet('scanIntervalSeconds', 30);

    expect(app(AppSettings::class)->maxConcurrentFrames())->toBe(3)
        ->and(app(AppSettings::class)->scanIntervalSeconds())->toBe(30);
});

it('persists api keys, trimmed', function () {
    Native::test(Settings::class)
        ->set('openAiApiKey', '  sk-abc  ')
        ->set('ebayClientId', 'client')
        ->set('ebayClientSecret', 'secret');

    expect(app(AppSettings::class)->openAiApiKey())->toBe('sk-abc')
        ->and(app(AppSettings::class)->ebayCredentials())->toBe(['clientId' => 'client', 'clientSecret' => 'secret']);
});

it('opens the api key page in the in-app browser', function () {
    Native::fakeBridge()->respondTo('Browser.OpenInApp', ['success' => true]);

    Native::test(Settings::class)
        ->tap('get-api-key')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params) => $params['url'] === Settings::OpenAiKeysUrl);
});

it('deletes a single find', function () {
    $item = Item::factory()->create(['name' => 'Le Creuset pot']);
    Item::factory()->create(['name' => 'Walkman']);

    Native::test(Settings::class)
        ->assertSee('Le Creuset pot')
        ->tap('delete-'.$item->id)
        ->assertDontSee('Le Creuset pot')
        ->assertSee('Walkman');

    expect(Item::count())->toBe(1);
});

it('confirms before deleting all finds', function () {
    Item::factory()->count(3)->create();

    $component = Native::test(Settings::class)
        ->tap('delete-all')
        ->assertNativeCalled('Dialog.Alert', fn (array $params) => $params['id'] === Settings::DeleteAllAlertId);

    expect(Item::count())->toBe(3);

    $component->emitNative(ButtonPressed::class, ['index' => 0, 'label' => 'Cancel', 'id' => Settings::DeleteAllAlertId]);
    expect(Item::count())->toBe(3);

    $component->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Delete all', 'id' => Settings::DeleteAllAlertId])
        ->assertWentBack();
    expect(Item::count())->toBe(0);
});

it('pages saved finds', function () {
    Item::factory()->count(Settings::SavedFindsPageSize + 5)->create();

    Native::test(Settings::class)
        ->assertSee('Load more finds')
        ->tap('load-more')
        ->assertSet('savedFindsLimit', Settings::SavedFindsPageSize * 2)
        ->assertDontSee('Load more finds');
});

it('is accessible', function () {
    Item::factory()->create();

    Native::test(Settings::class)->assertAccessible();
});

it('is routed under the stack layout', function () {
    Native::visit('/settings')->assertScreen(Settings::class)->assertNavTitle('Settings');
});

it('keeps typed api keys when leaving without blurring', function () {
    $component = Native::test(Settings::class)
        ->input('openai-key', 'sk-typed')
        ->assertSet('openAiApiKey', 'sk-typed');

    expect(app(AppSettings::class)->openAiApiKey())->toBe('sk-typed');

    $component->instance()->openAiApiKey = 'sk-unsynced ';
    $component->instance()->unmount();

    expect(app(AppSettings::class)->openAiApiKey())->toBe('sk-unsynced');
});

it('explains the parallel analysis limit', function () {
    Native::test(Settings::class)->assertSee('runs at most '.AppSettings::MaxConcurrentFramesLimit.' analyses in parallel');
});

it('pushes the 1000 character cap back into the field and shows a counter', function () {
    Native::test(Settings::class)
        ->input('find-criteria', str_repeat('a', 1063))
        ->assertSet('findCriteria', str_repeat('a', 1000))
        ->assertSee('1,000/1,000');
});

it('does not rewrite the criteria while the user is typing', function () {
    $component = Native::test(Settings::class)->input('find-criteria', 'Mid century lamps ');

    $component->assertSet('findCriteria', 'Mid century lamps ')->assertSee('18/1,000');
    expect(app(AppSettings::class)->get(AppSettings::FindCriteria))->toBe('Mid century lamps ');
});

it('ignores a second delete tap that lands on the next row', function () {
    [$first, $second] = Item::factory()->count(2)->create();

    $component = Native::test(Settings::class)->tap('delete-'.$first->id);
    $component->tap('delete-'.$second->id);

    expect(Item::count())->toBe(1);

    $this->travel(1)->seconds();
    $component->instance()->deleteFind($second->id);

    expect(Item::count())->toBe(0);
});

it('debounces the text inputs', function () {
    Native::test(Settings::class)->assertElement('outlined_text_input', fn (array $node) => ($node['props']['sync_mode'] ?? null) === 'debounce');
});

it('keeps the active preset selected when it is tapped again', function () {
    $component = Native::test(Settings::class)->tap('preset-0')->tap('preset-0');

    $component->assertSet('findCriteria', AppSettings::FindCriteriaPresets[0]['value'])
        ->assertElement('thrifty_pressable', fn (array $node) => ($node['ref'] ?? null) === 'preset-0'
            && str_ends_with($node['props']['a11y_label'] ?? '', ', selected'));
});

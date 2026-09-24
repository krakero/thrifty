<?php

namespace App\NativeComponents;

use App\Actions\DeleteItems;
use App\Models\Item;
use App\Scanning\ReceivesFrameAnalyses;
use App\Services\AppSettings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Facades\Browser;
use Native\Mobile\Facades\Dialog;

/**
 * Find criteria, scan tuning, API keys and saved-find management (the web app's settings dialog).
 */
class Settings extends NativeComponent
{
    use ReceivesFrameAnalyses;

    public const FindCriteriaMaxLength = 1000;

    public const SavedFindsPageSize = 50;

    public const DeleteAllAlertId = 'settings-delete-all-finds';

    public const OpenAiKeysUrl = 'https://platform.openai.com/api-keys';

    public const DeleteCooldownSeconds = 0.6;

    private float $lastDeleteAt = 0.0;

    /** The criteria as last typed or chosen (saved on every change). */
    public string $findCriteria = '';

    /**
     * The value the criteria field is rendered with. It only changes when the app sets the text (a preset,
     * Clear, the length cap), together with {@see $criteriaRevision}, which re-keys the field so it takes the new
     * text. Typing never changes it, so a re-render can't echo an older value into a field that's ahead of it.
     */
    public string $criteriaSeed = '';

    public int $criteriaRevision = 0;

    public int $maxConcurrentFrames = AppSettings::DefaultMaxConcurrentFrames;

    public int $scanIntervalSeconds = AppSettings::DefaultScanIntervalSeconds;

    public string $openAiApiKey = '';

    public string $ebayClientId = '';

    public string $ebayClientSecret = '';

    /**
     * The key fields render with their mount-time values only, for the same reason as {@see $criteriaSeed}.
     *
     * @var array{openAiApiKey: string, ebayClientId: string, ebayClientSecret: string}
     */
    public array $keySeeds = ['openAiApiKey' => '', 'ebayClientId' => '', 'ebayClientSecret' => ''];

    public int $savedFindsLimit = self::SavedFindsPageSize;

    public ?string $error = null;

    public function navTitle(): string
    {
        return 'Settings';
    }

    public function mount(AppSettings $settings): void
    {
        $this->findCriteria = (string) $settings->get(AppSettings::FindCriteria, '');
        $this->maxConcurrentFrames = $settings->maxConcurrentFrames();
        $this->scanIntervalSeconds = $settings->scanIntervalSeconds();
        $this->openAiApiKey = (string) $settings->openAiApiKey();
        $this->ebayClientId = (string) $settings->get(AppSettings::EbayClientId, '');
        $this->ebayClientSecret = (string) $settings->get(AppSettings::EbayClientSecret, '');
        $this->criteriaSeed = $this->findCriteria;
        $this->keySeeds = [
            'openAiApiKey' => $this->openAiApiKey,
            'ebayClientId' => $this->ebayClientId,
            'ebayClientSecret' => $this->ebayClientSecret,
        ];
    }

    /**
     * Backstop for Back: persist the fields as last synced, whether or not their change hooks ran.
     */
    public function unmount(): void
    {
        $settings = app(AppSettings::class);
        $settings->set(AppSettings::FindCriteria, $this->findCriteria);
        $settings->set(AppSettings::OpenAiApiKey, trim($this->openAiApiKey));
        $settings->set(AppSettings::EbayClientId, trim($this->ebayClientId));
        $settings->set(AppSettings::EbayClientSecret, trim($this->ebayClientSecret));

        parent::unmount();
    }

    /**
     * Text inputs sync on every keystroke. A debounced sync loses whatever was typed in the last moment before
     * Back: the vendor input keeps its timer running after the screen is popped and then reports to a screen
     * that no longer exists. Live events are queued ahead of the Back navigation, so they always land here.
     */
    public function criteriaTyped(string $text): void
    {
        $capped = mb_substr($text, 0, self::FindCriteriaMaxLength);

        if ($capped !== $text) {
            $this->updateFindCriteria($capped);

            return;
        }

        $this->findCriteria = $text;
        app(AppSettings::class)->set(AppSettings::FindCriteria, $text);
    }

    public function applyPreset(int $index): void
    {
        $preset = AppSettings::FindCriteriaPresets[$index] ?? null;

        if ($preset !== null) {
            $this->updateFindCriteria($preset['value']);
        }
    }

    public function clearFindCriteria(): void
    {
        $this->updateFindCriteria('');
    }

    public function updatedMaxConcurrentFrames(mixed $value): void
    {
        $this->maxConcurrentFrames = max(1, min(AppSettings::MaxConcurrentFramesLimit, (int) round((float) $value)));
        app(AppSettings::class)->set(AppSettings::MaxConcurrentFrames, (string) $this->maxConcurrentFrames);
    }

    public function updatedScanIntervalSeconds(mixed $value): void
    {
        $this->scanIntervalSeconds = max(1, min(30, (int) round((float) $value)));
        app(AppSettings::class)->set(AppSettings::ScanIntervalSeconds, (string) $this->scanIntervalSeconds);
    }

    public function openAiApiKeyTyped(#[\SensitiveParameter] string $text): void
    {
        $this->openAiApiKey = $text;
        app(AppSettings::class)->set(AppSettings::OpenAiApiKey, trim($text));
    }

    public function ebayClientIdTyped(#[\SensitiveParameter] string $text): void
    {
        $this->ebayClientId = $text;
        app(AppSettings::class)->set(AppSettings::EbayClientId, trim($text));
    }

    public function ebayClientSecretTyped(#[\SensitiveParameter] string $text): void
    {
        $this->ebayClientSecret = $text;
        app(AppSettings::class)->set(AppSettings::EbayClientSecret, trim($text));
    }

    public function openApiKeyPage(): void
    {
        Browser::inApp(self::OpenAiKeysUrl);
    }

    /**
     * The next row slides under a quick second tap, so deletes are ignored briefly after one lands (the web
     * disables every delete button while one is in flight).
     */
    public function deleteFind(string $itemId): void
    {
        $now = now()->getTimestampMs() / 1000;

        if ($now - $this->lastDeleteAt < self::DeleteCooldownSeconds) {
            return;
        }

        $this->lastDeleteAt = $now;
        $item = Item::query()->find($itemId);

        if ($item === null) {
            return;
        }

        try {
            app(DeleteItems::class)->one($item);
            $this->error = null;
        } catch (\Throwable) {
            $this->error = 'Could not delete this find.';
        }
    }

    public function confirmDeleteAll(): void
    {
        Dialog::alert(
            'Delete all finds?',
            'Every saved find and its photos will be removed. Processing stats are kept.',
            ['Cancel', ['label' => 'Delete all', 'style' => 'destructive']],
        )->id(self::DeleteAllAlertId);
    }

    #[On(ButtonPressed::class)]
    public function handleAlertButton(int $index, string $label, ?string $id = null): void
    {
        if ($id !== self::DeleteAllAlertId || $index !== 1) {
            return;
        }

        try {
            app(DeleteItems::class)->all();
        } catch (\Throwable) {
            $this->error = 'Could not delete all finds.';

            return;
        }

        // The web closes its settings dialog after deleting everything.
        $this->back();
    }

    public function loadMoreFinds(): void
    {
        $this->savedFindsLimit += self::SavedFindsPageSize;
    }

    public function dismissError(): void
    {
        $this->error = null;
    }

    public function render(): View
    {
        $savedFinds = $this->savedFinds();

        return view('native.settings', [
            'presets' => AppSettings::FindCriteriaPresets,
            'maxConcurrentLimit' => AppSettings::MaxConcurrentFramesLimit,
            'savedFinds' => $savedFinds->take($this->savedFindsLimit),
            'hasMoreFinds' => $savedFinds->count() > $this->savedFindsLimit,
        ]);
    }

    /**
     * One page past the limit, so the view knows whether "Load more" applies.
     *
     * @return Collection<int, Item>
     */
    private function savedFinds(): Collection
    {
        return Item::query()->latestSeen()->limit($this->savedFindsLimit + 1)->get();
    }

    /**
     * Set the criteria from the app side (a preset, Clear, the length cap) and push it into the field.
     */
    private function updateFindCriteria(string $value): void
    {
        $this->findCriteria = mb_substr($value, 0, self::FindCriteriaMaxLength);
        $this->criteriaSeed = $this->findCriteria;
        $this->criteriaRevision++;
        app(AppSettings::class)->set(AppSettings::FindCriteria, $this->findCriteria);
    }
}

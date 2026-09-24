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

    public string $findCriteria = '';

    public int $maxConcurrentFrames = AppSettings::DefaultMaxConcurrentFrames;

    public int $scanIntervalSeconds = AppSettings::DefaultScanIntervalSeconds;

    public string $openAiApiKey = '';

    public string $ebayClientId = '';

    public string $ebayClientSecret = '';

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

    public function updatedFindCriteria(string $value): void
    {
        $this->updateFindCriteria($value);
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

    public function updatedOpenAiApiKey(#[\SensitiveParameter] string $value): void
    {
        app(AppSettings::class)->set(AppSettings::OpenAiApiKey, trim($value));
    }

    public function updatedEbayClientId(#[\SensitiveParameter] string $value): void
    {
        app(AppSettings::class)->set(AppSettings::EbayClientId, trim($value));
    }

    public function updatedEbayClientSecret(#[\SensitiveParameter] string $value): void
    {
        app(AppSettings::class)->set(AppSettings::EbayClientSecret, trim($value));
    }

    public function openApiKeyPage(): void
    {
        Browser::inApp(self::OpenAiKeysUrl);
    }

    public function deleteFind(string $itemId): void
    {
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
            $this->error = null;
            $this->savedFindsLimit = self::SavedFindsPageSize;
        } catch (\Throwable) {
            $this->error = 'Could not delete all finds.';
        }
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

    private function updateFindCriteria(string $value): void
    {
        $this->findCriteria = mb_substr($value, 0, self::FindCriteriaMaxLength);
        app(AppSettings::class)->set(AppSettings::FindCriteria, $this->findCriteria);
    }
}

<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Typed access to persisted app settings (API keys, find criteria, scan tuning).
 */
class AppSettings
{
    public const OpenAiApiKey = 'openai_api_key';

    public const EbayClientId = 'ebay_client_id';

    public const EbayClientSecret = 'ebay_client_secret';

    public const FindCriteria = 'find_criteria';

    public const MaxConcurrentFrames = 'max_concurrent_frames';

    public const ScanIntervalSeconds = 'scan_interval_seconds';

    /**
     * The web default (5) clamped to the device limit.
     */
    public const DefaultMaxConcurrentFrames = 4;

    /**
     * The iOS AsyncTask pool has four fixed slots, so more concurrent analyses would only queue.
     */
    public const MaxConcurrentFramesLimit = 4;

    public const DefaultScanIntervalSeconds = 2;

    /** @var list<array{label: string, value: string}> */
    public const FindCriteriaPresets = [
        ['label' => 'Vintage tees', 'value' => 'Vintage band tees worth more than $40'],
        ['label' => 'Modern electronics', 'value' => 'Electronics that are still modern enough to use'],
        ['label' => 'Designer goods', 'value' => 'Authentic designer clothing, shoes, bags, and accessories with strong resale value'],
        ['label' => 'Collectibles', 'value' => 'Vintage toys, trading cards, figurines, and collectibles worth more than $30'],
        ['label' => 'Quality cookware', 'value' => 'High-quality cookware, cast iron, knives, and small kitchen appliances worth reselling'],
        ['label' => 'Rare media', 'value' => 'Rare, collectible, or out-of-print books, records, CDs, and physical media'],
    ];

    public function get(string $key, ?string $default = null): ?string
    {
        return Setting::query()->find($key)?->value ?? $default;
    }

    public function set(string $key, #[\SensitiveParameter] ?string $value): void
    {
        if ($value === null || $value === '') {
            Setting::query()->whereKey($key)->delete();

            return;
        }

        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public function openAiApiKey(): ?string
    {
        return $this->get(self::OpenAiApiKey);
    }

    /**
     * @return array{clientId: string, clientSecret: string}|null
     */
    public function ebayCredentials(): ?array
    {
        $clientId = $this->get(self::EbayClientId);
        $clientSecret = $this->get(self::EbayClientSecret);

        if (! $clientId || ! $clientSecret) {
            return null;
        }

        return ['clientId' => $clientId, 'clientSecret' => $clientSecret];
    }

    /**
     * The saved find criteria, untrimmed like the web app sends them.
     */
    public function findCriteria(): string
    {
        return (string) $this->get(self::FindCriteria, '');
    }

    public function maxConcurrentFrames(): int
    {
        $value = (int) $this->get(self::MaxConcurrentFrames, (string) self::DefaultMaxConcurrentFrames);

        return max(1, min(self::MaxConcurrentFramesLimit, $value));
    }

    public function scanIntervalSeconds(): int
    {
        $value = (int) $this->get(self::ScanIntervalSeconds, (string) self::DefaultScanIntervalSeconds);

        return max(1, min(30, $value));
    }
}

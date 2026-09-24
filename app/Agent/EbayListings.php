<?php

namespace App\Agent;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Searches live eBay fixed-price listings through the Browse API with an app (client-credentials) token.
 */
class EbayListings
{
    public const TokenUrl = 'https://api.ebay.com/identity/v1/oauth2/token';

    public const SearchUrl = 'https://api.ebay.com/buy/browse/v1/item_summary/search';

    public const ToolName = 'search_ebay_active_listings';

    public const RequestTimeoutSeconds = 30;

    /**
     * The application token, kept in memory for this PHP context only (like the Worker's module variable), never on disk.
     *
     * @var array{credentials: string, token: string, expiresAt: float}|null
     */
    private static ?array $appToken = null;

    /**
     * The function tool definition sent to the Responses API.
     *
     * @return array<string, mixed>
     */
    public static function toolDefinition(): array
    {
        return [
            'type' => 'function',
            'name' => self::ToolName,
            'description' => 'Secondary market-research tool that may run in parallel with retailer-focused web search once the product identity is specific enough. Searches live eBay fixed-price listings and returns asking prices with condition and shipping. These are active listings, never completed sales or the primary retail-price baseline; report them as type "active".',
            'strict' => true,
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'minLength' => 2,
                        'maxLength' => 300,
                        'description' => 'Search query with brand, item type, and key attributes.',
                    ],
                    'limit' => [
                        'type' => ['integer', 'null'],
                        'minimum' => 1,
                        'maximum' => 20,
                        'description' => 'Maximum listings to return (defaults to 8).',
                    ],
                ],
                'required' => ['query', 'limit'],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * Run the tool. The token fetch and the search share one `$timeoutSeconds` cap. Failures are reported to the model
     * in the result rather than thrown.
     *
     * @param  array{clientId: string, clientSecret: string}  $credentials
     * @return array{listings: list<array{title: string, priceCents: ?int, shippingCents: ?int, currency: string, condition: ?string, url: ?string}>, total: int, error?: string}
     */
    public function search(#[\SensitiveParameter] array $credentials, string $query, int $limit = 8, float $timeoutSeconds = self::RequestTimeoutSeconds): array
    {
        $callDeadline = Deadline::in($timeoutSeconds);

        try {
            $token = $this->accessToken($credentials, $callDeadline->cap($timeoutSeconds));

            if ($callDeadline->expired()) {
                return ['listings' => [], 'total' => 0, 'error' => 'eBay search timed out'];
            }

            $response = Http::withToken($token)
                ->withHeaders(['X-EBAY-C-MARKETPLACE-ID' => 'EBAY_US'])
                ->acceptJson()
                ->connectTimeout($callDeadline->cap(10))
                ->timeout($callDeadline->cap($timeoutSeconds))
                ->get(self::SearchUrl, ['q' => $query, 'limit' => $limit]);

            if ($response->failed()) {
                return ['listings' => [], 'total' => 0, 'error' => "eBay search failed with status {$response->status()}"];
            }

            $listings = collect($response->json('itemSummaries') ?? [])
                ->map(fn (array $summary): array => [
                    'title' => (string) ($summary['title'] ?? ''),
                    'priceCents' => self::parseCents($summary['price']['value'] ?? null),
                    'shippingCents' => self::parseCents($summary['shippingOptions'][0]['shippingCost']['value'] ?? null),
                    'currency' => (string) ($summary['price']['currency'] ?? 'USD'),
                    'condition' => $summary['condition'] ?? null,
                    'url' => $summary['itemWebUrl'] ?? null,
                ])
                ->values()
                ->all();

            return ['listings' => $listings, 'total' => (int) ($response->json('total') ?? count($listings))];
        } catch (Throwable $exception) {
            return [
                'listings' => [],
                'total' => 0,
                'error' => $exception instanceof ConnectionException ? 'eBay search failed' : ($exception->getMessage() ?: 'eBay search failed'),
            ];
        }
    }

    /**
     * An application access token, reused until a minute before it expires.
     *
     * @param  array{clientId: string, clientSecret: string}  $credentials
     */
    public function accessToken(#[\SensitiveParameter] array $credentials, float $timeoutSeconds = self::RequestTimeoutSeconds): string
    {
        $credentialsHash = hash('sha256', $credentials['clientId'].':'.$credentials['clientSecret']);

        if (self::$appToken !== null && self::$appToken['credentials'] === $credentialsHash && microtime(true) < self::$appToken['expiresAt']) {
            return self::$appToken['token'];
        }

        $response = Http::withBasicAuth($credentials['clientId'], $credentials['clientSecret'])
            ->asForm()
            ->acceptJson()
            ->connectTimeout(min(10, $timeoutSeconds))
            ->timeout($timeoutSeconds)
            ->post(self::TokenUrl, [
                'grant_type' => 'client_credentials',
                'scope' => 'https://api.ebay.com/oauth/api_scope',
            ]);

        if ($response->failed() || ! is_string($response->json('access_token'))) {
            throw new RuntimeException("eBay token request failed with status {$response->status()}");
        }

        $token = $response->json('access_token');

        self::$appToken = [
            'credentials' => $credentialsHash,
            'token' => $token,
            'expiresAt' => microtime(true) + ((int) $response->json('expires_in', 7200) - 60),
        ];

        return $token;
    }

    /**
     * Forget the in-memory token (tests, or after credentials change).
     */
    public static function forgetToken(): void
    {
        self::$appToken = null;
    }

    private static function parseCents(mixed $value): ?int
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return (int) round((float) $value * 100);
    }
}

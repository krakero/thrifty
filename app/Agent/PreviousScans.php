<?php

namespace App\Agent;

use App\Models\Item;

/**
 * The `check_previous_scans` tool: retrieves similar and recent saved finds so the agent can spot repeats.
 */
class PreviousScans
{
    public const ToolName = 'check_previous_scans';

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
            'description' => 'Retrieve richly described similar and recent saved items so you can decide whether each current item was already scanned.',
            'strict' => true,
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'candidates' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'maxItems' => 20,
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'fingerprint' => ['type' => 'string'],
                                'name' => ['type' => 'string'],
                                'category' => ['type' => 'string'],
                                'brand' => ['type' => ['string', 'null']],
                                'model' => ['type' => ['string', 'null']],
                                'description' => ['type' => 'string'],
                            ],
                            'required' => ['fingerprint', 'name', 'category', 'brand', 'model', 'description'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['candidates'],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * @param  list<array{fingerprint: string, name: string, category: string, brand: ?string, model: ?string, description: string}>  $candidates
     * @return array{activeSessionId: string, lookups: list<array<string, mixed>>, recentCandidates: list<array<string, mixed>>}
     */
    public function check(string $activeSessionId, array $candidates): array
    {
        $recentItems = Item::query()
            ->select(['id', 'fingerprint', 'name', 'category', 'brand', 'model', 'description', 'scan_session_id', 'last_seen_at', 'seen_count'])
            ->latestSeen()
            ->limit(120)
            ->get()
            ->map(fn (Item $item): array => [
                'id' => $item->id,
                'fingerprint' => $item->fingerprint,
                'name' => $item->name,
                'category' => $item->category,
                'brand' => $item->brand,
                'model' => $item->model,
                'description' => $item->description,
                'scanSessionId' => $item->scan_session_id,
                'lastSeenAt' => $item->last_seen_at?->toIso8601ZuluString('millisecond'),
                'seenCount' => $item->seen_count,
            ])
            ->all();

        $lookups = array_map(function (array $candidate) use ($recentItems): array {
            $candidateIdentity = self::identityText($candidate);

            $similarCandidates = collect($recentItems)
                ->map(fn (array $saved): array => [
                    ...$saved,
                    'retrievalScore' => Normalize::fingerprintSimilarity($candidateIdentity, self::identityText($saved)),
                ])
                ->filter(fn (array $saved): bool => $saved['retrievalScore'] > 0)
                ->sortByDesc('retrievalScore')
                ->take(6)
                ->values()
                ->all();

            return ['candidate' => $candidate, 'similarCandidates' => $similarCandidates];
        }, $candidates);

        return [
            'activeSessionId' => $activeSessionId,
            'lookups' => $lookups,
            'recentCandidates' => array_slice($recentItems, 0, 10),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function identityText(array $item): string
    {
        return collect([$item['fingerprint'] ?? null, $item['name'] ?? null, $item['category'] ?? null, $item['brand'] ?? null, $item['model'] ?? null, $item['description'] ?? null])
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->implode(' ');
    }
}

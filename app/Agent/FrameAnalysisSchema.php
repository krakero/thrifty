<?php

namespace App\Agent;

use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * The agent's structured output: a strict JSON schema for the Responses API plus matching validation.
 */
class FrameAnalysisSchema
{
    public const Name = 'output';

    public const MaxItems = 20;

    public const MaxComparables = 8;

    /**
     * The `text.format` payload for the Responses API.
     *
     * @return array<string, mixed>
     */
    public static function textFormat(): array
    {
        return ['type' => 'json_schema', 'name' => self::Name, 'strict' => true, 'schema' => self::schema()];
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $cents = ['type' => ['integer', 'null'], 'minimum' => 0];
        $coordinate = ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000];

        $comparable = self::object([
            'title' => ['type' => 'string'],
            'url' => ['type' => ['string', 'null']],
            'priceCents' => $cents,
            'currency' => ['type' => 'string'],
            'type' => ['type' => 'string', 'enum' => ['retail', 'active', 'sold']],
        ]);

        $item = self::object([
            'fingerprint' => ['type' => 'string', 'description' => 'Stable lowercase identity: brand + model + item name; no condition or price.'],
            'name' => ['type' => 'string'],
            'category' => ['type' => 'string'],
            'brand' => ['type' => ['string', 'null']],
            'model' => ['type' => ['string', 'null']],
            'description' => ['type' => 'string'],
            'condition' => ['type' => 'string'],
            'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
            'boundingBox' => [
                ...self::object(['xMin' => $coordinate, 'yMin' => $coordinate, 'xMax' => $coordinate, 'yMax' => $coordinate]),
                'description' => "Tight item bounds in normalized 0-1000 coordinates, measured from the frame's top-left corner.",
            ],
            'observedPriceCents' => $cents,
            'currency' => ['type' => 'string'],
            'estimatedLowCents' => $cents,
            'estimatedHighCents' => $cents,
            'retailPriceCents' => [
                ...$cents,
                'description' => 'Estimated current new-retail or equivalent replacement value in cents, based primarily on manufacturer and retailer evidence.',
            ],
            'activePriceCents' => $cents,
            'soldPriceCents' => $cents,
            'valueSummary' => ['type' => 'string'],
            'previousMatchId' => [
                'type' => ['string', 'null'],
                'description' => 'The saved candidate ID when this is the same previously scanned item; otherwise null.',
            ],
            'comparables' => ['type' => 'array', 'maxItems' => self::MaxComparables, 'items' => $comparable],
        ]);

        return self::object([
            'items' => ['type' => 'array', 'maxItems' => self::MaxItems, 'items' => $item],
        ]);
    }

    /**
     * Parse and validate the final message text, returning the analysis with normalized PHP types.
     *
     * @return array{items: list<array<string, mixed>>}
     *
     * @throws InvalidArgumentException when the output doesn't match the schema
     */
    public static function parse(string $text): array
    {
        try {
            $decoded = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException('The structured output was not valid JSON: '.$exception->getMessage());
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException('The structured output must be a JSON object.');
        }

        $cents = ['present', 'nullable', 'integer', 'min:0'];
        $string = ['present', 'string'];
        $nullableString = ['present', 'nullable', 'string'];
        $coordinate = ['required', 'integer', 'between:0,1000'];

        $validator = Validator::make($decoded, [
            'items' => ['present', 'array', 'list', 'max:'.self::MaxItems],
            'items.*' => ['array'],
            'items.*.fingerprint' => $string,
            'items.*.name' => $string,
            'items.*.category' => $string,
            'items.*.brand' => $nullableString,
            'items.*.model' => $nullableString,
            'items.*.description' => $string,
            'items.*.condition' => $string,
            'items.*.confidence' => ['required', 'numeric', 'between:0,1'],
            'items.*.boundingBox' => ['required', 'array'],
            'items.*.boundingBox.xMin' => $coordinate,
            'items.*.boundingBox.yMin' => $coordinate,
            'items.*.boundingBox.xMax' => $coordinate,
            'items.*.boundingBox.yMax' => $coordinate,
            'items.*.observedPriceCents' => $cents,
            'items.*.currency' => $string,
            'items.*.estimatedLowCents' => $cents,
            'items.*.estimatedHighCents' => $cents,
            'items.*.retailPriceCents' => $cents,
            'items.*.activePriceCents' => $cents,
            'items.*.soldPriceCents' => $cents,
            'items.*.valueSummary' => $string,
            'items.*.previousMatchId' => $nullableString,
            'items.*.comparables' => ['present', 'array', 'list', 'max:'.self::MaxComparables],
            'items.*.comparables.*' => ['array'],
            'items.*.comparables.*.title' => $string,
            'items.*.comparables.*.url' => $nullableString,
            'items.*.comparables.*.priceCents' => $cents,
            'items.*.comparables.*.currency' => $string,
            'items.*.comparables.*.type' => ['required', 'in:retail,active,sold'],
        ]);

        if ($validator->fails()) {
            throw new InvalidArgumentException('The structured output did not match the schema: '.implode(' ', $validator->errors()->all()));
        }

        return ['items' => array_map(self::normalizeItem(...), $decoded['items'])];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private static function normalizeItem(array $item): array
    {
        $cents = fn (mixed $value): ?int => $value === null ? null : (int) $value;

        return [
            'fingerprint' => $item['fingerprint'],
            'name' => $item['name'],
            'category' => $item['category'],
            'brand' => $item['brand'],
            'model' => $item['model'],
            'description' => $item['description'],
            'condition' => $item['condition'],
            'confidence' => (float) $item['confidence'],
            'boundingBox' => [
                'xMin' => (int) $item['boundingBox']['xMin'],
                'yMin' => (int) $item['boundingBox']['yMin'],
                'xMax' => (int) $item['boundingBox']['xMax'],
                'yMax' => (int) $item['boundingBox']['yMax'],
            ],
            'observedPriceCents' => $cents($item['observedPriceCents']),
            'currency' => $item['currency'],
            'estimatedLowCents' => $cents($item['estimatedLowCents']),
            'estimatedHighCents' => $cents($item['estimatedHighCents']),
            'retailPriceCents' => $cents($item['retailPriceCents']),
            'activePriceCents' => $cents($item['activePriceCents']),
            'soldPriceCents' => $cents($item['soldPriceCents']),
            'valueSummary' => $item['valueSummary'],
            'previousMatchId' => $item['previousMatchId'],
            'comparables' => array_map(fn (array $comparable): array => [
                'title' => $comparable['title'],
                'url' => $comparable['url'],
                'priceCents' => $cents($comparable['priceCents']),
                'currency' => $comparable['currency'],
                'type' => $comparable['type'],
            ], $item['comparables']),
        ];
    }

    /**
     * A strict object schema: every property required, nothing extra allowed.
     *
     * @param  array<string, array<string, mixed>>  $properties
     * @return array<string, mixed>
     */
    private static function object(array $properties): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }
}

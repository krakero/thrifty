<?php

namespace App\Models;

use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * A detected sellable item, deduplicated across frames and sessions by fingerprint.
 *
 * Bounding box coordinates are normalized 0-1000 relative to the full frame at `frameRun->frame_path`.
 * `thumbnail_path` is usually a padded crop of that box (`thumbs/{id}.jpg`), so never draw the box over it.
 */
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory, HasUlids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'scan_session_id', 'frame_run_id', 'fingerprint', 'name', 'category', 'brand', 'model',
        'description', 'condition', 'confidence', 'observed_price_cents', 'currency',
        'estimated_low_cents', 'estimated_high_cents', 'retail_price_cents', 'active_price_cents',
        'sold_price_cents', 'value_summary', 'thumbnail_path', 'box_x_min', 'box_y_min',
        'box_x_max', 'box_y_max', 'raw_json', 'first_seen_at', 'last_seen_at', 'seen_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'observed_price_cents' => 'integer',
            'estimated_low_cents' => 'integer',
            'estimated_high_cents' => 'integer',
            'retail_price_cents' => 'integer',
            'active_price_cents' => 'integer',
            'sold_price_cents' => 'integer',
            'box_x_min' => 'integer',
            'box_y_min' => 'integer',
            'box_x_max' => 'integer',
            'box_y_max' => 'integer',
            'raw_json' => 'array',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'seen_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ScanSession, $this>
     */
    public function scanSession(): BelongsTo
    {
        return $this->belongsTo(ScanSession::class);
    }

    /**
     * The most recent frame this item was detected in.
     *
     * @return BelongsTo<FrameRun, $this>
     */
    public function frameRun(): BelongsTo
    {
        return $this->belongsTo(FrameRun::class);
    }

    /**
     * @return HasMany<ValuationSource, $this>
     */
    public function valuationSources(): HasMany
    {
        return $this->hasMany(ValuationSource::class);
    }

    /**
     * Absolute on-device path to the item's thumbnail image (for `<native:image src>`).
     */
    public function thumbnailFile(): string
    {
        return Storage::disk('local')->path($this->thumbnail_path);
    }

    /**
     * Whether the item has been seen in more than one frame.
     */
    public function isRepeat(): bool
    {
        return $this->seen_count > 1;
    }

    /**
     * @return array{xMin: int, yMin: int, xMax: int, yMax: int}|null
     */
    public function boundingBox(): ?array
    {
        if ($this->box_x_min === null || $this->box_y_min === null || $this->box_x_max === null || $this->box_y_max === null) {
            return null;
        }

        return ['xMin' => $this->box_x_min, 'yMin' => $this->box_y_min, 'xMax' => $this->box_x_max, 'yMax' => $this->box_y_max];
    }

    /**
     * Newest first, with the id as a stable tiebreaker for cursor pagination.
     *
     * @param  Builder<Item>  $query
     */
    public function scopeLatestSeen(Builder $query): void
    {
        $query->orderByDesc('last_seen_at')->orderByDesc('id');
    }
}

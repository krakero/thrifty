<?php

namespace App\Models;

use App\Enums\FrameRunStatus;
use Database\Factories\FrameRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One agent run over one sampled frame, with its sanitized audit trail.
 */
class FrameRun extends Model
{
    /** @use HasFactory<FrameRunFactory> */
    use HasFactory, HasUlids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'scan_session_id', 'frame_path', 'captured_at', 'completed_at', 'latency_ms', 'item_count',
        'model_calls', 'searches_performed', 'model', 'instructions', 'input_json', 'events_json',
        'raw_responses_json', 'output_json', 'usage_json', 'status', 'error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'captured_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'latency_ms' => 'integer',
            'item_count' => 'integer',
            'model_calls' => 'integer',
            'searches_performed' => 'integer',
            'input_json' => 'array',
            'events_json' => 'array',
            'raw_responses_json' => 'array',
            'output_json' => 'array',
            'usage_json' => 'array',
            'status' => FrameRunStatus::class,
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
     * Items whose latest detection came from this frame.
     *
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }
}

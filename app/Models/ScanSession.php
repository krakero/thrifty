<?php

namespace App\Models;

use Database\Factories\ScanSessionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One continuous scan from a single source (live camera, a photo, or a video).
 */
class ScanSession extends Model
{
    /** @use HasFactory<ScanSessionFactory> */
    use HasFactory, HasUlids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['source_type', 'source_name', 'started_at', 'ended_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    /**
     * @return HasMany<FrameRun, $this>
     */
    public function frameRuns(): HasMany
    {
        return $this->hasMany(FrameRun::class);
    }
}

<?php

namespace App\Models;

use Database\Factories\AppStatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Singleton row (id 1) of cumulative processing counters.
 */
class AppStat extends Model
{
    /** @use HasFactory<AppStatFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['frames_processed', 'items_identified', 'searches_performed', 'model_calls', 'last_updated'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'frames_processed' => 'integer',
            'items_identified' => 'integer',
            'searches_performed' => 'integer',
            'model_calls' => 'integer',
            'last_updated' => 'immutable_datetime',
        ];
    }

    /**
     * The singleton stats row, created on demand.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1]);
    }

    /**
     * Atomically add one frame run's counts to the totals.
     */
    public static function record(int $frames, int $items, int $searches, int $modelCalls): void
    {
        static::current();

        static::query()->whereKey(1)->update([
            'frames_processed' => DB::raw('frames_processed + '.$frames),
            'items_identified' => DB::raw('items_identified + '.$items),
            'searches_performed' => DB::raw('searches_performed + '.$searches),
            'model_calls' => DB::raw('model_calls + '.$modelCalls),
            'last_updated' => now(),
        ]);
    }
}

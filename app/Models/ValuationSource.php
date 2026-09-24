<?php

namespace App\Models;

use App\Enums\ValuationSourceType;
use Database\Factories\ValuationSourceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A comparable price found while valuing an item (retail, active listing, or sold).
 */
class ValuationSource extends Model
{
    /** @use HasFactory<ValuationSourceFactory> */
    use HasFactory, HasUlids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['item_id', 'source_type', 'title', 'url', 'price_cents', 'currency', 'captured_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_type' => ValuationSourceType::class,
            'price_cents' => 'integer',
            'captured_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}

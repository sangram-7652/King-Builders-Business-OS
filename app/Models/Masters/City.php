<?php

declare(strict_types=1);

namespace App\Models\Masters;

use Database\Factories\Masters\CityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class City extends MasterModel
{
    /** @use HasFactory<CityFactory> */
    use HasFactory;

    protected $fillable = ['state_id', 'name', 'code', 'is_active', 'sort_order'];

    protected array $searchable = ['name', 'code'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'state_id' => 'integer',
        ]);
    }

    /**
     * @return BelongsTo<State, $this>
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function displayName(): string
    {
        return trim($this->name.($this->state?->code ? ', '.$this->state->code : ''));
    }
}

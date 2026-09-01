<?php

declare(strict_types=1);

namespace App\Models\Masters;

use Database\Factories\Masters\StateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class State extends MasterModel
{
    /** @use HasFactory<StateFactory> */
    use HasFactory;

    protected $fillable = ['name', 'code', 'is_active', 'sort_order'];

    protected array $searchable = ['name', 'code'];

    /**
     * @return HasMany<City, $this>
     */
    public function cities(): HasMany
    {
        return $this->hasMany(City::class);
    }

    /** @return list<string> */
    public function referencingRelations(): array
    {
        return ['cities'];
    }
}

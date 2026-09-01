<?php

declare(strict_types=1);

namespace App\Models\Masters;

use App\Models\Lead;
use App\Models\Masters\Concerns\HasSystemFlag;
use Database\Factories\Masters\LeadSourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadSource extends MasterModel
{
    /** @use HasFactory<LeadSourceFactory> */
    use HasFactory, HasSystemFlag;

    protected $fillable = ['name', 'code', 'description', 'is_active', 'is_system', 'sort_order'];

    protected array $searchable = ['name', 'code', 'description'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'is_system' => 'boolean',
        ]);
    }

    /** @return HasMany<Lead, $this> */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /** @return list<string> */
    public function referencingRelations(): array
    {
        return ['leads'];
    }
}

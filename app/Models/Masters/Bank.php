<?php

declare(strict_types=1);

namespace App\Models\Masters;

use Database\Factories\Masters\BankFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bank extends MasterModel
{
    /** @use HasFactory<BankFactory> */
    use HasFactory;

    protected $fillable = ['name', 'code', 'is_active', 'sort_order'];

    protected array $searchable = ['name', 'code'];

    /**
     * @return HasMany<BankBranch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(BankBranch::class);
    }

    /** @return list<string> */
    public function referencingRelations(): array
    {
        return ['branches'];
    }
}

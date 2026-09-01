<?php

declare(strict_types=1);

namespace App\Models\Masters;

use Database\Factories\Masters\BankBranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankBranch extends MasterModel
{
    /** @use HasFactory<BankBranchFactory> */
    use HasFactory;

    protected $fillable = ['bank_id', 'name', 'ifsc', 'address', 'is_active', 'sort_order'];

    protected array $searchable = ['name', 'ifsc', 'address'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'bank_id' => 'integer',
        ]);
    }

    /**
     * @return BelongsTo<Bank, $this>
     */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function displayName(): string
    {
        return trim(($this->bank?->name ? $this->bank->name.' — ' : '').$this->name);
    }
}

<?php

declare(strict_types=1);

namespace App\Models\Masters;

use App\Models\Masters\Concerns\HasSystemFlag;
use App\Models\Payment;
use Database\Factories\Masters\PaymentModeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentMode extends MasterModel
{
    /** @use HasFactory<PaymentModeFactory> */
    use HasFactory, HasSystemFlag;

    protected $fillable = ['name', 'code', 'description', 'requires_reference', 'is_cheque', 'is_active', 'is_system', 'sort_order'];

    protected array $searchable = ['name', 'code', 'description'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'requires_reference' => 'boolean',
            'is_cheque' => 'boolean',
            'is_system' => 'boolean',
        ]);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return list<string> */
    public function referencingRelations(): array
    {
        return ['payments'];
    }
}

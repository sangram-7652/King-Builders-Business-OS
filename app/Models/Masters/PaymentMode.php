<?php

declare(strict_types=1);

namespace App\Models\Masters;

use App\Models\Masters\Concerns\HasSystemFlag;
use Database\Factories\Masters\PaymentModeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PaymentMode extends MasterModel
{
    /** @use HasFactory<PaymentModeFactory> */
    use HasFactory, HasSystemFlag;

    protected $fillable = ['name', 'code', 'description', 'requires_reference', 'is_active', 'is_system', 'sort_order'];

    protected array $searchable = ['name', 'code', 'description'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'requires_reference' => 'boolean',
            'is_system' => 'boolean',
        ]);
    }
}

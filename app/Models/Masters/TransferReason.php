<?php

declare(strict_types=1);

namespace App\Models\Masters;

use App\Models\Masters\Concerns\HasSystemFlag;
use Database\Factories\Masters\TransferReasonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TransferReason extends MasterModel
{
    /** @use HasFactory<TransferReasonFactory> */
    use HasFactory, HasSystemFlag;

    protected $fillable = ['name', 'description', 'is_active', 'is_system', 'sort_order'];

    protected array $searchable = ['name', 'description'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'is_system' => 'boolean',
        ]);
    }
}

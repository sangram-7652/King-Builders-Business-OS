<?php

declare(strict_types=1);

namespace App\Models\Masters;

use App\Models\Masters\Concerns\HasSystemFlag;
use Database\Factories\Masters\DocumentTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DocumentType extends MasterModel
{
    /** @use HasFactory<DocumentTypeFactory> */
    use HasFactory, HasSystemFlag;

    protected $fillable = ['name', 'code', 'description', 'is_active', 'is_system', 'sort_order'];

    protected array $searchable = ['name', 'code', 'description'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'is_system' => 'boolean',
        ]);
    }
}

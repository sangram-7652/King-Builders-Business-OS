<?php

declare(strict_types=1);

namespace App\Models\Masters;

use App\Enums\DocumentScope;
use App\Models\Document;
use App\Models\DocumentRequirement;
use App\Models\Masters\Concerns\HasSystemFlag;
use Database\Factories\Masters\DocumentTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentType extends MasterModel
{
    /** @use HasFactory<DocumentTypeFactory> */
    use HasFactory, HasSystemFlag;

    protected $fillable = ['name', 'code', 'applies_to', 'default_required', 'allows_multiple', 'supports_expiry', 'description', 'is_active', 'is_system', 'sort_order'];

    protected array $searchable = ['name', 'code', 'description'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'applies_to' => DocumentScope::class,
            'default_required' => 'boolean',
            'allows_multiple' => 'boolean',
            'supports_expiry' => 'boolean',
            'is_system' => 'boolean',
        ]);
    }

    /** @return HasMany<DocumentRequirement, $this> */
    public function requirements(): HasMany
    {
        return $this->hasMany(DocumentRequirement::class);
    }

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /** @return list<string> */
    public function referencingRelations(): array
    {
        return ['requirements', 'documents'];
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentScope;
use App\Models\Masters\DocumentType;
use Database\Factories\DocumentRequirementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A configurable checklist rule (M9). `project_id` NULL = global rule; a
 * project-specific row overrides it.
 *
 * @property DocumentScope $applies_to
 */
class DocumentRequirement extends Model
{
    /** @use HasFactory<DocumentRequirementFactory> */
    use HasFactory;

    protected $fillable = ['project_id', 'document_type_id', 'applies_to', 'required', 'sequence', 'is_active'];

    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'document_type_id' => 'integer',
            'applies_to' => DocumentScope::class,
            'required' => 'boolean',
            'sequence' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<DocumentType, $this> */
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    /** @param  Builder<DocumentRequirement>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param  Builder<DocumentRequirement>  $query */
    public function scopeScope(Builder $query, DocumentScope $scope): void
    {
        $query->where('applies_to', $scope->value);
    }
}

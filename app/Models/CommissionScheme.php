<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommissionBasis;
use App\Enums\CommissionSchemeStatus;
use App\Enums\PartnerType;
use Database\Factories\CommissionSchemeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One version of a commission scheme family (M14.3). CMS-000001.
 *
 * @property CommissionSchemeStatus $status
 * @property CommissionBasis $basis
 * @property PartnerType|null $partner_type
 */
class CommissionScheme extends Model
{
    /** @use HasFactory<CommissionSchemeFactory> */
    use HasFactory;

    public const SEQUENCE_KEY = 'commission_scheme';

    protected $fillable = [
        'code', 'version', 'name', 'description', 'status', 'basis', 'partner_type', 'is_default',
        'effective_from', 'effective_to',
        'published_at', 'published_by', 'archived_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => CommissionSchemeStatus::class,
            'basis' => CommissionBasis::class,
            'partner_type' => PartnerType::class,
            'is_default' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'published_at' => 'datetime',
            'published_by' => 'integer',
            'archived_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    public static function formatCode(int $number): string
    {
        return 'CMS-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    // --- Relationships -------------------------------------------------

    /** @return HasMany<CommissionRule, $this> */
    public function rules(): HasMany
    {
        return $this->hasMany(CommissionRule::class)->orderBy('project_id');
    }

    /** The scheme-wide default rule (no project). @return HasOne<CommissionRule, $this> */
    public function defaultRule(): HasOne
    {
        return $this->hasOne(CommissionRule::class)->whereNull('project_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /** Every version of this scheme family. @return HasMany<CommissionScheme, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(CommissionScheme::class, 'code', 'code')->orderBy('version');
    }

    /**
     * The rule that applies for a given project — a project-specific override
     * if one exists, otherwise the scheme's default rule.
     */
    public function ruleForProject(?int $projectId): ?CommissionRule
    {
        $rules = $this->relationLoaded('rules') ? $this->rules : $this->rules()->get();

        return $rules->firstWhere('project_id', $projectId)
            ?? $rules->firstWhere('project_id', null);
    }

    // --- Scopes ------------------------------------------------------

    /** @param  Builder<CommissionScheme>  $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', CommissionSchemeStatus::Published->value);
    }

    /** @param  Builder<CommissionScheme>  $query */
    public function scopeStatus(Builder $query, CommissionSchemeStatus|string|null $status): void
    {
        if ($status !== null && $status !== '') {
            $query->where('status', $status instanceof CommissionSchemeStatus ? $status->value : $status);
        }
    }

    // --- Helpers ---------------------------------------------------

    public function isDraft(): bool
    {
        return $this->status === CommissionSchemeStatus::Draft;
    }

    public function isEditable(): bool
    {
        return $this->status === CommissionSchemeStatus::Draft;
    }

    public function isPublished(): bool
    {
        return $this->status === CommissionSchemeStatus::Published;
    }

    public function canTransitionTo(CommissionSchemeStatus $target): bool
    {
        return $this->status->canTransitionTo($target);
    }
}

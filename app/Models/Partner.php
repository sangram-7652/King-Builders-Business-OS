<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PartnerActivityType;
use App\Enums\PartnerStatus;
use App\Enums\PartnerType;
use App\Models\Concerns\GuardsAgainstDestructiveDelete;
use App\Models\Masters\City;
use App\Models\Masters\State;
use Database\Factories\PartnerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Channel partner / broker master (M14). PTNR-000001.
 *
 * @property PartnerType $type
 * @property PartnerStatus $status
 * @property string|null $pan_number decrypted; never logged or serialised
 * @property string|null $bank_account_number decrypted; never logged or serialised
 */
class Partner extends Model
{
    /** @use HasFactory<PartnerFactory> */
    use GuardsAgainstDestructiveDelete, HasFactory, SoftDeletes;

    public const SEQUENCE_KEY = 'partner';

    protected $fillable = [
        'partner_code', 'type', 'status',
        'name', 'company_name', 'contact_person',
        'phone', 'alternate_phone', 'email',
        'address', 'state_id', 'city_id', 'pincode',
        'pan_number', 'rera_number', 'commission_scheme_code',
        'bank_account_name', 'bank_account_number', 'bank_ifsc', 'bank_name',
        'notes',
        'onboarded_at', 'approved_at', 'approved_by',
        'status_changed_at', 'status_reason',
        'created_by',
    ];

    /**
     * Sensitive values are stripped from any array/JSON representation.
     *
     * @var list<string>
     */
    protected $hidden = ['pan_number', 'bank_account_number'];

    protected function casts(): array
    {
        return [
            'type' => PartnerType::class,
            'status' => PartnerStatus::class,
            'state_id' => 'integer',
            'city_id' => 'integer',
            'approved_by' => 'integer',
            'created_by' => 'integer',
            'pan_number' => 'encrypted',
            'bank_account_number' => 'encrypted',
            'onboarded_at' => 'datetime',
            'approved_at' => 'datetime',
            'status_changed_at' => 'datetime',
        ];
    }

    // --- Relationships -------------------------------------------------

    /** @return BelongsTo<State, $this> */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<PartnerProjectAuthorization, $this> */
    public function projectAuthorizations(): HasMany
    {
        return $this->hasMany(PartnerProjectAuthorization::class)->latest('id');
    }

    /** @return HasMany<PartnerProjectAuthorization, $this> */
    public function activeProjectAuthorizations(): HasMany
    {
        return $this->hasMany(PartnerProjectAuthorization::class)->where('status', 'active');
    }

    /** Projects this partner is currently authorised to work. @return BelongsToMany<Project, $this> */
    public function authorizedProjects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'partner_project_authorizations')
            ->wherePivot('status', 'active')
            ->withPivot(['status', 'authorized_at'])
            ->withTimestamps();
    }

    /** Partner KYC documents (M9 pipeline). @return MorphMany<Document, $this> */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable')->latest('id');
    }

    /** This partner's current booking attribution rows (M14.2). @return HasMany<BookingPartnerAttribution, $this> */
    public function bookingAttributions(): HasMany
    {
        return $this->hasMany(BookingPartnerAttribution::class)->where('status', 'active')->latest('attributed_at');
    }

    /** Bookings this partner currently shares. @return BelongsToMany<Booking, $this> */
    public function bookings(): BelongsToMany
    {
        return $this->belongsToMany(Booking::class, 'booking_partner_attributions')
            ->wherePivot('status', 'active')
            ->withPivot(['share_percentage', 'role', 'revision'])
            ->withTimestamps();
    }

    /** @return HasMany<PartnerActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(PartnerActivity::class)->latest('id');
    }

    /**
     * @return array<string, \Illuminate\Database\Eloquent\Relations\Relation<*, *, *>>
     */
    /** Commission cases for this partner (M14.4). @return HasMany<CommissionCase, $this> */
    public function commissionCases(): HasMany
    {
        return $this->hasMany(CommissionCase::class)->latest('id');
    }

    /**
     * @return array<string, \Illuminate\Database\Eloquent\Relations\Relation<*, *, *>>
     */
    protected function businessDependents(): array
    {
        return [
            'bookingAttributions' => $this->hasMany(BookingPartnerAttribution::class),
            'commissionCases' => $this->commissionCases(),
        ];
    }

    // --- Scopes ------------------------------------------------------

    /** @param  Builder<Partner>  $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->where('partner_code', 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%")
                ->orWhere('company_name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%");
        });
    }

    /** @param  Builder<Partner>  $query */
    public function scopeStatus(Builder $query, PartnerStatus|string|null $status): void
    {
        if ($status !== null && $status !== '') {
            $query->where('status', $status instanceof PartnerStatus ? $status->value : $status);
        }
    }

    /** @param  Builder<Partner>  $query */
    public function scopeType(Builder $query, PartnerType|string|null $type): void
    {
        if ($type !== null && $type !== '') {
            $query->where('type', $type instanceof PartnerType ? $type->value : $type);
        }
    }

    /** Partners that may receive new attribution. @param  Builder<Partner>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', PartnerStatus::Active->value);
    }

    // --- Helpers ---------------------------------------------------

    public static function formatCode(int $number): string
    {
        return 'PTNR-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    public function canTransitionTo(PartnerStatus $target): bool
    {
        return $this->status->canTransitionTo($target);
    }

    public function isActive(): bool
    {
        return $this->status === PartnerStatus::Active;
    }

    /** Only an active partner may be the target of a NEW attribution (M14.2). */
    public function canReceiveAttribution(): bool
    {
        return $this->status->canReceiveAttribution();
    }

    public function displayName(): string
    {
        return $this->company_name !== null && $this->company_name !== ''
            ? $this->company_name
            : $this->name;
    }

    /** Masks a PAN like ABCDE1234F → •••••1234F. */
    public function maskedPan(): ?string
    {
        $pan = $this->pan_number;

        return $pan === null || $pan === '' ? null : Str::mask($pan, '•', 0, max(0, strlen($pan) - 4));
    }

    /** Masks a bank account number, keeping the last 4 digits. */
    public function maskedBankAccount(): ?string
    {
        $acct = preg_replace('/\s+/', '', (string) $this->bank_account_number);

        return $acct === null || $acct === '' ? null : Str::mask($acct, '•', 0, max(0, strlen($acct) - 4));
    }

    public function locationLabel(): string
    {
        return collect([$this->city?->name, $this->state?->name])->filter()->implode(', ') ?: '—';
    }

    /**
     * Append one row to the partner activity timeline.
     *
     * @param  array<string, scalar|null>  $properties  never PII
     */
    public function recordActivity(PartnerActivityType $type, string $description, array $properties = [], ?User $causer = null): PartnerActivity
    {
        return $this->activities()->create([
            'type' => $type,
            'description' => $description,
            'properties' => $properties ?: null,
            'causer_id' => ($causer ?? auth()->user())?->id,
            'created_at' => now(),
        ]);
    }
}

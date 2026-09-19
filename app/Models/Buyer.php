<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BuyerStatus;
use App\Enums\CustomerActivityType;
use App\Enums\CustomerPortalStatus;
use App\Enums\Gender;
use App\Models\Concerns\GuardsAgainstDestructiveDelete;
use App\Models\Concerns\HasMarketingConsent;
use App\Models\Masters\City;
use App\Models\Masters\State;
use Database\Factories\BuyerFactory;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * The buyer is also the customer for the M15 self-service portal — it becomes
 * authenticatable on the SEPARATE `customer` guard, so it can never hold a
 * staff role/permission or reach an admin route. `password` stays null until
 * the buyer activates; only `portal_status = active` may sign in.
 *
 * @property BuyerStatus $status
 * @property CustomerPortalStatus $portal_status
 * @property string|null $pan_number decrypted; never logged or serialised
 * @property string|null $aadhaar_number decrypted; never logged or serialised
 */
class Buyer extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait;

    /** @use HasFactory<BuyerFactory> */
    use GuardsAgainstDestructiveDelete, HasFactory, HasMarketingConsent, SoftDeletes;

    public const SEQUENCE_KEY = 'buyer';

    protected $fillable = [
        'customer_code',
        'first_name', 'middle_name', 'last_name',
        'phone', 'alternate_phone', 'email',
        'date_of_birth', 'gender', 'occupation',
        'address', 'state_id', 'city_id', 'pincode',
        'pan_number', 'aadhaar_number',
        'status', 'created_by',
    ];

    /**
     * Sensitive values are stripped from any array/JSON representation. Reveal
     * them explicitly (and only after authorising) in the detail screen.
     *
     * @var list<string>
     */
    protected $hidden = ['pan_number', 'aadhaar_number', 'password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'status' => BuyerStatus::class,
            'portal_status' => CustomerPortalStatus::class,
            'gender' => Gender::class,
            'date_of_birth' => 'date',
            'state_id' => 'integer',
            'city_id' => 'integer',
            'created_by' => 'integer',
            'pan_number' => 'encrypted',
            'aadhaar_number' => 'encrypted',
            'password' => 'hashed',
            'portal_invited_at' => 'datetime',
            'portal_activated_at' => 'datetime',
            'portal_last_login_at' => 'datetime',
            'marketing_consent_at' => 'datetime',
            'marketing_opt_out_at' => 'datetime',
        ];
    }

    /**
     * Email is stored trimmed + lower-cased so it is a stable identity for
     * portal login and the `email_canonical` unique index (F-M5-1). An empty
     * string is normalised to NULL.
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value): ?string => self::normalizeEmail($value),
        );
    }

    /** Canonicalise an email for storage / comparison. */
    public static function normalizeEmail(?string $value): ?string
    {
        $value = mb_strtolower(trim((string) $value));

        return $value === '' ? null : $value;
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

    /** Portal activation / reset tokens (M15). @return HasMany<CustomerInvitation, $this> */
    public function portalInvitations(): HasMany
    {
        return $this->hasMany(CustomerInvitation::class)->latest('id');
    }

    /** Append-only portal audit trail (M15). @return HasMany<CustomerActivity, $this> */
    public function portalActivities(): HasMany
    {
        return $this->hasMany(CustomerActivity::class)->latest('id');
    }

    /** Bookings this buyer co-owns (M6). @return BelongsToMany<Booking, $this> */
    public function bookings(): BelongsToMany
    {
        return $this->belongsToMany(Booking::class, 'booking_buyers')
            ->withPivot(['ownership_percentage', 'is_primary'])
            ->withTimestamps();
    }

    /** @return HasMany<BookingBuyer, $this> */
    public function bookingBuyers(): HasMany
    {
        return $this->hasMany(BookingBuyer::class);
    }

    /** Buyer KYC documents (M9). @return \Illuminate\Database\Eloquent\Relations\MorphMany<\App\Models\Document, $this> */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable')->latest('id');
    }

    /** Plot ownership periods this buyer holds / held (M10). @return HasMany<\App\Models\PlotOwnershipHistory, $this> */
    public function plotOwnerships(): HasMany
    {
        return $this->hasMany(PlotOwnershipHistory::class)->orderByDesc('started_at')->orderByDesc('id');
    }

    /** Current (open) ownership periods. @return HasMany<\App\Models\PlotOwnershipHistory, $this> */
    public function currentOwnerships(): HasMany
    {
        return $this->hasMany(PlotOwnershipHistory::class)->whereNull('ended_at');
    }

    /** Nominee records, newest first (M10). @return HasMany<\App\Models\BuyerNominee, $this> */
    public function nominees(): HasMany
    {
        return $this->hasMany(BuyerNominee::class)->latest('id');
    }

    /** @return HasMany<TransferRequest, $this> */
    public function incomingTransfers(): HasMany
    {
        return $this->hasMany(TransferRequest::class, 'new_buyer_id')->latest('id');
    }

    /** @return HasMany<TransferRequest, $this> */
    public function outgoingTransfers(): HasMany
    {
        return $this->hasMany(TransferRequest::class, 'current_buyer_id')->latest('id');
    }

    /**
     * @return array<string, \Illuminate\Database\Eloquent\Relations\Relation<*, *, *>>
     */
    protected function businessDependents(): array
    {
        return ['bookings' => $this->bookingBuyers()];
    }

    // --- Scopes ------------------------------------------------------

    /** @param  Builder<Buyer>  $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->where('customer_code', 'like', "%{$term}%")
                ->orWhere('first_name', 'like', "%{$term}%")
                ->orWhere('last_name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%");
        });
    }

    /** Buyers reachable in the customer portal — active portal access only. @param  Builder<Buyer>  $query */
    public function scopePortalActive(Builder $query): void
    {
        $query->where('portal_status', CustomerPortalStatus::Active->value);
    }

    // --- Helpers ---------------------------------------------------

    public function fullName(): string
    {
        return trim(implode(' ', array_filter([$this->first_name, $this->middle_name, $this->last_name])));
    }

    // --- Customer portal (M15) --------------------------------------

    public function canAccessPortal(): bool
    {
        return $this->portal_status === CustomerPortalStatus::Active
            && $this->status === BuyerStatus::Active
            && $this->password !== null;
    }

    public function hasPortalInvite(): bool
    {
        return in_array($this->portal_status, [CustomerPortalStatus::Invited, CustomerPortalStatus::Active], true);
    }

    /**
     * Append one row to the portal audit trail (M15). Never store PII / KYC /
     * commission / internal notes in `$properties`.
     *
     * @param  array<string, scalar|null>  $properties
     */
    public function recordPortalActivity(CustomerActivityType $type, ?string $description = null, array $properties = [], ?string $ip = null): CustomerActivity
    {
        return $this->portalActivities()->create([
            'type' => $type,
            'description' => $description ?? $type->label(),
            'properties' => $properties ?: null,
            'ip_address' => $ip ?? request()?->ip(),
            'created_at' => now(),
        ]);
    }

    public static function formatCode(int $number): string
    {
        return 'BUY-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    /** Masks a PAN like ABCDE1234F → •••••1234F. */
    public function maskedPan(): ?string
    {
        $pan = $this->pan_number;

        return $pan === null || $pan === '' ? null : Str::mask($pan, '•', 0, max(0, strlen($pan) - 4));
    }

    /** Masks an Aadhaar like 123412341234 → ••••••••1234. */
    public function maskedAadhaar(): ?string
    {
        $aadhaar = preg_replace('/\D/', '', (string) $this->aadhaar_number);

        return $aadhaar === null || $aadhaar === '' ? null : Str::mask($aadhaar, '•', 0, max(0, strlen($aadhaar) - 4));
    }

    public function locationLabel(): string
    {
        return collect([$this->city?->name, $this->state?->name])->filter()->implode(', ') ?: '—';
    }
}

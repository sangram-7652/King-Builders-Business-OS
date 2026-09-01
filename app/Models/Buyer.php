<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BuyerStatus;
use App\Enums\Gender;
use App\Models\Concerns\GuardsAgainstDestructiveDelete;
use App\Models\Masters\City;
use App\Models\Masters\State;
use Database\Factories\BuyerFactory;
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
 * @property BuyerStatus $status
 * @property string|null $pan_number decrypted; never logged or serialised
 * @property string|null $aadhaar_number decrypted; never logged or serialised
 */
class Buyer extends Model
{
    /** @use HasFactory<BuyerFactory> */
    use GuardsAgainstDestructiveDelete, HasFactory, SoftDeletes;

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
    protected $hidden = ['pan_number', 'aadhaar_number'];

    protected function casts(): array
    {
        return [
            'status' => BuyerStatus::class,
            'gender' => Gender::class,
            'date_of_birth' => 'date',
            'state_id' => 'integer',
            'city_id' => 'integer',
            'created_by' => 'integer',
            'pan_number' => 'encrypted',
            'aadhaar_number' => 'encrypted',
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

    /** Leads that converted into this buyer. @return HasMany<Lead, $this> */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
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

    /**
     * @return array<string, \Illuminate\Database\Eloquent\Relations\Relation<*, *, *>>
     */
    protected function businessDependents(): array
    {
        return ['leads' => $this->leads(), 'bookings' => $this->bookingBuyers()];
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

    // --- Helpers ---------------------------------------------------

    public function fullName(): string
    {
        return trim(implode(' ', array_filter([$this->first_name, $this->middle_name, $this->last_name])));
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

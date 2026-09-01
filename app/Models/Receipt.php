<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ReceiptFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Receipt extends Model
{
    /** @use HasFactory<ReceiptFactory> */
    use HasFactory;

    public const SEQUENCE_KEY = 'receipt';

    protected $fillable = [
        'receipt_number', 'payment_id', 'booking_id', 'buyer_id',
        'amount', 'payment_date', 'payment_mode_label', 'reference_number', 'buyer_name_snapshot',
        'issued_by', 'issued_at',
        'voided_at', 'voided_by', 'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'payment_id' => 'integer',
            'booking_id' => 'integer',
            'buyer_id' => 'integer',
            'amount' => 'decimal:2',
            'payment_date' => 'date',
            'issued_by' => 'integer',
            'issued_at' => 'datetime',
            'voided_at' => 'datetime',
            'voided_by' => 'integer',
        ];
    }

    public static function formatCode(int $number): string
    {
        return 'RCPT-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<Buyer, $this> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** @param  Builder<Receipt>  $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term !== '') {
            $query->where('receipt_number', 'like', "%{$term}%");
        }
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }
}

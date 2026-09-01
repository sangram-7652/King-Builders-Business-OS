<?php

declare(strict_types=1);

namespace App\Support\Payments;

use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * One generated installment line, before it is persisted.
 */
final class InstallmentSpec
{
    public function __construct(
        public readonly int $number,
        public readonly string $name,
        public readonly Carbon $dueDate,
        public readonly Money $amount,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'installment_number' => $this->number,
            'name' => $this->name,
            'due_date' => $this->dueDate->toDateString(),
            'amount' => $this->amount->store(),
            'status' => 'upcoming',
        ];
    }
}

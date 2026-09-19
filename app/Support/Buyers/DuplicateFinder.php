<?php

declare(strict_types=1);

namespace App\Support\Buyers;

use App\Models\Buyer;
use Illuminate\Database\Eloquent\Collection;

/**
 * Duplicate detection. Phone is the primary signal, email a secondary one.
 * Nothing here mutates data — the caller decides what to do with the matches.
 *
 * Phone is compared on its digits only, so "+91 98765 43210" and "9876543210"
 * match. `Buyer` stores the phone as entered (marketing data is never
 * rewritten) — matching normalises on read.
 */
final class DuplicateFinder
{
    /**
     * Existing buyers that could be the same person.
     *
     * @return Collection<int, Buyer>
     */
    public function buyers(string $phone, ?string $email = null): Collection
    {
        [$digits, $email] = [self::phoneDigits($phone), self::normaliseEmail($email)];

        if ($digits === '' && $email === null) {
            return new Collection;
        }

        return Buyer::query()
            ->where(function ($q) use ($digits, $email): void {
                if ($digits !== '') {
                    $like = ["%{$digits}"];
                    $q->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'(',''),')','') LIKE ?", $like)
                        ->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(alternate_phone,''),' ',''),'-',''),'(',''),')','') LIKE ?", $like);
                }
                if ($email !== null) {
                    $q->orWhereRaw('LOWER(email) = ?', [$email]);
                }
            })
            ->latest('id')
            ->limit(10)
            ->get();
    }

    public static function phoneDigits(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        // Compare on the last 10 digits (Indian subscriber number).
        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }

    public static function normaliseEmail(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));

        return $email === '' ? null : $email;
    }
}

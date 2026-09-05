<?php

declare(strict_types=1);

namespace App\Support\Partners;

use App\Enums\BookingAttributionRole;
use App\Exceptions\DomainException;

/**
 * Validates and normalises a co-broker attribution split (M14.2).
 *
 * A valid split is either empty (a direct sale, no partner) or a set where:
 *   - every partner id is distinct
 *   - every share is > 0 with at most 2 decimals
 *   - exactly one row is PRIMARY
 *   - the shares total EXACTLY 100.00 (compared with bcmath at scale 2 — never
 *     float arithmetic)
 *
 * @phpstan-type RawShare array{partner_id: int|string, share_percentage: string|int|float, role?: string}
 * @phpstan-type CleanShare array{partner_id: int, share_percentage: string, role: BookingAttributionRole}
 */
final class AttributionSplit
{
    private const SCALE = 2;

    /**
     * @param  list<CleanShare>  $shares
     */
    private function __construct(public readonly array $shares) {}

    public function isEmpty(): bool
    {
        return $this->shares === [];
    }

    /** @return list<int> */
    public function partnerIds(): array
    {
        return array_map(static fn (array $s): int => $s['partner_id'], $this->shares);
    }

    /**
     * @param  iterable<RawShare>  $rows
     *
     * @throws DomainException
     */
    public static function fromRows(iterable $rows): self
    {
        $clean = [];
        $seen = [];
        $primaryCount = 0;
        $total = '0.00';

        foreach ($rows as $row) {
            $partnerId = (int) ($row['partner_id'] ?? 0);
            if ($partnerId <= 0) {
                throw new DomainException('Each attribution row needs a partner.');
            }
            if (isset($seen[$partnerId])) {
                throw new DomainException('A partner can only appear once in the split.');
            }
            $seen[$partnerId] = true;

            $share = self::normaliseShare($row['share_percentage'] ?? null);
            if (bccomp($share, '0', self::SCALE) <= 0) {
                throw new DomainException('Every partner share must be greater than zero.');
            }
            if (bccomp($share, '100', self::SCALE) > 0) {
                throw new DomainException('A partner share cannot exceed 100%.');
            }

            $role = BookingAttributionRole::tryFrom((string) ($row['role'] ?? BookingAttributionRole::CoBroker->value))
                ?? BookingAttributionRole::CoBroker;
            if ($role === BookingAttributionRole::Primary) {
                $primaryCount++;
            }

            $total = bcadd($total, $share, self::SCALE);
            $clean[] = ['partner_id' => $partnerId, 'share_percentage' => $share, 'role' => $role];
        }

        if ($clean === []) {
            return new self([]);
        }

        // A single-partner split is implicitly the primary.
        if (count($clean) === 1 && $primaryCount === 0) {
            $clean[0]['role'] = BookingAttributionRole::Primary;
            $primaryCount = 1;
        }

        if ($primaryCount !== 1) {
            throw new DomainException('Exactly one partner must be marked as the primary broker.');
        }

        if (bccomp($total, '100.00', self::SCALE) !== 0) {
            throw new DomainException("Partner shares must total exactly 100% (got {$total}%).");
        }

        return new self(array_values($clean));
    }

    private static function normaliseShare(mixed $value): string
    {
        if ($value === null || $value === '') {
            throw new DomainException('Every partner needs a share percentage.');
        }

        $string = is_float($value)
            ? number_format($value, self::SCALE, '.', '')
            : trim((string) $value);

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $string)) {
            throw new DomainException("[{$string}] is not a valid share percentage.");
        }

        return bcadd($string, '0', self::SCALE);
    }
}

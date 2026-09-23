<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Services\Pricing\PricingEngine;
use App\Support\Pricing\PriceBreakdown;
use App\Support\Pricing\PriceComponentInput;
use App\Support\Pricing\PricingArea;
use App\Support\Pricing\PricingRequest;

/**
 * Thin wrapper over {@see PricingEngine} that turns a booking pricing "config"
 * (base area/rate + a flat list of component rows) into a {@see PriceBreakdown}.
 *
 * Used identically by the live preview, by create/update persistence and by
 * confirmation — so the number the user sees is the number that is stored.
 *
 * Every user-entered pricing input (base area, base rate, each line's rate /
 * quantity) must fit the DECIMAL(15,4) columns it is persisted to — at most
 * {@see PricingArea::SCALE} decimal places. A value with more is REJECTED,
 * never silently rounded: otherwise the preview would price 1234.56789 while
 * the database stores 1234.5679, and confirmation (which recalculates from the
 * stored row) would land ₹0.01 away from what the user was shown.
 */
class CalculateBookingPriceAction
{
    public function __construct(private readonly PricingEngine $engine) {}

    /**
     * @param  array{base_area?: mixed, base_rate?: mixed, components?: array<int, array<string, mixed>>}  $config
     */
    public function handle(array $config): PriceBreakdown
    {
        self::assertStorablePrecision((string) ($config['base_area'] ?? '0'), 'Base area');
        self::assertStorablePrecision((string) ($config['base_rate'] ?? '0'), 'Base rate');

        foreach ($config['components'] ?? [] as $row) {
            $name = (string) ($row['name'] ?? 'Price line');
            self::assertStorablePrecision((string) ($row['rate'] ?? '0'), "{$name} rate");

            if (isset($row['quantity']) && $row['quantity'] !== '') {
                self::assertStorablePrecision((string) $row['quantity'], "{$name} quantity");
            }
        }

        $components = array_map(
            static fn (array $row) => PriceComponentInput::fromArray($row),
            $config['components'] ?? [],
        );

        return $this->engine->calculate(new PricingRequest(
            baseArea: (string) ($config['base_area'] ?? '0'),
            baseRate: (string) ($config['base_rate'] ?? '0'),
            components: $components,
        ));
    }

    /**
     * At most {@see PricingArea::SCALE} significant decimal places (trailing
     * zeros are fine — "2000.00000" is 2000). Non-numeric input is left for
     * the engine's own validation to reject.
     */
    public static function assertStorablePrecision(string $value, string $label): void
    {
        $value = trim($value);

        if (! preg_match('/^-?\d+\.(\d+)$/', $value, $m)) {
            return;
        }

        if (strlen(rtrim($m[1], '0')) > PricingArea::SCALE) {
            throw new DomainException("{$label} may have at most ".PricingArea::SCALE.' decimal places.');
        }
    }

    /**
     * Rebuild the pricing config from a persisted booking's price lines, so the
     * exact same inputs can be re-evaluated (e.g. at confirmation).
     *
     * @return array{base_area: string, base_rate: string, components: array<int, array<string, mixed>>}
     */
    public static function configFromBooking(Booking $booking): array
    {
        $components = $booking->priceLines
            ->reject(fn ($line) => $line->type->value === 'base')
            ->map(fn ($line) => [
                'type' => $line->type->value,
                'name' => $line->name,
                'calculation_type' => $line->calculation_type->value,
                'rate' => (string) $line->rate,
                'quantity' => $line->quantity !== null ? (string) $line->quantity : null,
                'plc_type_id' => $line->plc_type_id,
                'charge_type_id' => $line->charge_type_id,
                'tax_rate_id' => $line->tax_rate_id,
                'metadata' => $line->metadata ?? [],
            ])
            ->values()
            ->all();

        return [
            'base_area' => (string) $booking->base_area,
            'base_rate' => (string) $booking->base_rate,
            'components' => $components,
        ];
    }
}

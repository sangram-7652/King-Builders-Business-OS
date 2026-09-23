<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Actions\Bookings\OverrideBookingPriceAction;
use App\Actions\Bookings\UpdateBookingAction;
use App\Enums\PriceComponentType;
use App\Models\Booking;
use App\Support\Money;
use App\Support\Pricing\PriceComponentInput;
use App\Support\Pricing\PricingRequest;

/**
 * The ONE place that knows how a manual price override is represented in a
 * booking's pricing config.
 *
 * An override is an authorised "set the final amount to X": the difference
 * between X and the calculated price is stored as a single explicit
 * `Manual price override` CHARGE / DISCOUNT line carrying
 * `metadata = {override: true, target_final, reason, by, at}`, which
 * {@see PricingEngine} applies AFTER tax so the final lands exactly on X.
 *
 * Only {@see OverrideBookingPriceAction} ever creates or removes one. Every
 * other writer ({@see UpdateBookingAction}, the booking form preview) calls
 * {@see self::reapply()} to carry the persisted override forward
 * unchanged — the same target, reason, user and timestamp — so saving an
 * unrelated field never silently drops or alters it. Override rows arriving
 * from a client payload are always stripped ({@see self::withoutOverrides()}):
 * an override can never be forged through the normal booking form.
 */
class PriceOverrideService
{
    public const LINE_NAME = 'Manual price override';

    public function __construct(private readonly PricingEngine $engine) {}

    /**
     * The persisted override on a booking, or null.
     *
     * @return array{target_final: string, reason: string, by: int|null, at: string|null}|null
     */
    public static function persisted(Booking $booking): ?array
    {
        $line = $booking->priceLines->first(fn ($l) => (bool) data_get($l->metadata, 'override', false));

        if ($line === null) {
            return null;
        }

        return [
            'target_final' => (string) data_get($line->metadata, 'target_final', '0'),
            'reason' => (string) data_get($line->metadata, 'reason', ''),
            'by' => data_get($line->metadata, 'by') !== null ? (int) data_get($line->metadata, 'by') : null,
            'at' => data_get($line->metadata, 'at'),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $components
     * @return list<array<string, mixed>>
     */
    public static function withoutOverrides(array $components): array
    {
        return array_values(array_filter(
            $components,
            fn ($c) => ! (bool) data_get($c, 'metadata.override', false),
        ));
    }

    /**
     * Return `$config` (stripped of any override row) with an override line
     * that makes the final amount land exactly on `$override['target_final']`.
     *
     * With `$keepWhenZero = false` a target equal to the calculated price
     * yields NO override line (i.e. the override is cleared). With `true` a
     * zero-difference line is kept so the override's metadata survives.
     *
     * @param  array{base_area?: mixed, base_rate?: mixed, components?: array<int, array<string, mixed>>}  $config
     * @param  array{target_final: string, reason: string, by: int|null, at: string|null}  $override
     * @return array{base_area?: mixed, base_rate?: mixed, components: list<array<string, mixed>>}
     */
    public function apply(array $config, array $override, bool $keepWhenZero): array
    {
        $config['components'] = self::withoutOverrides($config['components'] ?? []);

        $baseline = $this->engine->calculate(new PricingRequest(
            baseArea: (string) ($config['base_area'] ?? '0'),
            baseRate: (string) ($config['base_rate'] ?? '0'),
            components: array_map(fn ($c) => PriceComponentInput::fromArray($c), $config['components']),
        ));

        $target = Money::of($override['target_final']);
        $delta = $target->minus($baseline->finalAmount);

        if ($delta->isZero() && ! $keepWhenZero) {
            return $config;
        }

        $config['components'][] = [
            'type' => $delta->isNegative() ? PriceComponentType::Discount->value : PriceComponentType::Charge->value,
            'name' => self::LINE_NAME,
            'calculation_type' => 'fixed',
            'rate' => $delta->abs()->store(),
            'quantity' => null,
            'metadata' => [
                'override' => true,
                'reason' => $override['reason'],
                'by' => $override['by'],
                'at' => $override['at'],
                'target_final' => $target->store(),
            ],
        ];

        return $config;
    }

    /**
     * Carry a booking's persisted override (if any) onto a new config —
     * same target, reason, user and timestamp.
     *
     * @param  array{base_area?: mixed, base_rate?: mixed, components?: array<int, array<string, mixed>>}  $config
     * @return array{base_area?: mixed, base_rate?: mixed, components: list<array<string, mixed>>}
     */
    public function reapply(array $config, ?array $persisted): array
    {
        if ($persisted === null) {
            $config['components'] = self::withoutOverrides($config['components'] ?? []);

            return $config;
        }

        return $this->apply($config, $persisted, keepWhenZero: true);
    }
}

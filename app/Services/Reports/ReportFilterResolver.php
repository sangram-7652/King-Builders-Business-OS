<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\AgingBucket;
use App\Enums\BookingStatus;
use App\Enums\DatePreset;
use App\Enums\PaymentStatus;
use App\Enums\PlotStatus;
use App\Models\User;
use App\Support\Reports\CollectionFilters;
use App\Support\Reports\InventoryFilters;
use App\Support\Reports\ReportFilterData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The single place raw report query-string input becomes a validated,
 * access-checked {@see ReportFilterData} (M11.1). Every report controller /
 * future report component calls this — filter parsing is never duplicated.
 *
 * Guarantees:
 *  - the date window is day-aligned in `config('app.timezone')` (inclusive)
 *  - `from` never comes after `to`
 *  - every id (`project_id`, `block_id`, `salesperson_id`, `lead_source`)
 *    references a row that exists; a block must belong to the given project
 *  - a user without `leads.view_all` cannot filter by another salesperson
 *
 * @phpstan-type RawInput array<string, mixed>
 */
class ReportFilterResolver
{
    /**
     * @param  array<string, mixed>  $input  typically `$request->query()`
     *
     * @throws ValidationException
     */
    public function resolve(array $input, User $user): ReportFilterData
    {
        $data = Validator::make($input, [
            'preset' => ['nullable', 'string', Rule::enum(DatePreset::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'project_id' => ['nullable', 'integer', 'min:1', Rule::exists('projects', 'id')],
            'block_id' => ['nullable', 'integer', 'min:1'],
            'salesperson_id' => ['nullable', 'integer', 'min:1', Rule::exists('users', 'id')],
            'booking_status' => ['nullable', Rule::enum(BookingStatus::class)],
            'payment_status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'plot_status' => ['nullable', Rule::enum(PlotStatus::class)],
            'lead_source' => ['nullable', 'integer', 'min:1', Rule::exists('lead_sources', 'id')],
        ], [
            'exists' => 'The selected :attribute is not valid.',
        ])->validate();

        $projectId = $this->intOrNull($data['project_id'] ?? null);
        $blockId = $this->intOrNull($data['block_id'] ?? null);

        // A block must exist, and belong to the selected project when one is set.
        if ($blockId !== null) {
            Validator::make(['block_id' => $blockId], [
                'block_id' => [
                    $projectId !== null
                        ? Rule::exists('blocks', 'id')->where('project_id', $projectId)
                        : Rule::exists('blocks', 'id'),
                ],
            ], ['exists' => 'The selected block is not valid for this project.'])->validate();
        }

        $salespersonId = $this->intOrNull($data['salesperson_id'] ?? null);

        if ($salespersonId !== null
            && ! $user->can('leads.view_all')
            && $salespersonId !== $user->getKey()) {
            throw ValidationException::withMessages([
                'salesperson_id' => 'You may only report on your own performance.',
            ]);
        }

        [$preset, $from, $to] = $this->resolveWindow($data);

        return new ReportFilterData(
            from: $from,
            to: $to,
            preset: $preset,
            projectId: $projectId,
            blockId: $blockId,
            salespersonId: $salespersonId,
            bookingStatus: isset($data['booking_status']) ? BookingStatus::from($data['booking_status']) : null,
            paymentStatus: isset($data['payment_status']) ? PaymentStatus::from($data['payment_status']) : null,
            plotStatus: isset($data['plot_status']) ? PlotStatus::from($data['plot_status']) : null,
            leadSourceId: $this->intOrNull($data['lead_source'] ?? null),
        );
    }

    /**
     * The inventory-report-only price / size range filters (M11.3).
     *
     * @param  array<string, mixed>  $input  typically `$request->query()`
     *
     * @throws ValidationException
     */
    public function resolveInventoryExtras(array $input): InventoryFilters
    {
        $data = Validator::make($input, [
            'price_min' => ['nullable', 'numeric', 'min:0'],
            'price_max' => ['nullable', 'numeric', 'min:0', 'gte:price_min'],
            'size_min' => ['nullable', 'numeric', 'min:0'],
            'size_max' => ['nullable', 'numeric', 'min:0', 'gte:size_min'],
        ], [
            'gte' => 'The maximum must be greater than or equal to the minimum.',
        ])->validate();

        $num = fn (mixed $v): ?float => ($v === null || $v === '') ? null : (float) $v;

        return new InventoryFilters(
            priceMin: $num($data['price_min'] ?? null),
            priceMax: $num($data['price_max'] ?? null),
            sizeMin: $num($data['size_min'] ?? null),
            sizeMax: $num($data['size_max'] ?? null),
        );
    }

    /**
     * The collection-report-only ageing-bucket + payment-method filters (M11.4).
     *
     * @param  array<string, mixed>  $input  typically `$request->query()`
     *
     * @throws ValidationException
     */
    public function resolveCollectionExtras(array $input): CollectionFilters
    {
        $data = Validator::make($input, [
            'ageing_bucket' => ['nullable', Rule::enum(AgingBucket::class)],
            'payment_method' => ['nullable', 'integer', 'min:1', Rule::exists('payment_modes', 'id')],
        ], [
            'exists' => 'The selected payment method is not valid.',
        ])->validate();

        return new CollectionFilters(
            bucket: isset($data['ageing_bucket']) ? AgingBucket::from($data['ageing_bucket']) : null,
            paymentModeId: $this->intOrNull($data['payment_method'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: DatePreset, 1: CarbonImmutable, 2: CarbonImmutable}
     *
     * @throws ValidationException
     */
    private function resolveWindow(array $data): array
    {
        $tz = config('app.timezone');
        $rawFrom = $data['from'] ?? null;
        $rawTo = $data['to'] ?? null;

        $preset = isset($data['preset'])
            ? DatePreset::from($data['preset'])
            : (($rawFrom !== null || $rawTo !== null) ? DatePreset::Custom : DatePreset::DEFAULT);

        if ($preset !== DatePreset::Custom) {
            [$from, $to] = $preset->resolveRange();

            return [$preset, $from, $to];
        }

        $now = CarbonImmutable::now($tz);
        $from = $rawFrom !== null
            ? CarbonImmutable::parse($rawFrom, $tz)->startOfDay()
            : ($rawTo !== null ? CarbonImmutable::parse($rawTo, $tz)->startOfMonth() : $now->startOfMonth());
        $to = $rawTo !== null
            ? CarbonImmutable::parse($rawTo, $tz)->endOfDay()
            : $now->endOfDay();

        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages([
                'to' => 'The "to" date must be on or after the "from" date.',
            ]);
        }

        return [DatePreset::Custom, $from, $to];
    }

    private function intOrNull(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }
}

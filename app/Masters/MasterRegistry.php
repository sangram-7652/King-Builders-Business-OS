<?php

declare(strict_types=1);

namespace App\Masters;

use App\Enums\Masters\MasterGroup;
use App\Masters\Resources\BankBranchResource;
use App\Masters\Resources\BankResource;
use App\Masters\Resources\CancellationReasonResource;
use App\Masters\Resources\CityResource;
use App\Masters\Resources\DocumentTypeResource;
use App\Masters\Resources\InterestRuleResource;
use App\Masters\Resources\LeadSourceResource;
use App\Masters\Resources\PaymentModeResource;
use App\Masters\Resources\PaymentTypeResource;
use App\Masters\Resources\PlcTypeResource;
use App\Masters\Resources\PlotCategoryResource;
use App\Masters\Resources\PlotDimensionResource;
use App\Masters\Resources\PlotSizeResource;
use App\Masters\Resources\StateResource;
use App\Masters\Resources\TdsRuleResource;
use App\Masters\Resources\TransferReasonResource;
use Illuminate\Support\Collection;

/**
 * Central catalogue of every Master Data resource.
 */
final class MasterRegistry
{
    /** @var list<class-string<MasterResource>> */
    private const RESOURCES = [
        // Property
        PlotCategoryResource::class,
        PlotSizeResource::class,
        PlotDimensionResource::class,
        PlcTypeResource::class,
        // Sales
        LeadSourceResource::class,
        // Finance
        TdsRuleResource::class,
        InterestRuleResource::class,
        PaymentTypeResource::class,
        PaymentModeResource::class,
        BankResource::class,
        BankBranchResource::class,
        // Location
        StateResource::class,
        CityResource::class,
        // Documents
        DocumentTypeResource::class,
        // Operations
        CancellationReasonResource::class,
        TransferReasonResource::class,
    ];

    /**
     * @return Collection<string, MasterResource>
     */
    public static function all(): Collection
    {
        return collect(self::RESOURCES)
            ->map(fn (string $class) => new $class)
            ->keyBy(fn (MasterResource $r) => $r->slug());
    }

    public static function find(string $slug): ?MasterResource
    {
        return self::all()->get($slug);
    }

    public static function findOrFail(string $slug): MasterResource
    {
        return self::find($slug) ?? abort(404, "Unknown master data resource [{$slug}].");
    }

    /**
     * Resources grouped by MasterGroup, in navigation order.
     *
     * @return array<string, list<MasterResource>>
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (MasterGroup::cases() as $group) {
            $items = self::all()
                ->filter(fn (MasterResource $r) => $r->group() === $group)
                ->values()
                ->all();

            if ($items !== []) {
                $grouped[$group->value] = $items;
            }
        }

        return $grouped;
    }

    /** @return list<class-string> */
    public static function modelClasses(): array
    {
        return self::all()->map(fn (MasterResource $r) => $r->model())->values()->all();
    }
}

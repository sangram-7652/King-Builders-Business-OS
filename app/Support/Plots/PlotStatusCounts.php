<?php

declare(strict_types=1);

namespace App\Support\Plots;

use App\Enums\PlotStatus;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Live, DB-derived plot counts by status. Never fabricated.
 */
final class PlotStatusCounts
{
    /**
     * @param  array<string, int>  $byStatus  every PlotStatus value → count
     */
    private function __construct(
        public readonly int $total,
        public readonly array $byStatus,
    ) {}

    public static function for(Builder $query): self
    {
        /** @var array<string, int> $raw */
        $raw = $query->clone()
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $byStatus = [];
        $total = 0;

        foreach (PlotStatus::cases() as $status) {
            $count = (int) ($raw[$status->value] ?? 0);
            $byStatus[$status->value] = $count;
            $total += $count;
        }

        return new self($total, $byStatus);
    }

    public function count(PlotStatus $status): int
    {
        return $this->byStatus[$status->value] ?? 0;
    }

    /**
     * @return list<array{status: PlotStatus, count: int}>
     */
    public function rows(): array
    {
        return array_map(
            fn (PlotStatus $s): array => ['status' => $s, 'count' => $this->count($s)],
            PlotStatus::cases(),
        );
    }
}

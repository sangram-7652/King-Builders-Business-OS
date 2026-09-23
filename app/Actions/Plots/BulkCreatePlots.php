<?php

declare(strict_types=1);

namespace App\Actions\Plots;

use App\Actions\Plots\Concerns\ResolvesPlotArea;
use App\Enums\PlotStatus;
use App\Exceptions\DomainException;
use App\Models\Block;
use App\Models\Plot;
use App\Models\Project;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Creates a contiguous run of plots in one all-or-nothing transaction.
 *
 * Rules:
 *  - the numeric range must be valid and within a sane cap;
 *  - none of the generated numbers may already exist in (project, block);
 *  - a single failure rolls back the whole batch — plots are never partially
 *    created;
 *  - the (project_id, block_id, plot_number) unique index is the final guard
 *    (e.g. against a concurrent bulk run).
 */
class BulkCreatePlots
{
    use ResolvesPlotArea;
    use RunsInTransaction;

    public const MAX_PER_RUN = 500;

    /**
     * @param  array<string, mixed>  $config
     * @return int number of plots created
     */
    public function handle(Project $project, ?Block $block, array $config): int
    {
        $this->assertBlockBelongsToProject($project, $block);

        $from = (int) $config['from'];
        $to = (int) $config['to'];
        $prefix = trim((string) ($config['prefix'] ?? ''));
        $pad = max(0, (int) ($config['pad'] ?? 0));

        if ($from < 1 || $to < 1 || $to < $from) {
            throw new DomainException('Enter a valid plot number range (from ≤ to, both positive).');
        }

        $count = $to - $from + 1;

        if ($count > self::MAX_PER_RUN) {
            throw new DomainException('A single bulk run is limited to '.self::MAX_PER_RUN.' plots.');
        }

        $numbers = [];
        for ($n = $from; $n <= $to; $n++) {
            $numbers[] = $prefix.($pad > 0 ? str_pad((string) $n, $pad, '0', STR_PAD_LEFT) : (string) $n);
        }

        $existing = Plot::withTrashed()
            ->where('project_id', $project->id)
            ->when($block !== null, fn ($q) => $q->where('block_id', $block->id), fn ($q) => $q->whereNull('block_id'))
            ->whereIn('plot_number', $numbers)
            ->pluck('plot_number')
            ->all();

        if ($existing !== []) {
            sort($existing);
            $scope = $block !== null ? 'in this block' : 'as direct project plots';
            throw new DomainException("These plot numbers already exist {$scope}: ".implode(', ', array_slice($existing, 0, 10))
                .(count($existing) > 10 ? ' …' : ''));
        }

        $snapshot = $this->resolveAreaSnapshot($config);
        $now = now();

        $rows = array_map(fn (string $number): array => [
            'project_id' => $project->id,
            'block_id' => $block?->id,
            'plot_number' => $number,
            'plot_category_id' => $config['plot_category_id'] ?: null,
            'plot_size_id' => $config['plot_size_id'] ?: null,
            'plot_dimension_id' => $config['plot_dimension_id'] ?: null,
            'area' => $snapshot['area'],
            'area_unit' => $snapshot['area_unit'],
            'facing' => $config['facing'] ?: null,
            'status' => PlotStatus::Available->value,
            'is_active' => (bool) ($config['is_active'] ?? true),
            'created_at' => $now,
            'updated_at' => $now,
        ], $numbers);

        return $this->transaction(function () use ($rows, $project, $block, $count): int {
            try {
                Plot::query()->insert($rows);
            } catch (QueryException $e) {
                // Unique-constraint (or any DB) failure → the whole batch rolls back.
                report($e);

                throw new DomainException('Bulk creation failed — no plots were created. '.
                    'A plot number in the range may have been taken by another operation.');
            }

            Log::info('plot.bulk_created', [
                'project_id' => $project->id,
                'block_id' => $block?->id,
                'count' => $count,
                'by' => auth()->id(),
            ]);

            return $count;
        });
    }
}

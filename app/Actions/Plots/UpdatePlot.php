<?php

declare(strict_types=1);

namespace App\Actions\Plots;

use App\Actions\Plots\Concerns\ResolvesPlotArea;
use App\Exceptions\DomainException;
use App\Models\Block;
use App\Models\Plot;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Edits a plot's descriptive attributes. Status and hold metadata are never
 * touched here — those go through the lifecycle Actions.
 *
 * Optionally also moves the plot between Blocks (Block A → Block B, Block A →
 * direct/no Block, or direct → Block A) — see {@see applyBlockChange()}. Pass
 * a `block_id` key in `$data` (an int, or null for "make it direct") only
 * when the caller actually wants to change it; omitting the key entirely
 * leaves the plot's current Block untouched.
 */
class UpdatePlot
{
    use ResolvesPlotArea;
    use RunsInTransaction;

    /**
     * @param  array<string, mixed>  $data  already-validated
     */
    public function handle(Plot $plot, array $data): Plot
    {
        return $this->transaction(function () use ($plot, $data): Plot {
            if (array_key_exists('block_id', $data)) {
                $this->applyBlockChange($plot, $data['block_id'] !== null ? (int) $data['block_id'] : null);
            }

            $snapshot = $this->resolveAreaSnapshot([
                'area' => $data['area'] ?? $plot->area,
                'area_unit' => $data['area_unit'] ?? null,
                'plot_size_id' => $data['plot_size_id'] ?? null,
            ]);

            $plot->fill([
                'plot_number' => trim((string) $data['plot_number']),
                'plot_category_id' => $data['plot_category_id'] ?: null,
                'plot_size_id' => $data['plot_size_id'] ?: null,
                'plot_dimension_id' => $data['plot_dimension_id'] ?: null,
                'area' => $snapshot['area'],
                'area_unit' => $snapshot['area_unit'],
                'facing' => $data['facing'] ?: null,
                'village_name' => ($data['village_name'] ?? null) ?: null,
                'gata_number' => ($data['gata_number'] ?? null) ?: null,
                'boundary_east' => ($data['boundary_east'] ?? null) ?: null,
                'boundary_west' => ($data['boundary_west'] ?? null) ?: null,
                'boundary_north' => ($data['boundary_north'] ?? null) ?: null,
                'boundary_south' => ($data['boundary_south'] ?? null) ?: null,
            ])->save();

            Log::info('plot.updated', [
                'plot_id' => $plot->id,
                'by' => auth()->id(),
            ]);

            return $plot->refresh();
        });
    }

    /**
     * A Block reassignment is refused outright while the plot has a live
     * (PENDING / CONFIRMED) booking — that booking's OWN `block_id` is a
     * denormalised copy of the plot's (see CreateBookingAction /
     * PlotTransferService), and silently drifting it out of sync would break
     * every screen/report that reads the booking's block directly. This is
     * the same "never mutate inventory underneath a live booking" rule
     * already enforced everywhere else in this codebase — not a new one.
     */
    private function applyBlockChange(Plot $plot, ?int $newBlockId): void
    {
        if ($newBlockId === $plot->block_id) {
            return;
        }

        if ($plot->activeBooking()->exists()) {
            throw new DomainException('This plot has an active booking — its Block cannot be changed while a booking is Pending or Confirmed.');
        }

        if ($newBlockId !== null) {
            $block = Block::find($newBlockId);

            if ($block === null || $block->project_id !== $plot->project_id) {
                throw new DomainException('The selected block does not belong to this project.');
            }
        }

        $plot->block_id = $newBlockId;
    }
}

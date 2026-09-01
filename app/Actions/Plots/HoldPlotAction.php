<?php

declare(strict_types=1);

namespace App\Actions\Plots;

use App\Enums\PlotStatus;
use App\Exceptions\DomainException;
use App\Models\Plot;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Places an AVAILABLE plot on HOLD.
 *
 * Concurrency: the plot row is SELECT ... FOR UPDATE locked and its status
 * re-read INSIDE the transaction, so two simultaneous holds on the same plot
 * are serialised at the database — the loser sees HOLD and is rejected. This
 * does not depend on any UI-level guarding.
 */
class HoldPlotAction
{
    use RunsInTransaction;

    public function handle(
        int $plotId,
        ?int $heldByUserId,
        ?Carbon $expiresAt = null,
        ?string $reason = null,
    ): Plot {
        return $this->transaction(function () use ($plotId, $heldByUserId, $expiresAt, $reason): Plot {
            /** @var Plot $plot */
            $plot = Plot::query()->whereKey($plotId)->lockForUpdate()->firstOrFail();

            if (! $plot->is_active) {
                throw new DomainException('This plot is archived and cannot be held.');
            }

            if ($plot->status !== PlotStatus::Available) {
                throw new DomainException("This plot is {$plot->status->label()} and can no longer be held.");
            }

            $plot->forceFill([
                'status' => PlotStatus::Hold,
                'held_at' => now(),
                'hold_expires_at' => $expiresAt,
                'hold_reason' => $reason,
                'held_by' => $heldByUserId,
            ])->save();

            Log::info('plot.held', [
                'plot_id' => $plot->id,
                'held_by' => $heldByUserId,
                'expires_at' => $expiresAt?->toIso8601String(),
                'by' => auth()->id(),
            ]);

            return $plot;
        });
    }
}

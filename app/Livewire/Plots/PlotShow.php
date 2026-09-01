<?php

declare(strict_types=1);

namespace App\Livewire\Plots;

use App\Actions\Plots\ChangePlotStatus;
use App\Actions\Plots\HoldPlotAction;
use App\Actions\Plots\ReleasePlotHoldAction;
use App\Actions\Plots\TogglePlotActive;
use App\Enums\PlotStatus;
use App\Exceptions\DomainException;
use App\Models\Block;
use App\Models\Plot;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class PlotShow extends Component
{
    public Project $project;

    public Block $block;

    public Plot $plot;

    public bool $showHold = false;

    public string $holdReason = '';

    public string $holdExpiresAt = '';

    public function mount(Project $project, Block $block, Plot $plot): void
    {
        $this->authorize('view', $plot);
        $this->project = $project;
        $this->block = $block;
        $this->plot = $plot->load([
            'category', 'size', 'dimension', 'heldBy',
            'activeBooking.primaryBookingBuyer.buyer',
        ]);
    }

    public function openHold(): void
    {
        $this->authorize('hold', $this->plot);
        $this->showHold = true;
        $this->holdReason = '';
        $this->holdExpiresAt = now()->addDay()->format('Y-m-d\TH:i');
    }

    public function closeHold(): void
    {
        $this->reset('showHold', 'holdReason', 'holdExpiresAt');
        $this->resetValidation();
    }

    public function hold(): void
    {
        $this->authorize('hold', $this->plot);

        $this->validate([
            'holdReason' => ['nullable', 'string', 'max:255'],
            'holdExpiresAt' => ['nullable', 'date', 'after:now'],
        ]);

        try {
            app(HoldPlotAction::class)->handle(
                plotId: $this->plot->id,
                heldByUserId: auth()->id(),
                expiresAt: $this->holdExpiresAt !== '' ? Carbon::parse($this->holdExpiresAt) : null,
                reason: $this->holdReason ?: null,
            );
            $this->refreshPlot();
            $this->dispatch('toast', message: 'Plot placed on hold.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }

        $this->closeHold();
    }

    public function release(): void
    {
        $this->authorize('release', $this->plot);

        try {
            app(ReleasePlotHoldAction::class)->handle($this->plot->id);
            $this->refreshPlot();
            $this->dispatch('toast', message: 'Hold released.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function changeStatus(string $status): void
    {
        $this->authorize('changeStatus', $this->plot);

        $target = PlotStatus::tryFrom($status);

        if ($target === null) {
            $this->dispatch('toast', message: 'Unknown status.', variant: 'danger');

            return;
        }

        try {
            app(ChangePlotStatus::class)->handle($this->plot, $target);
            $this->refreshPlot();
            $this->dispatch('toast', message: "Plot marked {$target->label()}.", variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function toggleActive(): void
    {
        $this->authorize($this->plot->is_active ? 'archive' : 'activate', $this->plot);

        try {
            app(TogglePlotActive::class)->handle($this->plot);
            $this->refreshPlot();
            $this->dispatch('toast', message: $this->plot->is_active ? 'Plot activated.' : 'Plot archived.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    private function refreshPlot(): void
    {
        $this->plot = $this->plot->fresh([
            'category', 'size', 'dimension', 'heldBy',
            'activeBooking.primaryBookingBuyer.buyer',
        ]);
    }

    public function render(): View
    {
        return view('livewire.plots.plot-show', [
            'allowedTransitions' => $this->plot->status->allowedTransitions(),
        ])->title("Plot {$this->plot->plot_number}");
    }
}

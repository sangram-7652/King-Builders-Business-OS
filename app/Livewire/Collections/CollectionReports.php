<?php

declare(strict_types=1);

namespace App\Livewire\Collections;

use App\Models\CollectionCase;
use App\Services\Collections\CollectionReportService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Collection reports')]
class CollectionReports extends Component
{
    #[Url]
    public string $report = 'outstanding';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
        $this->authorize('viewReports', CollectionCase::class);
        $this->from = $this->from ?: now()->startOfMonth()->toDateString();
        $this->to = $this->to ?: now()->toDateString();
    }

    public function render(): View
    {
        $service = app(CollectionReportService::class);

        $rows = match ($this->report) {
            'overdue' => $service->overdue(),
            'aging' => $service->aging(),
            'performance' => $service->performance(Carbon::parse($this->from), Carbon::parse($this->to)),
            default => $service->outstanding(),
        };

        return view('livewire.collections.collection-reports', [
            'rows' => $rows,
            'reports' => [
                'outstanding' => 'Outstanding report',
                'overdue' => 'Overdue report',
                'aging' => 'Aging report',
                'performance' => 'Collection performance',
            ],
        ]);
    }
}

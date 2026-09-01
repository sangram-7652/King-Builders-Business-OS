<?php

declare(strict_types=1);

namespace App\Livewire\Masters;

use App\Enums\Masters\MasterGroup;
use App\Enums\Permission;
use App\Masters\MasterRegistry;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Master Data')]
class MasterDashboard extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()?->can(Permission::MastersView->value), 403);
    }

    public function render(): View
    {
        $groups = [];

        foreach (MasterRegistry::grouped() as $groupValue => $resources) {
            $groups[] = [
                'group' => MasterGroup::from($groupValue),
                'items' => array_map(function ($resource): array {
                    $model = $resource->model();

                    return [
                        'resource' => $resource,
                        'total' => $model::query()->count(),
                        'active' => $model::query()->where('is_active', true)->count(),
                    ];
                }, $resources),
            ];
        }

        return view('livewire.masters.dashboard', ['groups' => $groups]);
    }
}

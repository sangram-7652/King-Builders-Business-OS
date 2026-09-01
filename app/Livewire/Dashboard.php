<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Dashboard')]
class Dashboard extends Component
{
    /**
     * M0 dashboard: a foundation health panel. Business KPIs land with their
     * respective modules — nothing here queries a business table.
     *
     * @return array<string, array{label:string, ok:bool, detail:string}>
     */
    public function health(): array
    {
        return [
            'database' => $this->probe('MySQL', function (): string {
                $name = DB::connection()->getDatabaseName();
                DB::connection()->getPdo()->query('SELECT 1');

                return "connected · {$name}";
            }),
            'redis' => $this->probe('Redis', function (): string {
                Redis::connection()->ping();

                return 'connected · '.config('database.redis.default.host');
            }),
            'queue' => $this->probe('Queue', fn (): string => 'driver · '.config('queue.default')),
            'cache' => $this->probe('Cache', fn (): string => 'store · '.config('cache.default')),
        ];
    }

    /**
     * @param  callable():string  $callback
     * @return array{label:string, ok:bool, detail:string}
     */
    private function probe(string $label, callable $callback): array
    {
        try {
            return ['label' => $label, 'ok' => true, 'detail' => $callback()];
        } catch (\Throwable $e) {
            report($e);

            return ['label' => $label, 'ok' => false, 'detail' => $e->getMessage()];
        }
    }

    public function render(): View
    {
        return view('livewire.dashboard', [
            'health' => $this->health(),
        ]);
    }
}

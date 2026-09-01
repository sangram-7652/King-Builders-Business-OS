<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Convention helper for Actions / Services that mutate more than one row.
 *
 * Wrap the write path in `$this->transaction(fn () => ...)` so a failure never
 * leaves a half-written booking / transfer / payment. Read-only operations
 * must NOT use this.
 */
trait RunsInTransaction
{
    /**
     * @template TReturn
     *
     * @param  callable():TReturn  $callback
     * @return TReturn
     *
     * @throws Throwable
     */
    protected function transaction(callable $callback, int $attempts = 1): mixed
    {
        return DB::transaction($callback, $attempts);
    }
}

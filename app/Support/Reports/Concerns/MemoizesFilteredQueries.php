<?php

declare(strict_types=1);

namespace App\Support\Reports\Concerns;

use App\Support\Reports\ReportFilterData;
use Closure;

/**
 * Request-scoped memoisation for report analytics (M11.6 hardening).
 *
 * Report services fan several section builders out over the same analytics
 * object, and some sections legitimately need the same sub-aggregate (e.g. the
 * daily collected series feeds both the trend chart and the monthly table).
 * Without this, one page render fires that query 2–3 times.
 *
 * The cache lives on the analytics instance, which the container resolves fresh
 * per request, so it never leaks across requests. The key always includes the
 * full resolved filter set, so a current-period call and a previous-period call
 * (`PreviousPeriod::for()`) never collide. It is purely a read-through cache of
 * SQL aggregates — no business logic, no writes.
 */
trait MemoizesFilteredQueries
{
    /** @var array<string, mixed> */
    private array $memoized = [];

    /**
     * @template T
     *
     * @param  Closure(): T  $resolve
     * @return T
     */
    protected function remember(string $name, ReportFilterData $filters, Closure $resolve, string $variant = ''): mixed
    {
        $key = $name.'|'.md5(serialize($filters->toQueryString())).($variant === '' ? '' : '|'.$variant);

        if (! array_key_exists($key, $this->memoized)) {
            $this->memoized[$key] = $resolve();
        }

        return $this->memoized[$key];
    }
}

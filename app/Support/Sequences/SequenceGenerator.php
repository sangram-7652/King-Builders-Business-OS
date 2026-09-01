<?php

declare(strict_types=1);

namespace App\Support\Sequences;

use Illuminate\Support\Facades\DB;

/**
 * Concurrency-safe monotonic counters backed by `code_sequences`.
 *
 * Must be called inside a transaction (the row is locked FOR UPDATE for the
 * duration of that transaction). Two callers racing for the same key are
 * serialised by the database and receive distinct values.
 */
final class SequenceGenerator
{
    public function next(string $key): int
    {
        // Ensure the row exists without racing on the insert.
        DB::table('code_sequences')->insertOrIgnore([
            'key' => $key,
            'next_value' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var object{id: int, next_value: int} $row */
        $row = DB::table('code_sequences')->where('key', $key)->lockForUpdate()->first();

        $value = (int) $row->next_value;

        DB::table('code_sequences')
            ->where('key', $key)
            ->update(['next_value' => $value + 1, 'updated_at' => now()]);

        return $value;
    }
}

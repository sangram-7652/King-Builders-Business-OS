<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * F-M5-1 / S-3 / DB-3 — make a buyer's email a reliable identity for portal
 * login.
 *
 * King Builders is a SINGLE-TENANT system (one builder, no `tenant_id` on any
 * table), so a buyer's email must be globally unique — there is no tenant scope
 * to qualify it with. Portal authentication resolves email → Buyer; two buyers
 * sharing an address made that resolution non-deterministic.
 *
 * This migration, in order:
 *   1. normalises every stored email to trimmed lower-case
 *   2. resolves any resulting collisions among LIVE buyers by keeping the
 *      address on the earliest-created buyer and NULLing it on the rest
 *      (buyers are never deleted here — staff can re-assign a nulled address)
 *   3. adds `email_canonical`, a STORED generated column that is the email only
 *      while the buyer is not soft-deleted, and a UNIQUE index on it (so a
 *      soft-deleted buyer never blocks re-registration, and multiple NULLs are
 *      allowed).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1 — normalise.
        DB::table('buyers')->whereNotNull('email')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                $normalised = mb_strtolower(trim((string) $row->email));
                $normalised = $normalised === '' ? null : $normalised;

                if ($normalised !== $row->email) {
                    DB::table('buyers')->where('id', $row->id)->update(['email' => $normalised]);
                }
            }
        });

        // 2 — de-duplicate among live buyers (keep the oldest, NULL the rest).
        $duplicates = DB::table('buyers')
            ->whereNotNull('email')
            ->whereNull('deleted_at')
            ->groupBy('email')
            ->havingRaw('count(*) > 1')
            ->pluck('email');

        foreach ($duplicates as $email) {
            $ids = DB::table('buyers')
                ->where('email', $email)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->pluck('id');

            $kept = $ids->shift();
            $nulled = $ids->all();

            DB::table('buyers')->whereIn('id', $nulled)->update(['email' => null]);

            Log::warning('buyers.email_deduplicated', [
                'email' => $email,
                'kept_buyer_id' => $kept,
                'nulled_buyer_ids' => $nulled,
            ]);
        }

        // 3 — canonical column + unique index.
        Schema::table('buyers', function (Blueprint $table): void {
            $table->string('email_canonical', 255)
                ->nullable()
                ->storedAs('(case when `deleted_at` is null then `email` else null end)')
                ->after('email');
        });

        Schema::table('buyers', function (Blueprint $table): void {
            $table->unique('email_canonical', 'buyers_email_canonical_unique');
        });
    }

    public function down(): void
    {
        Schema::table('buyers', function (Blueprint $table): void {
            $table->dropUnique('buyers_email_canonical_unique');
            $table->dropColumn('email_canonical');
        });
    }
};

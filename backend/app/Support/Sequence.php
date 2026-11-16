<?php

namespace App\Support;

use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Gap-free, concurrency-safe counters (patient codes, invoice numbers, tokens) using a row lock.
 * Must be called inside the caller's transaction so a rollback releases the number.
 */
class Sequence
{
    public static function next(string $key, string $scope = '', string $period = '', ?string $organizationId = null): int
    {
        $orgId = $organizationId ?? app(TenantContext::class)->idOrFail();

        return DB::transaction(function () use ($orgId, $key, $scope, $period) {
            $where = ['organization_id' => $orgId, 'scope' => $scope, 'key' => $key, 'period' => $period];
            $row = DB::table('sequences')->where($where)->lockForUpdate()->first();
            if (! $row) {
                DB::table('sequences')->insertOrIgnore($where + ['next_value' => 1, 'created_at' => now(), 'updated_at' => now()]);
                $row = DB::table('sequences')->where($where)->lockForUpdate()->first();
            }
            DB::table('sequences')->where('id', $row->id)->update(['next_value' => $row->next_value + 1, 'updated_at' => now()]);

            return (int) $row->next_value;
        });
    }

    /** Indian financial year label, e.g. 2026-27 for dates Apr 2026 – Mar 2027. */
    public static function financialYear(?\DateTimeInterface $date = null): string
    {
        $d = $date ? Carbon::instance($date) : now();
        $start = $d->month >= 4 ? $d->year : $d->year - 1;

        return $start.'-'.substr((string) ($start + 1), 2);
    }
}

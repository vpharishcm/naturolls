<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Gap-tolerant counters guarded by a row lock, shared by all terminals. */
class SequenceService
{
    public function next(string $key): int
    {
        return DB::transaction(function () use ($key) {
            $row = DB::table('sequences')->where('key', $key)->lockForUpdate()->first();
            if (! $row) {
                DB::table('sequences')->insertOrIgnore(['key' => $key, 'value' => 0, 'created_at' => now(), 'updated_at' => now()]);
                $row = DB::table('sequences')->where('key', $key)->lockForUpdate()->first();
            }
            $value = $row->value + 1;
            DB::table('sequences')->where('key', $key)->update(['value' => $value, 'updated_at' => now()]);

            return $value;
        });
    }

    /** Online numbers look like 261101-0042. Offline numbers carry the terminal code instead. */
    public function orderNumber(): string
    {
        $day = now()->format('ymd');

        return $day.'-'.str_pad((string) $this->next('order:'.$day), 4, '0', STR_PAD_LEFT);
    }

    /** Invoice numbers restart each Indian financial year: INV-2627-000001. */
    public function invoiceNumber(): string
    {
        $now = now();
        $start = $now->month >= 4 ? $now->year : $now->year - 1;
        $fy = substr((string) $start, 2).substr((string) ($start + 1), 2);

        return 'INV-'.$fy.'-'.str_pad((string) $this->next('invoice:'.$fy), 6, '0', STR_PAD_LEFT);
    }
}

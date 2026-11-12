<?php

namespace App\Services;

use App\Models\Setting;

class SettingsService
{
    public const DEFAULTS = [
        'business.name' => 'Naturolls',
        'business.address' => '',
        'business.phone' => '',
        'business.gstin' => '',
        'receipt.footer' => 'Thank you for visiting',
        'printer.driver' => 'browser',
        'printer.width' => 80,
        'printer.host' => '',
        'printer.port' => 9100,
        'tax.enabled' => false,
        'tax.prices_inclusive' => true,
        'discount.staff_max_percent' => 0,
        'billing.staff_edit_minutes' => 30,
        'inventory.low_stock_default' => 5,
        'loyalty.enabled' => true,
        'loyalty.points_per_100' => 5,
        'loyalty.rupees_per_point' => 1,
        'loyalty.min_redeem_points' => 10,
    ];

    private ?array $cache = null;

    public function all(): array
    {
        if ($this->cache === null) {
            $stored = Setting::query()->pluck('value', 'key')->all();
            $this->cache = array_merge(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
        }

        return $this->cache;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    public function update(array $values): array
    {
        foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
        $this->cache = null;

        return $this->all();
    }
}

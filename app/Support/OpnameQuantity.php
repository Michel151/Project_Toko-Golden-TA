<?php

namespace App\Support;

use App\Models\Product;

class OpnameQuantity
{
    public static function units(Product $product): array
    {
        $product->loadMissing(['productUnits.unit', 'buyUnit', 'retailUnit']);
        $baseName = $product->productUnits->firstWhere('is_base_unit', true)?->unit?->name
            ?? ($product->can_retail ? $product->retailUnit?->name : $product->buyUnit?->name) ?? 'Unit';
        $units = $product->productUnits->map(fn ($unit) => [
            'name' => $unit->unit?->name ?? $baseName,
            'conversion' => (float) $unit->conversion_to_base,
        ])->filter(fn ($unit) => $unit['conversion'] > 0)->all();
        if (!$units && $product->can_retail && (float) $product->conversion_factor > 1) {
            $units[] = ['name' => $product->buyUnit?->name ?? 'Unit', 'conversion' => (float) $product->conversion_factor];
        }
        if (!collect($units)->contains(fn ($unit) => $unit['conversion'] == 1)) {
            $units[] = ['name' => $baseName, 'conversion' => 1.0];
        }
        return collect($units)->sortByDesc('conversion')->values()->all();
    }

    public static function format(float $quantity, array $units, bool $difference = false): string
    {
        // Work in ten-thousandths so fractional base units are not discarded.
        $remaining = (int) round(abs($quantity) * 10000);
        $parts = [];
        foreach ($units as $unit) {
            $conversion = (int) round($unit['conversion'] * 10000);
            if ($conversion <= 10000) continue;
            $count = intdiv($remaining, $conversion);
            if ($count > 0) $parts[] = number_format($count, 0, ',', '.') . ' ' . $unit['name'];
            $remaining %= $conversion;
        }
        $baseName = collect($units)->firstWhere('conversion', 1)['name'] ?? 'Unit';
        if ($remaining || !$parts) {
            $parts[] = rtrim(rtrim(number_format($remaining / 10000, 4, ',', '.'), '0'), ',') . ' ' . $baseName;
        }
        $sign = round($quantity, 4) < 0 ? '-' : ($difference && round($quantity, 4) > 0 ? '+' : '');
        $text = implode(' + ', $parts);
        return $sign . ($sign === '-' && count($parts) > 1 ? '(' . $text . ')' : $text);
    }
}

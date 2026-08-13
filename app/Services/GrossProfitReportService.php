<?php

namespace App\Services;

use App\Models\SaleItem;
use Illuminate\Support\Facades\DB;

class GrossProfitReportService
{
    public function generate(string $from, string $to, ?int $categoryId = null): array
    {
        $baseQuery = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->whereBetween('sales.sale_date', [$from, $to])
            ->when($categoryId, fn ($query) => $query->where('products.category_id', $categoryId));

        $summary = (clone $baseQuery)->selectRaw(
            'COALESCE(SUM(sale_items.subtotal), 0) as revenue,
             COALESCE(SUM(sale_items.cost_subtotal), 0) as cost,
             COUNT(DISTINCT sale_items.sale_id) as transaction_count,
             COUNT(DISTINCT sale_items.product_id) as product_count'
        )->first();

        $products = (clone $baseQuery)
            ->select([
                'products.id as product_id',
                'products.name as product_name',
                'categories.name as category_name',
            ])
            ->selectRaw("SUM(CASE WHEN sale_items.sale_type = 'grosir' THEN sale_items.quantity ELSE 0 END) as wholesale_qty")
            ->selectRaw("SUM(CASE WHEN sale_items.sale_type = 'ecer' THEN sale_items.quantity ELSE 0 END) as retail_qty")
            ->selectRaw('SUM(sale_items.subtotal) as revenue')
            ->selectRaw('SUM(sale_items.cost_subtotal) as cost')
            ->selectRaw('COUNT(DISTINCT sale_items.sale_id) as transaction_count')
            ->groupBy('products.id', 'products.name', 'categories.name')
            ->orderByDesc(DB::raw('SUM(sale_items.subtotal) - SUM(sale_items.cost_subtotal)'))
            ->get()
            ->map(function ($row) {
                $row->revenue = (float) $row->revenue;
                $row->cost = (float) $row->cost;
                $row->gross_profit = $row->revenue - $row->cost;
                $row->margin = $row->revenue > 0 ? ($row->gross_profit / $row->revenue) * 100 : 0;

                return $row;
            });

        $revenue = (float) $summary->revenue;
        $cost = (float) $summary->cost;
        $grossProfit = $revenue - $cost;

        return [
            'products' => $products,
            'revenue' => $revenue,
            'cost' => $cost,
            'gross_profit' => $grossProfit,
            'margin' => $revenue > 0 ? ($grossProfit / $revenue) * 100 : 0,
            'transaction_count' => (int) $summary->transaction_count,
            'product_count' => (int) $summary->product_count,
        ];
    }
}

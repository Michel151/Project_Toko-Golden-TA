<?php

namespace App\Support;

class SaleQuantity
{
    public static function baseQuantitySql(): string
    {
        $sources = '(SELECT CASE WHEN COUNT(*) = COUNT(base_quantity) THEN SUM(base_quantity) ELSE NULL END FROM sale_item_sources WHERE sale_item_sources.sale_item_id = sale_items.id)';

        return "COALESCE(sale_items.cost_base_quantity, {$sources}, sale_items.quantity * COALESCE(product_units.conversion_to_base, CASE WHEN sale_items.sale_type = 'grosir' AND products.can_retail = 1 THEN products.conversion_factor ELSE 1 END))";
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('cost_price', 15, 2)->default(0)->after('unit_price')
                ->comment('Snapshot HPP per satuan jual saat transaksi');
            $table->decimal('cost_subtotal', 15, 2)->default(0)->after('subtotal')
                ->comment('Total HPP item saat transaksi');
        });

        $products = DB::table('products')->get()->keyBy('id');

        DB::table('sale_items')->orderBy('id')->chunkById(250, function ($items) use ($products) {
            foreach ($items as $item) {
                $product = $products->get($item->product_id);
                if (! $product) {
                    continue;
                }

                $costPrice = (float) $product->buy_price;
                if ($item->sale_type === 'ecer' && (float) $product->conversion_factor > 0) {
                    $costPrice /= (float) $product->conversion_factor;
                }

                DB::table('sale_items')->where('id', $item->id)->update([
                    'cost_price' => $costPrice,
                    'cost_subtotal' => $costPrice * (float) $item->quantity,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn(['cost_price', 'cost_subtotal']);
        });
    }
};

<?php

namespace Tests\Feature;

use App\Models\{Category, Location, Product, PurchaseOrder, PurchaseReceipt, PurchaseReturn, Sale, StockOpname, StockTransfer, Supplier, Unit, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_transaction_lists_keep_filters_across_fixed_pages(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-03 12:00:00', 'Asia/Jakarta'));
        $owner = User::create(['name'=>'Owner', 'email'=>'transaction-pages@test.com', 'password'=>'password', 'role'=>'owner']);
        $this->actingAs($owner);
        $supplier = Supplier::create(['name'=>'Supplier']);
        $a = Location::create(['name'=>'Gudang A']);
        $b = Location::create(['name'=>'Gudang B']);
        $product = Product::create(['name'=>'Barang', 'category_id'=>Category::create(['name'=>'Kategori'])->id,
            'buy_unit_id'=>Unit::create(['name'=>'Pcs'])->id, 'buy_price'=>1000, 'sell_price_wholesale'=>2000]);
        for ($i=0; $i<27; $i++) {
            $date = $i < 26 ? '2026-11-02' : '2026-10-01';
            $order = PurchaseOrder::create(['po_number'=>'PO-PAGE-'.$i, 'supplier_id'=>$supplier->id,
                'order_date'=>$date, 'status'=>'pending', 'created_by'=>$owner->id]);
            $receipt = PurchaseReceipt::create(['purchase_order_id'=>$order->id, 'receipt_date'=>$date, 'received_by'=>$owner->id]);
            PurchaseReturn::create(['return_number'=>'RT-PAGE-'.$i, 'purchase_receipt_id'=>$receipt->id,
                'created_by'=>$owner->id, 'status'=>$i < 26 ? 'draft' : 'rejected']);
            Sale::create(['sale_number'=>'INV-PAGE-'.$i, 'sale_date'=>$date, 'total_amount'=>1000,
                'payment_method'=>'tunai', 'status'=>'paid', 'created_by'=>$owner->id]);
            StockTransfer::create(['transfer_number'=>'TR-PAGE-'.$i, 'from_location_id'=>$a->id, 'to_location_id'=>$b->id,
                'transfer_date'=>$date, 'notes'=>'Pencarian Target', 'created_by'=>$owner->id, 'product_id'=>$product->id, 'quantity'=>1]);
            StockOpname::create(['opname_number'=>'OPN-PAGE-'.$i, 'location_id'=>$a->id, 'opname_date'=>$date,
                'status'=>'draft', 'created_by'=>$owner->id]);
        }
        $period = ['from'=>'2026-11-01', 'to'=>'2026-11-03'];
        $cases = [
            ['purchase.orders.index', 'orders', ['supplier_id'=>$supplier->id, 'status'=>'pending']],
            ['purchase.receipts.index', 'receipts', []],
            ['purchase.returns.index', 'returns', ['status'=>'draft']],
            ['sale.sales.index', 'sales', ['payment_method'=>'tunai', 'status'=>'paid', 'cashier_id'=>$owner->id]],
            ['inventory.transfers.index', 'transfers', ['search'=>'Pencarian Target', 'from_location_id'=>$a->id, 'to_location_id'=>$b->id]],
            ['inventory.opnames.index', 'opnames', []],
        ];
        foreach ($cases as [$route, $variable, $filters]) {
            $filters += $period;
            $first = $this->get(route($route, $filters))->assertOk()->viewData($variable);
            $this->assertSame(25, $first->perPage(), $route);
            $this->assertSame(26, $first->total(), $route);
            parse_str(parse_url($first->url(2), PHP_URL_QUERY), $query);
            foreach ($filters as $key=>$value) $this->assertEquals($value, $query[$key], $route.': '.$key);
            $second = $this->get($first->url(2))->assertOk()->viewData($variable);
            $this->assertCount(1, $second, $route);
            $this->assertEmpty(array_intersect($first->pluck('id')->all(), $second->pluck('id')->all()), $route);
        }
    }
}

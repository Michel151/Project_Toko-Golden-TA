<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductLocation;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\Stock;
use App\Models\StockBatch;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Unit $unit;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-29 10:00:00', 'Asia/Jakarta'));
        $this->owner = User::create(['name' => 'Owner', 'email' => 'notifications@test.com', 'password' => 'password', 'role' => 'owner']);
        $this->unit = Unit::create(['name' => 'Pcs']);
        $this->category = Category::create(['name' => 'Uji Notifikasi']);
    }

    private function product(string $name, array $attributes = []): Product
    {
        return Product::create(array_merge(['name' => $name, 'category_id' => $this->category->id, 'buy_unit_id' => $this->unit->id,
            'buy_price' => 1000, 'sell_price_wholesale' => 1500, 'is_active' => true, 'min_stock_total' => 0], $attributes));
    }

    public function test_summary_counts_unique_products_and_only_conditions_matching_the_filters(): void
    {
        $this->actingAs($this->owner);
        $mixed = $this->product('Gabungan', ['is_expirable'=>true, 'min_stock_total'=>100]);
        $a = $this->stock($mixed, 'Lokasi A', 5, 10);
        $b = $this->stock($mixed, 'Lokasi B', 4, 10);
        $this->batch($mixed, $a, -1, 1);
        $this->batch($mixed, $a, 5, 4);
        $this->batch($mixed, $b, 5, 4);
        $empty = $this->product('Kosong');
        $this->stock($empty, 'Lokasi C', 0, 5);
        $this->get(route('notifications.index'))->assertOk()->assertSee('Ringkasan notifikasi')
            ->assertViewHas('summary', ['products'=>2, 'expiry'=>1, 'stock'=>2, 'returns'=>0]);
        $this->get(route('notifications.index', ['condition'=>'expired']))->assertOk()
            ->assertViewHas('summary', ['products'=>1, 'expiry'=>1, 'stock'=>0, 'returns'=>0]);
        $this->get(route('notifications.index', ['condition'=>'expired', 'location_id'=>$b->id]))->assertOk()
            ->assertViewHas('summary', ['products'=>0, 'expiry'=>0, 'stock'=>0, 'returns'=>0]);
        $this->get(route('notifications.index', ['search'=>'Kosong']))->assertOk()
            ->assertViewHas('summary', ['products'=>1, 'expiry'=>0, 'stock'=>1, 'returns'=>0]);
        for ($i=0; $i<12; $i++) {
            $this->stock($this->product('Stock '.$i), 'Vide '.$i, 0, 1);
        }
        $this->get(route('notifications.index', ['page'=>2]))->assertOk()
            ->assertViewHas('summary', ['products'=>14, 'expiry'=>1, 'stock'=>14, 'returns'=>0]);
    }

    private function stock(Product $product, string $name, float $quantity, float $minimum): Location
    {
        $location = Location::firstOrCreate(['name' => $name]);
        ProductLocation::create(['product_id' => $product->id, 'location_id' => $location->id, 'min_stock' => $minimum]);
        Stock::create(['product_id' => $product->id, 'location_id' => $location->id, 'quantity' => $quantity]);

        return $location;
    }

    private function batch(Product $product, Location $location, ?int $days, float $quantity = 5): void
    {
        StockBatch::create(['product_id' => $product->id, 'location_id' => $location->id, 'received_date' => today()->subDays(40),
            'expired_date' => $days === null ? null : today()->addDays($days), 'quantity_initial' => $quantity, 'quantity_remaining' => $quantity]);
    }

    public function test_transfer_allocates_only_surplus_once_and_reports_uncovered_need(): void
    {
        $product = $this->product('Barang transfer');
        $a = $this->stock($product, 'Toko A', 2, 10);
        $b = $this->stock($product, 'Toko B', 2, 10);
        $source = $this->stock($product, 'Gudang', 15, 10);
        $item = app(NotificationService::class)->checkProduct($product)[0];
        $this->assertCount(1, $item['transfers']);
        $this->assertSame($source->id, $item['transfers'][0]['source_id']);
        $this->assertSame($a->id, $item['transfers'][0]['destination_id']);
        $this->assertEquals(5, $item['transfers'][0]['quantity']);
        $this->assertEquals(11, $item['shortfall']);
        $this->assertStringContainsString('pengadaan dari supplier', $item['advice']);

        Stock::where('location_id', $source->id)->update(['quantity' => 10]);
        $item = app(NotificationService::class)->checkProduct($product->fresh())[0];
        $this->assertSame([], $item['transfers']);
        $this->assertEquals(16, $item['shortfall']);
    }

    public function test_expired_stock_is_not_available_for_transfer_and_date_boundaries_are_sorted(): void
    {
        $location = Location::create(['name' => 'Gudang']);
        $products = [];
        foreach ([31, 30, 1, 0, -1] as $days) {
            $product = $this->product('Tanggal '.$days, ['is_expirable' => true]);
            $this->batch($product, $location, $days);
            $products[$days] = $product;
        }
        $undated = $this->product('Tanpa tanggal', ['is_expirable' => true]);
        $this->batch($undated, $location, null);
        $this->product('Tidak aktif', ['is_active' => false]);
        $empty = $this->product('Stok kosong');
        $items = app(NotificationService::class)->checkAllProducts();
        $this->assertSame([$products[-1]->id, $empty->id, $products[0]->id, $products[1]->id, $products[30]->id], array_column($items, 'product_id'));
        $this->assertSame('Kedaluwarsa Hari Ini', $items[2]['badge_label']);
        $this->assertStringContainsString('sisa 30 hari', $items[4]['summary']);
        $this->stock($products[-1], 'Toko', 0, 10);
        $item = app(NotificationService::class)->checkProduct($products[-1]->fresh())[0];
        $this->assertSame([], $item['transfers']);
        $this->assertEquals(10, $item['shortfall']);
    }

    public function test_open_orders_use_base_units_and_do_not_double_count_replacements(): void
    {
        $product = $this->product('Barang PO', ['min_stock_total' => 30]);
        $this->stock($product, 'Toko', 2, 0);
        $box = ProductUnit::create(['product_id' => $product->id, 'unit_id' => $this->unit->id, 'conversion_to_base' => 10, 'buy_price' => 10000, 'sell_price' => 15000]);
        $supplier = Supplier::create(['name' => 'Supplier']);
        $create = function ($number, $status, $ordered, $received, $reserved, $root = null) use ($product, $box, $supplier) {
            $po = PurchaseOrder::create(['po_number' => $number, 'supplier_id' => $supplier->id, 'order_date' => today(), 'status' => $status, 'created_by' => $this->owner->id, 'root_purchase_order_id' => $root]);
            $po->items()->create(['product_id' => $product->id, 'product_unit_id' => $box->id, 'quantity_ordered' => $ordered, 'quantity_received' => $received, 'quantity_reserved' => $reserved, 'buy_price' => 10000]);

            return $po;
        };
        $root = $create('PO-ROOT', 'partial', 5, 2, 1);
        $create('PO-REPLACEMENT', 'pending', 1, 0, 0, $root->id);
        foreach (['draft', 'cancelled', 'closed', 'completed'] as $status) {
            $create('PO-'.$status, $status, 100, 0, 0);
        }
        $item = app(NotificationService::class)->checkProduct($product)[0];
        $this->assertEquals(30, $item['incoming_quantity']);
        $this->assertEquals(0, $item['shortfall']);
        $this->assertCount(2, $item['orders']);
        $this->assertStringContainsString('menunggu penerimaan', $item['advice']);
        $this->assertSame('low_stock', $item['kind']);

        $root->items()->update(['quantity_received' => 3]);
        $item = app(NotificationService::class)->checkProduct($product->fresh())[0];
        $this->assertEquals(20, $item['incoming_quantity']);
        $this->assertEquals(8, $item['shortfall']);
        $this->assertStringContainsString('Masih kurang', $item['advice']);
    }

    public function test_page_filters_conditions_and_location_together_and_supports_pagination(): void
    {
        $this->actingAs($this->owner);
        $product = $this->product('Susu Filter', ['barcode' => 'MILK-123', 'is_expirable' => true]);
        $store = Location::create(['name' => 'Toko']);
        $warehouse = Location::create(['name' => 'Gudang']);
        $this->batch($product, $store, -1);
        $this->batch($product, $warehouse, 10);
        $this->get(route('notifications.index', ['condition' => 'expired', 'location_id' => $warehouse->id]))->assertOk()->assertViewHas('notifications', fn ($page) => $page->total() === 0);
        $response = $this->get(route('notifications.index', ['search' => 'milk-123', 'condition' => 'expired', 'location_id' => $store->id]));
        $response->assertOk()->assertViewHas('notifications', fn ($page) => $page->total() === 1)->assertSee('Rincian kondisi');
        $this->get(route('notifications.index', ['condition' => 'expiring', 'location_id' => $warehouse->id]))
            ->assertOk()->assertSee('1 produk sesuai filter dari 1 produk perlu perhatian.')
            ->assertViewHas('notifications', fn ($page) => $page->first()['summary'] === 'Stok Susu Filter di Gudang akan kedaluwarsa pada 08/11/2026 (sisa 10 hari). Sisa 5 Pcs.'
                && collect($page->first()['conditions'])->contains('kind', 'expired')
                && collect($page->first()['conditions'])->contains('kind', 'expiring'));
        $this->get(route('notifications.index', ['location_id' => $warehouse->id]))
            ->assertOk()->assertViewHas('notifications', fn ($page) => str_contains($page->first()['summary'], 'di Gudang'));
        $this->get(route('notifications.index', ['condition' => 'expiring']))
            ->assertOk()->assertViewHas('notifications', fn ($page) => str_contains($page->first()['summary'], 'sisa 10 hari'));
        $this->get(route('notifications.index', ['search' => 'tidak cocok']))
            ->assertOk()->assertSee('0 produk sesuai filter dari 1 produk perlu perhatian.');
        for ($i = 0; $i < 13; $i++) {
            $this->product('Produk '.$i);
        }
        $this->get(route('notifications.index'))->assertOk()->assertViewHas('notifications', fn ($page) => $page->total() === 14 && $page->count() === 12)->assertSee('14 produk perlu perhatian');
        $this->get(route('notifications.index', ['search' => 'Produk', 'page' => 2]))->assertOk()->assertSee('13 produk sesuai filter dari 14 produk perlu perhatian.')->assertViewHas('notifications', fn ($page) => $page->count() === 1 && $page->total() === 13);
        $this->get(route('notifications.index', ['page' => 2]))->assertOk()->assertViewHas('notifications', fn ($page) => $page->count() === 2);
        $detail = $this->get(route('notifications.index', ['product_id' => $product->id]))->assertOk()->assertViewHas('notifications', fn ($page) => $page->total() === 1)->assertSee('Buat Transfer');
        if (getenv('CAPTURE_NOTIFICATION_UI') === '1') {
            file_put_contents(base_path('tools/notification-detail-preview.html'), $detail->getContent());
            file_put_contents(base_path('tools/notification-list-preview.html'), $this->get(route('notifications.index'))->getContent());
        }
    }

    public function test_bell_and_dashboard_share_counts_and_refresh_on_next_request(): void
    {
        $product = $this->product('Stok Lokal');
        $location = $this->stock($product, 'Toko', 2, 10);
        $this->stock($product, 'Gudang', 30, 10);
        $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()->assertSee('1 produk perlu perhatian')->assertSee(route('notifications.index'), false);
        Stock::where('product_id', $product->id)->where('location_id', $location->id)->update(['quantity' => 11]);
        $this->get(route('notifications.index'))->assertOk()->assertViewHas('totalProducts', 0)->assertSee('notificationToggle')->assertSee('Tidak ada kondisi yang perlu perhatian.');
    }

    public function test_access_is_based_on_stock_permission_even_without_warehouse_dashboard_access(): void
    {
        $staff = User::create(['name' => 'Staf', 'email' => 'staff-notifications@test.com', 'password' => 'password', 'role' => 'staf_gudang']);
        DB::table('role_permissions')->where('role', 'staf_gudang')->delete();
        DB::table('role_permissions')->insert(['role' => 'staf_gudang', 'permission' => 'inventory.stock.view']);
        $this->actingAs($staff)->get(route('notifications.index'))->assertOk();
        DB::table('role_permissions')->where('role', 'staf_gudang')->delete();
        $this->get(route('notifications.index'))->assertForbidden();
    }

    public function test_actions_follow_permissions_and_open_forms_with_product_context(): void
    {
        $product = $this->product('Barang Tindakan');
        $destination = $this->stock($product, 'Toko', 2, 10);
        $source = $this->stock($product, 'Gudang', 15, 10);
        ProductUnit::create(['product_id'=>$product->id, 'unit_id'=>$this->unit->id, 'conversion_to_base'=>1, 'buy_price'=>1000, 'sell_price'=>1500, 'is_base_unit'=>true, 'is_default_purchase'=>true]);
        $supplier = Supplier::create(['name'=>'Supplier Tindakan']);
        $product->suppliers()->attach($supplier->id);
        $po = PurchaseOrder::create(['po_number'=>'PO-ACTION', 'supplier_id'=>$supplier->id, 'order_date'=>today(), 'status'=>'pending', 'created_by'=>$this->owner->id]);
        $po->items()->create(['product_id'=>$product->id, 'quantity_ordered'=>1, 'quantity_received'=>0, 'buy_price'=>1000]);
        $url = route('notifications.index', ['product_id'=>$product->id]);
        $transferUrl = route('inventory.transfers.create', ['product_id'=>$product->id, 'from_location_id'=>$source->id, 'to_location_id'=>$destination->id]);
        $response = $this->actingAs($this->owner)->get($url)->assertOk()->assertSee('Lihat Stok')->assertSee('Buat Transfer')->assertSee('Buat PO')->assertSee('Lihat PO')
            ->assertSee($transferUrl)->assertSee(route('purchase.orders.show', $po->id));
        if (getenv('CAPTURE_NOTIFICATION_UI') === '1') file_put_contents(base_path('tools/notification-actions-preview.html'), $response->getContent());
        $this->get(route('inventory.stock.index', ['product_id'=>$product->id]))->assertOk()->assertViewHas('products', fn ($items) => $items->pluck('id')->all() === [$product->id]);
        $this->get($transferUrl)->assertOk()->assertSee('value="'.$destination->id.'" selected', false);
        $this->get(route('purchase.orders.create', ['product_id'=>$product->id]))->assertOk()->assertViewHas('selectedSupplierId', $supplier->id)->assertViewHas('notificationProduct', fn ($p) => $p->id === $product->id);
        $this->get(route('purchase.orders.show', $po->id))->assertOk();
        $cashier = User::create(['name'=>'Kasir', 'email'=>'actions-cashier@test.com', 'password'=>'password', 'role'=>'kasir']);
        $this->actingAs($cashier)->get($url)->assertOk()->assertSee('Lihat Stok')->assertDontSee('Buat Transfer')->assertDontSee('Buat PO')->assertDontSee('Lihat PO');
        $this->get($transferUrl)->assertForbidden();
        $staff = User::create(['name'=>'Gudang', 'email'=>'actions-staff@test.com', 'password'=>'password', 'role'=>'staf_gudang']);
        $this->actingAs($staff)->get($url)->assertOk()->assertSee('Buat Transfer')->assertDontSee('Buat PO')->assertDontSee('Lihat PO');
        DB::table('role_permissions')->where('role', 'staf_gudang')->where('permission', 'inventory.transfers.create')->delete();
        $this->get($url)->assertOk()->assertDontSee('Buat Transfer');
        $this->get($transferUrl)->assertForbidden();
    }

    public function test_po_action_disappears_when_incoming_orders_cover_shortfall(): void
    {
        $product = $this->product('Barang Menunggu PO', ['min_stock_total'=>10]);
        $this->stock($product, 'Toko', 2, 0);
        $supplier = Supplier::create(['name'=>'Supplier']);
        $po = PurchaseOrder::create(['po_number'=>'PO-COVER', 'supplier_id'=>$supplier->id, 'order_date'=>today(), 'status'=>'pending', 'created_by'=>$this->owner->id]);
        $po->items()->create(['product_id'=>$product->id, 'quantity_ordered'=>10, 'buy_price'=>1000]);
        $this->actingAs($this->owner)->get(route('notifications.index'))->assertOk()->assertSee('Lihat PO')->assertDontSee('Buat PO')->assertDontSee('Buat Transfer');
        $this->get(route('purchase.orders.create', ['product_id'=>$product->id]))->assertOk()->assertSee('Produk belum terhubung ke supplier');
        $this->get(route('purchase.orders.create', ['product_id'=>'invalid']))->assertSessionHasErrors('product_id');
    }
    public function test_shared_products_skip_empty_batches_and_reuse_request_snapshot(): void
    {
        $product = $this->product('Shared stock', ['is_expirable' => true]);
        $location = $this->stock($product, 'Gudang', 10, 0);
        $this->batch($product, $location, 0, 10);
        $this->batch($product, $location, -1, 0);
        $products = Product::active()->with(['stocks', 'stockBatches' => fn ($q) => $q->where('quantity_remaining', '>', 0)])->get();
        $service = app(NotificationService::class);
        request()->attributes->remove('inventory.notifications');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $shared = $service->forRequest($products);
        $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
        $this->assertStringNotContainsString('from "products"', $queries);
        $this->assertStringNotContainsString('from "stock_batches"', $queries);
        $this->assertCount(1, $products->first()->stockBatches);
        DB::flushQueryLog();
        $this->assertSame($shared, $service->forRequest());
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($shared, $service->checkAllProducts());
        $this->assertSame('expiry_today', $shared[0]['kind']);
        $this->travelTo(now()->addDay());
        request()->attributes->remove('inventory.notifications');
        $this->assertSame('expired', $service->forRequest()[0]['kind']);
    }

    public function test_store_surplus_is_kept_for_sales_and_warehouse_shortage_requires_po(): void
    {
        $product = $this->product('Stok untuk penjualan');
        $store = $this->stock($product, 'Toko', 100, 10);
        $this->stock($product, 'Gudang 2', 0, 50);
        $item = app(NotificationService::class)->checkProduct($product)[0];
        $this->assertSame([], $item['transfers']);
        $this->assertEquals(50, $item['shortfall']);
        $this->assertStringContainsString('pengadaan dari supplier', $item['advice']);
        $this->assertEquals(100, array_sum(app(NotificationService::class)->sellableByLocation($product)));
        $this->actingAs($this->owner)->get(route('notifications.index', ['product_id' => $product->id]))
            ->assertOk()->assertDontSee('Buat Transfer')->assertSee('Buat PO');

        $supplier = Supplier::create(['name' => 'Supplier gudang']);
        $po = PurchaseOrder::create(['po_number' => 'PO-STORE-KEPT', 'supplier_id' => $supplier->id,
            'order_date' => today(), 'status' => 'pending', 'created_by' => $this->owner->id]);
        $po->items()->create(['product_id' => $product->id, 'quantity_ordered' => 50, 'buy_price' => 1000]);
        $item = app(NotificationService::class)->checkProduct($product->fresh())[0];
        $this->assertEquals(0, $item['shortfall']);
        $this->assertStringContainsString('menunggu penerimaan', $item['advice']);
        $this->get(route('notifications.index', ['product_id' => $product->id]))
            ->assertOk()->assertDontSee('Buat Transfer')->assertDontSee('Buat PO')->assertSee('Lihat PO');
    }

    public function test_warehouse_can_supply_store_and_other_warehouse_without_using_store_stock(): void
    {
        $product = $this->product('Distribusi gudang');
        $store = $this->stock($product, 'Toko', 2, 10);
        $destination = $this->stock($product, 'Gudang 2', 0, 5);
        $source = $this->stock($product, 'Gudang 1', 30, 10);
        $item = app(NotificationService::class)->checkProduct($product)[0];
        $this->assertCount(2, $item['transfers']);
        $this->assertSame([$source->id, $source->id], array_column($item['transfers'], 'source_id'));
        $this->assertSame([$store->id, $destination->id], array_column($item['transfers'], 'destination_id'));
        $this->assertEquals([8, 5], array_column($item['transfers'], 'quantity'));
        $this->assertEquals(0, $item['shortfall']);
    }

    public function test_empty_store_can_use_warehouse_stock_below_minimum_and_counts_replenishment(): void
    {
        $product = $this->product('Prioritas penjualan');
        $warehouse = $this->stock($product, 'Gudang 1', 10, 15);
        $this->stock($product, 'Gudang 2', 0, 5);
        $store = $this->stock($product, 'Toko', 0, 8);
        $item = app(NotificationService::class)->checkProduct($product)[0];
        $this->assertCount(1, $item['transfers']);
        $this->assertSame($warehouse->id, $item['transfers'][0]['source_id']);
        $this->assertSame($store->id, $item['transfers'][0]['destination_id']);
        $this->assertEquals(8, $item['transfers'][0]['quantity']);
        $this->assertEquals(18, $item['shortfall']);
        $this->assertSame('Direkomendasikan transfer stok dari Gudang 1 ke Toko.', $item['transfers'][0]['message']);
        $this->actingAs($this->owner)->get(route('notifications.index', ['product_id' => $product->id]))
            ->assertOk()->assertSee('Buat Transfer')->assertSee('Buat PO');
    }

    public function test_empty_store_with_zero_minimum_gets_transfer_but_never_expired_stock(): void
    {
        $product = $this->product('Minimum nol', ['is_expirable' => true]);
        $store = $this->stock($product, 'Toko', 0, 0);
        $source = $this->stock($product, 'Gudang 1', 10, 15);
        $this->batch($product, $source, 10, 0.5);
        $this->batch($product, $source, -1, 9.5);
        $item = app(NotificationService::class)->checkProduct($product)[0];
        $this->assertCount(1, $item['transfers']);
        $this->assertSame($store->id, $item['transfers'][0]['destination_id']);
        $this->assertEquals(0.5, $item['transfers'][0]['quantity']);
        $this->assertEquals(15.5, $item['shortfall']);
        StockBatch::where('product_id', $product->id)->update(['expired_date' => today()->subDay()]);
        $item = app(NotificationService::class)->checkProduct($product->fresh())[0];
        $this->assertSame([], $item['transfers']);
    }

}

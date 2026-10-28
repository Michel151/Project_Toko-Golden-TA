<?php

namespace Tests\Feature;

use App\Models\{Category, Location, Product, PurchaseOrder, Stock, StockMovement, StockTransfer, Supplier, Unit, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class UiConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;
    private Supplier $supplier;
    private Location $from;
    private Location $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-17 10:00:00','Asia/Jakarta'));
        $this->actingAs(User::create(['name'=>'Owner Uji','email'=>'ui@example.com','password'=>'password','role'=>'owner']));
        $unit=Unit::create(['name'=>'Pcs']);
        $category=Category::create(['name'=>'Kategori Uji']);
        $this->product=Product::create(['name'=>'Barang Utama','category_id'=>$category->id,'buy_unit_id'=>$unit->id,'buy_price'=>100,'sell_price_wholesale'=>200]);
        $this->supplier=Supplier::create(['name'=>'Supplier Uji']);
        $this->from=Location::create(['name'=>'Gudang']);
        $this->to=Location::create(['name'=>'Toko']);
    }

    private function order(string $number, array $extra=[]): PurchaseOrder
    {
        $order=PurchaseOrder::create(array_merge(['po_number'=>$number,'supplier_id'=>$this->supplier->id,'created_by'=>auth()->id(),'order_date'=>'2026-10-16','status'=>'pending'],$extra));
        if (isset($extra['created_at'])) $order->forceFill(['created_at'=>$extra['created_at']])->save();
        $order->items()->create(['product_id'=>$this->product->id,'quantity_ordered'=>10,'quantity_received'=>0,'buy_price'=>100]);
        return $order;
    }

    private function capture(string $name, string $content): void
    {
        if (getenv('CAPTURE_CONSISTENCY_UI')!=='1') return;
        $directory=storage_path('framework/testing/ui-consistency');
        if (!is_dir($directory)) mkdir($directory,0777,true);
        file_put_contents($directory.'/'.$name,$content);
    }

    public function test_master_products_sort_by_name_with_stable_order_for_equal_names(): void
    {
        $this->product->update(['name'=>'Zebra']);
        $biscuit = $this->product->replicate();
        $biscuit->name = 'Biskuit';
        $biscuit->save();
        $water = $this->product->replicate();
        $water->name = 'Air Mineral';
        $water->save();
        $otherWater = $water->replicate();
        $otherWater->save();
        $this->get(route('master.products.index'))->assertOk()
            ->assertViewHas('products', fn ($rows) => $rows->pluck('id')->all() === [$water->id,$otherWater->id,$biscuit->id,$this->product->id]);
    }

    public function test_alphabetical_product_pagination_preserves_search_category_and_status_filters(): void
    {
        $expected = [];
        for ($i = 22; $i >= 1; $i--) {
            $product = $this->product->replicate();
            $product->name = 'Produk Uji '.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $product->is_active = true;
            $product->save();
            $expected[] = $product->id;
        }
        $expected = array_reverse($expected);
        $inactive = $this->product->replicate();
        $inactive->name = 'Produk Uji Nonaktif';
        $inactive->is_active = false;
        $inactive->save();
        $other = $this->product->replicate();
        $other->name = 'Produk Uji Kategori Lain';
        $other->category_id = Category::create(['name'=>'Kategori Lain'])->id;
        $other->save();
        $filters = ['search'=>'Produk Uji','category_id'=>$this->product->category_id,'status'=>'active'];
        $page = $this->get(route('master.products.index', $filters))->assertOk()
            ->assertViewHas('products', fn ($rows) => $rows->total() === 22 && $rows->pluck('id')->all() === array_slice($expected, 0, 20));
        $this->get(route('master.products.index', $filters + ['page'=>2]))->assertOk()
            ->assertViewHas('products', fn ($rows) => $rows->pluck('id')->all() === array_slice($expected, 20));
        parse_str(parse_url($page->viewData('products')->url(2), PHP_URL_QUERY), $query);
        $this->assertEquals($filters + ['page'=>2], $query);
    }

    public function test_purchase_order_list_sorts_by_document_date_then_creation_time_and_id(): void
    {
        $first = $this->order('PO-UJI-1', ['order_date'=>'2026-10-17','created_at'=>'2026-10-17 09:00:00']);
        $second = $this->order('PO-UJI-2', ['order_date'=>'2026-10-17','created_at'=>'2026-10-17 10:00:00']);
        $return = $this->order('PO-RETUR-UJI', ['root_purchase_order_id'=>$first->id,'order_date'=>'2026-10-17','created_at'=>'2026-10-17 10:00:00']);
        $early = $this->order('PO-DIBUAT-LEBIH-AWAL', ['order_date'=>'2026-10-17','created_at'=>'2026-10-17 08:00:00']);
        $backdated = $this->order('PO-TANGGAL-LAMA', ['order_date'=>'2026-10-16','created_at'=>'2026-10-17 11:00:00']);
        $this->get(route('purchase.orders.index'))->assertOk()
            ->assertViewHas('orders', fn ($rows) => $rows->pluck('id')->all() === [$return->id,$second->id,$first->id,$early->id,$backdated->id]);
        $expected = [$return->id,$second->id,$first->id,$early->id,$backdated->id];
        $this->get(route('purchase.receipts.index'))->assertOk()
            ->assertViewHas('receivableOrders', fn ($rows) => $rows->pluck('id')->all() === $expected);
        $this->get(route('purchase.receipts.create'))->assertOk()
            ->assertViewHas('pendingOrders', fn ($rows) => $rows->pluck('id')->all() === $expected);
    }

    public function test_receipt_history_sorts_latest_first_and_keeps_date_filter_and_pagination(): void
    {
        $order = $this->order('PO-RIWAYAT');
        $ids = [];
        for ($i = 0; $i < 18; $i++) {
            $receipt = \App\Models\PurchaseReceipt::create(['purchase_order_id'=>$order->id,'receipt_date'=>'2026-10-17','received_by'=>auth()->id()]);
            $receipt->forceFill(['created_at'=>'2026-10-17 10:00:00'])->save();
            $ids[] = $receipt->id;
        }
        $early = \App\Models\PurchaseReceipt::create(['purchase_order_id'=>$order->id,'receipt_date'=>'2026-10-17','received_by'=>auth()->id()]);
        $early->forceFill(['created_at'=>'2026-10-17 09:00:00'])->save();
        $old = \App\Models\PurchaseReceipt::create(['purchase_order_id'=>$order->id,'receipt_date'=>'2026-10-16','received_by'=>auth()->id()]);
        $old->forceFill(['created_at'=>'2026-10-17 11:00:00'])->save();
        $expected = [...array_reverse($ids), $early->id];
        $filters = ['from'=>'2026-10-17','to'=>'2026-10-17'];
        $page = $this->get(route('purchase.receipts.index', $filters))->assertOk()
            ->assertViewHas('receipts', fn ($rows) => $rows->total() === 19 && $rows->pluck('id')->all() === array_slice($expected, 0, 15));
        $this->get(route('purchase.receipts.index', $filters + ['page'=>2]))->assertOk()
            ->assertViewHas('receipts', fn ($rows) => $rows->pluck('id')->all() === array_slice($expected, 15));
        $this->get(route('purchase.receipts.index'))->assertOk()
            ->assertViewHas('receipts', fn ($rows) => $rows->total() === 20 && $rows->pluck('id')->all() === array_slice($expected, 0, 15));
        parse_str(parse_url($page->viewData('receipts')->url(2), PHP_URL_QUERY), $query);
        $this->assertEquals($filters + ['page'=>2], $query);
    }

    public function test_purchase_order_sorting_stays_stable_across_pages_and_combined_filters(): void
    {
        $ids = [];
        for ($i = 1; $i <= 18; $i++) {
            $ids[] = $this->order('PO-HALAMAN-'.$i, ['order_date'=>'2026-10-17','created_at'=>'2026-10-17 10:00:00'])->id;
        }
        $this->order('PO-STATUS-LAIN', ['order_date'=>'2026-10-17','status'=>'completed']);
        $this->order('PO-TANGGAL-LAIN', ['order_date'=>'2026-10-18']);
        $supplier = Supplier::create(['name'=>'Supplier Lain']);
        $this->order('PO-SUPPLIER-LAIN', ['order_date'=>'2026-10-17','supplier_id'=>$supplier->id]);
        $filters = ['supplier_id'=>$this->supplier->id,'status'=>'pending','from'=>'2026-10-17','to'=>'2026-10-17'];
        $expected = array_reverse($ids);
        $firstPage = $this->get(route('purchase.orders.index', $filters))->assertOk()
            ->assertViewHas('orders', fn ($rows) => $rows->total() === 18 && $rows->pluck('id')->all() === array_slice($expected, 0, 15));
        $this->get(route('purchase.orders.index', $filters + ['page'=>2]))->assertOk()
            ->assertViewHas('orders', fn ($rows) => $rows->pluck('id')->all() === array_slice($expected, 15));
        parse_str(parse_url($firstPage->viewData('orders')->url(2), PHP_URL_QUERY), $query);
        $this->assertEquals($filters + ['page'=>2], $query);
    }

    public function test_purchase_order_dates_default_to_current_month_and_allow_custom_or_empty_ranges(): void
    {
        $previous = $this->order('PO-SEPTEMBER', ['order_date'=>'2026-09-30']);
        $first = $this->order('PO-AWAL-BULAN', ['order_date'=>'2026-10-01']);
        $today = $this->order('PO-HARI-INI', ['order_date'=>'2026-10-17']);
        $this->order('PO-BESOK', ['order_date'=>'2026-10-18']);
        $default = $this->get(route('purchase.orders.index'))->assertOk()
            ->assertViewHas('from', '2026-10-01')->assertViewHas('to', '2026-10-17');
        $this->assertSame([$today->id, $first->id], $default->viewData('orders')->pluck('id')->all());
        $this->assertEquals(2000, $default->viewData('totalOrderValue'));
        parse_str(parse_url($default->viewData('orders')->url(2), PHP_URL_QUERY), $query);
        $this->assertSame(['from'=>'2026-10-01', 'to'=>'2026-10-17', 'page'=>'2'], $query);
        $custom = $this->get(route('purchase.orders.index', ['from'=>'2026-09-01', 'to'=>'2026-09-30']))->assertOk();
        $this->assertSame([$previous->id], $custom->viewData('orders')->pluck('id')->all());
        $this->assertEquals(1000, $custom->viewData('totalOrderValue'));
        $this->get(route('purchase.orders.index', ['from'=>'', 'to'=>'']))->assertOk()
            ->assertViewHas('orders', fn ($rows)=>$rows->total() === 4);
        $this->get(route('purchase.orders.index', ['from'=>'2026-10-20', 'to'=>'2026-10-01']))->assertSessionHasErrors('to');
        $this->get(route('purchase.orders.index'))->assertViewHas('from', '2026-10-01')->assertViewHas('to', '2026-10-17');
    }

    public function test_receipt_sale_and_transfer_lists_default_to_current_month_and_keep_custom_dates_on_pages(): void
    {
        $oldOrder = $this->order('PO-LAMA-MASIH-MENUNGGU', ['order_date'=>'2026-09-30']);
        $dates = array_merge(['2026-09-30', '2026-10-01', '2026-10-17', '2026-10-18'], array_fill(0, 20, '2026-10-17'));
        foreach ($dates as $i=>$date) {
            \App\Models\PurchaseReceipt::create(['purchase_order_id'=>$oldOrder->id, 'receipt_date'=>$date, 'received_by'=>auth()->id()]);
            \App\Models\Sale::create(['sale_number'=>'INV-MONTH-'.$i, 'sale_date'=>$date, 'status'=>'paid', 'payment_method'=>'tunai', 'total_amount'=>100, 'created_by'=>auth()->id()]);
            StockTransfer::create(['transfer_number'=>'TRF-MONTH-'.$i, 'transfer_date'=>$date, 'product_id'=>$this->product->id,
                'from_location_id'=>$this->from->id, 'to_location_id'=>$this->to->id, 'quantity'=>1, 'created_by'=>auth()->id()]);
        }
        foreach (['purchase.receipts.index'=>'receipts', 'sale.sales.index'=>'sales', 'inventory.transfers.index'=>'transfers'] as $route=>$key) {
            $page = $this->get(route($route))->assertOk()->assertViewHas('from', '2026-10-01')->assertViewHas('to', '2026-10-17');
            $this->assertSame(22, $page->viewData($key)->total());
            $page->assertSee('value="2026-10-01"', false)->assertSee('value="2026-10-17"', false);
            $this->capture('monthly-'.$key.'-default.html', $page->getContent());
            parse_str(parse_url($page->viewData($key)->url(2), PHP_URL_QUERY), $query);
            $this->assertSame('2026-10-01', $query['from']);
            $this->assertSame('2026-10-17', $query['to']);
            $this->get($page->viewData($key)->url(2))->assertOk()->assertViewHas($key, fn ($rows)=>$rows->total() === 22);
            if ($key === 'sales') $this->assertEquals(2200, $page->viewData('totalAmount'));
            if ($key === 'receipts') {
                $this->assertSame(22, $page->viewData('receiptCount'));
                $this->assertTrue($page->viewData('receivableOrders')->contains('id', $oldOrder->id));
            }
            $custom = $this->get(route($route, ['from'=>'2026-09-01', 'to'=>'2026-09-30']))->assertOk()
                ->assertViewHas($key, fn ($rows)=>$rows->total() === 1);
            $this->capture('monthly-'.$key.'-custom.html', $custom->getContent());
            $all = $this->get(route($route, ['from'=>'', 'to'=>'']))->assertOk()->assertViewHas($key, fn ($rows)=>$rows->total() === 24);
            $this->capture('monthly-'.$key.'-all.html', $all->getContent());
            $this->get(route($route, ['from'=>'', 'to'=>'2026-09-30']))->assertOk()->assertViewHas($key, fn ($rows)=>$rows->total() === 1);
            $this->get(route($route, ['from'=>'2026-10-18', 'to'=>'']))->assertOk()->assertViewHas($key, fn ($rows)=>$rows->total() === 1);
            $this->get(route($route, ['from'=>'2026-10-17', 'to'=>'2026-10-01']))->assertSessionHasErrors('to');
            $this->get(route($route))->assertViewHas('from', '2026-10-01')->assertViewHas('to', '2026-10-17');
        }
        $this->travelTo(\Carbon\Carbon::parse('2027-01-01 00:15:00', 'Asia/Jakarta'));
        foreach (['purchase.receipts.index', 'sale.sales.index', 'inventory.transfers.index'] as $route) {
            $this->get(route($route))->assertOk()->assertViewHas('from', '2027-01-01')->assertViewHas('to', '2027-01-01');
        }
    }

    public function test_opname_and_supplier_return_history_support_monthly_defaults_and_other_periods(): void
    {
        $order = $this->order('PO-ASAL-PERIODE-LAMA', ['order_date'=>'2026-09-30']);
        $receipt = \App\Models\PurchaseReceipt::create(['purchase_order_id'=>$order->id, 'receipt_date'=>'2026-09-30', 'received_by'=>auth()->id()]);
        $dates = array_merge(['2026-09-30', '2026-10-01', '2026-10-17', '2026-10-18'], array_fill(0, 20, '2026-10-17'));
        foreach ($dates as $i=>$date) {
            \App\Models\StockOpname::create(['opname_number'=>'OPN-PERIOD-'.$i, 'location_id'=>$this->from->id,
                'opname_date'=>$date, 'status'=>'draft', 'created_by'=>auth()->id(), 'created_at'=>'2026-08-01 09:00:00']);
            \App\Models\PurchaseReturn::create(['return_number'=>'RT-PERIOD-'.$i, 'purchase_receipt_id'=>$receipt->id,
                'status'=>$i===1 ? 'approved' : 'draft', 'created_by'=>auth()->id(), 'created_at'=>$date.' 23:59:59']);
        }
        foreach (['inventory.opnames.index'=>'opnames', 'purchase.returns.index'=>'returns'] as $route=>$key) {
            $page = $this->get(route($route))->assertOk()->assertViewHas('from', '2026-10-01')->assertViewHas('to', '2026-10-17');
            $page->assertSee('value="2026-10-01"', false)->assertSee('value="2026-10-17"', false);
            $this->assertSame(22, $page->viewData($key)->total());
            $this->capture('monthly-'.$key.'-default.html', $page->getContent());
            parse_str(parse_url($page->viewData($key)->url(2), PHP_URL_QUERY), $query);
            $this->assertSame('2026-10-01', $query['from']);
            $this->assertSame('2026-10-17', $query['to']);
            $this->get($page->viewData($key)->url(2))->assertOk()->assertViewHas($key, fn ($rows)=>$rows->total()===22);
            $custom = $this->get(route($route, ['from'=>'2026-09-01', 'to'=>'2026-09-30']))->assertOk()->assertViewHas($key, fn ($rows)=>$rows->total()===1);
            $this->capture('monthly-'.$key.'-custom.html', $custom->getContent());
            $all = $this->get(route($route, ['from'=>'', 'to'=>'']))->assertOk()->assertViewHas($key, fn ($rows)=>$rows->total()===24);
            $this->capture('monthly-'.$key.'-all.html', $all->getContent());
            $this->get(route($route, ['from'=>'', 'to'=>'2026-09-30']))->assertOk()->assertViewHas($key, fn ($rows)=>$rows->total()===1);
            $this->get(route($route, ['from'=>'2026-10-18', 'to'=>'']))->assertOk()->assertViewHas($key, fn ($rows)=>$rows->total()===1);
            $this->get(route($route, ['from'=>'2026-10-17', 'to'=>'2026-10-01']))->assertSessionHasErrors('to');
            $this->get(route($route))->assertViewHas('from', '2026-10-01')->assertViewHas('to', '2026-10-17');
        }
        $filtered = $this->get(route('purchase.returns.index', ['status'=>'draft']))->assertOk();
        $this->assertSame(21, $filtered->viewData('returns')->total());
        parse_str(parse_url($filtered->viewData('returns')->url(2), PHP_URL_QUERY), $query);
        $this->assertSame('draft', $query['status']);
        $this->assertSame('2026-10-01', $query['from']);
        $this->get(route('purchase.returns.index', ['status'=>'approved']))->assertOk()->assertViewHas('returns', fn ($rows)=>$rows->total()===1);
        $this->travelTo(\Carbon\Carbon::parse('2027-01-01 00:15:00', 'Asia/Jakarta'));
        foreach (['inventory.opnames.index', 'purchase.returns.index'] as $route) {
            $this->get(route($route))->assertOk()->assertViewHas('from', '2027-01-01')->assertViewHas('to', '2027-01-01');
        }
    }

    public function test_filter_pages_keep_explicit_labels_and_shared_controls(): void
    {
        foreach (['products-list'=>'master.products.index','returns-list'=>'purchase.returns.index','sales-list'=>'sale.sales.index'] as $name => $route) {
            $page = $this->get(route($route))->assertOk()->assertSee('app-filter-bar')->assertSee('Terapkan');
            $this->capture($name.'.html', $page->getContent());
        }
        $this->get(route('master.products.index', ['search'=>'Barang','category_id'=>$this->product->category_id,'status'=>'active']))
            ->assertOk()->assertSee('Reset filter')->assertSee('Barang Utama');
        $page = $this->get(route('master.products.index'))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($page->getContent());
        $xpath = new \DOMXPath($document);
        foreach (['master.products.import','master.products.create'] as $route) {
            $this->assertSame(1, $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " product-list-toolbar ")]//a[@href="'.route($route).'"]')->length);
        }
        $page = $this->get(route('sale.sales.index'))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($page->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " sale-list-toolbar ")]//a[@href="'.route('sale.sales.create').'"]')->length);
    }

    public function test_stock_card_uses_the_shared_header_and_active_tab_colors(): void
    {
        $this->get(route('inventory.stock-card.show', $this->product))
            ->assertOk()
            ->assertSee('.stock-card-heading{background:var(--app-header)', false)
            ->assertSee('.stock-card-tabs .nav-link.active{background:var(--app-accent-2)', false);
    }

    public function test_page_headers_and_tabs_share_the_application_theme(): void
    {
        $pages = [
            'product-theme' => ['master.products.show', ['product' => $this->product]],
            'supplier-theme' => ['master.suppliers.create', []],
            'stock-theme' => ['inventory.stock.index', []],
            'stock-card-theme' => ['inventory.stock-card.show', ['product' => $this->product]],
            'transfer-theme' => ['inventory.transfers.create', []],
            'sale-theme' => ['sale.sales.create', []],
            'permissions-theme' => ['permissions.index', []],
            'audit-theme' => ['audit-logs.index', []],
            'backup-theme' => ['database-backups.index', []],
            'dashboard-theme' => ['dashboard', []],
            'report-theme' => ['report.stock', []],
        ];
        foreach ($pages as $name => [$route, $parameters]) {
            $page = $this->get(route($route, $parameters))->assertOk();
            $page->assertSee('.page-content .stock-card-heading,', false)
                ->assertSee('.page-content .supplier-catalog-tabs .nav-link.active,', false)
                ->assertSee('.page-content .product-tabs .nav-link.active,', false)
                ->assertSee('.page-content .nav-tabs .nav-link.active,', false)
                ->assertSee('background: var(--app-accent-2);', false);
            $this->capture($name.'.html', $page->getContent());
        }
    }

    public function test_purchase_report_summary_and_exports_cover_the_whole_period_not_only_the_page(): void
    {
        for($i=1;$i<=52;$i++) $first=$this->order('PO-UJI-'.$i);
        $this->order('PO-RETUR-UJI',['root_purchase_order_id'=>$first->id,'status'=>'completed']);
        $this->order('PO-LUAR-PERIODE',['order_date'=>'2026-09-30']);
        $url=route('report.purchase',['from'=>'2026-10-01','to'=>'2026-10-17','page'=>2]);
        $page=$this->get($url)->assertOk()->assertViewHas('orders',fn($rows)=>$rows->count()===25 && $rows->total()===53)
            ->assertViewHas('orderCount',53)->assertViewHas('totalValue',52000.0)->assertViewHas('completed',1)->assertViewHas('pending',52);
        $this->capture('purchase.html',$page->getContent());
        $response=$this->get(route('report.purchase.excel',['from'=>'2026-10-01','to'=>'2026-10-17','page'=>2]))->assertOk();
        $book=IOFactory::load($response->baseResponse->getFile()->getPathname());
        $sheet=$book->getActiveSheet();
        $this->assertSame(58,$sheet->getHighestDataRow());
        $this->assertEquals(52000,$sheet->getCell('G58')->getValue());
        $this->assertContains('Menunggu',array_column($sheet->toArray(),3));
        $book->disconnectWorksheets();
    }

    public function test_transfer_detail_is_paginated_searchable_and_keeps_all_item_records(): void
    {
        $transfer=StockTransfer::create(['transfer_number'=>'TRF-UJI','product_id'=>$this->product->id,'quantity'=>31,'from_location_id'=>$this->from->id,'to_location_id'=>$this->to->id,'transfer_date'=>today(),'created_by'=>auth()->id()]);
        for($i=1;$i<=31;$i++) {
            $product=$this->product->replicate();$product->name='Barang Transfer '.str_pad($i,3,'0',STR_PAD_LEFT);$product->save();
            $transfer->items()->create(['product_id'=>$product->id,'quantity'=>1]);
        }
        $page=$this->get(route('inventory.transfers.show',$transfer))->assertOk()
            ->assertViewHas('items',fn($items)=>$items->count()===25 && $items->total()===31);
        $this->capture('transfer.html',$page->getContent());
        $this->get(route('inventory.transfers.show',[$transfer,'page'=>2]))->assertOk()->assertViewHas('items',fn($items)=>$items->count()===6);
        $this->get(route('inventory.transfers.show',[$transfer,'q'=>'031']))->assertOk()->assertViewHas('items',fn($items)=>$items->total()===1)->assertSee('Barang Transfer 031');
        $this->get(route('inventory.transfers.show',[$transfer,'q'=>'Tidak Ada']))->assertOk()->assertSee('Tidak ada barang yang sesuai pencarian.');
        $this->get(route('inventory.transfers.show',[$transfer,'per_page'=>500]))->assertSessionHasErrors('per_page');
        $this->assertDatabaseCount('stock_transfer_items',31);
        $this->assertDatabaseCount('stock_movements',0);
    }

    public function test_pagination_is_indonesian_and_preserves_filters(): void
    {
        for($i=1;$i<=35;$i++) Category::create(['name'=>'Kategori '.str_pad($i,3,'0',STR_PAD_LEFT)]);
        $page=$this->get(route('master.categories.index',['page'=>2]))->assertOk()->assertSee('Menampilkan')->assertSee('Halaman sebelumnya')->assertDontSee('Showing');
        $this->capture('categories.html',$page->getContent());
        $this->order('PO-STATUS',['status'=>'partial']);
        $this->get(route('master.suppliers.show',$this->supplier))->assertOk()->assertSee('Diterima Sebagian')->assertDontSee('Partial');
        $this->get(route('report.purchase'))->assertOk()->assertSee('Diterima Sebagian')->assertDontSee('Pending/Partial');
    }

    public function test_opname_tabs_share_the_same_heading_and_identify_the_active_page(): void
    {
        foreach (['inventory.opnames.index','inventory.opnames.movements'] as $route) {
            $page=$this->get(route($route))->assertOk();
            $document=new \DOMDocument;
            @$document->loadHTML($page->getContent());
            $xpath=new \DOMXPath($document);
            $headings=$xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," inventory-heading ")]');
            $this->assertSame(1,$headings->length);
            $this->assertSame('Stock Opname',trim($headings->item(0)->textContent));
            $active=$xpath->query('//a[@aria-current="page" and @href="'.route($route).'"]');
            $this->assertSame(1,$active->length);
        }
    }

    public function test_sale_report_and_exports_sort_newest_date_creation_time_then_id_first(): void
    {
        $make = function ($number, $date, $created) {
            $sale = \App\Models\Sale::create(['sale_number'=>$number, 'created_by'=>auth()->id(), 'sale_date'=>$date, 'total_amount'=>100, 'payment_method'=>'tunai', 'status'=>'paid']);
            $sale->forceFill(['created_at'=>$created])->save();
        };
        $make('INV-OLDER-DATE', '2026-10-15', '2026-10-17 10:00:00');
        $make('INV-EARLY', '2026-10-16', '2026-10-16 08:00:00');
        $make('INV-LATE', '2026-10-16', '2026-10-16 10:00:00');
        $make('INV-TIE', '2026-10-16', '2026-10-16 10:00:00');
        $expected = ['INV-TIE', 'INV-LATE', 'INV-EARLY', 'INV-OLDER-DATE'];
        $filters = ['from'=>'2026-10-01', 'to'=>'2026-10-17'];
        $this->get(route('report.sale', $filters))->assertOk()
            ->assertViewHas('sales', fn ($sales) => $sales->pluck('sale_number')->all() === $expected)
            ->assertViewHas('totalTrx', 4)->assertViewHas('totalAmount', 400);
        \Maatwebsite\Excel\Facades\Excel::fake();
        $this->get(route('report.sale.excel', $filters))->assertOk();
        \Maatwebsite\Excel\Facades\Excel::assertDownloaded('Laporan-Penjualan-2026-10-01-sd-2026-10-17.xlsx',
            fn ($export) => array_column(array_slice($export->array(), 4), 0) === $expected);
        $pdf = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $pdf->shouldReceive('setPaper')->once()->with('a4', 'portrait')->andReturnSelf();
        $pdf->shouldReceive('stream')->once()->andReturn(response('sorted-pdf'));
        \Barryvdh\DomPDF\Facade\Pdf::shouldReceive('loadView')->once()->with('report.pdf.sale',
            \Mockery::on(fn ($data) => $data['sales']->pluck('sale_number')->all() === $expected))->andReturn($pdf);
        $this->get(route('report.sale.pdf', $filters))->assertOk()->assertSee('sorted-pdf');
    }

    public function test_sale_report_displays_the_correct_cashier_below_each_customer(): void
    {
        $cashierA=User::create(['name'=>'Kasir A Uji','email'=>'cashier-a-ui@example.com','password'=>'password','role'=>'kasir']);
        $cashierB=User::create(['name'=>'Kasir B Uji','email'=>'cashier-b-ui@example.com','password'=>'password','role'=>'kasir']);
        foreach ([['INV-UI-1','Rina',$cashierA],['INV-UI-2','',$cashierB],['INV-UI-3',null,$cashierA]] as [$number,$customer,$cashier]) {
            \App\Models\Sale::create(['sale_number'=>$number,'customer_name'=>$customer,'created_by'=>$cashier->id,'sale_date'=>'2026-10-16','total_amount'=>100,'payment_method'=>'tunai','amount_paid'=>100,'change_amount'=>0,'status'=>'paid']);
        }
        $page=$this->get(route('report.sale',['from'=>'2026-10-16','to'=>'2026-10-17']))->assertOk()->assertSee('Pelanggan / Kasir');
        $document=new \DOMDocument;@$document->loadHTML($page->getContent());$xpath=new \DOMXPath($document);
        foreach ([['INV-UI-1','Rina','Kasir A Uji'],['INV-UI-2','Umum','Kasir B Uji'],['INV-UI-3','Umum','Kasir A Uji']] as [$number,$customer,$cashier]) {
            $cells=$xpath->query('//tr[td/a[normalize-space(text())="'.$number.'"]]/td[3]');
            $this->assertSame(1,$cells->length);
            $this->assertStringContainsString($customer,$cells->item(0)->textContent);
            $this->assertSame($cashier,trim($xpath->query('./div',$cells->item(0))->item(0)->textContent));
        }
        $this->capture('sale-cashier.html',$page->getContent());
    }

    public function test_validation_summary_is_not_duplicated_in_transfer_or_opname_forms(): void
    {
        $message='Jumlah stok pengujian tidak valid.';
        $page=$this->withSession(['errors'=>(new \Illuminate\Support\ViewErrorBag)->put('default',new \Illuminate\Support\MessageBag(['items'=>$message]))])
            ->get(route('inventory.transfers.create'))->assertOk();
        $this->assertSame(1,substr_count($page->getContent(),$message));
        foreach (['master.products.import','master.suppliers.import','database-backups.index'] as $route) {
            $page=$this->withSession(['errors'=>(new \Illuminate\Support\ViewErrorBag)->put('default',new \Illuminate\Support\MessageBag(['file'=>$message]))])
                ->get(route($route))->assertOk();
            $this->assertSame(1,substr_count($page->getContent(),$message));
        }
        $opname=\App\Models\StockOpname::create(['opname_number'=>'OPN-UJI','location_id'=>$this->from->id,'opname_date'=>today(),'status'=>'draft','created_by'=>auth()->id()]);
        $page=$this->withSession(['errors'=>(new \Illuminate\Support\ViewErrorBag)->put('default',new \Illuminate\Support\MessageBag(['items'=>$message]))])
            ->get(route('inventory.opnames.edit',$opname))->assertOk();
        $this->assertSame(1,substr_count($page->getContent(),$message));
    }

    public function test_sale_filters_have_visible_date_labels_and_stock_card_pdf_uses_plain_shared_layout(): void
    {
        $this->get(route('sale.sales.index'))->assertOk()->assertSee('for="saleListFrom"',false)->assertSee('Dari Tanggal')->assertSee('Sampai Tanggal')->assertSee('Terapkan');
        Stock::create(['product_id'=>$this->product->id,'location_id'=>$this->from->id,'quantity'=>100]);
        for($i=1;$i<=45;$i++) StockMovement::create(['product_id'=>$this->product->id,'location_id'=>$this->from->id,'movement_date'=>'2026-10-16','movement_type'=>'adjustment','quantity'=>1,'balance_before'=>$i-1,'balance_after'=>$i,'reference_number'=>'REF-UJI-'.$i,'created_by'=>auth()->id()]);
        $page=$this->get(route('inventory.stock-card.show',$this->product))->assertOk();
        $this->capture('stock-card.html',$page->getContent());
        $pdf=$this->get(route('inventory.stock-card.pdf',$this->product))->assertOk()->assertHeader('Content-Type','application/pdf');
        $this->assertStringStartsWith('%PDF',$pdf->getContent());
        $this->capture('stock-card.pdf',$pdf->getContent());
        $template=view('inventory.stock-card.pdf',$page->original->getData())->render();
        $this->assertStringContainsString('Kartu Stok Barang',$template);
        $this->assertStringContainsString('Halaman',$template);
        $this->assertStringNotContainsString('#c8960c',$template);
        $this->assertStringNotContainsString('#27324a',$template);
    }
}

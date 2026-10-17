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

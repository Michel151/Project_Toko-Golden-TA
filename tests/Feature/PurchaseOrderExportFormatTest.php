<?php

namespace Tests\Feature;

use App\Models\{Category, Product, ProductUnit, PurchaseOrder, Supplier, Unit, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\{IOFactory, Style\Fill};
use Tests\TestCase;

class PurchaseOrderExportFormatTest extends TestCase
{
    use RefreshDatabase;

    private function order(int $count = 2): PurchaseOrder
    {
        $user=User::create(['name'=>'Owner Golden','email'=>'po-export@example.com','password'=>'password','role'=>'owner']);
        $this->actingAs($user);
        $supplier=Supplier::create(['name'=>'CV. Supplier Uji','address'=>'Jl. Merdeka No. 10, Kota Weda, Halmahera Tengah','phone'=>'081234567890']);
        $unit=Unit::create(['name'=>'Dus']);
        $category=Category::create(['name'=>'Kebutuhan Toko']);
        $order=PurchaseOrder::create(['po_number'=>'PO/2026/10/000001','supplier_id'=>$supplier->id,
            'order_date'=>'2026-10-17','status'=>'partial','created_by'=>$user->id]);
        for ($i=0;$i<$count;$i++) {
            $product=Product::create(['name'=>sprintf('Barang Uji %03d',$i+1).($count>2 ? ' Kemasan Grosir dengan Nama Produk Panjang untuk Pemeriksaan Tata Letak' : ''),
                'category_id'=>$category->id,'buy_unit_id'=>$unit->id,'buy_price'=>12000,'sell_price_wholesale'=>15000]);
            $productUnit=ProductUnit::create(['product_id'=>$product->id,'unit_id'=>$unit->id,'conversion_to_base'=>1,
                'buy_price'=>12000,'sell_price'=>15000,'is_default_purchase'=>true,'is_base_unit'=>true]);
            $order->items()->create(['product_id'=>$product->id,'product_unit_id'=>$productUnit->id,
                'quantity_ordered'=>$i===0 ? 1.25 : 2,'quantity_received'=>$i===0 ? .5 : 0,'buy_price'=>$i===0 ? 12000 : 3000]);
        }
        return $order;
    }

    private function exports(PurchaseOrder $order, string $name): array
    {
        $filename=str_replace('/','-',$order->po_number);
        $pdf=$this->get(route('purchase.orders.pdf',$order))->assertOk()->assertHeader('Content-Type','application/pdf');
        $this->assertStringContainsString($filename.'.pdf',$pdf->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString('PO-PO-',$pdf->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-',$pdf->getContent());
        $excel=$this->get(route('purchase.orders.excel',$order))->assertOk();
        $this->assertStringContainsString($filename.'.xlsx',$excel->headers->get('Content-Disposition'));
        $path=$excel->baseResponse->getFile()->getPathname();
        $workbook=IOFactory::load($path);
        if (getenv('CAPTURE_PO_EXPORTS')==='1') {
            $directory=storage_path('framework/testing/po-exports');
            if (!is_dir($directory)) mkdir($directory,0777,true);
            file_put_contents($directory.'/'.$name.'.pdf',$pdf->getContent());
            copy($path,$directory.'/'.$name.'.xlsx');
            (new \PhpOffice\PhpSpreadsheet\Writer\Html($workbook))->save($directory.'/'.$name.'.html');
        }
        $html=view('purchase.orders.pdf',['order'=>$order->load('supplier','createdBy','rootOrder','items.product.buyUnit','items.productUnit.unit')])->render();
        $this->assertStringNotContainsString('Status',$workbook->getActiveSheet()->getCell('A3')->getValue());
        $this->assertStringNotContainsString('>Status<',$html);
        $this->assertStringNotContainsString('Diterima Sebagian',$html);
        $this->assertStringNotContainsString('Menunggu',$html);
        $this->assertSame([['No.','Nama Barang','Satuan','Jumlah','Harga Satuan','Subtotal']],$workbook->getActiveSheet()->rangeToArray('A4:F4'));
        $this->assertSame('F',$workbook->getActiveSheet()->getHighestDataColumn());
        return [$workbook->getActiveSheet(),$html];
    }

    public function test_po_exports_use_monochrome_styles_complete_metadata_and_correct_totals(): void
    {
        $order=$this->order();
        [$sheet,$html]=$this->exports($order,'po');
        $this->assertSame('PURCHASE ORDER PO/2026/10/000001',$sheet->getCell('A1')->getValue());
        foreach (['PO/2026/10/000001','17/10/2026','CV. Supplier Uji','Jl. Merdeka','081234567890','Owner Golden'] as $text) {
            $this->assertStringContainsString($text,$sheet->getCell('A3')->getValue());
            $this->assertStringContainsString($text,$html);
        }
        $this->assertEquals(1.25,$sheet->getCell('D5')->getValue());
        $this->assertEquals(1,$sheet->getCell('A5')->getValue());
        $this->assertSame('Barang Uji 001',$sheet->getCell('B5')->getValue());
        $this->assertEquals(12000,$sheet->getCell('E5')->getValue());
        $this->assertEquals(15000,$sheet->getCell('F5')->getValue());
        $this->assertSame('TOTAL',$sheet->getCell('A7')->getValue());
        $this->assertEquals(21000,$sheet->getCell('F7')->getValue());
        $this->assertNull($sheet->getCell('E7')->getValue());
        $this->assertSame('A4:F6',$sheet->getAutoFilter()->getRange());
        $this->assertEquals(.5,$order->items()->first()->quantity_received);
        $this->assertSame('A5',$sheet->getFreezePane());
        $this->assertSame(1,$sheet->getPageSetup()->getFitToWidth());
        $this->assertSame(0,$sheet->getPageSetup()->getFitToHeight());
        $this->assertGreaterThanOrEqual(82,$sheet->getRowDimension(3)->getRowHeight());
        foreach (['A1','A3','A4','A5','F7'] as $cell) {
            $this->assertSame(Fill::FILL_NONE,$sheet->getStyle($cell)->getFill()->getFillType());
            $this->assertContains($sheet->getStyle($cell)->getFont()->getColor()->getRGB(),['111111','555555']);
        }
        $this->assertStringNotContainsString('#c8960c',$html);
        $this->assertStringNotContainsString('#1a2035',$html);
        $this->assertStringNotContainsString('Partial',$html);
        $this->assertStringContainsString('1,25',$html);
    }

    public function test_return_po_exports_show_original_po_and_no_additional_charge(): void
    {
        $root=$this->order();
        $order=PurchaseOrder::create(['po_number'=>'PO/R/2026/10/000001','supplier_id'=>$root->supplier_id,
            'order_date'=>'2026-10-17','status'=>'pending','created_by'=>$root->created_by,'root_purchase_order_id'=>$root->id]);
        $source=$root->items()->first();
        $order->items()->create(['product_id'=>$source->product_id,'product_unit_id'=>$source->product_unit_id,'quantity_ordered'=>1,'buy_price'=>12000]);
        [$sheet,$html]=$this->exports($order,'po-retur');
        $this->assertSame('PO RETUR PO/R/2026/10/000001',$sheet->getCell('A1')->getValue());
        foreach (['PO Asal: PO/2026/10/000001','PO RETUR - TANPA TAGIHAN TAMBAHAN'] as $text) {
            $this->assertStringContainsString($text,$sheet->getCell('A3')->getValue());
            $this->assertStringContainsString($text,$html);
        }
        $this->assertEquals(0,$sheet->getCell('E5')->getValue());
        $this->assertEquals(0,$sheet->getCell('F5')->getValue());
        $this->assertEquals(0,$sheet->getCell('F6')->getValue());
        $this->assertNull($sheet->getCell('E6')->getValue());
    }

    public function test_long_orders_preserve_every_item_and_print_settings(): void
    {
        $order=$this->order(65);
        [$sheet,$html]=$this->exports($order,'po-long');
        $this->assertStringContainsString('Barang Uji 065',$sheet->getCell('B69')->getValue());
        $this->assertEquals(65,$sheet->getCell('A69')->getValue());
        $this->assertSame('TOTAL',$sheet->getCell('A70')->getValue());
        $this->assertEquals(399000,$sheet->getCell('F70')->getValue());
        $this->assertStringContainsString('Barang Uji 065',$html);
        $this->assertSame('A1:F70',$sheet->getPageSetup()->getPrintArea());
        $this->assertSame([1,4],array_map('intval',$sheet->getPageSetup()->getRowsToRepeatAtTop()));
    }
}

<?php

namespace Tests\Feature;

use App\Exports\{ReportExport, ProfitLossExport};
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\{IOFactory, Cell\DataType, Style\Alignment, Style\Fill};
use Tests\TestCase;

class ReportExportFormatTest extends TestCase
{
    use RefreshDatabase;

    private string $sheetXml;

    private function sheet(ReportExport $export)
    {
        $path=tempnam(sys_get_temp_dir(),'report-format-');
        try {
            file_put_contents($path,Excel::raw($export,\Maatwebsite\Excel\Excel::XLSX));
            $zip=new \ZipArchive;
            $zip->open($path);
            $this->sheetXml=$zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();
            return IOFactory::load($path)->getActiveSheet();
        } finally { unlink($path); }
    }

    public function test_plain_excel_preserves_headers_literals_numbers_totals_and_print_settings(): void
    {
        $sheet=$this->sheet(new ReportExport('Laporan Penjualan 2026-10-01 s.d. 2026-10-16',
            ['Invoice','Pelanggan','Jumlah'],[['00123','=1+1',12000],['00124','Pelanggan dengan nama panjang',-2000]],[3]));
        $this->assertSame('Invoice',$sheet->getCell('A4')->getValue());
        $this->assertSame('00123',$sheet->getCell('A5')->getValue());
        $this->assertSame(DataType::TYPE_STRING,$sheet->getCell('B5')->getDataType());
        $this->assertSame('TOTAL',$sheet->getCell('A7')->getValue());
        $this->assertEquals(10000,$sheet->getCell('C7')->getValue());
        $this->assertSame('A4:C6',$sheet->getAutoFilter()->getRange());
        $this->assertSame('A5',$sheet->getFreezePane());
        $this->assertMatchesRegularExpression('/showGridLines="(?:0|false)"/', $this->sheetXml);
        $this->assertSame(1,$sheet->getPageSetup()->getFitToWidth());
        $this->assertSame(0,$sheet->getPageSetup()->getFitToHeight());
        $this->assertSame([1,4],array_map('intval',$sheet->getPageSetup()->getRowsToRepeatAtTop()));
        $this->assertSame('A1:C7',$sheet->getPageSetup()->getPrintArea());
        $this->assertStringContainsString('&P',$sheet->getHeaderFooter()->getOddFooter());
        $this->assertSame(Alignment::HORIZONTAL_RIGHT,$sheet->getStyle('C5')->getAlignment()->getHorizontal());
        foreach (['A1','A4','A5','C7'] as $cell) {
            $this->assertSame('111111',$sheet->getStyle($cell)->getFont()->getColor()->getRGB());
            $this->assertSame(Fill::FILL_NONE,$sheet->getStyle($cell)->getFill()->getFillType());
        }
    }

    public function test_empty_report_has_valid_ranges_and_zero_total(): void
    {
        $sheet=$this->sheet(new ReportExport('Kosong',['Barang','Nilai'],[],[2]));
        $this->assertSame('Tidak ada data sesuai filter laporan.',$sheet->getCell('A5')->getValue());
        $this->assertSame('A4:B4',$sheet->getAutoFilter()->getRange());
        $this->assertEquals(0,$sheet->getCell('B6')->getValue());
        $this->assertSame('TOTAL',$sheet->getCell('A6')->getValue());
    }

    public function test_profit_loss_results_are_not_duplicated_and_unknown_values_are_explicit(): void
    {
        $sheet=$this->sheet(new ProfitLossExport('Laba Rugi',
            [['Harga Pokok Penjualan',0,true,'total'],['Laba Kotor',null,true,'result']],[]));
        $this->assertSame('Laba Kotor',$sheet->getCell('A6')->getValue());
        $this->assertSame('Belum lengkap',$sheet->getCell('B6')->getValue());
        $this->assertSame('',$sheet->getAutoFilter()->getRange());
        $this->assertSame(6,$sheet->getHighestDataRow());
    }

    public function test_all_report_exports_remain_accessible_and_filter_metadata_is_preserved(): void
    {
        $this->actingAs(User::create(['name'=>'Owner','email'=>'report-format@example.com','password'=>'password','role'=>'owner']));
        foreach (['sale','purchase','stock','transfer','profit-loss'] as $name) {
            $this->get(route('report.'.$name.'.pdf'))->assertOk()->assertHeader('Content-Type','application/pdf');
            $this->get(route('report.'.$name.'.excel'))->assertOk();
        }
        $sheet=$this->sheet(new ReportExport('Stok',['Barang','Nilai'],[['Produk',1000]],[2],[],'Stok',['Kategori: Minuman','Cakupan: seluruh lokasi']));
        $this->assertSame('Kategori: Minuman | Cakupan: seluruh lokasi',$sheet->getCell('A3')->getValue());
    }

    public function test_report_pages_and_exported_cells_use_expiry_terminology(): void
    {
        $this->actingAs(User::create(['name'=>'Owner','email'=>'report-wording@example.com','password'=>'password','role'=>'owner']));
        $unit = \App\Models\Unit::create(['name'=>'Pcs']);
        $category = \App\Models\Category::create(['name'=>'Kategori Uji']);
        $location = \App\Models\Location::create(['name'=>'Gudang Uji']);
        $product = \App\Models\Product::create(['name'=>'Produk Uji Laporan','category_id'=>$category->id,
            'buy_unit_id'=>$unit->id,'buy_price'=>1000,'sell_price_wholesale'=>1500,'is_expirable'=>true]);
        \App\Models\Stock::create(['product_id'=>$product->id,'location_id'=>$location->id,'quantity'=>8]);
        foreach ([[today()->subDay(),3],[today()->addMonths(2),5]] as [$expiry,$quantity]) {
            \App\Models\StockBatch::create(['product_id'=>$product->id,'location_id'=>$location->id,
                'received_date'=>today(),'expired_date'=>$expiry,'quantity_initial'=>$quantity,'quantity_remaining'=>$quantity]);
        }
        foreach (['sale','purchase','stock','transfer','profit-loss','stock-expired'] as $name) {
            $report = $name === 'stock-expired' ? 'stock' : $name;
            $filters = $name === 'stock-expired' ? ['expiry_status'=>'expired'] : [];
            $page = $this->get(route('report.'.$report,$filters))->assertOk();
            $document = new \DOMDocument;
            @$document->loadHTML($page->getContent());
            $xpath = new \DOMXPath($document);
            foreach ($xpath->query('//script|//style') as $node) $node->parentNode->removeChild($node);
            $content = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " page-content ")]')->item(0);
            $this->assertNotNull($content);
            $this->assertDoesNotMatchRegularExpression('/\bbatch\b/i', $content->textContent);
            $pdf = $this->get(route('report.'.$report.'.pdf',$filters))->assertOk()->assertHeader('Content-Type','application/pdf');
            $excel = $this->get(route('report.'.$report.'.excel',$filters))->assertOk();
            $file = $excel->baseResponse->getFile()->getPathname();
            $workbook = IOFactory::load($file);
            $cells = [];
            foreach ($workbook->getAllSheets() as $sheet) {
                foreach ($sheet->toArray() as $row) {
                    foreach ($row as $value) $cells[] = (string) $value;
                }
            }
            $text = implode(' ', $cells);
            $this->assertDoesNotMatchRegularExpression('/\bbatch\b/i', $text);
            if ($report === 'stock') {
                $this->assertStringContainsString('Kedaluwarsa', $text);
                $this->assertStringContainsString('Produk Uji Laporan', $text);
            }
            $workbook->disconnectWorksheets();
            if (getenv('CAPTURE_REPORT_WORDING') === '1') {
                $directory = storage_path('framework/testing/report-wording');
                if (! is_dir($directory)) mkdir($directory,0755,true);
                file_put_contents($directory.'/'.$name.'.pdf',$pdf->getContent());
                copy($file,$directory.'/'.$name.'.xlsx');
            }
        }
        $this->assertDatabaseCount('stock_batches',2);
        $this->assertDatabaseHas('stocks',['product_id'=>$product->id,'quantity'=>8]);
    }
}

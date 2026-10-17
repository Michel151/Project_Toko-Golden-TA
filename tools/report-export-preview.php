<?php

// Synthetic, unsaved models exercise pagination and long labels without changing business data.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$out=__DIR__.'/report-export-previews';
if (!is_dir($out)) mkdir($out,0777,true);
$make=fn($class,$attrs)=> (new $class)->forceFill($attrs);
$user=$make(\App\Models\User::class,['name'=>'Petugas Contoh']);
$unit=$make(\App\Models\Unit::class,['name'=>'Botol']);
$category=$make(\App\Models\Category::class,['name'=>'Minuman dan kebutuhan sehari-hari']);
$supplier=$make(\App\Models\Supplier::class,['name'=>'PT Supplier Contoh dengan Nama Panjang']);
$fromLocation=$make(\App\Models\Location::class,['name'=>'Gudang Penyimpanan Utama']);
$toLocation=$make(\App\Models\Location::class,['name'=>'Toko']);
$sales=collect(); $orders=collect(); $products=collect(); $transfers=collect();
for($i=1;$i<=65;$i++) {
    $sale=$make(\App\Models\Sale::class,['sale_number'=>sprintf('INV-2026-%06d',$i),'sale_date'=>'2026-10-16','sale_time'=>'10:30:00','customer_name'=>$i%3 ? 'Pelanggan Umum' : 'PT Pelanggan Contoh dengan Nama Perusahaan yang Panjang','payment_method'=>$i%2?'tunai':'transfer','total_amount'=>150000,'report_cost'=>120000,'incomplete_cost'=>false]);
    $sale->setRelation('createdBy',$user); $sales->push($sale);
    $product=$make(\App\Models\Product::class,['name'=>'Produk Contoh Kemasan Botol untuk Pemeriksaan Cetak '.$i,'buy_price'=>2500,'can_retail'=>false,'min_stock_total'=>24,'is_expirable'=>false]);
    $product->setRelation('buyUnit',$unit)->setRelation('retailUnit',null)->setRelation('category',$category)->setRelation('productUnits',collect())->setRelation('stockBatches',collect())->setRelation('stocks',collect([$make(\App\Models\Stock::class,['quantity'=>125])]));
    $products->push($product);
    $item=$make(\App\Models\PurchaseOrderItem::class,['quantity_ordered'=>10,'buy_price'=>15000]);
    $order=$make(\App\Models\PurchaseOrder::class,['po_number'=>sprintf('PO-2026-%06d',$i),'order_date'=>'2026-10-16','status'=>'completed']);
    $order->setRelation('supplier',$supplier)->setRelation('items',collect([$item]));$orders->push($order);
    $transfer=$make(\App\Models\StockTransfer::class,['transfer_number'=>sprintf('TRF-2026-%06d',$i),'transfer_date'=>'2026-10-16']);
    $transferItem=$make(\App\Models\StockTransferItem::class,['quantity'=>20]);$transferItem->setRelation('product',$product);
    $transfer->setRelation('items',collect([$transferItem]))->setRelation('createdBy',$user)->setRelation('fromLocation',$fromLocation)->setRelation('toLocation',$toLocation);$transfers->push($transfer);
}
$data=compact('sales','orders','products','transfers')+['from'=>'2026-10-01','to'=>'2026-10-16','reportTitle'=>'Laporan Stok Toko Golden','expiryFilterLabel'=>null,'transactionCount'=>65,'totalRevenue'=>9750000,'totalCost'=>7800000,'incompleteCount'=>0,'grossProfit'=>1950000,'grossMargin'=>20];
$excelRows=[
    'sale'=>['Laporan Penjualan',['Invoice','Tanggal','Pelanggan','Pembayaran','Kasir','Total'],$sales->map(fn($s)=>[$s->sale_number,'16/10/2026',$s->customer_name,ucfirst($s->payment_method),$user->name,150000])->all(),[6]],
    'purchase'=>['Laporan Pembelian',['No. PO','Tanggal','Supplier','Status','Jumlah Item','Dibuat Oleh','Nilai PO'],$orders->map(fn($o)=>[$o->po_number,'16/10/2026',$supplier->name,'Completed',1,$user->name,150000])->all(),[7]],
    'stock'=>['Laporan Stok',['Barang','Kategori','Satuan Dasar','Total Fisik','Layak Jual','Kedaluwarsa','Min. Stok','Nilai Stok','Status'],$products->map(fn($p)=>[$p->name,$category->name,'Botol','125 Botol','125 Botol','0 Botol','24 Botol',312500,'Aman'])->all(),[8]],
    'transfer'=>['Laporan Transfer',['No. Transfer','Tanggal','Barang','Dari','Ke','Jumlah','Satuan Stok','Petugas','Catatan'],$transfers->map(fn($t)=>[$t->transfer_number,'16/10/2026',$products->first()->name,$fromLocation->name,'Toko',20,'Botol',$user->name,'Pemindahan stok untuk kebutuhan operasional toko'])->all(),[]],
    'gross-profit'=>['Laporan Laba Kotor',['Invoice','Tanggal','Waktu','Pelanggan','Kasir','Penjualan','HPP Tercatat','Laba Kotor','Margin','Kelengkapan HPP'],$sales->map(fn($s)=>[$s->sale_number,'16/10/2026','10:30:00 WIB',$s->customer_name,$user->name,150000,120000,30000,'20,00%','Lengkap'])->all(),[6,7,8]],
];
foreach($excelRows as $name=>[$title,$headings,$rows,$money]) {
    \Barryvdh\DomPDF\Facade\Pdf::loadView('report.pdf.'.$name,$data)->setPaper('a4',in_array($name,['stock','transfer','gross-profit'])?'landscape':'portrait')->save($out.'/'.$name.'.pdf');
    $export=new \App\Exports\ReportExport($title.' | 01/10/2026 s.d. 16/10/2026',$headings,$rows,$money,[],ucfirst($name));
    file_put_contents($out.'/'.$name.'.xlsx',\Maatwebsite\Excel\Facades\Excel::raw($export,\Maatwebsite\Excel\Excel::XLSX));
    echo $name.': PDF and XLSX exported (65 synthetic rows)'.PHP_EOL;
}

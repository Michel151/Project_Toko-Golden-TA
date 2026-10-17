<?php

namespace App\Exports;

use App\Models\PurchaseOrder;
use Maatwebsite\Excel\Events\AfterSheet;

class PurchaseOrderExport extends ReportExport
{
    public function __construct(PurchaseOrder $order)
    {
        $rows=$order->items->values()->map(function ($item, $index) use ($order) {
            $ordered=(float) $item->quantity_ordered;
            $price=$order->root_purchase_order_id ? 0 : (float) $item->buy_price;
            return [$index+1, $item->product->name,
                $item->productUnit?->unit?->name ?? $item->product->buyUnit?->name ?? '-',
                $ordered, $price, $ordered*$price];
        })->all();
        $rows[]=['TOTAL',null,null,null,null,$order->totalValue()];
        $metadata=[
            'Nomor PO: '.$order->po_number.' | Tanggal PO: '.($order->order_date?->format('d/m/Y') ?? '-'),
            'Supplier: '.($order->supplier?->name ?? '-'),
            'Alamat: '.($order->supplier?->address ?: '-'),
            'Telepon: '.($order->supplier?->phone ?: '-'),
            'Dibuat oleh: '.($order->createdBy?->name ?? '-'),
        ];
        if ($order->root_purchase_order_id) {
            $metadata[]='PO Asal: '.($order->rootOrder?->po_number ?? '-');
            $metadata[]='PO RETUR - TANPA TAGIHAN TAMBAHAN';
        }
        parent::__construct(($order->root_purchase_order_id ? 'PO RETUR ' : 'PURCHASE ORDER ').$order->po_number,
            ['No.','Nama Barang','Satuan','Jumlah','Harga Satuan','Subtotal'],
            $rows,[5,6],[4],$order->root_purchase_order_id ? 'PO Retur' : 'Purchase Order',$metadata);
    }

    public function array(): array
    {
        $rows=parent::array();
        $rows[2]=[implode("\n",$this->metadata)];
        return $rows;
    }

    public function registerEvents(): array
    {
        $format=parent::registerEvents()[AfterSheet::class];
        return [AfterSheet::class=>function (AfterSheet $event) use ($format) {
            $format($event);
            $sheet=$event->sheet->getDelegate();
            $lines=array_sum(array_map(fn ($line)=>max(1,(int) ceil(mb_strlen($line)/100)),$this->metadata));
            $sheet->getRowDimension(3)->setRowHeight($lines*14+12);
            $sheet->getStyle('D5:D'.$sheet->getHighestDataRow())->getNumberFormat()->setFormatCode('#,##0.####');
            $sheet->getColumnDimension('A')->setWidth(9);
            $sheet->getColumnDimension('B')->setWidth(40);
        }];
    }
}

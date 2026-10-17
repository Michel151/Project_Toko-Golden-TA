@extends('report.pdf.layout')
@section('report-title', $order->root_purchase_order_id ? 'PO RETUR' : 'PURCHASE ORDER')
@section('report-period', 'Kota Weda, Halmahera Tengah')
@section('report-body')
<style>
    .order-info { width:100%; border-collapse:collapse; table-layout:fixed; margin-bottom:16px; }
    .order-info td { vertical-align:top; padding:3px 0; word-wrap:break-word; }
    .order-info .label-cell { width:15%; color:#555555; }
    .order-info .value-cell { width:35%; padding-right:16px; }
    .signatures { width:100%; table-layout:fixed; border-collapse:collapse; margin-top:28px; page-break-inside:avoid; }
    .signatures td { width:50%; text-align:center; vertical-align:top; padding:0 24px; }
    .signature-space { height:52px; }
    .signature-name { border-top:1px solid #777777; padding-top:5px; }
</style>
<table class="order-info">
    <tr><td class="label-cell">Nomor PO</td><td class="value-cell"><strong>{{ $order->po_number }}</strong></td><td class="label-cell">Supplier</td><td class="value-cell"><strong>{{ $order->supplier?->name ?? '-' }}</strong></td></tr>
    <tr><td class="label-cell">Tanggal PO</td><td class="value-cell">{{ $order->order_date?->format('d/m/Y') ?? '-' }}</td><td class="label-cell">Telepon</td><td class="value-cell">{{ $order->supplier?->phone ?: '-' }}</td></tr>
    <tr><td class="label-cell">Dibuat oleh</td><td class="value-cell">{{ $order->createdBy?->name ?? '-' }}</td><td class="label-cell">Alamat</td><td class="value-cell">{{ $order->supplier?->address ?: '-' }}</td></tr>
</table>
@if($order->root_purchase_order_id)
<div class="notice"><strong>PO RETUR - TANPA TAGIHAN TAMBAHAN</strong><br>PO Asal: {{ $order->rootOrder?->po_number ?? '-' }}</div>
@endif
<table class="data compact">
    <thead><tr>
        <th style="width:5%">No.</th><th style="width:35%">Nama Barang</th><th style="width:10%">Satuan</th>
        <th style="width:12%" class="right">Jumlah</th><th style="width:18%" class="right">Harga Satuan</th><th style="width:20%" class="right">Subtotal</th>
    </tr></thead>
    <tbody>
    @forelse($order->items as $item)
        @php
            $price = $order->root_purchase_order_id ? 0 : (float) $item->buy_price;
            $quantity = rtrim(rtrim(number_format($item->quantity_ordered, 4, ',', '.'), '0'), ',');
        @endphp
        <tr><td>{{ $loop->iteration }}</td><td>{{ $item->product->name }}</td>
            <td>{{ $item->productUnit?->unit?->name ?? $item->product->buyUnit?->name ?? '-' }}</td>
            <td class="right">{{ $quantity }}</td><td class="right">Rp {{ number_format($price, 0, ',', '.') }}</td>
            <td class="right">Rp {{ number_format($item->quantity_ordered * $price, 0, ',', '.') }}</td></tr>
    @empty
        <tr><td colspan="6" class="center">Tidak ada barang pada dokumen ini.</td></tr>
    @endforelse
    </tbody>
    <tfoot><tr><td colspan="5" class="right">TOTAL NILAI PESANAN</td><td class="right">Rp {{ number_format($order->totalValue(), 0, ',', '.') }}</td></tr></tfoot>
</table>
<table class="signatures"><tr>
    <td><div>Dibuat oleh,</div><div class="signature-space"></div><div class="signature-name">{{ $order->createdBy?->name ?? 'Toko Golden' }}</div><div class="muted">Toko Golden</div></td>
    <td><div>Diterima oleh,</div><div class="signature-space"></div><div class="signature-name">Nama dan tanda tangan</div><div class="muted">Supplier</div></td>
</tr></table>
@endsection

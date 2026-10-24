@extends('report.pdf.layout')
@section('report-title')Laporan Pembelian
@endsection
@section('report-period')Periode {{ \Carbon\Carbon::parse($from)->format('d/m/Y') }} s.d. {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }}
@endsection
@section('report-body')
@if($supplierName ?? null)<div style="margin-bottom:10px">Supplier: {{ $supplierName }}</div>@endif
<table class="data"><thead><tr><th style="width:20%">No. PO</th><th style="width:14%">Tanggal</th><th style="width:28%">Supplier</th><th class="center" style="width:10%">Jumlah Item</th><th class="center" style="width:12%">Status</th><th class="right" style="width:16%">Nilai PO</th></tr></thead><tbody>
@php $total=0; @endphp
@forelse($orders as $order)@php $value=$order->totalValue();$total+=$value; @endphp<tr><td>{{ $order->po_number }}</td><td>{{ $order->order_date->format('d/m/Y') }}</td><td>{{ $order->supplier->name }}</td><td class="center">{{ $order->items->count() }}</td><td class="center status">{{ $order->status_label }}</td><td class="right">Rp {{ number_format($value,0,',','.') }}</td></tr>@empty<tr><td colspan="6" class="center">Tidak ada data</td></tr>@endforelse
</tbody><tfoot><tr><td colspan="5">TOTAL PEMBELIAN</td><td class="right">Rp {{ number_format($total,0,',','.') }}</td></tr></tfoot></table>

@endsection

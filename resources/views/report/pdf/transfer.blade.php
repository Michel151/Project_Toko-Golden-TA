@extends('report.pdf.layout')
@section('report-title')Laporan Transfer Stok
@endsection
@section('report-period')Periode {{ \Carbon\Carbon::parse($from)->format('d/m/Y') }} s.d. {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }}
@endsection
@section('report-body')
<table class="data"><thead><tr><th style="width:15%">No. Transfer</th><th style="width:10%">Tanggal</th><th style="width:25%">Barang</th><th style="width:14%">Dari</th><th style="width:10%">Ke</th><th class="right" style="width:13%">Jumlah</th><th style="width:13%">Petugas</th></tr></thead><tbody>
@forelse($transfers as $transfer)
@foreach($transfer->items as $item)
<tr><td>{{ $transfer->transfer_number }}</td><td>{{ $transfer->transfer_date->format('d/m/Y') }}</td><td>{{ $item->product->name }}</td><td class="from">{{ $transfer->fromLocation->name }}</td><td class="to">{{ $transfer->toLocation->name }}</td><td class="right">{{ $item->product->displayStock((float)$item->quantity) }}</td><td>{{ $transfer->createdBy->name ?? '-' }}</td></tr>
@endforeach
@empty
<tr><td colspan="7" class="center">Tidak ada data</td></tr>
@endforelse
</tbody></table>

@endsection

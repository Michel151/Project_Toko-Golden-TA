@extends('report.pdf.layout')
@section('report-header-centered', '1')
@section('report-title', 'Hasil Stock Opname')
@section('report-period'){{ $reportPeriod }}@endsection
@section('report-body')
<table class="data compact">
    <thead><tr>
        <th style="width:25%">{{ $headings[0] }}</th>
        <th style="width:14%">{{ $headings[1] }}</th>
        <th class="right" style="width:11%">{{ $headings[2] }}</th>
        <th class="right" style="width:11%">{{ $headings[3] }}</th>
        <th class="right" style="width:11%">{{ $headings[4] }}</th>
        <th style="width:28%">{{ $headings[5] }}</th>
    </tr></thead>
    <tbody>
    @forelse($rows as [$name, $expiry, $system, $physical, $difference, $notes])
        <tr @if($expiry !== '') style="background:#f4f4f4" @endif>
            <td>@if($expiry === '')<strong>{{ $name }}</strong>@else{{ $name }}@endif</td>
            <td>{{ $expiry === '' ? '-' : $expiry }}</td>
            <td class="right">{{ $system }}</td>
            <td class="right">{{ $physical }}</td>
            <td class="right">{{ $difference }}</td>
            <td style="white-space:pre-wrap">{{ $notes }}</td>
        </tr>
    @empty
        <tr><td colspan="6" class="center">Tidak ada barang yang sesuai filter.</td></tr>
    @endforelse
    </tbody>
</table>
@endsection

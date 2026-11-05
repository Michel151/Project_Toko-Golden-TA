@extends('report.pdf.layout')
@section('report-header-centered', '1')
@section('report-title'){{ $title }}@endsection
@section('report-period')Periode {{ \Carbon\Carbon::parse($from)->format('d/m/Y') }} s.d. {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }}@endsection
@section('report-body')
@foreach($metadata as $line)<div class="summary-line">{{ $line }}</div>@endforeach
<table class="data"><thead><tr>
    @foreach($headings as $index=>$heading)<th class="{{ in_array($index+1, array_merge($currencyColumns, $decimalColumns), true) ? 'right' : '' }}">{{ $heading }}</th>@endforeach
</tr></thead><tbody>
@forelse($rows as $row)
<tr>@foreach($row as $index=>$value)
    <td class="{{ in_array($index+1, array_merge($currencyColumns, $decimalColumns), true) ? 'right' : '' }}">@if(is_numeric($value) && in_array($index+1, $currencyColumns, true))Rp {{ number_format($value,0,',','.') }}@elseif(is_numeric($value) && in_array($index+1, $decimalColumns, true)){{ number_format($value,2,',','.') }}%@else{{ $value }}@endif</td>
@endforeach</tr>
@empty<tr><td colspan="{{ count($headings) }}" class="center">Tidak ada penjualan produk sesuai filter.</td></tr>@endforelse
</tbody>
@if($filePrefix === 'Laporan-Produk-Terlaris')
<tfoot><tr><td colspan="3">TOTAL</td><td class="right">Rp {{ number_format(array_sum(array_column($rows,3)),0,',','.') }}</td></tr></tfoot>
@endif
</table>
@endsection

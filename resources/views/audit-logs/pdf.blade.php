@extends('report.pdf.layout')
@section('report-header-centered', '1')
@section('report-title', 'Audit Log Sistem')
@section('report-period')Total aktivitas: {{ count($rows) }}@endsection
@section('report-body')
<table class="data compact">
    <thead><tr>
        @foreach([14, 13, 10, 12, 10, 25, 16] as $index => $width)
            <th style="width:{{ $width }}%">{{ $headings[$index] }}</th>
        @endforeach
    </tr></thead>
    <tbody>
    @forelse($rows as $row)
        <tr>
            @foreach($row as $value)<td>{{ $value }}</td>@endforeach
        </tr>
    @empty
        <tr><td colspan="7" class="center">Tidak ada aktivitas sesuai filter.</td></tr>
    @endforelse
    </tbody>
</table>
@endsection

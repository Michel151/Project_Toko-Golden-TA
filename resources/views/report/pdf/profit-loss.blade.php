@extends('report.pdf.layout')
@section('report-title', 'Laporan Laba Rugi')
@section('report-period', $periodLabel.' | '.\Carbon\Carbon::parse($from)->format('d/m/Y').' s.d. '.\Carbon\Carbon::parse($to)->format('d/m/Y'))
@section('report-body')
<div class="meta">Nominal disajikan dalam rupiah penuh, dibulatkan ke rupiah terdekat.</div>
@if($incompleteCount)<div class="notice">Catatan: HPP pada {{ $incompleteCount }} transaksi penjualan belum lengkap; laba belum dapat ditentukan secara lengkap.</div>@endif
@if(!$taxSummary['complete'])<div class="notice">Catatan: Beban pajak belum dikonfirmasi untuk {{ implode(', ', $taxSummary['missing']) }}; laba bersih belum dapat ditentukan.</div>@endif
<style>
.financial-lines td { padding: 3px 5px; }
.financial-lines .pl-section th { background: #eef2ed; font-size: 10px; padding: 4px 5px; }
.financial-lines .fw-bold td, .financial-lines .pl-total td, .financial-lines .pl-result td { font-weight: bold; }
.financial-lines .pl-total td { border-top: 1px solid #777777; }
.financial-lines .pl-result td { border-top: 1px solid #333333; border-bottom: 2px double #333333; }
.reconciliation-page { page-break-before: always; }
.reconciliation-page h2 { font-weight: bold; font-size: 14px; margin: 0 0 6px; }
.reconciliation-page .meta { margin-bottom: 12px; }
</style>
<table class="data financial-lines"><thead><tr><th style="width:70%">Uraian</th><th class="right">Nilai (Rp)</th></tr></thead><tbody>@include('report._financial-lines')</tbody></table>
<div class="reconciliation-page">
    <h2>Rekonsiliasi Persediaan</h2>
    <div class="meta">{{ $periodLabel }} | {{ $reconciliation['statusLabel'] }}</div>
    <div class="notice">@foreach($reconciliation['notes'] as $note)<div>{{ $note }}</div>@endforeach</div>
    <table class="data financial-lines"><thead><tr><th style="width:70%">Uraian</th><th class="right">Nilai (Rp)</th></tr></thead><tbody>@include('report._financial-lines',['lines'=>$reconciliation['lines'],'missing'=>'Belum tersedia'])</tbody></table>
</div>
@endsection

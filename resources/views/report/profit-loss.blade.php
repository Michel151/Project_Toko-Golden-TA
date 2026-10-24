@extends('layouts.app')
@section('title', 'Laporan Laba Rugi')
@section('page-title', 'Laporan Laba Rugi')
@section('content')
<div class="report-heading mb-3"><h5 class="fw-bold mb-0"><i class="bi bi-calculator me-2"></i>Laporan Laba Rugi</h5></div>
<div class="report-toolbar d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
    <form method="GET" class="d-flex flex-wrap align-items-end gap-2">
        <div><label for="plPeriod" class="form-label small">Periode</label><select id="plPeriod" name="period_type" class="form-select form-select-sm"><option value="monthly" @selected($periodType === 'monthly')>Bulanan</option><option value="quarterly" @selected($periodType === 'quarterly')>Kuartalan</option><option value="yearly" @selected($periodType === 'yearly')>Tahunan</option></select></div>
        <div data-pl-period="monthly" @if($periodType !== 'monthly') hidden @endif><label for="plMonth" class="form-label small">Bulan</label><input id="plMonth" name="month" type="month" class="form-control form-control-sm" value="{{ $month }}" @disabled($periodType !== 'monthly') @required($periodType === 'monthly')></div>
        <div data-pl-period="quarterly" @if($periodType !== 'quarterly') hidden @endif><label for="plQuarter" class="form-label small">Kuartal</label><select id="plQuarter" name="quarter" class="form-select form-select-sm" @disabled($periodType !== 'quarterly')>@foreach([1=>'Kuartal 1 (Jan-Mar)',2=>'Kuartal 2 (Apr-Jun)',3=>'Kuartal 3 (Jul-Sep)',4=>'Kuartal 4 (Okt-Des)'] as $key=>$label)<option value="{{ $key }}" @selected($quarter === $key)>{{ $label }}</option>@endforeach</select></div>
        <div data-pl-period="quarterly yearly" @if($periodType === 'monthly') hidden @endif><label for="plYear" class="form-label small">Tahun</label><input id="plYear" name="year" type="number" min="1900" max="9999" step="1" class="form-control form-control-sm" style="width:110px" value="{{ $year }}" @disabled($periodType === 'monthly') @required($periodType !== 'monthly')></div>
        <button class="btn btn-sm btn-success"><i class="bi bi-funnel me-1"></i>Terapkan</button><a href="{{ route('report.profit-loss') }}" class="btn btn-sm btn-outline-secondary">Bulan Ini</a>
    </form>
    <div class="btn-group btn-group-sm"><a class="btn btn-outline-danger" href="{{ route('report.profit-loss.pdf',$exportFilters) }}" target="_blank" rel="noopener"><i class="bi bi-file-pdf me-1"></i>PDF</a><a class="btn btn-outline-success" href="{{ route('report.profit-loss.excel',$exportFilters) }}"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a></div>
</div>
<div class="small text-muted mb-3"><strong>{{ $periodLabel }}</strong> | {{ \Carbon\Carbon::parse($from)->format('d/m/Y') }} s.d. {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }}</div>
<div class="alert alert-light border small">{{ $notice }}</div>
@if(!$taxSummary['complete'])<div class="alert alert-warning">Pajak belum dikonfirmasi untuk seluruh periode. Laba bersih belum dapat ditentukan. <a href="{{ route('taxes.index',$exportFilters) }}">Periksa pencatatan pajak</a></div>@endif
@if($incompleteCount)<div class="alert alert-warning" role="alert">{{ $incompleteCount }} nota memiliki HPP belum lengkap. Laba belum dapat ditentukan.</div>@endif
@if($draftCount)<div class="alert alert-warning" role="alert">{{ $draftCount }} draft beban pada periode ini belum dihitung. <a href="{{ route('expenses.index',$exportFilters + ['status'=>'draft']) }}">Periksa draft</a></div>@endif
@include('components.report-kpis',['metrics'=>[
['icon'=>'cash-stack','tone'=>'success','label'=>'Penjualan Lunas','value'=>'Rp '.number_format($revenue,2,',','.'),'hint'=>'Sesuai tanggal transaksi'],
['icon'=>'calculator','tone'=>'primary','label'=>'Laba Kotor','value'=>$grossProfit === null ? 'Belum lengkap' : 'Rp '.number_format($grossProfit,2,',','.'),'hint'=>'Penjualan dikurangi HPP'],
['icon'=>'journal-minus','tone'=>'warning','label'=>'Beban Operasional','value'=>'Rp '.number_format($expenseTotal,2,',','.'),'hint'=>'Beban terkonfirmasi'],
['icon'=>'graph-up','tone'=>'info','label'=>'Laba (Rugi) Bersih','value'=>$netProfit === null ? 'Belum lengkap' : 'Rp '.number_format($netProfit,2,',','.'),'hint'=>'Laba sebelum pajak dikurangi beban pajak terkonfirmasi'],
]])
<section class="bg-white border mb-3">
    <h6 class="fw-bold p-3 mb-0">Ringkasan Laba Rugi</h6>
    <div class="table-responsive"><table class="table financial-lines mb-0"><thead><tr><th>Uraian</th><th class="text-end">Nilai (Rp)</th></tr></thead><tbody>@include('report._financial-lines')</tbody></table></div>
</section>
<section class="bg-white border mb-3" id="inventoryReconciliation">
    <div class="p-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h6 class="fw-bold mb-0">Rekonsiliasi Persediaan</h6>
        <span class="badge {{ ['matched'=>'bg-success','partial'=>'bg-warning text-dark','mismatch'=>'bg-danger','unavailable'=>'bg-secondary'][$reconciliation['status']] }}">{{ $reconciliation['statusLabel'] }}</span>
    </div>
    <div class="px-3 pb-3 small text-muted">@foreach($reconciliation['notes'] as $note)<div class="mb-1">{{ $note }}</div>@endforeach</div>
    <div class="table-responsive"><table class="table financial-lines mb-0"><thead><tr><th>Uraian</th><th class="text-end">Nilai (Rp)</th></tr></thead><tbody>@include('report._financial-lines',['lines'=>$reconciliation['lines'],'missing'=>'Belum tersedia'])</tbody></table></div>
</section>
<section class="bg-white border"><div class="p-3 d-flex flex-wrap justify-content-between gap-2"><h6 class="fw-bold mb-0">Rincian Beban Terkonfirmasi</h6><a href="{{ route('expenses.index',$exportFilters + ['status'=>'confirmed']) }}">Pencatatan Beban</a></div><div class="table-responsive"><table class="table report-table mb-0"><thead><tr><th>Tanggal Pengakuan</th><th>Nomor</th><th>Kategori / Keterangan</th><th>Pembayaran</th><th class="text-end">Nominal</th></tr></thead><tbody>@forelse($expenses as $expense)<tr><td class="text-nowrap">{{ $expense->recognized_date->format('d/m/Y') }}</td><td><a href="{{ route('expenses.show',$expense) }}">{{ $expense->number }}</a></td><td>{{ \App\Models\Expense::CATEGORIES[$expense->category] }}<div class="small text-muted text-break">{{ $expense->description }}</div></td><td>{{ $expense->paid_date ? 'Sudah dibayar' : 'Belum dibayar' }}</td><td class="text-end text-nowrap">Rp {{ number_format($expense->amount,2,',','.') }}</td></tr>@empty<tr><td colspan="5" class="text-center text-muted py-4">Tidak ada beban terkonfirmasi pada periode ini.</td></tr>@endforelse</tbody></table></div><div class="p-3">{{ $expenses->links() }}</div></section>
@endsection
@push('styles')
<style>
.financial-lines > :not(caption) > * > * { padding: .65rem 1rem; }
.financial-lines th:last-child:not([colspan]), .financial-lines td:last-child { width: 30%; min-width: 150px; }
.financial-lines .pl-section th { background: #eef2ed; color: #243228; font-size: .8rem; padding-top: .7rem; padding-bottom: .7rem; }
.financial-lines .pl-total td { border-top: 1px solid #b9c4bc; font-weight: 700; }
.financial-lines .pl-result td { background: #edf4ef; font-weight: 700; border-bottom: 3px double #62776a; }
</style>
@endpush
@push('scripts')
<script>
(() => {
    const period = document.getElementById('plPeriod');
    const refresh = () => document.querySelectorAll('[data-pl-period]').forEach(group => {
        const active = group.dataset.plPeriod.split(' ').includes(period.value);
        group.hidden = !active;
        group.querySelectorAll('input,select').forEach(field => { field.disabled = !active; field.required = active; });
    });
    period.addEventListener('change', refresh);
    refresh();
})();
</script>
@endpush

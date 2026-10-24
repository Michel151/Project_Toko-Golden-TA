@extends('layouts.app')
@section('title', 'Pencatatan Beban')
@section('page-title', 'Pencatatan Beban')
@section('content')
<div class="inventory-heading d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
    <h5><i class="bi bi-journal-minus me-2"></i>Pencatatan Beban</h5>
    <a class="btn btn-sm btn-success" href="{{ route('expenses.create') }}"><i class="bi bi-plus-circle me-1"></i>Tambah Beban</a>
</div>
<form class="report-toolbar d-flex flex-wrap align-items-end gap-2 mb-3" method="GET">
    <div><label class="form-label small" for="expensePeriod">Periode</label><select id="expensePeriod" name="period_type" class="form-select form-select-sm"><option value="monthly" @selected($periodType === 'monthly')>Bulanan</option><option value="quarterly" @selected($periodType === 'quarterly')>Kuartalan</option><option value="yearly" @selected($periodType === 'yearly')>Tahunan</option></select></div>
    <div data-expense-period="monthly" @if($periodType !== 'monthly') hidden @endif><label class="form-label small" for="expenseMonth">Bulan</label><input class="form-control form-control-sm" id="expenseMonth" type="month" name="month" value="{{ $month }}" @disabled($periodType !== 'monthly') @required($periodType === 'monthly')></div>
    <div data-expense-period="quarterly" @if($periodType !== 'quarterly') hidden @endif><label class="form-label small" for="expenseQuarter">Kuartal</label><select id="expenseQuarter" name="quarter" class="form-select form-select-sm" @disabled($periodType !== 'quarterly')>@foreach([1=>'Kuartal 1 (Jan-Mar)',2=>'Kuartal 2 (Apr-Jun)',3=>'Kuartal 3 (Jul-Sep)',4=>'Kuartal 4 (Okt-Des)'] as $key=>$label)<option value="{{ $key }}" @selected($quarter === $key)>{{ $label }}</option>@endforeach</select></div>
    <div data-expense-period="quarterly yearly" @if($periodType === 'monthly') hidden @endif><label class="form-label small" for="expenseYear">Tahun</label><input id="expenseYear" name="year" type="number" min="1900" max="9999" step="1" class="form-control form-control-sm" style="width:110px" value="{{ $year }}" @disabled($periodType === 'monthly') @required($periodType !== 'monthly')></div>
    <div><label class="form-label small" for="expenseCategory">Kategori</label><select class="form-select form-select-sm" id="expenseCategory" name="category"><option value="">Semua kategori</option>@foreach(\App\Models\Expense::CATEGORIES as $key => $label)<option value="{{ $key }}" @selected(($filters['category'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></div>
    <div><label class="form-label small" for="expenseStatus">Status</label><select class="form-select form-select-sm" id="expenseStatus" name="status"><option value="">Semua status</option>@foreach(\App\Models\Expense::STATUSES as $key => $label)<option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></div>
    <div><label class="form-label small" for="expensePayment">Pembayaran</label><select class="form-select form-select-sm" id="expensePayment" name="payment"><option value="">Semua pembayaran</option><option value="paid" @selected(($filters['payment'] ?? '') === 'paid')>Sudah dibayar</option><option value="unpaid" @selected(($filters['payment'] ?? '') === 'unpaid')>Belum dibayar</option></select></div>
    <div><label class="form-label small" for="expenseSearch">Keterangan</label><input class="form-control form-control-sm" id="expenseSearch" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}"></div>
    <button class="btn btn-sm btn-success"><i class="bi bi-funnel me-1"></i>Terapkan</button><a href="{{ route('expenses.index') }}" class="btn btn-sm btn-outline-secondary" title="Reset filter" aria-label="Reset filter"><i class="bi bi-arrow-counterclockwise"></i></a>
</form>
<div class="small text-muted mb-3"><strong>{{ $periodLabel }}</strong> | {{ \Carbon\Carbon::parse($from)->format('d/m/Y') }} s.d. {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }}</div>
<div class="d-flex flex-wrap justify-content-between gap-2 mb-3"><span>{{ $expenses->total() }} catatan beban</span><span>Beban terkonfirmasi sesuai filter: <strong>Rp {{ number_format($total,2,',','.') }}</strong></span></div>
<div class="table-responsive"><table class="table table-hover report-table bg-white"><thead><tr><th>Nomor / Tanggal Pengakuan</th><th>Kategori / Keterangan</th><th class="text-end">Nominal</th><th>Status</th><th>Pembayaran</th><th>Aksi</th></tr></thead><tbody>
@forelse($expenses as $expense)
<tr><td class="text-nowrap">{{ $expense->number }}<div class="small text-muted">{{ $expense->recognized_date->format('d/m/Y') }}</div></td><td>{{ \App\Models\Expense::CATEGORIES[$expense->category] }}<div class="small text-muted text-break">{{ $expense->description }}</div></td><td class="text-end text-nowrap">Rp {{ number_format($expense->amount,2,',','.') }}</td><td><span class="badge bg-{{ $expense->status === 'confirmed' ? 'success' : ($expense->status === 'void' ? 'secondary' : 'warning text-dark') }}">{{ \App\Models\Expense::STATUSES[$expense->status] }}</span></td><td>{{ $expense->paid_date ? 'Sudah dibayar' : 'Belum dibayar' }}@if($expense->paid_date)<div class="small text-muted">{{ $expense->paid_date->format('d/m/Y') }}</div>@endif</td><td><a class="btn btn-sm btn-outline-success" href="{{ route('expenses.show',$expense) }}" title="Detail beban" aria-label="Detail beban"><i class="bi bi-eye"></i></a></td></tr>
@empty<tr><td colspan="6" class="text-center text-muted py-4">Belum ada catatan beban sesuai filter.</td></tr>@endforelse
</tbody></table></div>
{{ $expenses->links() }}
@endsection
@push('scripts')
<script>
(() => {
    const period = document.getElementById('expensePeriod');
    const refresh = () => document.querySelectorAll('[data-expense-period]').forEach(group => {
        const active = group.dataset.expensePeriod.split(' ').includes(period.value);
        group.hidden = !active;
        group.querySelectorAll('input,select').forEach(field => { field.disabled = !active; field.required = active; });
    });
    period.addEventListener('change', refresh);
    refresh();
})();
</script>
@endpush

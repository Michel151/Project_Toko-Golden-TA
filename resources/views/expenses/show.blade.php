@extends('layouts.app')
@section('title', 'Detail Beban')
@section('page-title', 'Detail Beban')
@section('content')
<div class="mb-3"><x-back-link :fallback="route('expenses.index')" /></div>
<div class="inventory-heading d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><h5>{{ $expense->number }}</h5><span class="badge bg-{{ $expense->status === 'confirmed' ? 'success' : 'secondary' }}">{{ \App\Models\Expense::STATUSES[$expense->status] }}</span></div>
<div class="bg-white border p-3 mb-3">
<dl class="row mb-0">
    <dt class="col-sm-4">Tanggal Pengakuan</dt><dd class="col-sm-8">{{ $expense->recognized_date->format('d/m/Y') }}</dd>
    <dt class="col-sm-4">Kategori</dt><dd class="col-sm-8">{{ \App\Models\Expense::CATEGORIES[$expense->category] }}</dd>
    <dt class="col-sm-4">Nominal</dt><dd class="col-sm-8 fw-bold">Rp {{ number_format($expense->amount,2,',','.') }}</dd>
    <dt class="col-sm-4">Keterangan</dt><dd class="col-sm-8 text-break">{{ $expense->description }}</dd>
    <dt class="col-sm-4">Pembayaran</dt><dd class="col-sm-8">{{ $expense->paid_date ? 'Sudah dibayar' : 'Belum dibayar' }}@if($expense->paid_date) - {{ $expense->paid_date->format('d/m/Y') }} ({{ $expense->payment_method === 'cash' ? 'Tunai' : 'Transfer' }})@endif</dd>
    <dt class="col-sm-4">Dibuat Oleh</dt><dd class="col-sm-8">{{ $expense->createdBy?->name }} - {{ $expense->created_at->format('d/m/Y H:i') }}</dd>
    @if($expense->confirmed_at)<dt class="col-sm-4">Dikonfirmasi Oleh</dt><dd class="col-sm-8">{{ $expense->confirmedBy?->name }} - {{ $expense->confirmed_at->format('d/m/Y H:i') }}</dd>@endif
    @if($expense->proof_path)<dt class="col-sm-4">Bukti Transaksi</dt><dd class="col-sm-8"><a href="{{ route('expenses.proof',$expense) }}"><i class="bi bi-download me-1"></i>Unduh Bukti</a></dd>@endif
    @if($expense->status === 'void')<dt class="col-sm-4">Alasan Pembatalan</dt><dd class="col-sm-8 text-break">{{ $expense->void_reason }} - {{ $expense->voided_at->format('d/m/Y H:i') }}</dd>@endif
</dl>
</div>
<div class="d-flex flex-wrap gap-2 mb-3">
@if($expense->status === 'draft')
<a class="btn btn-outline-success" href="{{ route('expenses.edit',$expense) }}"><i class="bi bi-pencil me-1"></i>Ubah Draf</a>
<form method="POST" action="{{ route('expenses.confirm',$expense) }}">@csrf<input type="hidden" name="version" value="{{ $expense->version }}"><button class="btn btn-success"><i class="bi bi-check-circle me-1"></i>Konfirmasi Beban</button></form>
@endif
<a href="{{ route('report.profit-loss',['period_type'=>'monthly','month'=>$expense->recognized_date->format('Y-m')]) }}" class="btn btn-outline-success"><i class="bi bi-calculator me-1"></i>Laporan Laba Rugi</a>
</div>
@if($expense->status === 'confirmed' && !$expense->paid_date)
<section class="bg-white border p-3 mb-3"><h6 class="fw-bold">Catat Pelunasan</h6><form method="POST" action="{{ route('expenses.pay',$expense) }}" class="row g-3 align-items-end">@csrf<input type="hidden" name="version" value="{{ $expense->version }}"><div class="col-md-4"><label for="payDate" class="form-label">Tanggal Pembayaran</label><input id="payDate" type="date" name="paid_date" class="form-control" max="{{ now('Asia/Jakarta')->toDateString() }}" value="{{ old('paid_date',now('Asia/Jakarta')->toDateString()) }}" required></div><div class="col-md-4"><label for="payMethod" class="form-label">Metode</label><select id="payMethod" name="payment_method" class="form-select" required><option value="cash">Tunai</option><option value="transfer" @selected(old('payment_method') === 'transfer')>Transfer</option></select></div><div class="col-md-4"><button class="btn btn-success"><i class="bi bi-check-circle me-1"></i>Simpan Pelunasan</button></div></form></section>
@endif
@if($expense->status !== 'void')
<details class="bg-white border p-3"><summary class="text-danger">Batalkan Beban</summary><form class="mt-3" method="POST" action="{{ route('expenses.void',$expense) }}">@csrf<input type="hidden" name="version" value="{{ $expense->version }}"><label class="form-label" for="voidReason">Alasan Pembatalan</label><textarea id="voidReason" name="void_reason" class="form-control mb-2" maxlength="500" rows="2" required>{{ old('void_reason') }}</textarea><button class="btn btn-outline-danger"><i class="bi bi-x-circle me-1"></i>Batalkan Beban</button></form></details>
@endif
@endsection

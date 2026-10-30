@extends('layouts.app')
@section('title', 'Notifikasi')
@section('page-title', 'Notifikasi')
@section('content')
<div class="mb-3">
    <h4 class="fw-bold">Notifikasi Persediaan</h4>
    <p class="text-muted mb-0">@if($hasAppliedFilters){{ $notifications->total() }} produk sesuai filter dari {{ $totalProducts }} produk perlu perhatian.@else{{ $totalProducts }} produk perlu perhatian.@endif Diurutkan dari kondisi paling mendesak.</p>
</div>
@php
    $cardUrl = fn ($condition = null) => route('notifications.index', array_merge(request()->only(['search','location_id','product_id']), $condition ? ['condition'=>$condition] : []));
    $selectedCondition = $filters['condition'] ?? '';
@endphp
@include('components.report-kpis', ['summaryLabel'=>'Ringkasan notifikasi', 'metrics'=>[
    ['label'=>'Produk Perlu Perhatian', 'value'=>number_format($summary['products'],0,',','.').' produk', 'hint'=>'Dari '.number_format($totalProducts,0,',','.').' produk perlu perhatian', 'icon'=>'bell', 'tone'=>'primary', 'href'=>$cardUrl(), 'active'=>$selectedCondition === ''],
    ['label'=>'Risiko Kedaluwarsa', 'value'=>number_format($summary['expiry'],0,',','.').' produk', 'hint'=>'Sudah, hari ini, atau mendekati kedaluwarsa', 'icon'=>'calendar-x', 'tone'=>'danger', 'href'=>$cardUrl('expiry_all'), 'active'=>in_array($selectedCondition, ['expiry_all','expired','expiry_today','expiring'], true)],
    ['label'=>'Stok Habis/Rendah', 'value'=>number_format($summary['stock'],0,',','.').' produk', 'hint'=>'Stok layak jual perlu perhatian', 'icon'=>'box-seam', 'tone'=>'warning', 'href'=>$cardUrl('stock_all'), 'active'=>in_array($selectedCondition, ['stock_all','out_of_stock','low_stock'], true)],
    ['label'=>'Perlu Retur/Klaim', 'value'=>number_format($summary['returns'],0,',','.').' produk', 'hint'=>'Belum diajukan atau menunggu persetujuan', 'icon'=>'box-arrow-up', 'tone'=>'warning', 'href'=>$cardUrl('return_all'), 'active'=>in_array($selectedCondition, ['return_all','return_pending','return_approval'], true)],
]])
<p class="small text-muted mb-3">Ringkasan mengikuti filter. Satu produk dapat masuk lebih dari satu kelompok kondisi.</p>
<form method="GET" action="{{ route('notifications.index') }}" class="app-unified-filter card card-body mb-3">
    <div class="row g-3 align-items-end">
        <div class="col-md-4"><label for="notificationSearch" class="form-label">Cari produk</label><input id="notificationSearch" name="search" class="form-control" value="{{ $filters['search'] ?? '' }}" maxlength="150" placeholder="Nama atau kode/barcode produk"></div>
        <div class="col-md-3"><label for="notificationCondition" class="form-label">Kondisi</label><select id="notificationCondition" name="condition" class="form-select"><option value="">Semua kondisi</option>@foreach($conditionOptions as $key=>$label)<option value="{{ $key }}" @selected(($filters['condition'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></div>
        <div class="col-md-3"><label for="notificationLocation" class="form-label">Lokasi</label><select id="notificationLocation" name="location_id" class="form-select"><option value="">Semua lokasi</option>@foreach($locations as $location)<option value="{{ $location->id }}" @selected(($filters['location_id'] ?? '') == $location->id)>{{ $location->name }}</option>@endforeach</select></div>
        <div class="col-md-2 d-flex flex-wrap gap-2"><button class="btn btn-primary" type="submit">Terapkan</button><a class="btn btn-outline-secondary" href="{{ route('notifications.index') }}"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reset</a></div>
    </div>
</form>
@if(!empty($filters['product_id']))<p class="small text-muted">Menampilkan produk yang dipilih. <a href="{{ route('notifications.index') }}">Tampilkan semua produk</a></p>@endif
<div class="row g-3 mb-3">
@forelse($notifications as $notif)
    <div class="col-12"><article class="card" id="product-{{ $notif['product_id'] }}"><div class="card-body">
        <div class="d-flex flex-column flex-md-row flex-wrap justify-content-between align-items-start gap-2 mb-2">
            <div><h5 class="mb-1">{{ $notif['product_name'] }}</h5><span class="small text-muted">{{ $notif['location_name'] }}@if($notif['barcode']) · {{ $notif['barcode'] }}@endif</span></div>
            <div class="d-flex flex-wrap gap-1 align-items-start">@foreach($notif['badges'] as $badge)<span class="badge bg-{{ $badge['class'] }}">{{ $badge['label'] }}</span>@endforeach</div>
        </div>
        <p class="mb-2">{{ $notif['summary'] }}</p>
        @if(collect($notif['conditions'])->contains('kind', 'return_pending'))
        <div class="small mb-2 border-start border-warning border-3 ps-2">
            @foreach(collect($notif['conditions'])->where('kind', 'return_pending')->unique(fn ($issue) => $issue['receipt_id'].'|'.$issue['storage']) as $issue)
            <div>Penerimaan #{{ $issue['receipt_id'] }} · {{ $issue['order_number'] }} · {{ $issue['supplier_name'] }} — {{ $issue['storage'] }}</div>
            @endforeach
        </div>
        @endif
        <details @if(!empty($filters['product_id'])) open @endif>
            <summary class="text-primary">Rincian kondisi ({{ count($notif['conditions']) }})</summary>
            <ul class="mt-2 mb-2">@foreach($notif['conditions'] as $condition)<li class="mb-2"><strong>{{ $condition['badge_label'] }}:</strong> {{ $condition['message'] }}</li>@endforeach</ul>
            @if($notif['transfers'])<div class="border-top pt-2"><strong>Rekomendasi Distribusi Stok</strong><ul class="mt-1">@foreach($notif['transfers'] as $transfer)<li>{{ $transfer['message'] }}</li>@endforeach</ul></div>@endif
            @if($notif['advice'])<p class="mb-2">{{ $notif['advice'] }}</p>@endif
            @if($notif['orders'] && auth()->user()->hasPermission('purchase.orders.view'))<div class="small text-muted">PO berjalan: @foreach($notif['orders'] as $order)<span>{{ $order['number'] }} ({{ $order['quantity'] }}){{ !$loop->last ? '; ' : '' }}</span>@endforeach</div>@endif
        </details>
        @include('notifications.actions')
    </div></article></div>
@empty
    <div class="col-12"><div class="card card-body text-center text-muted py-5">{{ $totalProducts === 0 ? 'Tidak ada kondisi yang perlu perhatian.' : 'Tidak ada produk yang cocok dengan filter. Kondisi produk yang dipilih mungkin sudah terselesaikan.' }}</div></div>
@endforelse
</div>
{{ $notifications->links() }}
@endsection

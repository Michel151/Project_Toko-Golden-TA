@extends('layouts.app')
@section('title', 'Notifikasi')
@section('page-title', 'Notifikasi')
@section('content')
<div class="mb-3">
    <h4 class="fw-bold">Notifikasi Persediaan</h4>
    <p class="text-muted mb-0">{{ $totalProducts }} produk perlu perhatian. Diurutkan dari kondisi paling mendesak.</p>
</div>
<form method="GET" action="{{ route('notifications.index') }}" class="card card-body mb-3">
    <div class="row g-3 align-items-end">
        <div class="col-md-4"><label for="notificationSearch" class="form-label">Cari produk</label><input id="notificationSearch" name="search" class="form-control" value="{{ $filters['search'] ?? '' }}" maxlength="150" placeholder="Nama atau kode/barcode produk"></div>
        <div class="col-md-3"><label for="notificationCondition" class="form-label">Kondisi</label><select id="notificationCondition" name="condition" class="form-select"><option value="">Semua kondisi</option>@foreach($conditionOptions as $key=>$label)<option value="{{ $key }}" @selected(($filters['condition'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></div>
        <div class="col-md-3"><label for="notificationLocation" class="form-label">Lokasi</label><select id="notificationLocation" name="location_id" class="form-select"><option value="">Semua lokasi</option>@foreach($locations as $location)<option value="{{ $location->id }}" @selected(($filters['location_id'] ?? '') == $location->id)>{{ $location->name }}</option>@endforeach</select></div>
        <div class="col-md-2 d-flex flex-wrap gap-2"><button class="btn btn-primary" type="submit">Terapkan</button><a class="btn btn-outline-secondary" href="{{ route('notifications.index') }}">Reset</a></div>
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
        <details @if(!empty($filters['product_id'])) open @endif>
            <summary class="text-primary">Rincian kondisi ({{ count($notif['conditions']) }})</summary>
            <ul class="mt-2 mb-2">@foreach($notif['conditions'] as $condition)<li class="mb-2"><strong>{{ $condition['badge_label'] }}:</strong> {{ $condition['message'] }}</li>@endforeach</ul>
            @if($notif['transfers'])<div class="border-top pt-2"><strong>Rekomendasi Distribusi Stok</strong><ul class="mt-1">@foreach($notif['transfers'] as $transfer)<li>{{ $transfer['message'] }}</li>@endforeach</ul><p class="small text-muted">Rekomendasi mempertimbangkan ketersediaan stok layak jual dan prioritas penjualan di Toko. Transfer antar gudang tetap mempertahankan batas minimum lokasi asal. Verifikasi ketersediaan stok sebelum memproses transfer.</p></div>@endif
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

@if(auth()->user()->hasPermission('inventory.stock.view'))
@php $summaryNotifications = app(\App\Services\NotificationService::class)->forRequest(); @endphp
<div class="d-flex flex-wrap justify-content-between gap-2 mb-2"><span class="small text-muted">{{ count($summaryNotifications) }} produk perlu perhatian</span><a href="{{ route('notifications.index') }}" class="small">Lihat semua notifikasi <i class="bi bi-arrow-right"></i></a></div>
@forelse(array_slice($summaryNotifications, 0, 5) as $notif)
<a class="d-block text-decoration-none text-body border-bottom py-2" href="{{ route('notifications.index', ['product_id'=>$notif['product_id']]) }}#product-{{ $notif['product_id'] }}">
    <div class="d-flex flex-wrap justify-content-between gap-2"><strong>{{ $notif['product_name'] }}</strong><span class="d-flex flex-wrap gap-1">@foreach($notif['badges'] as $badge)<span class="badge bg-{{ $badge['class'] }}">{{ $badge['label'] }}</span>@endforeach</span></div>
    <div class="small text-muted mt-1">{{ $notif['summary'] }}</div>
</a>
@empty
<p class="text-muted mb-0 py-3">Tidak ada kondisi yang perlu perhatian.</p>
@endforelse
@endif

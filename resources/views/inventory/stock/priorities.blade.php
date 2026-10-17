<section class="bg-white border-top p-3" aria-labelledby="priority-heading">
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
        <div><h6 class="fw-bold mb-1" id="priority-heading">Perlu Ditindaklanjuti</h6><div class="small text-muted">{{ $priorityCount }} rincian kedaluwarsa sesuai filter &middot; Urutan tanggal kedaluwarsa terdekat</div></div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 priority-table">
            <thead><tr><th>Barang</th><th>Lokasi</th><th class="text-end">Sisa Fisik</th><th>Kedaluwarsa</th><th>Prioritas</th><th></th></tr></thead>
            <tbody>
            @forelse($priorityBatches as $batch)
                @php
                    $expired = $batch->expired_date && $batch->expired_date->lt(today());
                    $todayExpiry = $batch->expired_date && $batch->expired_date->isToday();
                    $tone = $expired ? 'danger' : ($batch->expired_date ? 'warning text-dark' : 'secondary');
                @endphp
                <tr>
                    <td style="min-width:200px"><div class="fw-semibold">{{ $batch->product->name }}</div><div class="font-monospace small text-muted">{{ $batch->batch_code ?: '#'.$batch->id }}</div></td>
                    <td data-label="Lokasi">{{ $batch->location->name }}</td>
                    <td data-label="Sisa fisik" class="text-end text-nowrap">{{ $batch->product->displayStock((float) $batch->quantity_remaining) }}@if($expired)<div class="small text-danger">Tidak layak jual</div>@endif</td>
                    <td data-label="Kedaluwarsa" class="text-nowrap"><div>{{ $batch->expired_date?->format('d/m/Y') ?? '-' }}</div><span class="badge bg-{{ $tone }}">{{ $batch->expiry_countdown }}</span></td>
                    <td data-label="Prioritas" style="min-width:190px"><div class="fw-semibold">{{ $expired ? 'Pisahkan dari rak' : ($batch->expired_date ? ($todayExpiry ? 'Periksa hari ini' : 'Dahulukan pengambilan') : 'Lengkapi tanggal kedaluwarsa') }}</div><div class="small text-muted">{{ $expired ? 'Stok fisik tetap tercatat' : ($batch->expired_date ? 'Ikuti urutan FEFO' : 'Status kedaluwarsa belum diketahui') }}</div></td>
                    <td><a class="btn btn-sm btn-outline-secondary" href="{{ route('inventory.stock-card.show', ['product'=>$batch->product_id,'tab'=>'expiry']) }}" title="Lihat kedaluwarsa {{ $batch->batch_code ?: '#'.$batch->id }}" aria-label="Lihat kedaluwarsa {{ $batch->batch_code ?: '#'.$batch->id }}"><i class="bi bi-arrow-up-right"></i></a></td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4"><i class="bi bi-check-circle me-1"></i>Tidak ada rincian kedaluwarsa yang perlu ditindaklanjuti sesuai filter.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($priorityBatches->hasPages())<div class="mt-3">{{ $priorityBatches->links() }}</div>@endif
</section>

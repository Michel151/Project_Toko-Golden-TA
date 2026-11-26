{{-- Membuka halaman tindakan; transaksi diproses melalui halaman yang dituju. --}}
<div class="d-flex flex-wrap gap-2 mt-3 pt-3 border-top" role="group" aria-label="Tindakan untuk {{ $notif['product_name'] }}">
    {{-- Satu tombol tinjau untuk setiap pengajuan yang menunggu persetujuan. --}}
    @if(auth()->user()->canAccessRoute('purchase.returns.approve'))
        @foreach(collect($notif['conditions'])->where('kind', 'return_approval')->unique('return_id') as $approval)
        <a class="btn btn-sm btn-primary" href="{{ route('purchase.returns.show', $approval['return_id']) }}"><i class="bi bi-clipboard-check me-1" aria-hidden="true"></i>Tinjau Pengajuan · {{ $approval['return_number'] }}</a>
        @endforeach
    @endif
    {{-- Satu rangkaian tombol per penerimaan yang masih perlu retur atau klaim. --}}
    @foreach(collect($notif['conditions'])->where('kind', 'return_pending')->unique('receipt_id') as $issue)
        @if(auth()->user()->canAccessRoute('purchase.receipts.show'))
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('purchase.receipts.show', $issue['receipt_id']) }}"><i class="bi bi-receipt me-1" aria-hidden="true"></i>Lihat Penerimaan · #{{ $issue['receipt_id'] }}</a>
        @endif
        @if(auth()->user()->canAccessRoute('purchase.returns.create'))
        <a class="btn btn-sm btn-outline-warning" href="{{ route('purchase.returns.create', ['receipt_id'=>$issue['receipt_id']]) }}"><i class="bi bi-box-arrow-up me-1" aria-hidden="true"></i>Buat Retur/Klaim · #{{ $issue['receipt_id'] }}</a>
        @endif
    @endforeach
    @if(auth()->user()->canAccessRoute('inventory.stock.index'))
    <a class="btn btn-sm btn-outline-secondary" href="{{ route('inventory.stock.index', ['product_id'=>$notif['product_id']]) }}"><i class="bi bi-box-seam me-1" aria-hidden="true"></i>Lihat Stok</a>
    @endif
    {{-- Membuka form transfer dengan produk, lokasi asal, dan tujuan dari rekomendasi. --}}
    @if(auth()->user()->canAccessRoute('inventory.transfers.create'))
        @foreach($notif['transfers'] as $transfer)
        <a class="btn btn-sm btn-outline-primary" href="{{ route('inventory.transfers.create', ['product_id'=>$notif['product_id'], 'from_location_id'=>$transfer['source_id'], 'to_location_id'=>$transfer['destination_id']]) }}" title="{{ $transfer['message'] }}">
            <i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>Buat Transfer
            @if(count($notif['transfers']) > 1) ({{ $loop->iteration }})@endif
        </a>
        @endforeach
    @endif
    {{-- Menampilkan tombol buat PO jika pembelian disarankan dan pengguna memiliki akses. --}}
    @if($notif['suggest_purchase'] && auth()->user()->canAccessRoute('purchase.orders.create'))
    <a class="btn btn-sm btn-outline-success" href="{{ route('purchase.orders.create', ['product_id'=>$notif['product_id']]) }}"><i class="bi bi-cart-plus me-1" aria-hidden="true"></i>Buat PO</a>
    @endif
    @if(auth()->user()->canAccessRoute('purchase.orders.show'))
        @foreach(collect($notif['orders'])->unique('id') as $order)
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('purchase.orders.show', $order['id']) }}"><i class="bi bi-receipt me-1" aria-hidden="true"></i>Lihat PO · {{ $order['number'] }}</a>
        @endforeach
    @endif
</div>

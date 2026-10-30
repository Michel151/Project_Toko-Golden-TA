<div class="d-flex flex-wrap gap-2 mt-3 pt-3 border-top" role="group" aria-label="Tindakan untuk {{ $notif['product_name'] }}">
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
    @if(auth()->user()->canAccessRoute('inventory.transfers.create'))
        @foreach($notif['transfers'] as $transfer)
        <a class="btn btn-sm btn-outline-primary" href="{{ route('inventory.transfers.create', ['product_id'=>$notif['product_id'], 'from_location_id'=>$transfer['source_id'], 'to_location_id'=>$transfer['destination_id']]) }}" title="{{ $transfer['message'] }}">
            <i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>Buat Transfer
            @if(count($notif['transfers']) > 1) ({{ $loop->iteration }})@endif
        </a>
        @endforeach
    @endif
    @if(auth()->user()->canAccessRoute('purchase.orders.create') && collect($notif['conditions'])->contains(fn ($condition) => $condition['type'] === 'stock') && ($notif['shortfall'] > 0 || ($notif['incoming_quantity'] <= 0 && empty($notif['transfers']))))
    <a class="btn btn-sm btn-outline-success" href="{{ route('purchase.orders.create', ['product_id'=>$notif['product_id']]) }}"><i class="bi bi-cart-plus me-1" aria-hidden="true"></i>Buat PO</a>
    @endif
    @if(auth()->user()->canAccessRoute('purchase.orders.show'))
        @foreach(collect($notif['orders'])->unique('id') as $order)
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('purchase.orders.show', $order['id']) }}"><i class="bi bi-receipt me-1" aria-hidden="true"></i>Lihat PO · {{ $order['number'] }}</a>
        @endforeach
    @endif
</div>

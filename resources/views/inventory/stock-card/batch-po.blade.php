@if($batchOrder)
    @if(auth()->user()->canAccessRoute('purchase.orders.show'))
        <a class="font-monospace text-nowrap" href="{{ route('purchase.orders.show', $batchOrder) }}">{{ $batchOrder->po_number }}</a>
    @else
        <span class="font-monospace text-nowrap">{{ $batchOrder->po_number }}</span>
    @endif
@else
    <span class="text-muted">-</span>
@endif

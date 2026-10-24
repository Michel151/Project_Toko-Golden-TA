@foreach($transfers as $transfer)
<template id="transfer-items-{{ $transfer->id }}">
    @foreach($transfer->items as $item)
    <tr data-transfer-item><td class="text-muted">{{ $loop->iteration }}</td><td data-item-name>{{ $item->product->name }}</td><td class="text-end fw-semibold">{{ $item->product->displayStock((float) $item->quantity) }}</td></tr>
    @endforeach
</template>
@endforeach

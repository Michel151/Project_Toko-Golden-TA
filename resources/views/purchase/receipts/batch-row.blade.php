<div class="receipt-batch-grid batch-row">
    <input type="number" class="form-control form-control-sm batch-quantity" name="items[{{ $i }}][batches][{{ $batchIndex }}][quantity]" value="{{ $batchValues['quantity'] ?? 0 }}" min="0" max="{{ $item->receivableQuantity() }}" step="0.0001" aria-label="Jumlah per kedaluwarsa {{ $item->product->name }}" required>
    <input type="date" class="form-control form-control-sm batch-expiry" name="items[{{ $i }}][batches][{{ $batchIndex }}][expired_date]" value="{{ $batchValues['expired_date'] ?? '' }}" aria-label="Tanggal kedaluwarsa {{ $item->product->name }}">
    <button type="button" class="btn btn-sm btn-outline-danger batch-delete" title="Hapus rincian kedaluwarsa" aria-label="Hapus rincian kedaluwarsa {{ $item->product->name }}"><i class="bi bi-trash"></i></button>
</div>

@php $availability = $product->stockAvailability(); @endphp
<div class="d-flex flex-wrap gap-4 bg-white border-top p-3 mb-3">
    <div><div class="small text-muted">Stok Fisik</div><strong>{{ $product->displayStock($availability['physical']) }}</strong></div>
    <div><div class="small text-muted">Layak Jual</div><strong class="text-success">{{ $product->displayStock($availability['sellable']) }}</strong></div>
    <div><div class="small text-muted">Kedaluwarsa</div><strong class="text-danger">{{ $product->displayStock($availability['expired']) }}</strong></div>
</div>

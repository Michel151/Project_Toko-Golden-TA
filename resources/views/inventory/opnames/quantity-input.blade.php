@php
    $inputUnits=\App\Support\OpnameQuantity::units($product);
    $remaining=(int)round(max(0,(float)$value)*10000);
@endphp
<div class="opname-quantity d-flex gap-1">
    <input type="hidden" class="physical-value" name="{{ $field }}" value="{{ $value }}">
    @foreach($inputUnits as $inputUnit)
        @php
            $conversion=(int)round($inputUnit['conversion']*10000);
            $isBase=$conversion===10000;
            $amount=$isBase ? $remaining/10000 : intdiv($remaining,$conversion);
            $remaining=$isBase ? 0 : $remaining%$conversion;
            $displayValue=rtrim(rtrim(number_format($amount,4,'.',''),'0'),'.');
        @endphp
        <div class="input-group input-group-sm"><input aria-label="{{ $inputUnit['name'] }} {{ $product->name }}" class="form-control qty-unit {{ $isBase ? 'qty-base' : 'qty-whole' }} text-end" data-conversion="{{ $inputUnit['conversion'] }}" type="number" min="0" step="{{ $isBase ? '0.0001' : '1' }}" value="{{ $displayValue }}" required><span class="input-group-text" title="{{ $inputUnit['name'] }}">{{ $inputUnit['name'] }}</span></div>
    @endforeach
</div>

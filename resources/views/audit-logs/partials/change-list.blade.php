@forelse($items as $item)
    <div class="audit-change-row">
        <span class="audit-change-label">{{ $item['label'] }}</span>
        <span class="audit-change-value">{{ $item['value'] }}</span>
    </div>
@empty
    <div class="audit-change-empty">
        <i class="bi {{ $emptyIcon }}"></i>
        <span>{{ $emptyMessage }}</span>
    </div>
@endforelse

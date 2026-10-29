<div class="row g-3 mb-3 report-kpis" aria-label="Ringkasan laporan">
    @foreach($metrics as $metric)
    @php
        $accent = ['primary'=>'#0d6efd', 'success'=>'#198754', 'warning'=>'#c8960c', 'danger'=>'#dc3545', 'info'=>'#0dcaf0'][$metric['tone']] ?? '#6c757d';
    @endphp
    <div class="col-6 col-xl-3">
        <div class="card report-stat h-100" style="--stat-color:{{ $accent }}"><div class="card-body d-flex align-items-center gap-3">
            <div class="stat-symbol flex-shrink-0"><i class="bi bi-{{ $metric['icon'] }}" aria-hidden="true"></i></div>
            <div>
                <div class="small text-muted">{{ $metric['label'] }}</div>
                <div class="report-kpi-value fw-bold">{{ $metric['value'] }}</div>
                <div class="small text-muted">{{ $metric['hint'] }}</div>
            </div>
        </div></div>
    </div>
    @endforeach
</div>

<div class="row g-3 mb-3 report-kpis" aria-label="Ringkasan laporan">
    @foreach($metrics as $metric)
    <div class="col-6 col-xl-3">
        <div class="card h-100"><div class="card-body">
            <div class="small text-muted"><i class="bi bi-{{ $metric['icon'] }} text-{{ $metric['tone'] }} me-1" aria-hidden="true"></i>{{ $metric['label'] }}</div>
            <div class="report-kpi-value fw-bold">{{ $metric['value'] }}</div>
            <div class="small text-muted">{{ $metric['hint'] }}</div>
        </div></div>
    </div>
    @endforeach
</div>

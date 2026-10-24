@once
<style>
    .master-heading{position:relative;overflow:hidden;background:var(--app-header);color:#fff;border-radius:15px;padding:1.2rem 1.35rem;box-shadow:0 5px 18px rgba(23,32,57,.13)}
    .master-heading::after{content:'';position:absolute;width:150px;height:150px;border-radius:50%;right:-45px;top:-75px;background:rgba(255,193,7,.12)}
    .master-heading-icon{width:48px;height:48px;flex:0 0 48px;border-radius:13px;background:rgba(255,193,7,.14);border:1px solid rgba(255,193,7,.18);color:#ffc107;display:grid;place-items:center;font-size:1.25rem}
    .master-heading .master-label{font-size:.67rem;letter-spacing:.11em;text-transform:uppercase;color:#ffc107;font-weight:700}
    .master-heading h5{font-weight:750;margin:.12rem 0}.master-heading p{margin:0;color:rgba(255,255,255,.62);font-size:.82rem}
    .master-heading-badge{position:relative;z-index:1;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.12);border-radius:9px;padding:.45rem .7rem;font-size:.72rem;white-space:nowrap}
</style>
@endonce
<div class="master-heading d-flex justify-content-between align-items-center gap-3 mb-3">
    <div class="d-flex align-items-center gap-3">
        <div class="master-heading-icon"><i class="bi bi-{{ $icon }}"></i></div>
        <div><div class="master-label">Master Data</div><h5>{{ $title }}</h5><p>{{ $description }}</p></div>
    </div>
    <div class="master-heading-badge d-none d-sm-block"><i class="bi bi-database-check me-1 text-warning"></i>Data Referensi</div>
</div>

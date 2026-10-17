<div class="app-filter-field">
    <label for="{{ $prefix }}From">Dari Tanggal</label>
    <input id="{{ $prefix }}From" type="date" name="from" class="form-control form-control-sm" value="{{ $fromValue ?? request('from') }}">
</div>
<div class="app-filter-field">
    <label for="{{ $prefix }}To">Sampai Tanggal</label>
    <input id="{{ $prefix }}To" type="date" name="to" class="form-control form-control-sm" value="{{ $toValue ?? request('to') }}">
</div>

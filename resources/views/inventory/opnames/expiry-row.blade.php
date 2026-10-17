<tr data-expiry-row>
    <td>
        @if(!empty($row['id']))
        <input type="hidden" name="items[{{ $item->id }}][expiries][{{ $index }}][id]" value="{{ $row['id'] }}">
        <input type="hidden" name="items[{{ $item->id }}][expiries][{{ $index }}][expired_date]" value="{{ $row['expired_date'] ?? '' }}">
        <span class="fw-semibold">{{ !empty($row['expired_date']) ? (\Carbon\Carbon::hasFormat($row['expired_date'],'Y-m-d') ? \Carbon\Carbon::parse($row['expired_date'])->format('d/m/Y') : $row['expired_date']) : 'Tanggal belum dicatat' }}</span>
        @if(empty($row['expired_date']))<div class="small text-warning-emphasis">Tanpa tanggal</div>
        @elseif($row['expired_date']<today()->toDateString())<div class="small text-danger">Sudah kedaluwarsa</div>
        @elseif($row['expired_date']<=today()->addDays(30)->toDateString())<div class="small text-warning-emphasis">Hampir kedaluwarsa</div>
        @else<div class="small text-success">Aman</div>@endif
        @else
        <input aria-label="Tanggal kedaluwarsa baru" type="date" class="form-control form-control-sm expiry-date" name="items[{{ $item->id }}][expiries][{{ $index }}][expired_date]" value="{{ $row['expired_date'] ?? '' }}" required>
        @endif
    </td>
    <td class="text-end">{{ \App\Support\OpnameQuantity::format((float)($row['system_quantity'] ?? 0),$displayUnits) }}</td>
    <td>@include('inventory.opnames.quantity-input',['field'=>"items[{$item->id}][expiries][{$index}][physical_quantity]",'value'=>$row['physical_quantity'] ?? 0])</td>
    <td class="text-end expiry-difference" data-system="{{ $row['system_quantity'] ?? 0 }}">0</td>
    <td class="text-center">@if(empty($row['id']))<button type="button" class="btn btn-sm btn-outline-danger remove-expiry" title="Hapus rincian baru" aria-label="Hapus rincian baru"><i class="bi bi-trash"></i></button>@endif</td>
</tr>

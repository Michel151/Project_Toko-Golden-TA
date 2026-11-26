{{-- Menampilkan uraian, nilai, dan jenis baris yang sudah disusun perhitungan laporan. --}}
@foreach($lines as $line)
    @php [$label, $value, $bold] = $line; $kind = $line[3] ?? 'detail'; @endphp
    <tr class="pl-{{ $kind }} {{ $bold ? 'fw-bold' : '' }}">
        {{-- Judul kelompok memakai dua kolom; nilai negatif memakai kurung dan null memakai keterangan. --}}
        @if($kind === 'section')
            <th colspan="2">{{ $label }}</th>
        @else
            <td>{{ $label }}</td>
            <td class="right text-end text-nowrap {{ $value !== null && $value < 0 ? 'text-danger' : '' }}">{{ $value === null ? ($missing ?? 'Belum lengkap') : ($value < 0 ? '('.number_format(abs($value),0,',','.').')' : number_format($value,0,',','.')) }}</td>
        @endif
    </tr>
@endforeach

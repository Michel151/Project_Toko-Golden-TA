<div class="app-pagination d-flex flex-wrap align-items-center justify-content-between gap-2">
    @if($showSummary ?? true)<span class="small text-muted">Menampilkan {{ $paginator->firstItem() ?? 0 }}-{{ $paginator->lastItem() ?? 0 }} dari {{ number_format($paginator->total(),0,',','.') }} data</span>@endif
    @if($paginator->hasPages())
    <nav aria-label="Navigasi halaman"><ul class="pagination pagination-sm flex-wrap mb-0">
        <li class="page-item {{ $paginator->onFirstPage() ? 'disabled' : '' }}">
            @if($paginator->onFirstPage())<span class="page-link" aria-disabled="true"><i class="bi bi-chevron-left" aria-hidden="true"></i><span class="visually-hidden">Sebelumnya</span></span>
            @else<a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Halaman sebelumnya" title="Halaman sebelumnya"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>@endif
        </li>
        @foreach($elements as $element)
            @if(is_string($element))<li class="page-item disabled"><span class="page-link">{{ $element }}</span></li>@endif
            @if(is_array($element))
                @foreach($element as $page=>$url)<li class="page-item {{ $page===$paginator->currentPage() ? 'active' : '' }}">
                    @if($page===$paginator->currentPage())<span class="page-link" aria-current="page">{{ $page }}</span>
                    @else<a class="page-link" href="{{ $url }}" aria-label="Halaman {{ $page }}">{{ $page }}</a>@endif
                </li>@endforeach
            @endif
        @endforeach
        <li class="page-item {{ $paginator->hasMorePages() ? '' : 'disabled' }}">
            @if($paginator->hasMorePages())<a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Halaman berikutnya" title="Halaman berikutnya"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
            @else<span class="page-link" aria-disabled="true"><i class="bi bi-chevron-right" aria-hidden="true"></i><span class="visually-hidden">Berikutnya</span></span>@endif
        </li>
    </ul></nav>
    @endif
</div>

{{--
  Navigasi halaman versi Less Worry.

  View pagination bawaan Laravel ditulis untuk Tailwind. Aplikasi ini tidak
  memuat Tailwind, jadi kelasnya tidak berarti apa-apa: di layar 390px bloknya
  memuai jadi 755px tinggi dengan ikon panah 215×215px, nomor halaman tersusun
  ke bawah satu per baris, dan teksnya berbahasa Inggris. (API-38 #2)

  Yang dipakai di sini hanya kelas yang memang ada di aplikasi: .btn dan .ghost.
--}}
@if ($paginator->hasPages())
  <nav class="pager" role="navigation" aria-label="Navigasi halaman">
    <div class="pager-nums">
      @if ($paginator->onFirstPage())
        <span class="btn ghost off" aria-disabled="true">Sebelumnya</span>
      @else
        <a class="btn ghost" href="{{ $paginator->previousPageUrl() }}" rel="prev">Sebelumnya</a>
      @endif

      @foreach ($elements as $element)
        @if (is_string($element))
          <span class="gap" aria-hidden="true">{{ $element }}</span>
        @endif

        @if (is_array($element))
          @foreach ($element as $page => $url)
            @if ($page == $paginator->currentPage())
              <span class="btn num now" aria-current="page">{{ $page }}</span>
            @else
              <a class="btn ghost num" href="{{ $url }}">{{ $page }}</a>
            @endif
          @endforeach
        @endif
      @endforeach

      @if ($paginator->hasMorePages())
        <a class="btn ghost" href="{{ $paginator->nextPageUrl() }}" rel="next">Berikutnya</a>
      @else
        <span class="btn ghost off" aria-disabled="true">Berikutnya</span>
      @endif
    </div>

    <p class="pager-info muted small">
      Menampilkan {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}
      dari {{ $paginator->total() }} complaint
    </p>
  </nav>
@endif

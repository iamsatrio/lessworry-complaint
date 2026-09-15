{{-- Satu kartu alarm. `redup` dipakai saat sudah ada yang memegangnya: alarm
     yang ditangani TETAP TAMPIL — hilangnya akan membuat rekan yang membuka
     setengah jam kemudian menyimpulkan keadaannya sudah beres. --}}
@php $nyala = $baris->nyala; @endphp
<div class="card flush alarm {{ $baris->ditangani() ? 'redup' : '' }}">
  <div class="card-h alarm-h">
    <div>
      <h2>{{ $baris->alarm->judul() }}</h2>
      <p class="muted small" style="margin:6px 0 0">{{ $nyala->ringkasan }}</p>
    </div>
    <div class="n">{{ $nyala->jumlah }}</div>
  </div>

  <div class="alarm-b">
    <p class="tindakan">{{ $baris->alarm->tindakan() }}</p>

    <table>
      <thead><tr><th>Tiket</th><th>Outlet</th><th>Lama</th></tr></thead>
      <tbody>
      @foreach($nyala->daftar as $item)
        <tr>
          <td><a href="{{ route('complaints.show', $item['id']) }}" class="tix">{{ $item['tiket'] }}</a></td>
          <td class="muted small">{{ $item['outlet'] }}</td>
          <td class="small">{{ $item['umur'] }}</td>
        </tr>
      @endforeach
      </tbody>
    </table>

    @if($nyala->sisa() > 0)
      {{-- Yang dipotong disebut, tidak dihilangkan diam-diam: lima baris yang
           terlihat seperti seluruhnya lebih buruk daripada angka yang jujur. --}}
      <p class="muted small" style="margin:12px 0 0">
        Dan {{ $nyala->sisa() }} lagi.
        <a href="{{ route('complaints.index', ['status' => 'open']) }}">Lihat di papan kerja →</a>
      </p>
    @endif

    @if(count($nyala->perOutlet) > 1)
      <div class="chips">
        @foreach($nyala->perOutlet as $per)
          <span class="chip">{{ $per['outlet'] }} <b>{{ $per['jumlah'] }}</b></span>
        @endforeach
      </div>
    @endif
  </div>

  <div class="alarm-f">
    @if($baris->ditangani())
      {{-- Nama dan jam, terlihat semua orang. Inilah yang membuat "semua
           mengira orang lain sudah menanganinya" berhenti terjadi. --}}
      <span class="held">
        Dipegang <b>{{ $baris->penanda?->user->name ?? 'pengguna yang sudah dihapus' }}</b>
        pukul {{ $baris->penanda?->ditandai_pada->timezone(config('complaint.alarms.zona_waktu'))->format('H:i') }}
      </span>
      <span class="muted small">Kalau keadaannya masih ada besok, alarmnya menyala lagi.</span>
    @else
      <form method="POST" action="{{ route('operasional.alarm.tangani', $baris->alarm->kunci()) }}">
        @csrf
        <button class="ghost">Sudah saya tangani</button>
      </form>
      <span class="muted small">Menandai berarti kamu memegangnya hari ini — bukan bahwa masalahnya selesai.</span>
    @endif
  </div>
</div>

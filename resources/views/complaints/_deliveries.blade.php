@php
  $deliveries = $complaint->deliveries();
  // Nama dan NIP kurir adalah data karyawan: aturannya sama dengan kasir POS
  // dan penanggung jawab. Perjalanannya sendiri tetap tampil untuk semua —
  // yang disembunyikan hanya identitas orangnya. (API-14 #5)
  $bolehLihatKurir = auth()->user()->canSeeStaffAttribution();
@endphp

@if(!empty($deliveries))
<div class="card">
  <div class="eyebrow">Perjalanan kurir</div>
  <div class="tl">
    @foreach($deliveries as $d)
      <div class="item">
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:baseline">
          <b class="display">{{ $d['status'] }}</b>
          <span class="muted small">
            {{ $d['date'] ? \Illuminate\Support\Carbon::parse($d['date'])->translatedFormat('d M Y') : '—' }}
          </span>
        </div>
        @if($d['cancel_reason'])
          <div class="small" style="color:var(--danger)">{{ $d['cancel_reason'] }}</div>
        @endif
        <div class="small">
          @if($d['courier_name'])
            @if($bolehLihatKurir)
              Kurir: <b>{{ $d['courier_name'] }}</b>
              @if($d['courier_nip'])<span class="muted" style="font-family:var(--mono)"> · {{ $d['courier_nip'] }}</span>@endif
            @else
              <span class="muted">Kurir sudah ditugaskan</span>
            @endif
          @else
            <span class="muted">Kurir belum ditugaskan</span>
          @endif
        </div>
        <div class="muted small">
          @if($d['queue_no'])Antrean {{ $d['queue_no'] }}@endif
          @if($d['distance']) · {{ $d['distance'] }} km @endif
          @if($d['proof_count']) · {{ $d['proof_count'] }} foto bukti @endif
        </div>
        @if($d['courier_notes'])<div class="small">Catatan kurir: {{ $d['courier_notes'] }}</div>@endif
        @if($d['notes'])<div class="muted small">{{ $d['notes'] }}</div>@endif
      </div>
    @endforeach
  </div>
  <p class="hint">
    Diurutkan menurut tanggal jadwal. Penjemputan dan pengantaran untuk satu nota bisa lebih dari satu baris
    kalau sempat dijadwalkan ulang.
  </p>
</div>
@elseif($complaint->isLinkedToOrder() && $complaint->nevira_synced_at)
<div class="card">
  <div class="eyebrow">Perjalanan kurir</div>
  <p class="muted" style="margin:0">
    Tidak ada penjemputan atau pengantaran tercatat untuk nota ini. Order yang diambil sendiri
    di outlet memang tidak punya baris kurir.
  </p>
</div>
@endif

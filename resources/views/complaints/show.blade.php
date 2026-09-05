@extends('layouts.app')
@section('title',$complaint->ticket_number)
@section('content')
<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:20px;flex-wrap:wrap;margin-bottom:6px">
  <div>
    <div class="eyebrow">{{ $complaint->channelLabel() }}</div>
    <h1 style="font-family:var(--mono);font-size:26px;letter-spacing:-.01em">{{ $complaint->ticket_number }}</h1>
    <p class="lede" style="margin-bottom:0">
      Masuk {{ $complaint->created_at->translatedFormat('d F Y, H:i') }}
      @if($complaint->creator) · dicatat {{ $complaint->creator->name }} @endif
    </p>
  </div>
  <div style="display:flex;flex-direction:column;gap:10px;align-items:flex-end">
    <div style="display:flex;gap:8px">
      <span class="badge w-{{ $complaint->bobot }}">{{ $complaint->bobotLabel() }}</span>
      <span class="badge b-{{ $complaint->status }}">{{ $complaint->statusDisplay() }}</span>
    </div>
    @include('partials.sla')
    @if($complaint->totalPauseMinutes() > 0)
      {{-- Angka penyelesaian tidak menghitung jeda. Kalau itu tidak disebut,
           orang akan membandingkannya dengan tanggal di layar dan mengira
           salah satunya bohong. --}}
      <div class="muted small" style="text-align:right">
        Termasuk jeda {{ \App\Models\Complaint::humanMinutes($complaint->totalPauseMinutes()) }},
        tidak dihitung sebagai waktu penyelesaian
      </div>
    @endif
  </div>
</div>

<div class="grid g2">
  <div>
    <div class="card">
      <div class="eyebrow">Keluhan</div>
      <p style="white-space:pre-wrap;margin:0;font-size:16px">{{ $complaint->description }}</p>
      @php $lampiranKeluhan = $complaint->attachments->whereNull('complaint_activity_id'); @endphp
      @if($lampiranKeluhan->isNotEmpty())
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px">
        @foreach($lampiranKeluhan as $a)
          <a href="{{ route('complaints.attachment', [$complaint, $a]) }}" target="_blank" rel="noopener">
            <img src="{{ route('complaints.attachment.thumb', [$complaint, $a]) }}" alt="{{ $a->original_name }}"
                 style="height:96px;border-radius:10px;border:1px solid var(--line)">
          </a>
        @endforeach
      </div>
      @endif
      <dl class="kv" style="margin-top:20px;padding-top:18px;border-top:1px solid var(--line)">
        <dt>Pelapor</dt><dd>{{ $complaint->reporter_name }}</dd>
        {{-- Dua dari tiga kanal masuk adalah WhatsApp, dan mengabari pelanggan
             adalah langkah terakhir yang wajib pada setiap penutupan. Nomor
             yang dicetak sebagai teks biasa berarti: blok dengan jari, salin,
             pindah aplikasi, tempel, ketik. (API-38 #10) --}}
        <dt>Telepon</dt>
        <dd>
          @if($complaint->reporter_phone)
            @php $wa = $complaint->waLink('Halo, complaint '.$complaint->ticket_number.' sudah kami tindak lanjuti.'); @endphp
            @if($wa)
              <a href="{{ $wa }}" target="_blank" rel="noopener">{{ $complaint->reporter_phone }}</a>
              <span class="muted small"> · buka WhatsApp</span>
            @else
              <a href="tel:{{ $complaint->reporter_phone }}">{{ $complaint->reporter_phone }}</a>
            @endif
          @else
            —
          @endif
        </dd>
        <dt>Outlet</dt><dd>{{ $complaint->outlet?->name ?? '—' }}</dd>
        <dt>Kategori</dt><dd>{{ $complaint->categoryLabel() }}@if($complaint->sub_category) · {{ $complaint->sub_category }}@endif</dd>
        @if($complaint->resolution)<dt>Penyelesaian</dt><dd>{{ $complaint->resolution }}</dd>@endif
        @if($complaint->root_cause)<dt>Penyebab akar</dt><dd>{{ $complaint->root_cause }}</dd>@endif
        @if($complaint->compensation_amount > 0)
          <dt>Kompensasi</dt><dd>Rp {{ number_format($complaint->compensation_amount,0,',','.') }}</dd>
        @endif
      </dl>
    </div>

    {{-- Menutup complaint adalah tindakan paling sering di halaman ini.
         Sebelumnya kartunya berada di y=4128 pada halaman setinggi 5076px di
         390px — hampir lima layar gulir. Yang ditindak diletakkan di atas
         yang dibaca; riwayat penanganan bersifat bacaan. (API-38 #6) --}}
    <div class="card">
      <div class="eyebrow">Perbarui status</div>
      <form method="POST" action="{{ route('complaints.status',$complaint) }}">
        @csrf
        {{-- Versi yang sedang ditampilkan. Kalau ada yang menyimpan duluan,
             penyimpanan dari halaman ini ditolak, bukan menimpanya. --}}
        <input type="hidden" name="lock_version" value="{{ $complaint->lock_version }}">
        {{-- old() lebih dulu, sama seperti close_reason dan tindak_lanjut di
             bawah. Tanpa itu, penyimpanan yang ditolak validasi mengembalikan
             select ke status LAMA: petugas yang memilih Close tanpa alasan
             harus memilih Close lagi sebelum bisa mengisi alasannya. --}}
        <label for="st">Status</label>
        <select id="st" name="status" required>
          @foreach(config('complaint.statuses') as $k=>$v)
            <option value="{{ $k }}" @selected(old('status', $complaint->status)===$k)>{{ $v }}</option>
          @endforeach
        </select>

        {{-- Jeda: penanda pada tiket Handling, bukan status keenam. Selama
             dijeda, hitungan SLA berhenti dan tenggatnya mundur sebanyak lama
             jeda begitu dilanjutkan.

             Kolomnya hanya muncul untuk yang berwenang MEMULAI jeda. Yang
             tidak berwenang tetap bisa memperbarui tiket yang sudah dijeda
             orang lain — form-nya tidak mengirim kolom ini, dan jedanya
             dibiarkan apa adanya. Penjaganya tetap server. --}}
        @if(auth()->user()->canPause($complaint))
          <label for="pz">Jeda SLA</label>
          <select id="pz" name="pause_reason">
            <option value="">Tidak dijeda — hitungan SLA berjalan</option>
            @foreach(config('complaint.pause_reasons') as $k=>$v)
              <option value="{{ $k }}" @selected(old('pause_reason', $complaint->pause_reason)===$k)>{{ $v }}</option>
            @endforeach
          </select>
        @elseif($complaint->isPaused())
          {{-- Tetap bisa melanjutkan: arahnya aman, ia mengembalikan tiket ke
               hitungan SLA alih-alih menyembunyikannya. --}}
          <label for="pz">Jeda SLA</label>
          <select id="pz" name="pause_reason">
            <option value="menunggu_pelanggan" selected>Tetap dijeda — {{ $complaint->pauseReasonLabel() }}</option>
            <option value="">Lanjutkan, jalankan lagi hitungan SLA</option>
          </select>
        @endif
        @if($complaint->isPaused())
          <p class="hint">Dijeda sejak {{ $complaint->paused_at->translatedFormat('d M Y, H:i') }}
            ({{ \App\Models\Complaint::humanMinutes($complaint->pauseMinutes()) }}).
            Tenggat akan mundur sebanyak itu saat dilanjutkan.</p>
        @elseif(! auth()->user()->canPause($complaint))
          <p class="hint">Complaint {{ $complaint->bobotLabel() }} hanya bisa dijeda Customer Care —
            jeda menghentikan hitungan SLA.</p>
        @endif

        {{-- Alasan penutupan menggantikan status "Ditolak". Tiketnya tetap
             Close; laporan tetap bisa memisahkan yang selesai dari yang tidak
             berdasar. --}}
        <label for="cr" id="cr-label">Alasan penutupan <span class="req" id="cr-req" style="display:none">*</span></label>
        <select id="cr" name="close_reason">
          <option value="" id="cr-kosong">— hanya diisi kalau statusnya Close —</option>
          @foreach(config('complaint.close_reasons') as $k=>$v)
            <option value="{{ $k }}" @selected(old('close_reason', $complaint->close_reason)===$k)>{{ $v }}</option>
          @endforeach
        </select>

        <label for="tl">Tindak lanjut</label>
        <select id="tl" name="tindak_lanjut">
          <option value="">— belum ditentukan —</option>
          @foreach(config('complaint.tindak_lanjut') as $k=>$v)
            <option value="{{ $k }}" @selected(old('tindak_lanjut', $complaint->tindak_lanjut)===$k)>{{ $v }}</option>
          @endforeach
        </select>

        <label for="res">Tindakan penyelesaian</label>
        <textarea id="res" name="resolution" style="min-height:76px"
          placeholder="Apa yang dilakukan untuk menyelesaikan keluhan ini?">{{ $complaint->resolution }}</textarea>
        <label for="rc">Penyebab akar</label>
        <input id="rc" name="root_cause" value="{{ $complaint->root_cause }}"
          placeholder="Kenapa ini bisa terjadi?">
        <label for="komp">Kompensasi (Rp)</label>
        <input id="komp" type="number" name="compensation_amount" min="0" inputmode="numeric" value="{{ $complaint->compensation_amount }}">
        <p class="hint">
          Batas wewenangmu:
          {{ auth()->user()->compensationLimit() === PHP_INT_MAX ? 'tanpa batas' : 'Rp '.number_format(auth()->user()->compensationLimit(),0,',','.') }}.
          Lebih dari itu, naikkan ke supervisor.
        </p>
        <label for="cn">Catatan perubahan</label>
        <input id="cn" name="note" placeholder="Opsional">
        @unless(auth()->user()->canResolve($complaint))
          <div class="panel" style="margin-top:14px">
            @if(auth()->user()->isKasir())
              Complaint ini berbobot {{ $complaint->bobotLabel() }}. Kasir hanya boleh menutup complaint
              berbobot Ringan — untuk yang ini, teruskan ke Customer Care.
            @else
              Peranmu bisa memperbarui penanganan, tapi penutupan complaint dilakukan Customer Care atau supervisor.
            @endif
          </div>
        @elseif(auth()->user()->isKasir())
          <div class="panel" style="margin-top:14px">
            Complaint Ringan boleh kamu tutup sendiri, selama kompensasinya tidak melebihi batas wewenangmu.
          </div>
        @endunless
        <div style="margin-top:16px"><button>Simpan Status</button></div>
      </form>
      {{-- Aturan servernya sudah Rule::requiredIf(status === 'close'). Yang
           kurang hanya tandanya di layar: labelnya terbaca opsional, jadi
           petugas menekan Simpan, ditolak, dan harus mengulang pilihan
           statusnya. Ditandai di sini, ditegakkan tetap di server. (API-38 #7) --}}
      <script>
      (function(){
        const st = document.getElementById('st');
        const cr = document.getElementById('cr');
        const req = document.getElementById('cr-req');
        const kosong = document.getElementById('cr-kosong');
        if (!st || !cr || !kosong) return;
        function ikutiStatus(){
          const tutup = st.value === 'close';
          cr.required = tutup;
          if (req) req.style.display = tutup ? '' : 'none';
          kosong.textContent = tutup ? '— pilih alasan —' : '— hanya diisi kalau statusnya Close —';
        }
        st.addEventListener('change', ikutiStatus);
        ikutiStatus();
      })();
      </script>
    </div>

    <div class="card">
      <div class="eyebrow">Riwayat penanganan</div>
      <div class="tl">
        {{-- Baris yang menyebut nama karyawan disaring di sini: riwayat
             complaint dibaca juga oleh kasir, dan penetapan pelaku bukan
             konsumsinya. Datanya tetap tersimpan utuh. (API-19) --}}
        @php $riwayat = $complaint->activities->reject(fn ($a) => $a->type === 'responsible'
              && ! auth()->user()->canSeeStaffAttribution()); @endphp
        @forelse($riwayat as $a)
          <div class="item">
            <div class="small">
              <b class="display">{{ $a->user?->name ?? 'Sistem' }}</b>
              <span class="muted">· {{ $a->created_at->translatedFormat('d M, H:i') }}</span>
            </div>
            @if($a->type==='status_change')
              <div>{{ config('complaint.statuses.'.$a->from_status, $a->from_status) }}
                → <b>{{ config('complaint.statuses.'.$a->to_status, $a->to_status) }}</b></div>
            @endif
            @if($a->note)<div style="white-space:pre-wrap">{{ $a->note }}</div>@endif
            @if($a->attachments->isNotEmpty())
              {{-- Versi kecil dulu; yang penuh hanya saat diklik. Membuka
                   halaman complaint tidak boleh berarti mengunduh semua foto
                   ukuran penuh di perangkat outlet. (API-20) --}}
              <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
                @foreach($a->attachments as $foto)
                  <a href="{{ route('complaints.attachment', [$complaint, $foto]) }}" target="_blank" rel="noopener">
                    <img src="{{ route('complaints.attachment.thumb', [$complaint, $foto]) }}"
                         alt="{{ $foto->original_name }}" loading="lazy"
                         title="{{ $foto->original_name }}@if($foto->sizeLabel()) · {{ $foto->sizeLabel() }}@endif"
                         style="height:76px;border-radius:8px;border:1px solid var(--line)">
                  </a>
                @endforeach
              </div>
            @endif
          </div>
        @empty<p class="muted">Belum ada aktivitas.</p>@endforelse
      </div>
      <form method="POST" action="{{ route('complaints.note',$complaint) }}" enctype="multipart/form-data" style="margin-top:18px">
        @csrf
        <label for="note">Tambah catatan</label>
        <textarea id="note" name="note" required style="min-height:80px"
          placeholder="Apa yang sudah kamu lakukan untuk complaint ini?">{{ old('note') }}</textarea>
        <label for="photos">Foto bukti</label>
        <input id="photos" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple>
        <p class="hint">
          Maksimal {{ App\Services\PenyimpanFoto::PER_CATATAN }} foto,
          {{ App\Services\PenyimpanFoto::maksMb() }} MB per foto.
          Fotonya dikecilkan otomatis dan data lokasi dari kamera dibuang sebelum disimpan.
        </p>
        <div style="margin-top:12px"><button class="ghost">Simpan Catatan</button></div>
      </form>
    </div>

    @if(auth()->user()->canSeeStaffAttribution())
      @include('complaints._staff')
    @endif
  </div>

  <div>
    <div class="card">
      <div class="eyebrow">Order di NEVIRA</div>
      @if($kembaran->isNotEmpty())
        {{-- Satu nota boleh punya beberapa keluhan berbeda; yang berbahaya
             adalah mengerjakannya paralel tanpa saling tahu. --}}
        <div class="panel" style="background:var(--warn-soft);border-color:#f3dfa4;margin:0 0 14px">
          <b>Nota ini juga dikeluhkan di tiket lain</b>
          <div style="margin-top:5px">
            @foreach($kembaran as $lain)
              <a href="{{ route('complaints.show',$lain) }}" class="tix">{{ $lain->ticket_number }}</a>
              <span class="muted small">({{ $lain->statusLabel() }})</span>@if(!$loop->last), @endif
            @endforeach
          </div>
          <div class="small" style="margin-top:8px">Periksa dulu sebelum mengerjakan — kalau keluhannya sama, gabungkan.</div>
        </div>
      @endif
      @if($complaint->isLinkedToOrder())
        <div class="tix" style="font-size:15px;margin-bottom:12px">{{ $complaint->orderLabel() }}</div>
        @if($complaint->nevira_snapshot)
          @php $nv = $complaint->nevira_snapshot; @endphp
          <dl class="kv">
            <dt>Nomor struk</dt><dd style="font-family:var(--mono);font-size:13px">{{ $nv['invoice'] ?? '—' }}</dd>
            <dt>Pelanggan</dt><dd>{{ $nv['customer_name'] ?? '—' }}@if(!empty($nv['customer_phone']))<div class="muted small">{{ $nv['customer_phone'] }}</div>@endif</dd>
            <dt>Outlet</dt><dd>{{ $nv['outlet_name'] ?? '—' }}</dd>
            <dt>Status order</dt><dd>{{ $nv['status'] ?? '—' }}@if(!empty($nv['progress'])) · {{ $nv['progress'] }}%@endif</dd>
            <dt>Pembayaran</dt><dd>{{ $nv['payment_status'] ?? '—' }}</dd>
            <dt>Total</dt><dd>@if(isset($nv['grand_total']))Rp {{ number_format((int) $nv['grand_total'],0,',','.') }}@else—@endif</dd>
            {{-- Nama kasir menyangkut penilaian kerja orang: hanya untuk yang berwenang. --}}
            @if(!empty($nv['cashier_name']) && auth()->user()->canSeeStaffAttribution())
              <dt>Kasir</dt><dd>{{ $nv['cashier_name'] }}</dd>
            @endif
            @if(!empty($nv['estimated_done']))
              <dt>Estimasi selesai</dt><dd>{{ \Illuminate\Support\Carbon::parse($nv['estimated_done'])->translatedFormat('d M Y, H:i') }}</dd>
            @endif
          </dl>
          @if(!empty($nv['services']))
            <div class="panel" style="margin-top:12px">
              <b>Layanan dalam order ini</b>
              @foreach($nv['services'] as $svc)
                <div style="margin-top:6px">
                  {{ $svc['name'] ?? 'Layanan' }}
                  @if(!empty($svc['quantity'])) · {{ $svc['quantity'] }} item @endif
                  @if(!empty($svc['status'])) · {{ $svc['status'] }} @endif
                  @if(!empty($svc['notes']))<div class="muted small">Catatan: {{ $svc['notes'] }}</div>@endif
                </div>
              @endforeach
            </div>
          @endif
          @if($complaint->transactionIsOld())
            <div class="panel" style="background:var(--warn-soft);border-color:#f3dfa4">
              Nota ini berumur {{ $complaint->transactionAgeDays() }} hari — lebih dari
              {{ config('complaint.nota_max_age_days') }} hari. Periksa apakah keluhannya masih terkait order ini.
            </div>
          @endif
          <p class="hint">Ditarik {{ $complaint->nevira_synced_at?->diffForHumans() }}</p>
        @endif
        @if($complaint->nevira_sync_error)
          <div class="panel bad">
            <b>Data order belum bisa ditarik</b>
            <div style="margin-top:5px">{{ $complaint->nevira_sync_error }}</div>
            <div class="small" style="margin-top:8px">Complaint ini tetap aman tersimpan. Coba tarik lagi setelah NEVIRA pulih.</div>
          </div>
        @endif
        <form method="POST" action="{{ route('complaints.resync',$complaint) }}" style="margin-top:14px">
          @csrf<button class="ghost">Tarik Ulang dari NEVIRA</button>
        </form>
      @else
        @if($complaint->nota_exemption)
          <div class="panel" style="margin:0 0 14px">
            <b>Tanpa nomor nota</b>
            <div style="margin-top:5px">{{ $complaint->notaExemptionLabel() }}</div>
          </div>
        @else
          <p class="muted" style="margin:0 0 14px">Complaint ini belum tertaut ke order.</p>
        @endif
      @endif

      {{-- Nomor order bisa dipasang atau dibetulkan kapan saja setelah complaint tersimpan. --}}
      <details class="link-editor" @if(!$complaint->isLinkedToOrder()) open @endif style="margin-top:14px">
        <summary>{{ $complaint->isLinkedToOrder() ? 'Betulkan nomor order' : 'Tautkan ke order sekarang' }}</summary>
        <form method="POST" action="{{ route('complaints.link',$complaint) }}" style="margin-top:12px">
          @csrf @method('PUT')
          <label for="lnk">ID transaksi NEVIRA</label>
          <input id="lnk" name="nevira_transaction_number" value="{{ $complaint->nevira_transaction_number }}"
                 placeholder="Nomor ID transaksi dari struk">
          <label for="lex">Kalau dikosongkan, sebutkan alasannya</label>
          <select id="lex" name="nota_exemption">
            <option value="">— tidak ada alasan —</option>
            @foreach(config('complaint.nota_exemptions') as $k=>$v)
              <option value="{{ $k }}" @selected($complaint->nota_exemption===$k)>{{ $v }}</option>
            @endforeach
          </select>
          <p class="hint">
            @if($complaint->isLinkedToOrder())
              Mengubah nomor akan membuang data order yang sekarang dan menariknya ulang. Kosongkan untuk melepas tautan.
            @else
              Isi kalau pelanggan sudah membawa struknya. Perubahan tercatat di riwayat.
            @endif
          </p>
          <div style="margin-top:12px"><button class="ghost">Simpan Nomor Order</button></div>
        </form>
      </details>
    </div>

    @include('complaints._deliveries')

    {{-- Penugasan dan penerusan ke divisi adalah wewenang, bukan pencatatan:
         hanya Customer Care dan supervisor. Server memeriksanya lagi di
         ComplaintController::assign — menyembunyikan tombol saja bukan pagar. --}}
    @if(auth()->user()->canAssignResponsibility())
      <div class="card">
        <div class="eyebrow">Siapa yang menangani</div>
        <form method="POST" action="{{ route('complaints.assign',$complaint) }}">
          @csrf
          @if($handlers->isNotEmpty())
            <label for="asg">Penanggung jawab</label>
            <select id="asg" name="assigned_to">
              <option value="">Belum ditentukan</option>
              @foreach($handlers as $h)
                <option value="{{ $h->id }}" @selected($complaint->assigned_to==$h->id)>{{ $h->name }} — {{ $h->roleLabel() }}</option>
              @endforeach
            </select>
          @endif
          <label for="fwd">Teruskan ke divisi</label>
          <select id="fwd" name="forwarded_division">
            <option value="">Tidak diteruskan</option>
            @foreach(config('complaint.divisions') as $k=>$v)
              <option value="{{ $k }}" @selected($complaint->forwarded_division===$k)>{{ $v }}</option>
            @endforeach
          </select>
          <p class="hint">Meneruskan ke divisi membuat complaint ini muncul di papan kerja mereka.</p>
          <div style="margin-top:14px"><button class="ghost">Simpan Penugasan</button></div>
        </form>
      </div>
    @endif

  </div>
</div>
@endsection

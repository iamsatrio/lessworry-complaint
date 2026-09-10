@php
  // Dikelompokkan per baris layanan nota. Satu nota bisa berisi sepuluh
  // sprei, masing-masing dengan rantai pengerjaannya sendiri: diratakan jadi
  // satu daftar, sepuluh rantai itu terbaca sebagai pengulangan. Nota satu
  // layanan pulang sebagai satu grup tanpa judul, jadi tampilannya tidak
  // berubah sama sekali. (API-51)
  $grupHandler = $complaint->orderHandlerGroups();
  $handlers = $complaint->orderHandlers();
  $adaJudulGrup = collect($grupHandler)->contains(fn ($g) => filled($g['label']));
@endphp

<div class="card">
  <div class="eyebrow">Karyawan yang menangani order ini</div>

  @if(empty($handlers))
    <p class="muted" style="margin:0">
      @if($complaint->isLinkedToOrder())
        NEVIRA belum mencatat siapa yang mengerjakan order ini. Kalau order baru masuk,
        tahapan produksinya memang belum berjalan.
      @else
        Complaint ini belum tertaut ke order, jadi belum diketahui siapa yang menanganinya.
        Pasang nomor order di panel sebelah untuk melihatnya.
      @endif
    </p>
  @else
    <table style="font-size:14px">
      <thead><tr><th>Tahap</th><th>Karyawan</th><th>Waktu</th></tr></thead>
      <tbody>
      @foreach($grupHandler as $grup)
        @if($grup['label'])
          <tr>
            <td colspan="3" style="padding-top:14px;border-bottom:none">
              <div class="eyebrow" style="margin:0">{{ $grup['label'] }}</div>
              @if($grup['index'] !== null && $grup['index'] === $complaint->nevira_service_index)
                <span class="badge">Yang dikeluhkan</span>
              @endif
            </td>
          </tr>
        @endif
        @foreach($grup['items'] as $h)
          <tr>
            <td>{{ $h['stage'] }}
              @if($h['status'])<div class="muted small">{{ $h['status'] }}</div>@endif
            </td>
            <td><b class="display">{{ $h['name'] }}</b>
              @if($h['nip'])<div class="muted small" style="font-family:var(--mono)">{{ $h['nip'] }}</div>@endif
            </td>
            <td class="muted small">
              @if($h['duration']){{ intdiv($h['duration'],60) }} mnt {{ $h['duration']%60 }} dtk @else — @endif
            </td>
          </tr>
        @endforeach
      @endforeach
      </tbody>
    </table>
    <p class="hint">
      Ini catatan NEVIRA tentang siapa mengerjakan tahap apa — keterangan, bukan kesimpulan.
      Nama muncul di sini karena orangnya menangani order ini, belum tentu karena dia penyebabnya.
      @if($adaJudulGrup)
        Nota ini berisi beberapa barang dan tiap barang punya rantai pengerjaannya sendiri —
        nama yang muncul lebih dari sekali mengerjakan barang yang berbeda, bukan tahap yang sama dua kali.
      @endif
    </p>
  @endif
</div>

@if(auth()->user()->canAssignResponsibility() && $kandidat)
<div class="card">
  <div class="eyebrow">Pelaku complaint ini</div>

  @forelse($complaint->responsibles as $pelaku)
    <div class="panel" style="margin-top:0;margin-bottom:10px">
      <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:baseline">
        <div>
          <b class="display" style="font-size:15px">{{ $pelaku->staff_name }}</b>
          @if($pelaku->staff_nip)
            <span class="muted small" style="font-family:var(--mono)"> · {{ $pelaku->staff_nip }}</span>
          @endif
        </div>
        <span class="badge">{{ $pelaku->roleLabel() }}@if($pelaku->stage) · {{ $pelaku->stage }}@endif</span>
      </div>

      <div style="margin-top:8px;white-space:pre-wrap">{{ $pelaku->reason }}</div>

      <div class="muted small" style="margin-top:10px">
        Ditetapkan {{ $pelaku->setter?->name ?? 'seseorang' }},
        {{ $pelaku->set_at?->translatedFormat('d M Y, H:i') }}
      </div>

      {{-- Alasan wajib di sini juga, jadi lipatannya punya jalur gagal yang
           sama dengan form "Tetapkan pelaku" di bawah. Penandanya memuat id
           pelakunya supaya yang terbuka kembali hanya baris yang memang
           ditolak, bukan seluruh daftar. (Tinjauan PR #13) --}}
      <details class="link-editor" @if(old('_form') === 'ubah-pelaku-'.$pelaku->id) open @endif style="margin-top:10px">
        <summary>Ubah atau cabut</summary>
        <form method="POST" action="{{ route('complaints.responsibles.update',[$complaint,$pelaku]) }}" style="margin-top:12px">
          @csrf @method('PUT')
          <input type="hidden" name="_form" value="ubah-pelaku-{{ $pelaku->id }}">
          <label for="peran-{{ $pelaku->id }}">Peran dalam kejadian ini</label>
          <select id="peran-{{ $pelaku->id }}" name="peran">
            @foreach(config('complaint.responsible_roles') as $k=>$v)
              <option value="{{ $k }}" @selected($pelaku->role===$k)>{{ $v }}</option>
            @endforeach
          </select>
          <label for="alasan-{{ $pelaku->id }}">Alasan <span class="req">*</span></label>
          <textarea id="alasan-{{ $pelaku->id }}" name="alasan" style="min-height:70px">{{ $pelaku->reason }}</textarea>
          <div style="margin-top:12px"><button class="ghost">Simpan Perubahan</button></div>
        </form>
        <form method="POST" action="{{ route('complaints.responsibles.destroy',[$complaint,$pelaku]) }}" style="margin-top:10px">
          @csrf @method('DELETE')
          <button class="ghost">Cabut Penetapan</button>
        </form>
      </details>
    </div>
  @empty
    <p class="muted" style="margin:0 0 10px">
      Belum ada pelaku yang ditetapkan. Complaint tanpa pelaku juga wajar — jangan menunjuk orang
      hanya supaya kolomnya terisi.
    </p>
  @endforelse

  {{-- Sengaja TIDAK terbuka sendiri. Daftar kandidat memuat satu baris
       centang dan satu select peran per pengguna sistem — sekitar 1500px di
       390px — sementara penetapan pelaku adalah tindakan jarang. Selama blok
       ini terbuka bawaan, semua orang yang hanya ingin memperbarui status
       harus menggulirinya lebih dulu. Membukanya tetap satu ketukan.
       (API-38 #6)

       KECUALI saat kesalahan validasinya berasal dari form ini. `alasan`
       wajib; petugas yang mencentang orang lalu lupa alasannya dikembalikan
       ke halaman ini dengan spanduk "Periksa lagi sebelum lanjut" di atas —
       tapi kolom yang dimaksud terlipat kembali, dan ia harus menebak bahwa
       yang diminta ada di balik lipatan. (Tinjauan PR #13)

       Ditandai lewat `_form`, bukan lewat nama kolomnya: `alasan` dipakai
       juga oleh form "Ubah atau cabut" di setiap pelaku yang sudah ada, jadi
       memeriksa $errors->has('alasan') akan membuka form yang salah. --}}
  <details class="link-editor" id="form-tambah-pelaku"
           @if(old('_form') === 'tambah-pelaku') open @endif style="margin-top:10px">
    <summary>Tetapkan pelaku complaint ini</summary>

    <form method="POST" action="{{ route('complaints.responsibles.store',$complaint) }}" style="margin-top:12px">
      @csrf
      <input type="hidden" name="_form" value="tambah-pelaku">

      @foreach($kandidat->groups() as $grup)
        @if($grup['collapsed'])
          {{-- Barang lain di nota yang sama: dipersempit, TIDAK disembunyikan.
               Kesalahan bisa terjadi di tahap mana pun, termasuk pengemasan
               yang mencampur barang antar-baris. (API-51) --}}
          <details style="margin-top:14px">
            <summary class="muted small">{{ $grup['label'] }} ({{ count($grup['items']) }} nama)</summary>
            @include('complaints._kandidat', ['items' => $grup['items']])
          </details>
        @else
          <div style="margin-top:14px">
            <div class="eyebrow">{{ $grup['label'] }}</div>
            @include('complaints._kandidat', ['items' => $grup['items']])
          </div>
        @endif
      @endforeach

      @if(empty($kandidat->groups()))
        <p class="muted" style="margin:12px 0 0">
          Belum ada nama yang bisa ditawarkan — complaint ini belum tertaut ke order, atau daftar
          karyawan outletnya sedang tidak bisa ditarik dari NEVIRA. Isi manual di bawah.
        </p>
      @endif

      <details style="margin-top:14px">
        <summary class="muted small">Orang yang tidak ada di daftar</summary>
        <div class="row" style="margin-top:10px">
          <div>
            <label for="mnama">Nama</label>
            <input id="mnama" name="manual_nama" value="{{ old('manual_nama') }}" placeholder="mis. kurir dari outlet lain">
          </div>
          <div>
            <label for="mnip">NIP</label>
            <input id="mnip" name="manual_nip" value="{{ old('manual_nip') }}">
          </div>
        </div>
        <label for="mperan">Peran dalam kejadian ini</label>
        <select id="mperan" name="manual_peran">
          @foreach(config('complaint.responsible_roles') as $k=>$v)
            <option value="{{ $k }}" @selected(old('manual_peran')===$k)>{{ $v }}</option>
          @endforeach
        </select>
      </details>

      <label for="alasan" style="margin-top:14px">Alasan <span class="req">*</span></label>
      <textarea id="alasan" name="alasan" style="min-height:76px"
        placeholder="Apa yang ditemukan saat ditelusuri?">{{ old('alasan') }}</textarea>
      <p class="hint">
        Wajib diisi, dan berlaku untuk semua yang dicentang sekaligus. Penetapan tanpa alasan tidak
        bisa ditinjau ulang, dan menempel di catatan kerja orang. Tulis temuannya, bukan kesan.
      </p>

      <div style="margin-top:14px"><button class="ghost">Tetapkan Pelaku</button></div>
    </form>
  </details>

  <p class="hint" style="margin-top:14px">
    Satu complaint boleh punya beberapa pelaku — kasir yang menerima, petugas yang mencuci, kurir
    yang mengantar. Tercatat siapa yang menetapkan dan kapan; setiap penambahan, perubahan, dan
    pencabutan masuk riwayat complaint.
  </p>
</div>
@endif

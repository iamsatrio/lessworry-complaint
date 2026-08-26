@php $handlers = $complaint->orderHandlers(); @endphp

<div class="card">
  <div class="eyebrow">Karyawan yang menangani order ini</div>

  @if(empty($handlers))
    <p class="muted" style="margin:0">
      @if($complaint->nevira_transaction_id)
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
      @foreach($handlers as $h)
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
      </tbody>
    </table>
    <p class="hint">
      Ini catatan NEVIRA tentang siapa mengerjakan tahap apa — keterangan, bukan kesimpulan.
      Nama muncul di sini karena orangnya menangani order ini, belum tentu karena dia penyebabnya.
    </p>
  @endif
</div>

@if(auth()->user()->canAssignResponsibility())
<div class="card">
  <div class="eyebrow">Penanggung jawab akar masalah</div>

  @if($complaint->hasResponsibility())
    <div class="panel" style="margin-top:0">
      <b class="display" style="font-size:15px">{{ $complaint->responsible_staff_name }}</b>
      @if($complaint->responsible_staff_nip)
        <span class="muted small" style="font-family:var(--mono)"> · {{ $complaint->responsible_staff_nip }}</span>
      @endif
      @if($complaint->responsible_stage)<div class="small">Tahap: {{ $complaint->responsible_stage }}</div>@endif
      @if($complaint->responsibility_note)
        <div style="margin-top:8px;white-space:pre-wrap">{{ $complaint->responsibility_note }}</div>
      @endif
      <div class="muted small" style="margin-top:10px">
        Ditetapkan {{ $complaint->responsibilitySetter?->name ?? 'seseorang' }},
        {{ $complaint->responsibility_set_at?->translatedFormat('d M Y, H:i') }}
      </div>
    </div>
  @else
    <p class="muted" style="margin:0 0 10px">Belum ditetapkan.</p>
  @endif

  <details class="link-editor" style="margin-top:10px">
    <summary>{{ $complaint->hasResponsibility() ? 'Ubah atau cabut penetapan' : 'Tetapkan penanggung jawab' }}</summary>
    <form method="POST" action="{{ route('complaints.responsibility',$complaint) }}" style="margin-top:12px">
      @csrf @method('PUT')

      @if(!empty($handlers))
        <label for="pick">Pilih dari yang menangani order</label>
        <select id="pick" onchange="isiDariDaftar(this)">
          <option value="">— isi manual di bawah —</option>
          @foreach($handlers as $h)
            <option value="{{ json_encode($h) }}">{{ $h['name'] }} — {{ $h['stage'] }}</option>
          @endforeach
        </select>
      @endif

      <label for="rname">Nama karyawan</label>
      <input id="rname" name="responsible_staff_name" value="{{ $complaint->responsible_staff_name }}"
             placeholder="Kosongkan untuk mencabut penetapan">

      <div class="row" style="margin-top:0">
        <div><label for="rnip">NIP</label>
          <input id="rnip" name="responsible_staff_nip" value="{{ $complaint->responsible_staff_nip }}">
        </div>
        <div><label for="rstage">Tahap</label>
          <input id="rstage" name="responsible_stage" value="{{ $complaint->responsible_stage }}"
                 placeholder="Cuci, Pengemasan, Kasir…">
        </div>
      </div>
      <input type="hidden" id="rid" name="responsible_staff_id" value="{{ $complaint->responsible_staff_id }}">

      <label for="rnote">Alasan <span class="req">*</span></label>
      <textarea id="rnote" name="responsibility_note" style="min-height:76px"
        placeholder="Apa yang ditemukan saat ditelusuri?">{{ $complaint->responsibility_note }}</textarea>
      <p class="hint">
        Wajib diisi. Penetapan tanpa alasan tidak bisa ditinjau ulang, dan menempel di catatan
        kerja orang. Tulis temuannya, bukan kesan.
      </p>

      <div style="margin-top:14px"><button class="ghost">Simpan Penetapan</button></div>
    </form>
  </details>

  <p class="hint" style="margin-top:14px">
    Tercatat siapa yang menetapkan dan kapan. Perubahan masuk riwayat complaint.
  </p>
</div>

<script>
function isiDariDaftar(sel){
  if(!sel.value) return;
  const h = JSON.parse(sel.value);
  document.getElementById('rname').value  = h.name ?? '';
  document.getElementById('rnip').value   = h.nip ?? '';
  document.getElementById('rstage').value = h.stage ?? '';
  document.getElementById('rid').value    = h.staff_id ?? '';
}
</script>
@endif

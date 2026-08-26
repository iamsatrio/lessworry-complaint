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
      <span class="badge p-{{ $complaint->priority }}">{{ config('complaint.priorities.'.$complaint->priority) }}</span>
      <span class="badge b-{{ $complaint->status }}">{{ $complaint->statusLabel() }}</span>
    </div>
    @include('partials.sla')
  </div>
</div>

<div class="grid g2">
  <div>
    <div class="card">
      <div class="eyebrow">Keluhan</div>
      <p style="white-space:pre-wrap;margin:0;font-size:16px">{{ $complaint->description }}</p>
      @if($complaint->attachments->isNotEmpty())
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px">
        @foreach($complaint->attachments as $a)
          <a href="{{ asset('storage/'.$a->path) }}" target="_blank" rel="noopener">
            <img src="{{ asset('storage/'.$a->path) }}" alt="{{ $a->original_name }}"
                 style="height:96px;border-radius:10px;border:1px solid var(--line)">
          </a>
        @endforeach
      </div>
      @endif
      <dl class="kv" style="margin-top:20px;padding-top:18px;border-top:1px solid var(--line)">
        <dt>Pelapor</dt><dd>{{ $complaint->reporter_name }}</dd>
        <dt>Telepon</dt><dd>{{ $complaint->reporter_phone ?: '—' }}</dd>
        <dt>Outlet</dt><dd>{{ $complaint->outlet?->name ?? '—' }}</dd>
        <dt>Kategori</dt><dd>{{ $complaint->categoryLabel() }}@if($complaint->sub_category) · {{ $complaint->sub_category }}@endif</dd>
        @if($complaint->resolution)<dt>Penyelesaian</dt><dd>{{ $complaint->resolution }}</dd>@endif
        @if($complaint->root_cause)<dt>Penyebab akar</dt><dd>{{ $complaint->root_cause }}</dd>@endif
        @if($complaint->compensation_amount > 0)
          <dt>Kompensasi</dt><dd>Rp {{ number_format($complaint->compensation_amount,0,',','.') }}</dd>
        @endif
      </dl>
    </div>

    <div class="card">
      <div class="eyebrow">Riwayat penanganan</div>
      <div class="tl">
        @forelse($complaint->activities as $a)
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
          </div>
        @empty<p class="muted">Belum ada aktivitas.</p>@endforelse
      </div>
      <form method="POST" action="{{ route('complaints.note',$complaint) }}" style="margin-top:18px">
        @csrf
        <label for="note">Tambah catatan</label>
        <textarea id="note" name="note" required style="min-height:80px"
          placeholder="Apa yang sudah kamu lakukan untuk complaint ini?"></textarea>
        <div style="margin-top:12px"><button class="ghost">Simpan Catatan</button></div>
      </form>
    </div>
  </div>

  <div>
    <div class="card">
      <div class="eyebrow">Order di NEVIRA</div>
      @if($complaint->nevira_transaction_id)
        <div class="tix" style="font-size:15px;margin-bottom:12px">{{ $complaint->nevira_transaction_id }}</div>
        @if($complaint->nevira_snapshot)
          @php $nv = $complaint->nevira_snapshot; @endphp
          <dl class="kv">
            <dt>Nomor struk</dt><dd style="font-family:var(--mono);font-size:13px">{{ $nv['invoice'] ?? '—' }}</dd>
            <dt>Pelanggan</dt><dd>{{ $nv['customer_name'] ?? '—' }}@if(!empty($nv['customer_phone']))<div class="muted small">{{ $nv['customer_phone'] }}</div>@endif</dd>
            <dt>Outlet</dt><dd>{{ $nv['outlet_name'] ?? '—' }}</dd>
            <dt>Status order</dt><dd>{{ $nv['status'] ?? '—' }}@if(!empty($nv['progress'])) · {{ $nv['progress'] }}%@endif</dd>
            <dt>Pembayaran</dt><dd>{{ $nv['payment_status'] ?? '—' }}</dd>
            <dt>Total</dt><dd>@if(isset($nv['grand_total']))Rp {{ number_format((int) $nv['grand_total'],0,',','.') }}@else—@endif</dd>
            @if(!empty($nv['cashier_name']))<dt>Kasir</dt><dd>{{ $nv['cashier_name'] }}</dd>@endif
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
        <p class="muted" style="margin:0 0 14px">Complaint ini belum tertaut ke order.</p>
      @endif

      {{-- Nomor order bisa dipasang atau dibetulkan kapan saja setelah complaint tersimpan. --}}
      <details class="link-editor" @if(!$complaint->nevira_transaction_id) open @endif style="margin-top:14px">
        <summary>{{ $complaint->nevira_transaction_id ? 'Betulkan nomor order' : 'Tautkan ke order sekarang' }}</summary>
        <form method="POST" action="{{ route('complaints.link',$complaint) }}" style="margin-top:12px">
          @csrf @method('PUT')
          <label for="lnk">ID transaksi NEVIRA</label>
          <input id="lnk" name="nevira_transaction_id" value="{{ $complaint->nevira_transaction_id }}"
                 placeholder="Nomor ID transaksi dari struk">
          <p class="hint">
            @if($complaint->nevira_transaction_id)
              Mengubah nomor akan membuang data order yang sekarang dan menariknya ulang. Kosongkan untuk melepas tautan.
            @else
              Isi kalau pelanggan sudah membawa struknya. Perubahan tercatat di riwayat.
            @endif
          </p>
          <div style="margin-top:12px"><button class="ghost">Simpan Nomor Order</button></div>
        </form>
      </details>
    </div>

    <div class="card">
      <div class="eyebrow">Siapa yang menangani</div>
      <form method="POST" action="{{ route('complaints.assign',$complaint) }}">
        @csrf
        <label for="asg">Penanggung jawab</label>
        <select id="asg" name="assigned_to">
          <option value="">Belum ditentukan</option>
          @foreach($handlers as $h)
            <option value="{{ $h->id }}" @selected($complaint->assigned_to==$h->id)>{{ $h->name }} — {{ $h->roleLabel() }}</option>
          @endforeach
        </select>
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

    <div class="card">
      <div class="eyebrow">Perbarui status</div>
      <form method="POST" action="{{ route('complaints.status',$complaint) }}">
        @csrf
        <label for="st">Status</label>
        <select id="st" name="status" required>
          @foreach(config('complaint.statuses') as $k=>$v)
            <option value="{{ $k }}" @selected($complaint->status===$k)>{{ $v }}</option>
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
        @unless(auth()->user()->canResolve())
          <div class="panel" style="margin-top:14px">
            Peranmu bisa memperbarui penanganan, tapi penutupan complaint (Selesai / Ditolak) dilakukan Customer Care atau supervisor.
          </div>
        @endunless
        <div style="margin-top:16px"><button>Simpan Status</button></div>
      </form>
    </div>
  </div>
</div>
@endsection

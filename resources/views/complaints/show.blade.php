@extends('layouts.app')
@section('title',$complaint->ticket_number)
@section('content')
<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
  <div>
    <h1 class="mono" style="font-size:24px">{{ $complaint->ticket_number }}</h1>
    <div class="sub">
      Masuk {{ $complaint->created_at->translatedFormat('d M Y, H:i') }} lewat {{ $complaint->channelLabel() }}
      @if($complaint->creator) · dicatat {{ $complaint->creator->name }} @endif
    </div>
  </div>
  <div style="text-align:right">
    <span class="badge b-{{ $complaint->status }}">{{ $complaint->statusLabel() }}</span>
    <span class="badge p-{{ $complaint->priority }}">{{ config('complaint.priorities.'.$complaint->priority) }}</span>
    <div style="margin-top:8px">
      @if($complaint->isOverdue())
        <span class="overdue">Lewat SLA {{ $complaint->due_resolution_at->diffForHumans(null,true) }}</span>
      @elseif($complaint->resolved_at)
        <span class="muted">Selesai dalam {{ intdiv($complaint->resolutionMinutes(),60) }}j {{ $complaint->resolutionMinutes()%60 }}m</span>
      @elseif($complaint->due_resolution_at)
        <span class="muted">Tenggat {{ $complaint->due_resolution_at->translatedFormat('d M H:i') }}</span>
      @endif
    </div>
  </div>
</div>

<div class="grid g2">
  <div>
    <div class="card">
      <h3 style="margin:0 0 12px">Keluhan</h3>
      <p style="white-space:pre-wrap;margin:0">{{ $complaint->description }}</p>
      @if($complaint->attachments->isNotEmpty())
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px">
        @foreach($complaint->attachments as $a)
          <a href="{{ asset('storage/'.$a->path) }}" target="_blank">
            <img src="{{ asset('storage/'.$a->path) }}" style="height:88px;border-radius:7px;border:1px solid var(--line)">
          </a>
        @endforeach
      </div>
      @endif
    </div>

    <div class="card">
      <h3 style="margin:0 0 12px">Riwayat</h3>
      <div class="tl">
        @forelse($complaint->activities as $a)
          <div class="item">
            <div style="font-size:13px">
              <b>{{ $a->user?->name ?? 'Sistem' }}</b>
              <span class="muted">· {{ $a->created_at->translatedFormat('d M H:i') }}</span>
            </div>
            @if($a->type==='status_change')
              <div style="font-size:14px">Status: <span class="muted">{{ config('complaint.statuses.'.$a->from_status, $a->from_status) }}</span>
                → <b>{{ config('complaint.statuses.'.$a->to_status, $a->to_status) }}</b></div>
            @endif
            @if($a->note)<div style="font-size:14px;white-space:pre-wrap">{{ $a->note }}</div>@endif
          </div>
        @empty<p class="muted">Belum ada aktivitas.</p>@endforelse
      </div>
      <form method="POST" action="{{ route('complaints.note',$complaint) }}" style="margin-top:14px">
        @csrf
        <textarea name="note" placeholder="Tambah catatan penanganan…" required style="min-height:70px"></textarea>
        <div style="margin-top:10px"><button>Tambah Catatan</button></div>
      </form>
    </div>
  </div>

  <div>
    <div class="card">
      <h3 style="margin:0 0 12px">Pelapor</h3>
      <dl class="kv">
        <dt>Nama</dt><dd>{{ $complaint->reporter_name }}</dd>
        <dt>Telepon</dt><dd>{{ $complaint->reporter_phone ?? '—' }}</dd>
        <dt>Outlet</dt><dd>{{ $complaint->outlet?->name ?? '—' }}</dd>
        <dt>Kategori</dt><dd>{{ $complaint->categoryLabel() }} @if($complaint->sub_category)<span class="muted">· {{ $complaint->sub_category }}</span>@endif</dd>
      </dl>
    </div>

    <div class="card">
      <h3 style="margin:0 0 10px">Order NEVIRA</h3>
      @if($complaint->nevira_transaction_id)
        <div class="mono" style="margin-bottom:8px">{{ $complaint->nevira_transaction_id }}</div>
        @if($complaint->nevira_snapshot)
          <dl class="kv">
            @foreach(['invoice'=>'Invoice','customer_name'=>'Pelanggan','customer_phone'=>'Telepon','outlet_name'=>'Outlet','total'=>'Total','status'=>'Status'] as $k=>$label)
              <dt>{{ $label }}</dt><dd>{{ $complaint->nevira_snapshot[$k] ?? '—' }}</dd>
            @endforeach
          </dl>
          <p class="muted" style="font-size:12px;margin-top:10px">Ditarik {{ $complaint->nevira_synced_at?->diffForHumans() }}</p>
        @endif
        @if($complaint->nevira_sync_error)
          <div class="nevira err-box">
            <b>Sinkron gagal</b><br>{{ $complaint->nevira_sync_error }}
            <p class="muted" style="margin:8px 0 0;font-size:12px">Complaint tetap tersimpan. Coba tarik lagi setelah NEVIRA pulih.</p>
          </div>
        @endif
        <form method="POST" action="{{ route('complaints.resync',$complaint) }}" style="margin-top:10px">
          @csrf<button class="btn ghost">Tarik Ulang dari NEVIRA</button>
        </form>
      @else
        <p class="muted" style="margin:0">Complaint ini tidak tertaut ke order.</p>
      @endif
    </div>

    <div class="card">
      <h3 style="margin:0 0 12px">Penugasan</h3>
      <form method="POST" action="{{ route('complaints.assign',$complaint) }}">
        @csrf
        <label>Penanggung Jawab</label>
        <select name="assigned_to">
          <option value="">— belum ditentukan —</option>
          @foreach($handlers as $h)
            <option value="{{ $h->id }}" @selected($complaint->assigned_to==$h->id)>{{ $h->name }} ({{ $h->roleLabel() }})</option>
          @endforeach
        </select>
        <label>Teruskan ke Divisi</label>
        <select name="forwarded_division">
          <option value="">— tidak diteruskan —</option>
          @foreach(config('complaint.divisions') as $k=>$v)
            <option value="{{ $k }}" @selected($complaint->forwarded_division===$k)>{{ $v }}</option>
          @endforeach
        </select>
        <div style="margin-top:14px"><button class="btn ghost">Simpan Penugasan</button></div>
      </form>
    </div>

    <div class="card">
      <h3 style="margin:0 0 12px">Ubah Status</h3>
      <form method="POST" action="{{ route('complaints.status',$complaint) }}">
        @csrf
        <label>Status</label>
        <select name="status" required>
          @foreach(config('complaint.statuses') as $k=>$v)
            <option value="{{ $k }}" @selected($complaint->status===$k)>{{ $v }}</option>
          @endforeach
        </select>
        <label>Tindakan Penyelesaian</label>
        <textarea name="resolution" style="min-height:64px">{{ $complaint->resolution }}</textarea>
        <label>Penyebab Akar</label>
        <input name="root_cause" value="{{ $complaint->root_cause }}">
        <label>Kompensasi (Rp)</label>
        <input type="number" name="compensation_amount" min="0" value="{{ $complaint->compensation_amount }}">
        <p class="muted" style="font-size:12px;margin:6px 0 0">
          Batas wewenangmu: Rp {{ auth()->user()->compensationLimit() === PHP_INT_MAX ? 'tanpa batas' : number_format(auth()->user()->compensationLimit(),0,',','.') }}
        </p>
        <label>Catatan</label>
        <input name="note">
        @unless(auth()->user()->canResolve())
          <p class="muted" style="font-size:12px;margin-top:8px">Peranmu tidak berwenang menutup complaint (selesai/ditolak).</p>
        @endunless
        <div style="margin-top:14px"><button>Simpan Status</button></div>
      </form>
    </div>
  </div>
</div>
@endsection

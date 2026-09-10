{{-- Satu blok nama kandidat pelaku. Dipakai dua kali: grup yang terbuka dan
     grup yang terlipat, supaya keduanya tidak pelan-pelan jadi berbeda. --}}
@foreach($items as $item)
  <div style="display:flex;gap:10px;align-items:center;padding:7px 0;border-bottom:1px solid var(--line);flex-wrap:wrap">
    <label class="pick" style="flex:1;min-width:200px">
      <input type="checkbox" name="pelaku[]" value="{{ $item['key'] }}"
             @checked(in_array($item['key'], (array) old('pelaku', []), true))>
      <span style="min-width:0">
        <b class="display" style="text-transform:none">{{ $item['name'] }}</b>
        @if($item['nip'])
          <span class="muted small" style="font-family:var(--mono)"> · {{ $item['nip'] }}</span>
        @endif
        @if($item['stage'])<div class="muted small">{{ $item['stage'] }}</div>@endif
      </span>
    </label>
    <select name="peran[{{ $item['key'] }}]" style="max-width:190px;margin:0">
      @foreach(config('complaint.responsible_roles') as $k=>$v)
        {{-- Dibaca sebagai indeks array, bukan lewat notasi titik:
             kunci kandidat bisa memuat titik (nama disingkat). --}}
        <option value="{{ $k }}" @selected((old('peran', [])[$item['key']] ?? $item['role'])===$k)>{{ $v }}</option>
      @endforeach
    </select>
  </div>
@endforeach

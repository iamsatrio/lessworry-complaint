{{-- Tabel satu pengelompokan halaman Kerugian: kategori, outlet, atau tindak
     lanjut. (API-43)

     Satu berkas untuk ketiganya, dan itu yang menjamin syarat halaman ini
     tidak bisa bocor di salah satunya: kolom cakupan ADA di setiap tabel
     karena hanya ada satu tabel. Tiga salinan berarti tiga tempat yang bisa
     lupa menuliskannya, dan yang lupa akan yang paling jarang dibuka. --}}
@php use App\Services\NilaiBiaya; @endphp
<table>
  <thead><tr>
    <th>{{ $judulKolom }}</th><th class="num">Kasus</th><th class="num">Punya nilai</th>
    <th class="num">Cakupan</th><th class="num">Biaya tercatat</th><th class="num">Porsi biaya</th>
  </tr></thead>
  <tbody>
    @foreach($baris as $b)
      <tr>
        <td>{{ $b['label'] }}</td>
        <td class="num">{{ $b['kasus'] }}</td>
        <td class="num">{{ $b['terisi'] }}</td>
        <td class="num">
          {{ $b['persen'] === null ? '—' : $b['persen'].'%' }}
          @if($b['rendah'])<span class="muted small"> · rendah</span>@endif
        </td>
        <td class="num">{{ NilaiBiaya::rupiah($b['biaya']) }}</td>
        <td class="num">{{ $totalBiaya > 0 ? round(100 * $b['biaya'] / $totalBiaya).'%' : '—' }}</td>
      </tr>
    @endforeach
    <tr>
      <td><b>Total</b></td>
      <td class="num">{{ $totalKasus }}</td>
      <td class="num">{{ collect($baris)->sum('terisi') }}</td>
      <td class="num">{{ NilaiBiaya::cakupanTeks(collect($baris)->sum('terisi'), $totalKasus) }}</td>
      <td class="num"><b>{{ NilaiBiaya::rupiah($totalBiaya) }}</b></td>
      <td class="num">100%</td>
    </tr>
  </tbody>
</table>

<?php

namespace App\Http\Controllers;

use App\Http\Requests\TagihanRequest;
use App\Models\Outlet;
use App\Models\PembayaranTagihan;
use App\Models\Pengaturan;
use App\Models\Tagihan;
use App\Models\User;
use App\Services\PeriodeTagihan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Daftar tagihan bulanan — dikelola satrio sendiri, tanpa menyentuh kode.
 * (API-73)
 *
 * Itu seluruh maksud halaman ini. Kalau menambah satu baris tagihan berarti
 * mengubah berkas lalu deploy, daftarnya akan basi dalam dua bulan dan
 * alarmnya berubah dari pengingat jadi kabar yang menyesatkan — pelajaran
 * kolom `Pelaku` di spreadsheet lama, yang terisi 2 dari 90 baris.
 *
 * Dua gerbang, bukan satu:
 *
 *   dashboard.view          — membaca daftarnya, dan alarmnya
 *   dashboard.manage_tagihan — menambah, mengubah, menonaktifkan, menandai bayar
 *
 * Keduanya ditegakkan middleware di rutenya. Tombol yang disembunyikan di
 * tampilan bukan wewenang; ia hanya membuat kebocoran lebih sulit ditemukan.
 */
class TagihanController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $tagihan = Tagihan::query()
            ->visibleTo($user)
            ->with('outlet')
            // Aktif lebih dulu, lalu menurut nama. Yang nonaktif TETAP TAMPIL:
            // riwayat pembayarannya harus tetap terbaca, dan itu sebabnya
            // tagihan dinonaktifkan dan tidak pernah dihapus.
            ->orderByDesc('is_active')
            ->orderBy('nama')
            ->get();

        $periode = new PeriodeTagihan($tagihan->where('is_active', true), PeriodeTagihan::hariIni());

        $baris = $tagihan->map(function (Tagihan $satu) use ($periode): array {
            if (! $satu->is_active) {
                return ['tagihan' => $satu, 'jatuh_tempo' => null, 'periode' => null, 'selisih' => null];
            }

            $jatuhTempo = $periode->berjalan($satu);

            return [
                'tagihan' => $satu,
                'jatuh_tempo' => $jatuhTempo,
                'periode' => Tagihan::periode($jatuhTempo),
                'selisih' => $periode->selisihHari($jatuhTempo),
            ];
        })->all();

        return view('tagihan.index', [
            'baris' => $baris,
            'ambang' => Pengaturan::ambilAmbangTagihan(),
            'ambangMaks' => Pengaturan::AMBANG_MAKS,
            'riwayat' => $this->riwayatTerakhir($tagihan->pluck('id')->all()),
            'bolehKelola' => $user->canManageTagihan(),
        ]);
    }

    public function create(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('tagihan.create', [
            'outlets' => Outlet::query()->visibleTo($user)->orderBy('name')->get(),
        ]);
    }

    public function store(TagihanRequest $request): RedirectResponse
    {
        $tagihan = Tagihan::create($request->tersimpan());

        return redirect()->route('tagihan.index')
            ->with('status', 'Tagihan "'.$tagihan->nama.'" ditambahkan. '.$tagihan->jadwalTerbaca().'.');
    }

    public function edit(Request $request, Tagihan $tagihan): View
    {
        /** @var User $user */
        $user = $request->user();

        $this->pastikanTerlihat($user, $tagihan);

        return view('tagihan.edit', [
            'tagihan' => $tagihan,
            'outlets' => Outlet::query()->visibleTo($user)->orderBy('name')->get(),
        ]);
    }

    public function update(TagihanRequest $request, Tagihan $tagihan): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->pastikanTerlihat($user, $tagihan);

        $tagihan->update($request->tersimpan());

        return redirect()->route('tagihan.index')
            ->with('status', 'Tagihan "'.$tagihan->nama.'" diperbarui.');
    }

    /**
     * Mengaktifkan atau menonaktifkan — TIDAK ADA TOMBOL HAPUS, dan itu
     * keputusan, bukan kelalaian. (API-73 kriteria 1 dan 2)
     *
     * Tagihan yang dihapus membawa riwayat pembayarannya ikut hilang, dan
     * "kapan terakhir internet outlet ini dibayar" kehilangan jawabannya tanpa
     * siapa pun sadar ia pernah punya satu.
     */
    public function status(Request $request, Tagihan $tagihan): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->pastikanTerlihat($user, $tagihan);

        $aktif = $request->boolean('aktif');

        $tagihan->update(['is_active' => $aktif]);

        return redirect()->route('tagihan.index')->with(
            'status',
            $aktif
                ? 'Tagihan "'.$tagihan->nama.'" diaktifkan lagi.'
                : 'Tagihan "'.$tagihan->nama.'" dinonaktifkan. Alarmnya padam; riwayat pembayarannya tetap terbaca.'
        );
    }

    /**
     * Ambang pengingat — berapa hari sebelum jatuh tempo alarmnya menyala.
     *
     * Tiga hari itu usulan, bukan hasil pengukuran, dan karena itu harus bisa
     * dikoreksi dari halaman. Kotaknya duduk di sini, bukan di halaman Setelan
     * tersendiri: halaman yang isinya satu kotak adalah halaman yang tidak ada
     * yang tahu harus dibuka.
     */
    public function ambang(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ambang_hari' => ['required', 'integer', 'min:1', 'max:'.Pengaturan::AMBANG_MAKS],
        ], [], ['ambang_hari' => 'ambang pengingat']);

        Pengaturan::simpanAmbangTagihan((int) $data['ambang_hari']);

        return redirect()->route('tagihan.index')
            ->with('status', 'Alarm tagihan mulai menyala '.$data['ambang_hari'].' hari sebelum jatuh tempo.');
    }

    /**
     * Cakupan outlet ditegakkan di tiap tindakan, bukan hanya di daftarnya.
     *
     * Wewenang mengelola tagihan tidak memperluas outlet mana yang boleh
     * disentuh. Tidak terlihat dan tidak boleh dijawab sama — 404, bukan 403,
     * dengan alasan yang sama seperti MenyaringOutlet.
     */
    private function pastikanTerlihat(User $user, Tagihan $tagihan): void
    {
        abort_unless(
            Tagihan::query()->visibleTo($user)->whereKey($tagihan->id)->exists(),
            404
        );
    }

    /**
     * Penandaan terakhir tiap tagihan, untuk menjawab "ini terakhir diurus
     * kapan, oleh siapa".
     *
     * @param  list<int>  $tagihanId
     * @return array<int, PembayaranTagihan>
     */
    private function riwayatTerakhir(array $tagihanId): array
    {
        if ($tagihanId === []) {
            return [];
        }

        // Satu kueri, lalu diambil yang terbaru per tagihan di memori.
        // Jumlahnya terikat: satu baris per tagihan per periode, dan daftar
        // tagihannya belasan baris. Window function tidak dipakai karena
        // sqlite yang dipakai suite test dan MySQL produksi menulisnya
        // berbeda, dan yang dihemat tidak sebanding.
        $hasil = [];

        PembayaranTagihan::query()
            ->whereIn('tagihan_id', $tagihanId)
            ->with('user')
            ->orderBy('periode')
            ->get()
            ->each(function (PembayaranTagihan $baris) use (&$hasil): void {
                $hasil[$baris->tagihan_id] = $baris;
            });

        return $hasil;
    }
}

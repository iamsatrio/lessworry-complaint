<?php

namespace App\Http\Controllers;

use App\Models\PembayaranTagihan;
use App\Models\Tagihan;
use App\Models\User;
use App\Services\PeriodeTagihan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Sudah dibayar" untuk satu tagihan pada satu periode. (API-73)
 *
 * PER PERIODE, bukan sekali selamanya: membayar tagihan Oktober tidak
 * memadamkan tagihan November. Yang disimpan siapa menandai dan kapan — BUKAN
 * bukti bayar. Ini pengingat, bukan pembukuan, dan jarak antara keduanya lebih
 * pendek daripada kelihatannya kalau tidak dijaga.
 *
 * Wewenangnya `dashboard.manage_tagihan`, ditegakkan middleware di rutenya.
 * Berbeda dari penandaan alarm complaint — yang boleh dilakukan siapa pun yang
 * membaca papan — karena tindakan ini MEMADAMKAN alarmnya, bukan menyatakan
 * seseorang sedang memegangnya.
 */
class PembayaranTagihanController extends Controller
{
    public function store(Request $request, Tagihan $tagihan): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->pastikanTerlihat($user, $tagihan);

        if (! $tagihan->is_active) {
            return back(fallback: route('tagihan.index'))
                ->with('warning', 'Tagihan "'.$tagihan->nama.'" sedang nonaktif — tidak ada periode yang berjalan.');
        }

        $periode = new PeriodeTagihan([$tagihan], PeriodeTagihan::hariIni());
        $berjalan = Tagihan::periode($periode->berjalan($tagihan));

        $diminta = (string) $request->input('periode');

        // Periode yang ditandai ditentukan SERVER, dan yang dikirim halaman
        // hanya dicocokkan. Halaman yang dibuka sejak kemarin — atau sejak
        // rekan menandainya setengah jam lalu — menunjuk periode yang sudah
        // lewat, dan menandainya akan memadamkan alarm periode yang salah.
        // Itu terbaca sebagai "sudah beres" tanpa satu pun tanda keliru.
        if ($diminta !== $berjalan) {
            return back(fallback: route('tagihan.index'))->with(
                'warning',
                'Halaman sudah berubah — periode berjalan "'.$tagihan->nama.'" sekarang '
                    .PeriodeTagihan::periodeTerbaca($berjalan).'. Muat ulang, lalu tandai lagi.'
            );
        }

        // insertOrIgnore, bukan updateOrCreate: yang pertama menandai adalah
        // yang tercatat. Sama dengan PapanAlarm::tandai, dan alasannya sama.
        PembayaranTagihan::query()->insertOrIgnore([
            'tagihan_id' => $tagihan->id,
            'periode' => $berjalan,
            'user_id' => $user->id,
            'ditandai_pada' => now(),
        ]);

        return back(fallback: route('tagihan.index'))->with(
            'status',
            '"'.$tagihan->nama.'" ditandai dibayar untuk '.PeriodeTagihan::periodeTerbaca($berjalan)
                .'. Alarm periode berikutnya tetap terbit pada waktunya.'
        );
    }

    /**
     * Membatalkan penandaan yang salah periode.
     *
     * Ada karena penandaan yang tidak bisa dibatalkan menyembunyikan tagihan
     * yang benar-benar jatuh tempo — persis kegagalan yang bikin fitur ini
     * dibuat. Barisnya dihapus dan tidak disisakan jejak: ini pengingat, bukan
     * pembukuan, dan penandaan yang salah bukan peristiwa yang perlu disimpan.
     */
    public function destroy(Request $request, Tagihan $tagihan, string $periode): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->pastikanTerlihat($user, $tagihan);

        $terhapus = PembayaranTagihan::query()
            ->where('tagihan_id', $tagihan->id)
            ->where('periode', $periode)
            ->delete();

        return back(fallback: route('tagihan.index'))->with(
            'status',
            $terhapus > 0
                ? 'Penandaan "'.$tagihan->nama.'" untuk '.PeriodeTagihan::periodeTerbaca($periode).' dibatalkan.'
                : 'Tidak ada penandaan "'.$tagihan->nama.'" untuk periode itu.'
        );
    }

    /**
     * Cakupan outlet ditegakkan DI SINI juga, bukan hanya di daftarnya.
     *
     * Wewenang mengelola tagihan tidak memperluas outlet mana yang boleh
     * disentuh: pengelola yang cakupannya satu outlet tetap tidak bisa
     * menandai tagihan outlet lain lewat permintaan langsung. Tidak terlihat
     * dan tidak boleh dijawab sama — 404, bukan 403 (lihat MenyaringOutlet).
     */
    private function pastikanTerlihat(User $user, Tagihan $tagihan): void
    {
        abort_unless(
            Tagihan::query()->visibleTo($user)->whereKey($tagihan->id)->exists(),
            404
        );
    }
}

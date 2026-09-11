<?php

namespace App\Http\Controllers;

use App\Alarms\Lingkup;
use App\Models\User;
use App\Services\PapanAlarm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Sudah saya tangani" — satu orang mengangkat tangan atas sebuah alarm.
 * (API-72 bagian 1)
 *
 * Penandaan ini BUKAN penutupan. Ia menyatakan *aku memegangnya*, terlihat
 * semua orang lengkap dengan nama dan jam, dan berlaku satu hari: kalau
 * keadaannya masih ada besok, alarmnya menyala lagi.
 *
 * Wewenangnya `dashboard.view`, ditegakkan middleware di rutenya — siapa yang
 * boleh membaca papan boleh mengangkat tangan di sana.
 */
class PenandaAlarmController extends Controller
{
    public function __construct(private PapanAlarm $papan) {}

    public function store(Request $request, string $alarm): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $terdaftar = $this->papan->cari($alarm);

        // Alarm yang tidak terdaftar bukan soal wewenang — tidak ada yang bisa
        // dipegang, jadi 404 dan bukan 403.
        abort_if($terdaftar === null, 404);

        // Cakupannya pengguna itu sendiri, TANPA saringan outlet: penandaan
        // berlaku untuk alarmnya, bukan untuk potongan outlet yang sedang
        // dilihat. Dua orang yang menyaring outlet berbeda tetap membaca satu
        // pemegang yang sama — kalau tidak, keduanya sama-sama mengira orang
        // lain sudah menanganinya, yang justru kegagalan yang mau dicegah.
        if ($terdaftar->periksa(new Lingkup($user)) === null) {
            return back(fallback: route('operasional'))->with(
                'warning',
                'Alarm "'.$terdaftar->judul().'" sudah padam — tidak ada yang perlu dipegang.'
            );
        }

        $penanda = $this->papan->tandai($terdaftar, $user);

        return back(fallback: route('operasional'))->with('status', $penanda->user_id === $user->id
            ? 'Tercatat. Rekan-rekanmu melihat kamu yang memegang "'.$terdaftar->judul().'" hari ini.'
            : ($penanda->user->name ?? 'Orang lain').' sudah memegang "'.$terdaftar->judul().'" lebih dulu hari ini.');
    }
}

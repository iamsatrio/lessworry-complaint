<?php

namespace App\Http\Controllers;

use App\Alarms\Lingkup;
use App\Http\Requests\AlarmFilterRequest;
use App\Models\Outlet;
use App\Models\User;
use App\Services\PapanAlarm;

/**
 * Dashboard Operations — halaman yang menjawab SATU pertanyaan: ada yang perlu
 * ditangani sekarang? (API-72)
 *
 * Yang sengaja tidak ada di sini: grafik, tren, dan angka pencapaian. Semuanya
 * dibaca sebulan sekali, bukan tiap pagi, dan halaman yang menuntut lima menit
 * setiap pagi akan berhenti dibuka dalam dua minggu. Ukuran yang menentukan
 * rancangan halaman ini: hari normal selesai dalam 30 detik. (API-71 alur 6)
 */
class OperasionalController extends Controller
{
    public function __construct(private PapanAlarm $papan) {}

    public function __invoke(AlarmFilterRequest $request)
    {
        /** @var User $user */
        $user = $request->user();

        $outlet = $request->outletDiminta();

        return view('operasional.index', [
            // Satu jalur data untuk seluruh papan: Lingkup yang memanggil
            // Complaint::visibleTo, bukan kueri yang ditulis ulang per alarm.
            'alarms' => $this->papan->untuk(new Lingkup($user, $outlet)),
            'outlet' => $outlet,
            // Daftarnya hanya outlet yang boleh dilihat pengguna ini. Yang
            // menegakkannya tetap sisi server: AlarmFilterRequest menolak
            // permintaan langsung dengan outlet lain.
            'pilihanOutlet' => Outlet::query()->visibleTo($user)->orderBy('name')->get(),
        ]);
    }
}

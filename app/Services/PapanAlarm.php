<?php

namespace App\Services;

use App\Alarms\Alarm;
use App\Alarms\DiPapan;
use App\Alarms\Lingkup;
use App\Models\PenandaAlarm;
use App\Models\User;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Papan alarm: yang tahu alarm apa saja terdaftar, mana yang sedang menyala,
 * dan siapa yang sudah memegangnya hari ini. (API-72 bagian 1)
 *
 * Alur hidup sebuah alarm ada di API-71 alur 2:
 *
 *   PADAM ──keadaan terpenuhi──► MENYALA ──ditandai──► DITANGANI
 *     ▲                             ▲                     │
 *     └────keadaan hilang───────────┴──keadaan masih ada───┘
 *
 * Yang memadamkan alarm HANYA keadaannya berubah. Menandai "sudah saya
 * tangani" menyatakan *aku memegangnya*, bukan *masalahnya selesai* — dan
 * karena itu penandaannya berlaku satu hari saja.
 *
 * Ini BUKAN sistem penugasan: tidak ada penetapan pemilik, tenggat per alarm,
 * atau notifikasi ke orang tertentu. Itu sistem tiket, dan Less Worry sudah
 * punya satu.
 */
final class PapanAlarm
{
    /** @var list<Alarm> */
    private array $alarms;

    public function __construct()
    {
        $this->alarms = [];

        /** @var array<int,mixed> $terdaftar */
        $terdaftar = (array) config('complaint.alarms.terdaftar', []);

        foreach ($terdaftar as $kelas) {
            $alarm = is_string($kelas) ? app($kelas) : $kelas;

            // Daftar alarm datang dari config, jadi kesalahan tulis di sana
            // harus berbunyi di sini — bukan berubah jadi papan yang diam-diam
            // kehilangan satu alarm. Papan yang kehilangan alarm terlihat sama
            // dengan papan yang alarmnya padam.
            if (! $alarm instanceof Alarm) {
                throw new RuntimeException('Alarm terdaftar tidak mengimplementasikan App\Alarms\Alarm: '
                    .(is_string($kelas) ? $kelas : get_debug_type($kelas)));
            }

            $this->alarms[] = $alarm;
        }
    }

    /**
     * Alarm yang MENYALA untuk cakupan ini, beserta penandaan hari ini.
     *
     * Yang padam tidak ikut — papan hanya berisi yang perlu ditangani.
     *
     * @return list<DiPapan>
     */
    public function untuk(Lingkup $lingkup): array
    {
        $menyala = [];

        foreach ($this->alarms as $alarm) {
            $nyala = $alarm->periksa($lingkup);

            if ($nyala !== null) {
                $menyala[$alarm->kunci()] = [$alarm, $nyala];
            }
        }

        if ($menyala === []) {
            return [];
        }

        // Satu kueri untuk seluruh penanda, bukan satu per alarm: jumlah alarm
        // akan bertambah (tagihan, saldo koin, nota terlambat), dan halaman ini
        // dibuka tiap pagi.
        $penanda = PenandaAlarm::query()
            ->whereIn('alarm', array_keys($menyala))
            ->whereDate('untuk_tanggal', self::tanggalOperasional())
            ->with('user')
            ->get()
            ->keyBy('alarm');

        $hasil = [];

        foreach ($menyala as $kunci => [$alarm, $nyala]) {
            $hasil[] = new DiPapan($alarm, $nyala, $penanda->get($kunci));
        }

        return $hasil;
    }

    /** Alarm terdaftar dengan kunci ini, atau null. */
    public function cari(string $kunci): ?Alarm
    {
        foreach ($this->alarms as $alarm) {
            if ($alarm->kunci() === $kunci) {
                return $alarm;
            }
        }

        return null;
    }

    /**
     * Catat "sudah saya tangani". Balasannya pemegang yang BERLAKU — belum
     * tentu orang yang baru menekan tombolnya.
     *
     * insertOrIgnore, bukan updateOrCreate: kalau sudah ada yang memegangnya
     * hari ini, namanya tidak ditimpa. Dua orang membuka dashboard pagi yang
     * sama adalah keadaan normal di sini, bukan kasus tepi — dan yang pertama
     * mengangkat tangan adalah yang benar-benar sedang mengerjakannya.
     */
    public function tandai(Alarm $alarm, User $user): PenandaAlarm
    {
        $tanggal = self::tanggalOperasional();

        PenandaAlarm::query()->insertOrIgnore([
            'alarm' => $alarm->kunci(),
            'untuk_tanggal' => $tanggal,
            'user_id' => $user->id,
            'ditandai_pada' => now(),
        ]);

        /** @var PenandaAlarm $penanda */
        $penanda = PenandaAlarm::query()
            ->where('alarm', $alarm->kunci())
            ->whereDate('untuk_tanggal', $tanggal)
            ->with('user')
            ->firstOrFail();

        return $penanda;
    }

    /**
     * Tanggal yang menentukan kapan "besok" mulai.
     *
     * Aplikasi berjalan di UTC (config/app.php), dan hari UTC berganti pukul
     * 07.00 WIB — di tengah jam kerja. Tanpa zona operasional, alarm yang
     * ditandai pukul 06.30 menyala lagi setengah jam kemudian, dan yang
     * ditandai pukul 08.00 padam sampai pukul 07.00 besok. Keduanya bukan
     * "besok" yang dimaksud orang yang membacanya.
     */
    public static function tanggalOperasional(): string
    {
        $zona = (string) config('complaint.alarms.zona_waktu', 'Asia/Jakarta');

        return Carbon::now($zona)->toDateString();
    }
}

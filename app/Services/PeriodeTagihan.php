<?php

namespace App\Services;

use App\Models\PembayaranTagihan;
use App\Models\Tagihan;
use Carbon\CarbonImmutable;

/**
 * Periode mana dari sebuah tagihan yang SEDANG berjalan — yaitu jatuh tempo
 * yang masih menunggu ditandai dibayar. (API-73)
 *
 * Ditulis sekali di sini karena dua tempat menanyakannya dan keduanya harus
 * menjawab sama: alarm `TagihanJatuhTempo` (apakah menyala) dan halaman
 * Tagihan (tombol "Tandai dibayar" menandai periode yang mana). Dua tempat
 * yang menghitungnya sendiri-sendiri akan berpisah persis di kasus yang paling
 * jarang diuji — akhir bulan, dan tanggal 31 di bulan yang tidak punya
 * tanggal 31. Tombol yang menandai periode berbeda dari yang dikeluhkan alarm
 * memadamkan alarm yang salah, dan orang membacanya sebagai "sudah beres".
 *
 * ## Jendelanya tiga periode, dan itu keputusan
 *
 * Yang diperiksa hanya: jatuh tempo terakhir yang SUDAH tiba, lalu dua yang
 * akan datang. Bukan seluruh riwayat.
 *
 * Alasannya kegunaan, bukan kinerja. Tagihan yang tidak pernah sekali pun
 * ditandai sejak Januari akan berteriak "telat 270 hari" tiap pagi — angka
 * yang benar secara aritmetika dan tidak berguna sama sekali, karena yang
 * sebenarnya terjadi adalah orang belum mulai memakai penandaannya. Alarm yang
 * berteriak begitu akan diabaikan dalam seminggu, dan sesudahnya ia berhenti
 * menyampaikan apa pun — termasuk tagihan yang benar-benar telat besok.
 *
 * Yang ditanyakan alarm ini satu: **tagihan periode ini sudah diurus atau
 * belum.** Riwayat yang lebih tua tetap terbaca di halaman Tagihan, tempat
 * orang memang sedang mencarinya.
 *
 * ## Kenapa di PHP, bukan di SQL
 *
 * docs/alarm.md mewajibkan penyaringan di SQL karena tabel complaint berisi
 * ratusan baris dan tumbuh terus. Tagihan bukan tabel itu — ia daftar tulisan
 * tangan berisi belasan baris — dan aturan "hari terakhir bulan" ditulis
 * berbeda di sqlite dan MySQL. Dua dialek tanggal yang harus sepakat soal
 * Februari adalah dua tempat yang akan berpisah diam-diam. Jumlah kuerinya
 * tetap DUA berapa pun banyak tagihannya.
 */
final class PeriodeTagihan
{
    /** @var array<int, list<CarbonImmutable>> jendela per tagihan, paling tua lebih dulu */
    private array $jendela = [];

    /** @var array<int, array<string, true>> */
    private array $lunas = [];

    /** @param  iterable<Tagihan>  $tagihan */
    public function __construct(iterable $tagihan, public readonly CarbonImmutable $hariIni)
    {
        foreach ($tagihan as $satu) {
            $this->jendela[$satu->id] = self::jendela($satu, $hariIni);
        }

        $this->lunas = $this->muatLunas();
    }

    /**
     * Tanggal hari ini menurut zona operasional — zona yang sama dengan
     * PapanAlarm::tanggalOperasional().
     *
     * Dikembalikan sebagai TANGGAL POLOS: tengah malam di zona bawaan
     * aplikasi, bukan di Asia/Jakarta. Jatuh tempo dihitung dari kalender,
     * bukan dari jam, dan dua nilai yang zonanya berbeda selisihnya bergeser
     * tujuh jam — cukup untuk membuat `(int)` selisih hari turun satu, jadi
     * "telat 6 hari" tertulis "telat 5 hari". Salah satu hari, tiap hari,
     * tanpa satu pun tanda.
     */
    public static function hariIni(): CarbonImmutable
    {
        return self::tanggal(CarbonImmutable::now(self::zona()));
    }

    private static function zona(): string
    {
        return (string) config('complaint.alarms.zona_waktu', 'Asia/Jakarta');
    }

    /** Tanggal kalender operasional sebuah waktu, sebagai tanggal polos. */
    private static function tanggal(mixed $waktu): CarbonImmutable
    {
        return CarbonImmutable::parse(
            CarbonImmutable::parse($waktu)->setTimezone(self::zona())->toDateString()
        );
    }

    /**
     * Jatuh tempo yang masih mungkin relevan untuk tagihan ini, paling tua
     * lebih dulu: yang terakhir sudah tiba (kalau ada), lalu dua berikutnya.
     *
     * Yang jatuh tempo SEBELUM tagihannya dicatat tidak pernah ikut — tagihan
     * yang dimasukkan hari ini bukan tagihan yang menunggak bulan lalu, dan
     * menyalakannya di hari pertama adalah cara tercepat membuat orang
     * berhenti memakai halaman ini.
     *
     * @return list<CarbonImmutable>
     */
    public static function jendela(Tagihan $tagihan, CarbonImmutable $hariIni): array
    {
        $ini = $tagihan->jatuhTempoDi($hariIni);

        // Jatuh tempo terakhir yang sudah tiba. Kalau yang di periode ini
        // belum lewat, yang sudah tiba adalah periode sebelumnya.
        $tiba = $ini->lessThanOrEqualTo($hariIni) ? $ini : $tagihan->jatuhTempoSebelum($ini);

        // Yang berikutnya ikut karena "beberapa hari lagi" bisa menyeberang
        // bulan: tagihan tanggal 2 sudah harus terbaca pada tanggal 30.
        $lanjut = $tiba->equalTo($ini) ? $tagihan->jatuhTempoSetelah($ini) : $ini;
        $lanjut2 = $tagihan->jatuhTempoSetelah($lanjut);

        $dibuat = self::tanggal($tagihan->created_at ?? $hariIni);

        return $tiba->greaterThanOrEqualTo($dibuat)
            ? [$tiba, $lanjut, $lanjut2]
            : [$lanjut, $lanjut2];
    }

    /**
     * Jatuh tempo yang sedang menunggu ditandai dibayar.
     *
     * Yang tertua di jendela lebih dulu: periode yang sudah tiba dan belum
     * ditandai lebih mendesak daripada yang baru akan datang. Kalau seluruh
     * jendela sudah ditandai — orang membayar di muka — yang dijawab periode
     * paling akhir, dan alarmnya padam karena jaraknya jauh.
     */
    public function berjalan(Tagihan $tagihan): CarbonImmutable
    {
        $jendela = $this->jendela[$tagihan->id] ?? self::jendela($tagihan, $this->hariIni);

        foreach ($jendela as $jatuhTempo) {
            if (! $this->sudahDibayar($tagihan, Tagihan::periode($jatuhTempo))) {
                return $jatuhTempo;
            }
        }

        return $jendela[count($jendela) - 1];
    }

    public function sudahDibayar(Tagihan $tagihan, string $periode): bool
    {
        return isset($this->lunas[$tagihan->id][$periode]);
    }

    /** Selisih hari ke jatuh tempo: negatif berarti sudah lewat. */
    public function selisihHari(CarbonImmutable $jatuhTempo): int
    {
        return (int) $this->hariIni->diffInDays($jatuhTempo, false);
    }

    /**
     * Periode yang sudah ditandai dibayar, dikelompokkan per tagihan.
     *
     * Satu kueri untuk seluruh tagihan, bukan satu per tagihan.
     *
     * @return array<int, array<string, true>>
     */
    private function muatLunas(): array
    {
        if ($this->jendela === []) {
            return [];
        }

        $periode = [];

        foreach ($this->jendela as $daftar) {
            foreach ($daftar as $tanggal) {
                $periode[Tagihan::periode($tanggal)] = true;
            }
        }

        $hasil = [];

        PembayaranTagihan::query()
            ->whereIn('tagihan_id', array_keys($this->jendela))
            ->whereIn('periode', array_keys($periode))
            ->get(['tagihan_id', 'periode'])
            ->each(function (PembayaranTagihan $baris) use (&$hasil): void {
                $hasil[$baris->tagihan_id][$baris->periode] = true;
            });

        return $hasil;
    }

    /** Berapa hari terlambat, atau berapa hari lagi. (API-73 kriteria 5) */
    public static function keterangan(int $selisih): string
    {
        return match (true) {
            $selisih < 0 => 'Telat '.abs($selisih).' hari',
            $selisih === 0 => 'Hari ini',
            $selisih === 1 => 'Besok',
            default => $selisih.' hari lagi',
        };
    }

    /** "Oktober 2026" — periode 'YYYY-MM' yang bisa dibaca orang. */
    public static function periodeTerbaca(string $periode): string
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $periode.'-01')
            ->locale('id')
            ->translatedFormat('F Y');
    }
}

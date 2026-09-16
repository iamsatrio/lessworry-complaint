<?php

namespace App\Services;

use App\Models\Complaint;
use Illuminate\Support\Carbon;

/**
 * Kapan barang benar-benar berpindah ke tangan pelanggan. (API-48)
 *
 * Tanpa angka ini supervisor tidak bisa menjawab "complaint ini datang berapa
 * lama setelah barangnya diambil?", dan syarat "komplain maksimal 3x24 jam"
 * yang tercetak di nota tidak punya satu pun cara untuk diperiksa.
 *
 * ## Dari mana tanggalnya
 *
 * Dua jalur, keduanya jejak yang dicatat NEVIRA sendiri:
 *
 * 1. **Diambil sendiri di outlet** — `services[].service_process_log` berisi
 *    `diambil_customer` ("Diambil oleh Customer"), lengkap dengan foto bukti.
 * 2. **Diantar kurir** — baris pengantaran yang berawal ANTAR
 *    (`initial_status` '1') dan sudah selesai. `delivery_date`-nya tanggal
 *    barang sampai.
 *
 * Kalau nota punya keduanya, yang dipakai yang LEBIH BARU: itu saat terakhir
 * pelanggan memegang barangnya.
 *
 * ## Yang sengaja TIDAK dipakai
 *
 * - **`completion_date` transaksi.** Namanya menjanjikan, isinya tidak ada:
 *   kosong di 953 dari 953 transaksi yang diperiksa April–September 2026,
 *   termasuk 702 yang berstatus COMPLETED. Memakainya berarti seluruh order
 *   ambil-sendiri tercatat "tidak diketahui".
 * - **Stempel `diantar_kurir`.** Itu saat barang KELUAR outlet dibawa kurir,
 *   bukan saat pelanggan menerimanya. Pada 19 dari 21 nota keduanya jatuh di
 *   hari yang sama, tapi pada 2 nota pelanggan baru menerima 10 dan 15 hari
 *   kemudian. Yang menandai penerimaan adalah selesainya baris pengantaran.
 * - **`created_at` complaint.** Memakainya membuat jarak hari selalu nol, dan
 *   angka nol yang salah tidak terlihat berbeda dari angka nol yang benar.
 *
 * Kalau tidak satu pun jalur memberi tanggal, jawabannya `tidak_diketahui` —
 * bukan perkiraan.
 */
final class TanggalPengambilan
{
    public const AMBIL_SENDIRI = 'diambil_customer';

    public const ANTAR = 'antar';

    public const MANUAL = 'manual';

    public const TIDAK_DIKETAHUI = 'tidak_diketahui';

    /** Nama kejadian di jejak NEVIRA yang berarti pelanggan mengambil sendiri. */
    private const AKTIVITAS_AMBIL_SENDIRI = 'diambil_customer';

    /**
     * Tanggal pengambilan complaint ini menurut snapshot NEVIRA-nya.
     *
     * @return array{tanggal:?string,sumber:string}
     */
    public function untuk(Complaint $complaint): array
    {
        $kandidat = array_values(array_filter([
            $this->dariJejakAmbilSendiri($complaint),
            $this->dariPengantaranSelesai($complaint),
        ]));

        if ($kandidat === []) {
            return $this->pertahankanYangDiketikOrang($complaint);
        }

        // Yang lebih baru menang. Kalau tanggalnya sama, jejak ambil-sendiri
        // yang dipakai: ia punya stempel sampai detiknya, sementara baris
        // pengantaran hanya punya tanggalnya.
        usort(
            $kandidat,
            fn (array $a, array $b) => [$a['tanggal'], $a['sumber'] === self::AMBIL_SENDIRI]
                <=> [$b['tanggal'], $b['sumber'] === self::AMBIL_SENDIRI],
        );

        return $kandidat[count($kandidat) - 1];
    }

    /**
     * Sinkron yang tidak menemukan apa pun TIDAK menghapus tanggal yang sudah
     * diketik orang dari spreadsheet lama.
     *
     * Complaint impor tidak pernah tertaut ke nota NEVIRA — nomor nota lama
     * tidak unik — jadi sinkron atasnya selalu pulang tangan kosong. Menimpa
     * 84 tanggal yang susah payah terkumpul dengan `tidak_diketahui` adalah
     * kehilangan data, bukan pembaruan.
     *
     * @return array{tanggal:?string,sumber:string}
     */
    private function pertahankanYangDiketikOrang(Complaint $complaint): array
    {
        if ($complaint->sumber_tanggal_pengambilan === self::MANUAL && $complaint->tanggal_pengambilan !== null) {
            return ['tanggal' => $complaint->tanggal_pengambilan->toDateString(), 'sumber' => self::MANUAL];
        }

        return ['tanggal' => null, 'sumber' => self::TIDAK_DIKETAHUI];
    }

    /** @return array{tanggal:string,sumber:string}|null */
    private function dariJejakAmbilSendiri(Complaint $complaint): ?array
    {
        $snapshot = $complaint->nevira_snapshot ?? [];
        $jejak = $snapshot['handovers'] ?? [];

        if (! is_array($jejak)) {
            return null;
        }

        $baris = collect($jejak)
            ->filter(fn ($row) => is_array($row)
                && ($row['activity'] ?? null) === self::AKTIVITAS_AMBIL_SENDIRI
                && filled($row['at'] ?? null));

        // Satu nota bisa berisi sepuluh barang yang diserahkan bertahap.
        // Kalau keluhannya menunjuk satu barang, yang berlaku tanggal barang
        // ITU — bukan barang terakhir yang kebetulan diambil sebulan
        // kemudian. Kalau baris yang dikeluhkan tidak punya jejaknya sendiri,
        // seluruh nota yang dipakai. (API-51)
        $index = $complaint->nevira_service_index;

        if ($index !== null) {
            $milikBaris = $baris->filter(fn ($row) => (int) ($row['service_index'] ?? 0) === $index);

            if ($milikBaris->isNotEmpty()) {
                $baris = $milikBaris;
            }
        }

        $stempel = $baris->max(fn ($row) => (string) $row['at']);

        if (! is_string($stempel) || $stempel === '') {
            return null;
        }

        $tanggal = $this->tanggalOperasional($stempel);

        return $tanggal === null ? null : ['tanggal' => $tanggal, 'sumber' => self::AMBIL_SENDIRI];
    }

    /** @return array{tanggal:string,sumber:string}|null */
    private function dariPengantaranSelesai(Complaint $complaint): ?array
    {
        $selesai = array_map('intval', (array) config('nevira.delivery_done_status'));
        $antar = (string) config('nevira.delivery_initial_antar');

        $tanggal = collect($complaint->deliveries())
            ->filter(fn (array $row) => in_array((int) $row['status_code'], $selesai, true)
                && (string) ($row['initial_status'] ?? '') === $antar
                && filled($row['date']))
            ->max(fn (array $row) => substr((string) $row['date'], 0, 10));

        return is_string($tanggal) && $tanggal !== ''
            ? ['tanggal' => $tanggal, 'sumber' => self::ANTAR]
            : null;
    }

    /**
     * Tanggal sebuah stempel UTC menurut jam outlet.
     *
     * Aplikasi berjalan di UTC. Barang yang diserahkan 16 September pukul
     * 06.00 WIB berstempel 15 September 23.00 UTC — tanpa konversi ini, satu
     * dari setiap serah terima pagi tercatat mundur sehari.
     */
    private function tanggalOperasional(string $stempel): ?string
    {
        try {
            return Carbon::parse($stempel)
                ->setTimezone((string) config('complaint.zona_operasional'))
                ->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}

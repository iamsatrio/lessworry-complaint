<?php

namespace App\Support;

/**
 * Mengambil pengenal nota dari nota WhatsApp yang ditempel kasir. (API-26)
 *
 * Kenapa menempel, bukan mengetik: dari 500 nomor nota di riwayat complaint,
 * NOL berformat `INV/…`. Tim menulis angka pendek — `4348`, `2138 (Juli)`,
 * `929/1` — dan angka pendek itu tidak unik; 132 baris membubuhkan nama bulan
 * dengan tangan justru untuk membedakannya. Jadi form yang meminta `INV/…`
 * meminta bentuk yang belum pernah ditulis siapa pun, dan form yang menerima
 * angka pendek akan menautkan complaint ke order orang lain.
 *
 * Jalan keluarnya bukan mengubah apa yang diminta, melainkan berhenti meminta:
 * pelanggan menerima nota elektronik lewat WhatsApp, dan isinya bisa
 * disalin-tempel.
 *
 * PENTING — kelas ini hanya MENGAMBIL. Teks yang ditempel memuat alamat,
 * nomor telepon outlet, dan saldo deposit pelanggan; tidak ada satu pun
 * jalan dari sini yang menyimpannya. Yang keluar hanya pengenal.
 *
 * Polanya dipakai dua kali: di sini untuk diuji, dan di form intake yang
 * mengambilnya di peramban supaya teks mentahnya tidak pernah dikirim ke
 * server sama sekali. Satu sumber supaya keduanya tidak berbeda diam-diam.
 */
final class PolaNota
{
    /**
     * Nomor nota. Pengenal pasti, dan satu-satunya yang dipakai memanggil
     * NEVIRA.
     */
    public const INV = '(INV/\d+/\d+/\d+)';

    /**
     * Tautan webstruk. CADANGAN — disimpan sebagai rujukan manusia saat pola
     * INV tidak ketemu.
     *
     * Belum diketahui apakah ada endpoint NEVIRA yang menerima token ini,
     * dan menebaknya bukan tugas kelas ini.
     */
    public const WEBSTRUK = 'nevira\.id/webstruk/([A-Za-z0-9]+)';

    /** Tanggal masuk, untuk dicocokkan kasir — bukan untuk menggantikan NEVIRA. */
    public const MASUK = 'Masuk\s*:\s*(\d{2}-\d{2}-\d{4})';

    /**
     * Nota WhatsApp yang wajar jauh di bawah ini. Batasnya ada supaya
     * tempelan raksasa ditolak sebagai kalimat, bukan dijalankan melewati
     * regex sampai halamannya menggantung.
     */
    public const MAKS_PANJANG = 4000;

    /**
     * @return array{
     *     invoice: ?string,
     *     webstruk: ?string,
     *     masuk: ?string,
     *     terlalu_panjang: bool
     * }
     */
    public static function ambil(?string $teks): array
    {
        $kosong = ['invoice' => null, 'webstruk' => null, 'masuk' => null, 'terlalu_panjang' => false];

        if (! is_string($teks) || trim($teks) === '') {
            return $kosong;
        }

        // Panjang diperiksa SEBELUM regex apa pun dijalankan.
        if (mb_strlen($teks) > self::MAKS_PANJANG) {
            return [...$kosong, 'terlalu_panjang' => true];
        }

        return [
            'invoice' => self::cocok(self::INV, $teks),
            'webstruk' => self::cocok(self::WEBSTRUK, $teks),
            'masuk' => self::cocok(self::MASUK, $teks),
            'terlalu_panjang' => false,
        ];
    }

    private static function cocok(string $pola, string $teks): ?string
    {
        return preg_match('#'.$pola.'#', $teks, $m) === 1 ? $m[1] : null;
    }

    /**
     * Pola yang dikirim ke peramban. Sengaja hanya pola — tidak ada data.
     *
     * @return array<string,string|int>
     */
    public static function untukPeramban(): array
    {
        return [
            'inv' => self::INV,
            'webstruk' => self::WEBSTRUK,
            'masuk' => self::MASUK,
            'maks' => self::MAKS_PANJANG,
        ];
    }
}

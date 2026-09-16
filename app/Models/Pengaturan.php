<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Setelan yang boleh diubah dari halaman, bukan dari berkas. (API-73)
 *
 * Hari ini isinya SATU kunci: ambang pengingat tagihan. Itu bukan kekurangan —
 * setelan masuk ke sini hanya kalau orang yang memakainya memang perlu
 * mengubahnya sendiri. Yang ditentukan sekali saat pasang (zona waktu, alamat
 * NEVIRA) tetap di env, dan memindahkannya ke sini hanya memindahkan tempat
 * salah tulisnya.
 *
 * @property string $kunci
 * @property string $nilai
 */
class Pengaturan extends Model
{
    public const TAGIHAN_AMBANG_HARI = 'tagihan.ambang_hari';

    /**
     * Berapa hari sebelum jatuh tempo alarm tagihan mulai menyala.
     *
     * Tiga hari itu USULAN, bukan hasil pengukuran — dipilih supaya tagihan
     * yang jatuh Senin sudah terbaca Jumat. Karena ia tebakan, ia harus bisa
     * dikoreksi tanpa deploy.
     */
    public const AMBANG_BAWAAN = 3;

    public const AMBANG_MAKS = 30;

    protected $table = 'pengaturan';

    protected $primaryKey = 'kunci';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = ['kunci', 'nilai'];

    public static function ambilAmbangTagihan(): int
    {
        $baris = static::query()->find(self::TAGIHAN_AMBANG_HARI);

        // Baris yang belum pernah ditulis, dan baris yang isinya kacau,
        // dijawab sama: pakai bawaannya. Halaman yang melempar karena satu
        // baris setelan tidak terbaca membuat seluruh daftar tagihan hilang
        // demi satu angka yang punya nilai aman.
        $nilai = $baris === null ? 0 : (int) $baris->nilai;

        return $nilai >= 1 && $nilai <= self::AMBANG_MAKS ? $nilai : self::AMBANG_BAWAAN;
    }

    public static function simpanAmbangTagihan(int $hari): void
    {
        static::query()->updateOrCreate(
            ['kunci' => self::TAGIHAN_AMBANG_HARI],
            ['nilai' => (string) $hari],
        );
    }
}

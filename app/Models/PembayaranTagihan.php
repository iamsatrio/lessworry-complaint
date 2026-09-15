<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Penandaan "sudah dibayar" untuk satu tagihan pada satu periode. (API-73)
 *
 * BUKAN bukti bayar. Ini pengingat, bukan pembukuan: tidak ada nominal yang
 * benar-benar dibayar, tidak ada unggahan, tidak ada nomor transaksi. Yang
 * disimpan cuma siapa yang menyatakan dan kapan — cukup untuk menjawab "ini
 * sudah diurus siapa" pagi berikutnya.
 *
 * @property int $id
 * @property int $tagihan_id
 * @property string $periode
 * @property int $user_id
 * @property Carbon $ditandai_pada
 * @property-read User|null $user
 * @property-read Tagihan|null $tagihan
 */
class PembayaranTagihan extends Model
{
    protected $table = 'pembayaran_tagihan';

    /**
     * Sama seperti PenandaAlarm: `ditandai_pada` sudah menjawab kapan, dan
     * baris ini tidak pernah diubah setelah tercatat.
     */
    public $timestamps = false;

    protected $fillable = ['tagihan_id', 'periode', 'user_id', 'ditandai_pada'];

    protected function casts(): array
    {
        return ['ditandai_pada' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Tagihan, $this> */
    public function tagihan(): BelongsTo
    {
        return $this->belongsTo(Tagihan::class);
    }
}

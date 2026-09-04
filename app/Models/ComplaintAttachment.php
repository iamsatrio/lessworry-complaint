<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu berkas bukti. Menempel pada complaint saat dibuat, atau pada satu
 * catatan penanganan kalau complaint_activity_id terisi. (API-20)
 *
 * Selalu di disk privat, selalu keluar lewat rute yang memeriksa wewenang:
 * foto bukti memuat barang pelanggan dan kadang wajahnya.
 */
class ComplaintAttachment extends Model
{
    protected $fillable = [
        'complaint_id', 'complaint_activity_id', 'path', 'thumb_path',
        'original_name', 'mime', 'original_bytes', 'stored_bytes', 'compression_error',
    ];

    protected function casts(): array
    {
        return [
            'original_bytes' => 'integer',
            'stored_bytes' => 'integer',
        ];
    }

    public function complaint()
    {
        return $this->belongsTo(Complaint::class);
    }

    public function activity()
    {
        return $this->belongsTo(ComplaintActivity::class, 'complaint_activity_id');
    }

    /** Berkas yang kompresinya gagal disajikan apa adanya, tanpa versi kecil. */
    public function hasThumb(): bool
    {
        return filled($this->thumb_path);
    }

    /**
     * Keterangan ukuran siap tampil: "4,2 MB → 380 KB (-91%)".
     *
     * Ditulis di halaman, bukan hanya di basis data — penghematan yang tidak
     * pernah dilihat siapa pun tidak bisa diperiksa benar tidaknya.
     */
    public function sizeLabel(): ?string
    {
        if (! $this->original_bytes) {
            return null;
        }

        $label = $this->humanBytes($this->original_bytes);

        if (! $this->stored_bytes || $this->stored_bytes === $this->original_bytes) {
            return $label;
        }

        return $label.' → '.$this->humanBytes($this->stored_bytes).' ('.$this->savedPercent().'% lebih kecil)';
    }

    private function humanBytes(int $bytes): string
    {
        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1, ',', '.').' MB'
            : number_format($bytes / 1024, 0, ',', '.').' KB';
    }

    /** Penghematan dalam persen; null kalau ukurannya tidak tercatat. */
    public function savedPercent(): ?int
    {
        if (! $this->original_bytes || ! $this->stored_bytes) {
            return null;
        }

        return (int) round((1 - $this->stored_bytes / $this->original_bytes) * 100);
    }
}

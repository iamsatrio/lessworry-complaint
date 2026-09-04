<?php

namespace App\Http\Controllers;

use App\Models\Complaint;
use App\Models\ComplaintAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Menyajikan foto bukti lewat pemeriksaan wewenang. (API-20)
 *
 * Berkas ini pernah duduk di disk publik: siapa pun yang memegang URL-nya
 * bisa membuka foto barang pelanggan tanpa login sama sekali. (API-8 T9)
 */
class ComplaintAttachmentController extends Controller
{
    public function show(Request $request, Complaint $complaint, ComplaintAttachment $attachment)
    {
        $this->authorize('viewAttachment', $complaint);
        // 404, bukan 403: ini keutuhan rute, bukan wewenang.
        abort_unless($attachment->complaint_id === $complaint->id, 404);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return $this->sajikan($attachment->path, $attachment->original_name);
    }

    /**
     * Versi kecil untuk lini masa.
     *
     * Wewenangnya diperiksa persis sama dengan berkas penuh — kalau tidak,
     * versi kecil jadi jalan memutar untuk melihat foto yang sama.
     */
    public function thumb(Request $request, Complaint $complaint, ComplaintAttachment $attachment)
    {
        $this->authorize('viewAttachment', $complaint);
        abort_unless($attachment->complaint_id === $complaint->id, 404);

        // Foto yang kompresinya gagal tidak punya versi kecil; yang disajikan
        // berkas penuhnya, bukan 404 yang membuat lini masa terlihat rusak.
        $path = $attachment->thumb_path ?: $attachment->path;

        abort_unless(Storage::disk('local')->exists($path), 404);

        return $this->sajikan($path, $attachment->original_name);
    }

    private function sajikan(string $path, ?string $nama)
    {
        return Storage::disk('local')->response($path, $nama, [
            'Cache-Control' => 'private, max-age=0, no-store',
        ]);
    }
}

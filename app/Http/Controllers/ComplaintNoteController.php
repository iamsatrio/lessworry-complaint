<?php

namespace App\Http\Controllers;

use App\Http\Requests\AddComplaintNoteRequest;
use App\Models\Complaint;
use App\Services\JejakComplaint;
use App\Services\PenyimpanFoto;
use Illuminate\Support\Facades\DB;

/**
 * Catatan penanganan, dengan foto bukti kalau ada. (API-20)
 *
 * Foto sering justru yang menentukan: noda yang tersisa setelah cuci ulang,
 * kondisi barang saat diserahkan kembali. Berkasnya dikecilkan dan
 * dibersihkan dari EXIF sebelum disimpan — lihat PenyimpanFoto.
 */
class ComplaintNoteController extends Controller
{
    public function __construct(
        private PenyimpanFoto $foto,
        private JejakComplaint $jejak,
    ) {}

    public function store(AddComplaintNoteRequest $request, Complaint $complaint)
    {
        $user = $request->user();
        $data = $request->validated();

        // Berkas ditulis sebelum barisnya dibuat, dan kegagalannya tidak
        // menjatuhkan catatan: petugas sudah mengetik temuannya, dan
        // membuangnya karena satu foto gagal adalah kehilangan data.
        [$berkas, $gagal] = $this->foto->simpanBanyak($request->file('photos', []), 'complaints/'.$complaint->id);

        DB::transaction(function () use ($complaint, $data, $user, $berkas) {
            $activity = $this->jejak->catatan($complaint, $user, $data['note']);

            foreach ($berkas as $b) {
                $complaint->attachments()->create($b + ['complaint_activity_id' => $activity->id]);
            }
        });

        if ($complaint->first_response_at === null) {
            $complaint->forceFill(['first_response_at' => now()])->save();
        }

        $back = back()->with('status', 'Catatan ditambahkan.');

        return $gagal > 0
            ? $back->with('warning', $gagal.' foto gagal disimpan. Catatannya tetap tersimpan — coba unggah ulang fotonya.')
            : $back;
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\AddResponsibleRequest;
use App\Models\Complaint;
use App\Models\ComplaintResponsible;
use App\Models\User;
use App\Services\DaftarPetugas;
use App\Services\JejakComplaint;
use App\Services\KandidatPelaku;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Siapa yang menyebabkan complaint ini. (API-19)
 *
 * Sistem TIDAK menyimpulkan ini sendiri. NEVIRA hanya memberi tahu siapa
 * mengerjakan tahap apa; menautkan keluhan ke orang adalah penilaian, dan
 * penilaian harus punya nama pembuatnya serta alasannya.
 *
 * Yang dikirim browser hanya KUNCI kandidat — nama, NIP, dan id NEVIRA
 * dibaca server dari daftarnya sendiri. Itu yang membuat pengisian cukup
 * satu centang plus satu alasan, dan sekaligus menutup jalan menyuntikkan
 * nama karyawan sembarangan lewat form.
 */
class ComplaintResponsibleController extends Controller
{
    public function __construct(
        private DaftarPetugas $petugas,
        private JejakComplaint $jejak,
    ) {}

    public function store(AddResponsibleRequest $request, Complaint $complaint)
    {
        $user = $request->user();
        $data = $request->validated();

        $dipilih = $this->pilihanJadiPelaku($complaint, $user, $data);

        // Kunci yang tidak dikenali dibalas sebagai kesalahan form, bukan
        // dilewati diam-diam — lihat pilihanJadiPelaku().
        if ($dipilih instanceof RedirectResponse) {
            return $dipilih;
        }

        $baru = $this->hanyaYangBelumTercatat($complaint, $dipilih);

        if ($baru === []) {
            return back()->with('status', 'Semua yang dipilih sudah tercatat sebagai pelaku complaint ini.');
        }

        // Penetapan dan jejaknya harus jatuh bersama. Penetapan yang tersimpan
        // tanpa catatan riwayat adalah tuduhan tanpa asal-usul.
        DB::transaction(function () use ($complaint, $baru, $user, $data) {
            foreach ($baru as $calon) {
                $complaint->responsibles()->create([
                    'nevira_user_id' => $calon['staff_id'],
                    'staff_name' => $calon['name'],
                    'staff_nip' => $calon['nip'],
                    'role' => $calon['role'],
                    'stage' => $calon['stage'],
                    'reason' => $data['alasan'],
                    'set_by' => $user->id,
                    'set_at' => now(),
                ]);
            }

            $this->jejak->pelakuDitetapkan($complaint, $user, $baru, $data['alasan']);
        });

        return back()->with('status', count($baru).' pelaku ditetapkan.');
    }

    /** Ubah peran atau alasan seorang pelaku. Perubahan ikut ke riwayat. */
    public function update(Request $request, Complaint $complaint, ComplaintResponsible $responsible)
    {
        $this->authorize('manageResponsible', $complaint);
        // 404, bukan 403: ini keutuhan rute, bukan wewenang.
        abort_unless($responsible->complaint_id === $complaint->id, 404);

        $user = $request->user();

        $data = $request->validate([
            'peran' => ['required', Rule::in(array_keys(config('complaint.responsible_roles')))],
            'alasan' => ['required', 'string', 'max:2000'],
        ], [
            'alasan.required' => 'Tulis alasannya. Menunjuk orang tanpa alasan tidak bisa ditinjau ulang.',
        ], ['peran' => 'peran', 'alasan' => 'alasan']);

        DB::transaction(function () use ($complaint, $responsible, $data, $user) {
            $responsible->forceFill([
                'role' => $data['peran'],
                'reason' => $data['alasan'],
                'set_by' => $user->id,
                'set_at' => now(),
            ])->save();

            $this->jejak->pelakuDiperbarui($complaint, $user, $responsible, $data['alasan']);
        });

        return back()->with('status', 'Penetapan '.$responsible->staff_name.' diperbarui.');
    }

    /** Cabut penetapan seorang pelaku. Pencabutan juga masuk riwayat. */
    public function destroy(Request $request, Complaint $complaint, ComplaintResponsible $responsible)
    {
        $this->authorize('manageResponsible', $complaint);
        abort_unless($responsible->complaint_id === $complaint->id, 404);

        $user = $request->user();
        $nama = $responsible->staff_name;

        DB::transaction(function () use ($complaint, $responsible, $user, $nama) {
            $responsible->delete();

            $this->jejak->pelakuDicabut($complaint, $user, $nama);
        });

        return back()->with('status', 'Penetapan '.$nama.' dicabut.');
    }

    /**
     * Terjemahkan kunci kandidat yang dicentang jadi calon pelaku.
     *
     * Mengembalikan RedirectResponse kalau ada kunci yang tidak dikenali.
     * Daftar kandidat disusun ulang tiap permintaan; kalau NEVIRA sedang
     * mati, karyawan outlet tidak ada di dalamnya. Ditolak dengan terang,
     * bukan diam-diam dilewati — pelaku yang dicentang lalu tidak tersimpan
     * adalah kehilangan data.
     *
     * @param  array<string,mixed>  $data
     * @return array<int,array<string,mixed>>|RedirectResponse
     */
    private function pilihanJadiPelaku(Complaint $complaint, User $user, array $data)
    {
        $daftar = KandidatPelaku::untuk(
            $complaint,
            $this->petugas->karyawanOutlet($user, $complaint),
            $this->petugas->penggunaSistem()
        );

        $dipilih = [];

        foreach ($data['pelaku'] ?? [] as $kunci) {
            $kandidat = $daftar->find($kunci);

            if (! $kandidat) {
                return back()->withInput()->withErrors([
                    'pelaku' => 'Ada pilihan yang tidak lagi dikenali — daftarnya mungkin berubah. '
                        .'Muat ulang halaman ini, lalu pilih lagi.',
                ]);
            }

            $dipilih[] = [
                'staff_id' => $kandidat['staff_id'],
                'name' => $kandidat['name'],
                'nip' => $kandidat['nip'],
                'role' => $data['peran'][$kunci] ?? $kandidat['role'],
                'stage' => $kandidat['stage'],
            ];
        }

        // Isian bebas tetap ada untuk orang yang tidak ada di daftar
        // (mis. kurir outlet lain) — tapi bukan jalur utamanya.
        if (filled($data['manual_nama'] ?? null)) {
            $dipilih[] = [
                'staff_id' => null,
                'name' => trim($data['manual_nama']),
                'nip' => $data['manual_nip'] ?? null,
                'role' => $data['manual_peran'] ?? 'lainnya',
                'stage' => null,
            ];
        }

        return $dipilih;
    }

    /**
     * Buang calon yang sudah tercatat sebagai pelaku complaint ini.
     *
     * @param  array<int,array<string,mixed>>  $dipilih
     * @return array<int,array<string,mixed>>
     */
    private function hanyaYangBelumTercatat(Complaint $complaint, array $dipilih): array
    {
        $sudahAda = $complaint->responsibles()->get()->map->identity()->all();
        $baru = [];

        foreach ($dipilih as $calon) {
            $identity = ComplaintResponsible::identityFor($calon['staff_id'], $calon['nip'], $calon['name']);

            if (in_array($identity, $sudahAda, true)) {
                continue;
            }

            $sudahAda[] = $identity;
            $baru[] = $calon;
        }

        return $baru;
    }
}

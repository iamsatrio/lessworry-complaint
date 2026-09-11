<?php

namespace App\Http\Controllers;

use App\Http\Requests\LaporanFilterRequest;
use App\Models\ComplaintResponsible;
use App\Services\EksporCsv;
use App\Services\EksporXlsx;
use App\Services\GrafikLaporan;
use App\Services\RekapEkspor;
use App\Services\SaringanLaporan;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(LaporanFilterRequest $request)
    {
        $saringan = SaringanLaporan::dariPermintaan($request);
        $user = $saringan->user;
        $complaints = $saringan->complaints();

        $resolved = $complaints->whereNotNull('resolved_at');

        $pelaku = $user->canSeeStaffAttribution()
            ? ComplaintResponsible::whereIn('complaint_id', $complaints->modelKeys())->get()
            : collect();

        return view('reports.index', [
            'from' => $saringan->dari,
            'to' => $saringan->sampai,
            // Saringan outlet: yang sedang dipilih, dan pilihan yang boleh
            // ditawarkan kepada pengguna ini. (API-62 nomor 3)
            'outlet' => $saringan->outlet,
            'pilihanOutlet' => $saringan->pilihanOutlet(),
            // Grafik dihitung dari KOLEKSI YANG SAMA dengan tabel di bawahnya,
            // bukan lewat kueri sendiri: satu jalur data berarti satu jalur
            // wewenang. Kalau grafik mengambil datanya sendiri, kebocoran di
            // sana tidak akan terlihat karena tabelnya tetap benar. (API-52)
            'grafik' => new GrafikLaporan($user, $complaints, $saringan->outletId(), $saringan->satuan),
            // Satuan waktu sumbu grafik, dan pintasan rentang tanggal.
            // (API-62 nomor 2 dan 5)
            'satuan' => $saringan->satuan,
            'satuanDipilih' => $saringan->satuanDipilih,
            'pintasan' => $saringan->pintasan(),
            'rentangTerbaca' => $saringan->rentangTerbaca(),
            'total' => $complaints->count(),
            'resolved' => $resolved->count(),
            'overdue' => $complaints->filter->isOverdue()->count(),
            'compensation' => $complaints->sum('compensation_amount'),
            // Tiket Close tetap bisa dipisah: yang benar-benar selesai dan
            // yang ditolak. Kemampuannya tidak hilang bersama statusnya —
            // hanya pindah ke close_reason. (API-18 #6)
            'closedDone' => $complaints->where('status', 'close')->where('close_reason', 'selesai')->count(),
            'closedReject' => $complaints->where('status', 'close')->where('close_reason', 'ditolak')->count(),
            // Tiket Close yang alasannya tidak diketahui — seluruh 541 baris
            // impor data lama masuk ke sini, karena spreadsheet tim hanya
            // mengenal Open/Handling/Close dan tidak pernah mencatat alasan
            // penutupan. Tanpa angka ini, halaman melaporkan 541 tiket Close
            // sebagai "selesai 0, ditolak 0" dan pembacanya menyimpulkan
            // datanya rusak. (Review PR #7, P2-3)
            'closedNoReason' => $complaints->where('status', 'close')->whereNull('close_reason')->count(),
            // Dihitung dari statusnya sendiri, bukan sisa pengurangan: begitu
            // ada tiket Close tanpa alasan, "total dikurangi yang selesai dan
            // yang ditolak" berhenti berarti "masih terbuka".
            'stillOpen' => $complaints->filter->isOpen()->count(),
            'avgMinutes' => $resolved->isEmpty() ? null : (int) round($resolved->avg(fn ($c) => $c->resolutionMinutes())),
            'byCategory' => $complaints->groupBy('category')->map->count()->sortDesc(),
            'byBobot' => $complaints->groupBy('bobot')->map->count()->sortDesc(),
            // Layanan dan tindak lanjut ada supaya bisa DIKELOMPOKKAN, bukan
            // sekadar tersimpan: itu yang membuat "layanan mana yang paling
            // sering bermasalah" bisa dijawab tanpa menghitung tangan.
            'byLayanan' => $complaints->groupBy(fn ($c) => $c->layanan ?: 'tidak_dicatat')->map->count()->sortDesc(),
            'byTindakLanjut' => $complaints->whereNotNull('tindak_lanjut')
                ->groupBy('tindak_lanjut')->map->count()->sortDesc(),
            // Dikelompokkan menurut LABEL, bukan kunci: kanal `impor` sengaja
            // tidak ada di daftar kanal intake (API-28), jadi tampilan yang
            // mencari labelnya di config('complaint.channels') akan menampilkan
            // kunci mentah. Yang tahu cara menamai sebuah kanal adalah model.
            'byChannel' => $complaints->groupBy(fn ($c) => $c->channelLabel())->map->count()->sortDesc(),
            // ?? sudah menahan complaint tanpa outlet: PHP membaca properti
            // di kirinya secara isset, jadi ?-> di sini mubazir.
            'byOutlet' => $complaints->groupBy(fn ($c) => $c->outlet->name ?? 'Tanpa outlet')->map->count()->sortDesc(),
            // Rekap per karyawan hanya untuk yang berwenang melihatnya.
            // Tiap pelaku dihitung, bukan satu per complaint: satu keluhan
            // bisa melibatkan kasir, petugas cuci, dan kurir sekaligus.
            'byStaff' => $user->canSeeStaffAttribution()
                ? $pelaku->groupBy(fn ($p) => $p->staff_name)
                    ->map(fn ($group) => [
                        'total' => $group->pluck('complaint_id')->unique()->count(),
                        'nip' => $group->pluck('staff_nip')->filter()->first(),
                        'stages' => $group
                            ->map(fn ($p) => $p->roleLabel().($p->stage ? ' · '.$p->stage : ''))
                            ->unique()->values()->all(),
                    ])
                    ->sortByDesc('total')
                : collect(),
            'unattributed' => $complaints->count() - $pelaku->pluck('complaint_id')->unique()->count(),
            'repeat' => $complaints->whereNotNull('reporter_phone')
                ->groupBy('reporter_phone')->filter(fn ($g) => $g->count() > 1)
                ->map(fn ($g) => ['name' => $g->first()->reporter_name, 'count' => $g->count()]),
        ]);
    }

    /**
     * Rekap CSV. TETAP ADA dan tidak berubah bentuk oleh hadirnya `.xlsx`:
     * ia yang dipakai alat lain, dan ia satu-satunya yang tidak menuntut
     * pustaka apa pun. (API-62 nomor 4)
     */
    public function export(LaporanFilterRequest $request): StreamedResponse
    {
        return (new EksporCsv($this->rekap($request)))->unduh();
    }

    /**
     * Rekap `.xlsx`. Kolomnya sama persis dengan CSV — keduanya berangkat
     * dari RekapEkspor yang sama, termasuk aturan nomor nota dan kolom
     * karyawan.
     */
    public function exportXlsx(LaporanFilterRequest $request): StreamedResponse
    {
        return (new EksporXlsx($this->rekap($request)))->unduh();
    }

    private function rekap(LaporanFilterRequest $request): RekapEkspor
    {
        $saringan = SaringanLaporan::dariPermintaan($request);

        return new RekapEkspor($saringan->user, $saringan->complaints(['outlet', 'assignee']));
    }
}

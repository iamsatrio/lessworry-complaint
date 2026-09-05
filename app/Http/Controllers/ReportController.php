<?php

namespace App\Http\Controllers;

use App\Models\Complaint;
use App\Models\ComplaintResponsible;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $from = $request->date('from') ?? now()->subDays(30)->startOfDay();
        $to = $request->date('to') ?? now()->endOfDay();

        $complaints = Complaint::query()
            ->visibleTo($user)
            ->whereBetween('created_at', [$from, $to])
            ->with('outlet')
            ->get();

        $resolved = $complaints->whereNotNull('resolved_at');

        $pelaku = $user->canSeeStaffAttribution()
            ? ComplaintResponsible::whereIn('complaint_id', $complaints->modelKeys())->get()
            : collect();

        return view('reports.index', [
            'from' => $from,
            'to' => $to,
            'total' => $complaints->count(),
            'resolved' => $resolved->count(),
            'overdue' => $complaints->filter->isOverdue()->count(),
            'compensation' => $complaints->sum('compensation_amount'),
            // Tiket Close tetap bisa dipisah: yang benar-benar selesai dan
            // yang ditolak. Kemampuannya tidak hilang bersama statusnya —
            // hanya pindah ke close_reason. (API-18 #6)
            'closedDone' => $complaints->where('status', 'close')->where('close_reason', 'selesai')->count(),
            'closedReject' => $complaints->where('status', 'close')->where('close_reason', 'ditolak')->count(),
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

    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        $from = $request->date('from') ?? now()->subDays(30)->startOfDay();
        $to = $request->date('to') ?? now()->endOfDay();

        $complaints = Complaint::query()
            ->visibleTo($user)
            ->whereBetween('created_at', [$from, $to])
            ->with(['outlet', 'assignee'])
            ->get();

        $showStaff = $user->canSeeStaffAttribution();

        // Semua pelaku satu complaint masuk ke satu baris CSV — rekap ini
        // dibaca per complaint, bukan per orang.
        $pelaku = $showStaff
            ? ComplaintResponsible::whereIn('complaint_id', $complaints->modelKeys())->get()->groupBy('complaint_id')
            : collect();

        return response()->streamDownload(function () use ($complaints, $showStaff, $pelaku) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Nomor Tiket', 'Dibuat', 'Kanal', 'Outlet', 'Pelapor', 'Telepon',
                // Nomor nota, bukan id internal NEVIRA. CSV rekap diteruskan
                // lewat WhatsApp dan email; pengenal internal sistem lain
                // tidak boleh ikut keluar. (API-8 T2)
                'Nomor Nota', 'Kategori', 'Bobot', 'Layanan', 'Status', 'Alasan Penutupan', 'Tindak Lanjut',
                'Penanggung Jawab', 'Selesai', 'Menit Penyelesaian', 'Kompensasi', 'Lewat SLA',
                ...($showStaff ? ['Karyawan Penanggung Jawab', 'NIP', 'Peran', 'Alasan'] : []),
            ]);

            foreach ($complaints as $c) {
                fputcsv($out, [
                    $c->ticket_number,
                    $c->created_at?->format('Y-m-d H:i'),
                    $c->channelLabel(),
                    $c->outlet?->name,
                    $c->reporter_name,
                    $c->reporter_phone,
                    $c->nevira_transaction_number,
                    $c->categoryLabel(),
                    $c->bobotLabel(),
                    $c->layananLabel(),
                    $c->statusLabel(),
                    $c->closeReasonLabel(),
                    $c->tindakLanjutLabel(),
                    $c->assignee?->name,
                    $c->resolved_at?->format('Y-m-d H:i'),
                    $c->resolutionMinutes(),
                    $c->compensation_amount,
                    $c->isOverdue() ? 'YA' : 'tidak',
                    ...($showStaff ? [
                        $pelaku->get($c->id, collect())->pluck('staff_name')->implode('; '),
                        $pelaku->get($c->id, collect())->pluck('staff_nip')->implode('; '),
                        $pelaku->get($c->id, collect())
                            ->map(fn ($p) => $p->roleLabel().($p->stage ? ' ('.$p->stage.')' : ''))
                            ->implode('; '),
                        $pelaku->get($c->id, collect())
                            ->map(fn ($p) => $p->staff_name.': '.$p->reason)
                            ->implode(' | '),
                    ] : []),
                ]);
            }

            fclose($out);
        }, 'complaint-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }
}

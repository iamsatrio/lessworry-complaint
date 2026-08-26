<?php

namespace App\Http\Controllers;

use App\Models\Complaint;
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

        return view('reports.index', [
            'from'        => $from,
            'to'          => $to,
            'total'       => $complaints->count(),
            'resolved'    => $resolved->count(),
            'overdue'     => $complaints->filter->isOverdue()->count(),
            'compensation' => $complaints->sum('compensation_amount'),
            'avgMinutes'  => $resolved->isEmpty() ? null : (int) round($resolved->avg(fn ($c) => $c->resolutionMinutes())),
            'byCategory'  => $complaints->groupBy('category')->map->count()->sortDesc(),
            'byChannel'   => $complaints->groupBy('channel')->map->count()->sortDesc(),
            'byOutlet'    => $complaints->groupBy(fn ($c) => $c->outlet?->name ?? 'Tanpa outlet')->map->count()->sortDesc(),
            'repeat'      => $complaints->whereNotNull('reporter_phone')
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

        return response()->streamDownload(function () use ($complaints) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Nomor Tiket', 'Dibuat', 'Kanal', 'Outlet', 'Pelapor', 'Telepon',
                'ID Transaksi NEVIRA', 'Kategori', 'Prioritas', 'Status',
                'Penanggung Jawab', 'Selesai', 'Menit Penyelesaian', 'Kompensasi', 'Lewat SLA',
            ]);

            foreach ($complaints as $c) {
                fputcsv($out, [
                    $c->ticket_number,
                    $c->created_at?->format('Y-m-d H:i'),
                    $c->channelLabel(),
                    $c->outlet?->name,
                    $c->reporter_name,
                    $c->reporter_phone,
                    $c->nevira_transaction_id,
                    $c->categoryLabel(),
                    $c->priority,
                    $c->statusLabel(),
                    $c->assignee?->name,
                    $c->resolved_at?->format('Y-m-d H:i'),
                    $c->resolutionMinutes(),
                    $c->compensation_amount,
                    $c->isOverdue() ? 'YA' : 'tidak',
                ]);
            }

            fclose($out);
        }, 'complaint-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }
}

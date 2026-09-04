<?php

namespace App\Http\Controllers;

use App\Models\Complaint;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        $base = fn () => Complaint::query()->visibleTo($user);

        $open = $base()->open()->get();
        $overdue = $open->filter->isOverdue();

        $today = $base()->whereDate('created_at', today())->count();
        $resolvedToday = $base()->whereDate('resolved_at', today())->count();

        // Rata-rata waktu penyelesaian 30 hari terakhir
        $recentResolved = $base()
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '>=', now()->subDays(30))
            ->get();

        $avgMinutes = $recentResolved->isEmpty()
            ? null
            : (int) round($recentResolved->avg(fn ($c) => $c->resolutionMinutes()));

        $byCategory = $base()
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('category, count(*) as total')
            ->groupBy('category')
            ->pluck('total', 'category');

        $byStatus = $base()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('dashboard', [
            'openCount' => $open->count(),
            'overdueCount' => $overdue->count(),
            'overdue' => $overdue->sortBy('due_resolution_at')->take(8),
            'todayCount' => $today,
            'resolvedToday' => $resolvedToday,
            'avgMinutes' => $avgMinutes,
            'byCategory' => $byCategory,
            'byStatus' => $byStatus,
            'latest' => $base()->latest()->with('outlet')->take(8)->get(),
        ]);
    }
}

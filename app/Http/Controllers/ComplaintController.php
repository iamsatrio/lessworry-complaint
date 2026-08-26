<?php

namespace App\Http\Controllers;

use App\Models\Complaint;
use App\Models\ComplaintActivity;
use App\Models\Outlet;
use App\Models\User;
use App\Services\NeviraClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

class ComplaintController extends Controller
{
    public function __construct(private NeviraClient $nevira) {}

    /** Papan kerja: complaint terbuka, disaring per peran. */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Complaint::query()
            ->visibleTo($user)
            ->with(['outlet', 'assignee'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        } else {
            $query->open();
        }

        foreach (['category', 'priority', 'channel', 'outlet_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        if ($request->filled('q')) {
            $q = $request->string('q');
            $query->where(function ($sub) use ($q) {
                $sub->where('ticket_number', 'like', "%{$q}%")
                    ->orWhere('reporter_name', 'like', "%{$q}%")
                    ->orWhere('reporter_phone', 'like', "%{$q}%")
                    ->orWhere('nevira_transaction_id', 'like', "%{$q}%");
            });
        }

        return view('complaints.index', [
            'complaints' => $query->paginate(20)->withQueryString(),
            'outlets'    => Outlet::orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        abort_unless(Auth::user()->canCreateComplaint(), 403);

        return view('complaints.create', [
            'outlets' => Outlet::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canCreateComplaint(), 403);

        $data = $request->validate([
            'channel'               => ['required', Rule::in(array_keys(config('complaint.channels')))],
            'reporter_name'         => ['required', 'string', 'max:120'],
            'reporter_phone'        => ['nullable', 'string', 'max:30'],
            'nevira_transaction_id' => ['nullable', 'string', 'max:64'],
            'outlet_id'             => ['nullable', 'exists:outlets,id'],
            'category'              => ['required', Rule::in(array_keys(config('complaint.categories')))],
            'sub_category'          => ['nullable', 'string', 'max:120'],
            'priority'              => ['required', Rule::in(array_keys(config('complaint.priorities')))],
            'description'           => ['required', 'string'],
            'attachments.*'         => ['nullable', 'image', 'max:5120'],
        ]);

        // Kasir hanya boleh mencatat untuk outletnya sendiri.
        if ($user->isKasir()) {
            $data['outlet_id'] = $user->outlet_id;
        }

        $complaint = DB::transaction(function () use ($data, $user, $request) {
            $complaint = new Complaint($data);
            $complaint->ticket_number = Complaint::nextTicketNumber();
            $complaint->status = 'baru';
            $complaint->created_by = $user->id;
            $complaint->created_at = now();
            $complaint->applySla();
            $complaint->save();

            ComplaintActivity::create([
                'complaint_id' => $complaint->id,
                'user_id'      => $user->id,
                'type'         => 'created',
                'to_status'    => 'baru',
                'note'         => 'Complaint dibuat lewat '.$complaint->channelLabel(),
            ]);

            foreach ($request->file('attachments', []) as $file) {
                $path = $file->store('complaints/'.$complaint->id, 'public');
                $complaint->attachments()->create([
                    'path'          => $path,
                    'original_name' => $file->getClientOriginalName(),
                ]);
            }

            return $complaint;
        });

        // Tarik data order NEVIRA kalau ID diisi. Kegagalan tidak boleh
        // membatalkan complaint — dicatat, bisa dicoba lagi nanti. (API-8)
        if (filled($complaint->nevira_transaction_id)) {
            $this->syncNevira($complaint);
        }

        return redirect()
            ->route('complaints.show', $complaint)
            ->with('status', 'Complaint '.$complaint->ticket_number.' tercatat.');
    }

    public function show(Complaint $complaint)
    {
        abort_unless(Auth::user()->canView($complaint), 403);

        $complaint->load(['outlet', 'assignee', 'creator', 'activities.user', 'attachments']);

        return view('complaints.show', [
            'complaint' => $complaint,
            'handlers'  => User::where('is_active', true)
                ->whereIn('role', ['kasir', 'customer_care', 'supervisor'])
                ->orderBy('name')->get(),
        ]);
    }

    /** Ubah status, dengan pencatatan riwayat dan penegakan wewenang. */
    public function updateStatus(Request $request, Complaint $complaint)
    {
        $user = $request->user();
        abort_unless($user->canView($complaint), 403);

        $data = $request->validate([
            'status'              => ['required', Rule::in(array_keys(config('complaint.statuses')))],
            'note'                => ['nullable', 'string'],
            'resolution'          => ['nullable', 'string'],
            'root_cause'          => ['nullable', 'string'],
            'compensation_amount' => ['nullable', 'integer', 'min:0'],
        ]);

        $closing = in_array($data['status'], ['selesai', 'ditolak'], true);

        if ($closing && ! $user->canResolve()) {
            return back()->withErrors(['status' => 'Peranmu tidak berwenang menutup complaint.']);
        }

        $compensation = (int) ($data['compensation_amount'] ?? 0);
        if ($compensation > $user->compensationLimit()) {
            return back()->withErrors([
                'compensation_amount' => 'Nilai kompensasi melebihi batas wewenang '.$user->roleLabel()
                    .' (Rp '.number_format($user->compensationLimit(), 0, ',', '.').'). Naikkan ke supervisor.',
            ]);
        }

        $from = $complaint->status;

        DB::transaction(function () use ($complaint, $data, $user, $from, $closing, $compensation) {
            $complaint->status = $data['status'];
            $complaint->resolution = $data['resolution'] ?? $complaint->resolution;
            $complaint->root_cause = $data['root_cause'] ?? $complaint->root_cause;

            if ($compensation > 0) {
                $complaint->compensation_amount = $compensation;
            }

            if ($complaint->first_response_at === null) {
                $complaint->first_response_at = now();
            }

            if ($closing && $complaint->resolved_at === null) {
                $complaint->resolved_at = now();
            }

            if (! $closing) {
                $complaint->resolved_at = null;
            }

            $complaint->save();

            ComplaintActivity::create([
                'complaint_id' => $complaint->id,
                'user_id'      => $user->id,
                'type'         => 'status_change',
                'from_status'  => $from,
                'to_status'    => $complaint->status,
                'note'         => $data['note'] ?? null,
            ]);
        });

        return back()->with('status', 'Status diperbarui: '.$complaint->statusLabel());
    }

    public function assign(Request $request, Complaint $complaint)
    {
        $user = $request->user();
        abort_unless($user->canView($complaint), 403);

        $data = $request->validate([
            'assigned_to'        => ['nullable', 'exists:users,id'],
            'forwarded_division' => ['nullable', Rule::in(array_keys(config('complaint.divisions')))],
        ]);

        $complaint->fill($data)->save();

        ComplaintActivity::create([
            'complaint_id' => $complaint->id,
            'user_id'      => $user->id,
            'type'         => filled($data['forwarded_division'] ?? null) ? 'forward' : 'assign',
            'note'         => filled($data['forwarded_division'] ?? null)
                ? 'Diteruskan ke divisi '.config('complaint.divisions.'.$data['forwarded_division'])
                : 'Penanggung jawab diperbarui',
        ]);

        return back()->with('status', 'Penugasan diperbarui.');
    }

    public function addNote(Request $request, Complaint $complaint)
    {
        abort_unless($request->user()->canView($complaint), 403);

        $data = $request->validate(['note' => ['required', 'string']]);

        ComplaintActivity::create([
            'complaint_id' => $complaint->id,
            'user_id'      => $request->user()->id,
            'type'         => 'note',
            'note'         => $data['note'],
        ]);

        if ($complaint->first_response_at === null) {
            $complaint->forceFill(['first_response_at' => now()])->save();
        }

        return back()->with('status', 'Catatan ditambahkan.');
    }

    /** Coba tautkan ulang ke NEVIRA (dipakai saat sinkron pertama gagal). */
    public function resync(Complaint $complaint)
    {
        abort_unless(Auth::user()->canView($complaint), 403);

        $this->syncNevira($complaint);

        return back()->with('status', $complaint->nevira_sync_error
            ? 'Sinkron NEVIRA gagal: '.$complaint->nevira_sync_error
            : 'Data NEVIRA berhasil ditarik.');
    }

    private function syncNevira(Complaint $complaint): void
    {
        try {
            $payload = $this->nevira->transaction($complaint->nevira_transaction_id);
            $summary = $this->nevira->summarizeTransaction($payload);

            $complaint->forceFill([
                'nevira_snapshot'   => $summary,
                'nevira_customer_id' => $summary['customer_id'] ?? null,
                'nevira_synced_at'  => now(),
                'nevira_sync_error' => null,
            ])->save();
        } catch (Throwable $e) {
            // Complaint tetap hidup walau NEVIRA mati. (API-8, API-10)
            $complaint->forceFill([
                'nevira_sync_error' => mb_substr($e->getMessage(), 0, 190),
            ])->save();
        }
    }
}

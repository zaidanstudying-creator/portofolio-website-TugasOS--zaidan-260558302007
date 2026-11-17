<?php

use App\Models\TaskSubmission;
use App\Services\Lms\LmsAdapterRegistry;
use App\Services\Lms\LmsAssignment;
use App\Services\Lms\LmsSubmissionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $taskId = '';

    public string $proofFileId = '';

    public string $submissionUrl = '';

    public string $confirmationReference = '';

    public string $submittedAt = '';

    public string $notes = '';

    public string $lmsTaskId = '';

    public string $lmsFileId = '';

    public string $lmsPlatform = '';

    public string $lmsAssignmentId = '';

    public array $availableAssignments = [];

    #[Computed]
    public function tasks(): Collection
    {
        return auth()->user()->tasks()->whereNull('archived_at')->orderBy('title')->get();
    }

    #[Computed]
    public function files(): Collection
    {
        return auth()->user()->taskFiles()
            ->with('task')
            ->whereIn('kind', ['final', 'proof'])
            ->when($this->taskId !== '', fn ($query) => $query->where('task_id', $this->taskId))
            ->latest()
            ->get();
    }

    #[Computed]
    public function submissions(): Collection
    {
        return auth()->user()->taskSubmissions()->with(['task', 'proofFile', 'attempts', 'assessmentEvents'])->latest()->get();
    }

    #[Computed]
    public function lmsAdapters(): array
    {
        return app(LmsAdapterRegistry::class)->available();
    }

    #[Computed]
    public function finalFiles(): Collection
    {
        return auth()->user()->taskFiles()
            ->with('task')
            ->where('kind', 'final')
            ->where('is_final', true)
            ->when($this->lmsTaskId !== '', fn ($query) => $query->where('task_id', $this->lmsTaskId))
            ->latest()
            ->get();
    }

    #[Computed]
    public function selectedLmsAssignment(): ?array
    {
        return collect($this->availableAssignments)->firstWhere('id', $this->lmsAssignmentId);
    }

    public function updatedLmsTaskId(): void
    {
        $this->lmsFileId = '';
        unset($this->finalFiles);
    }

    public function updatedLmsPlatform(): void
    {
        $this->lmsAssignmentId = '';
        $this->availableAssignments = [];
        unset($this->selectedLmsAssignment);
    }

    public function loadLmsAssignments(): void
    {
        $this->validate([
            'lmsPlatform' => ['required', Rule::in(array_keys($this->lmsAdapters))],
        ]);

        try {
            $assignments = app(LmsAdapterRegistry::class)
                ->forPlatform($this->lmsPlatform)
                ->assignments(auth()->user());

            $this->availableAssignments = array_map(fn (LmsAssignment $assignment): array => [
                'id' => $assignment->id,
                'title' => $assignment->title,
                'course_name' => $assignment->courseName,
                'due_at' => $assignment->dueAt?->format(DATE_ATOM),
                'allowed_mime_types' => $assignment->allowedMimeTypes,
                'max_file_bytes' => $assignment->maxFileBytes,
                'resubmission_allowed' => $assignment->resubmissionAllowed,
            ], $assignments);
            $this->lmsAssignmentId = '';
            unset($this->selectedLmsAssignment);
        } catch (\Throwable) {
            $this->availableAssignments = [];
            $this->addError('lmsPlatform', 'Daftar assignment gagal dimuat. Periksa koneksi LMS lalu coba kembali.');
        }
    }

    public function submitToLms(LmsSubmissionService $service): void
    {
        $validated = $this->validate([
            'lmsTaskId' => ['required', 'integer', Rule::exists('tasks', 'id')->where('user_id', auth()->id())->whereNull('archived_at')],
            'lmsFileId' => ['required', 'integer', Rule::exists('task_files', 'id')->where('user_id', auth()->id())->where('task_id', $this->lmsTaskId)->where('kind', 'final')->where('is_final', true)],
            'lmsPlatform' => ['required', Rule::in(array_keys($this->lmsAdapters))],
            'lmsAssignmentId' => ['required', 'string', 'max:255'],
        ]);

        $submission = $service->queue(
            auth()->user(),
            (int) $validated['lmsTaskId'],
            (int) $validated['lmsFileId'],
            $validated['lmsPlatform'],
            $validated['lmsAssignmentId'],
        );

        $this->reset(['lmsTaskId', 'lmsFileId', 'lmsPlatform', 'lmsAssignmentId', 'availableAssignments']);
        unset($this->submissions, $this->finalFiles, $this->selectedLmsAssignment);
        session()->flash('status', $submission->status === 'sending'
            ? 'Pengiriman masuk antrean. TUGAS!OS akan menampilkan berhasil hanya setelah LMS mengonfirmasinya.'
            : 'Pengiriman untuk assignment ini sudah sedang diproses.');
    }

    public function retryLmsSubmission(int $submissionId, LmsSubmissionService $service): void
    {
        $submission = auth()->user()->taskSubmissions()
            ->with(['task', 'proofFile'])
            ->findOrFail($submissionId);

        abort_unless(
            $submission->status === 'failed'
                && $submission->proof_file_id !== null
                && $submission->external_assignment_id !== null
                && $submission->task->archived_at === null
                && array_key_exists($submission->platform, $this->lmsAdapters),
            422,
        );

        $service->queue(
            auth()->user(),
            $submission->task_id,
            $submission->proof_file_id,
            $submission->platform,
            $submission->external_assignment_id,
        );

        unset($this->submissions);
        session()->flash('status', 'Percobaan ulang masuk antrean dengan idempotency key yang sama. Periksa bukti di LMS jika status belum berubah.');
    }

    public function updatedTaskId(): void
    {
        $this->proofFileId = '';
        unset($this->files);
    }

    public function recordManualSubmission(): void
    {
        $validated = $this->validate([
            'taskId' => ['required', 'integer', Rule::exists('tasks', 'id')->where('user_id', auth()->id())->whereNull('archived_at')],
            'proofFileId' => ['nullable', 'integer', Rule::exists('task_files', 'id')->where('user_id', auth()->id())->where('task_id', $this->taskId)->whereIn('kind', ['final', 'proof'])],
            'submissionUrl' => ['nullable', 'url', 'max:2048'],
            'confirmationReference' => ['nullable', 'string', 'max:255'],
            'submittedAt' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if (filled($validated['submissionUrl']) && ! in_array(parse_url($validated['submissionUrl'], PHP_URL_SCHEME), ['http', 'https'], true)) {
            $this->addError('submissionUrl', 'Link harus memakai HTTPS atau HTTP.');

            return;
        }

        DB::transaction(function () use ($validated): void {
            $submission = auth()->user()->taskSubmissions()->create([
                'task_id' => $validated['taskId'],
                'proof_file_id' => filled($validated['proofFileId']) ? $validated['proofFileId'] : null,
                'platform' => 'manual',
                'status' => 'manual_recorded',
                'confirmation_method' => 'manual',
                'submission_url' => $validated['submissionUrl'] ?: null,
                'confirmation_reference' => $validated['confirmationReference'] ?: null,
                'submitted_at' => filled($validated['submittedAt']) ? $validated['submittedAt'] : now(),
                'notes' => $validated['notes'] ?: null,
            ]);

            $submission->attempts()->create([
                'user_id' => auth()->id(),
                'attempt_number' => $submission->attempts()->count() + 1,
                'idempotency_key' => (string) Str::uuid(),
                'status' => 'manual_recorded',
                'started_at' => $submission->submitted_at,
                'completed_at' => now(),
                'response_payload' => ['method' => 'manual', 'lms_verified' => false],
            ]);
        });

        $this->reset(['taskId', 'proofFileId', 'submissionUrl', 'confirmationReference', 'submittedAt', 'notes']);
        unset($this->submissions);
        session()->flash('status', 'Pengumpulan manual dan bukti berhasil dicatat. Status ini belum diverifikasi oleh LMS.');
    }

    public function updateAssessmentStatus(int $submissionId, string $assessmentStatus): void
    {
        abort_unless(in_array($assessmentStatus, ['pending', 'graded', 'revision_required'], true), 422);

        $changed = DB::transaction(function () use ($submissionId, $assessmentStatus): bool {
            $submission = auth()->user()->taskSubmissions()->lockForUpdate()->findOrFail($submissionId);
            $previousStatus = $submission->assessment_status;

            if ($previousStatus === $assessmentStatus) {
                return false;
            }

            $submission->update([
                'assessment_status' => $assessmentStatus,
                'assessed_at' => $assessmentStatus === 'pending' ? null : now(),
            ]);
            $submission->assessmentEvents()->create([
                'user_id' => auth()->id(),
                'event_type' => 'change',
                'from_status' => $previousStatus,
                'to_status' => $assessmentStatus,
            ]);

            return true;
        });

        unset($this->submissions);

        if ($changed) {
            session()->flash('status', 'Status penilaian diperbarui tanpa mengubah status pengumpulan.');
        }
    }
};
?>

<div class="min-h-screen bg-[#f7f4ec] px-4 py-8 text-[#171717] sm:px-8 lg:px-10">
    <div class="mx-auto max-w-7xl space-y-7">
        <header class="flex flex-col justify-between gap-5 border-b-2 border-black pb-6 sm:flex-row sm:items-end"><div><p class="text-xs font-black uppercase tracking-[0.24em] text-[#555]">TUGAS!OS / Submission Log</p><h1 class="mt-2 text-4xl font-black tracking-tight sm:text-5xl">Pengumpulan tercatat.</h1><p class="mt-3 max-w-xl text-sm text-[#555]">Catat pengumpulan, bukti, dan status verifikasi secara terpisah. Pengiriman otomatis hanya aktif melalui konektor resmi yang cocok dengan LMS kampus.</p></div><div class="border-2 border-black bg-[#ffd9d1] px-4 py-3 text-sm font-bold shadow-[4px_4px_0_#171717]">TAHAP 04 + 07 — SUBMISSION</div></header>
        @if(count($this->lmsAdapters) === 0)<aside role="status" class="border-2 border-black bg-[#fff1b8] p-4 shadow-[4px_4px_0_#171717]"><h2 class="font-black">Konektor LMS belum dikonfigurasi</h2><p class="mt-1 text-sm">TUGAS!OS belum mengirim atau mengklaim verifikasi LMS. Pengumpulan manual tetap dapat dicatat. Konektor otomatis baru diaktifkan setelah platform dan API resminya ditetapkan.</p></aside>@endif
        @if(session('status'))<div role="status" aria-live="polite" class="border-2 border-black bg-[#b8edce] px-4 py-3 font-bold shadow-[4px_4px_0_#171717]">{{ session('status') }}</div>@endif
        <div class="grid gap-5 lg:grid-cols-[0.85fr_1.15fr]">
            @if(count($this->lmsAdapters) > 0)
                <section class="border-2 border-black bg-[#dceaff] p-5 shadow-[5px_5px_0_#171717]"><h2 class="text-2xl font-black">Kirim melalui konektor LMS</h2><p class="mt-2 text-sm text-[#333]">Tinjau file dan aturan assignment terlebih dahulu. Pengiriman hanya dimulai setelah konfirmasi, lalu status menunggu verifikasi LMS.</p><form class="mt-5 space-y-3" wire:submit="submitToLms">
                    <label class="grid gap-1 text-sm font-bold">Platform<select required wire:model.live="lmsPlatform" class="border-2 border-black bg-white px-3 py-2 font-normal"><option value="">Pilih platform</option>@foreach($this->lmsAdapters as $platform => $label)<option value="{{ $platform }}">{{ $label }}</option>@endforeach</select>@error('lmsPlatform')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                    <button type="button" wire:click="loadLmsAssignments" wire:loading.attr="disabled" class="border-2 border-black bg-white px-3 py-2 text-sm font-black">Muat assignment</button>
                    <label class="grid gap-1 text-sm font-bold">Tugas TUGAS!OS<select required wire:model.live="lmsTaskId" class="border-2 border-black bg-white px-3 py-2 font-normal"><option value="">Pilih tugas</option>@foreach($this->tasks as $task)<option value="{{ $task->id }}">{{ $task->title }}</option>@endforeach</select>@error('lmsTaskId')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                    <label class="grid gap-1 text-sm font-bold">File final<select required wire:model="lmsFileId" class="border-2 border-black bg-white px-3 py-2 font-normal"><option value="">Pilih file final</option>@foreach($this->finalFiles as $file)<option value="{{ $file->id }}">{{ $file->original_name }} · {{ number_format($file->byte_size / 1024, 1) }} KB</option>@endforeach</select>@error('lmsFileId')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                    <label class="grid gap-1 text-sm font-bold">Assignment<select required wire:model="lmsAssignmentId" class="border-2 border-black bg-white px-3 py-2 font-normal"><option value="">Pilih assignment</option>@foreach($availableAssignments as $assignment)<option value="{{ $assignment['id'] }}">{{ $assignment['course_name'] ? $assignment['course_name'].' · ' : '' }}{{ $assignment['title'] }} @if($assignment['due_at']) — {{ \Illuminate\Support\Carbon::parse($assignment['due_at'])->format('d M Y') }}@endif</option>@endforeach</select>@error('lmsAssignmentId')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                    @if($this->selectedLmsAssignment)<div class="border-2 border-black bg-white p-3 text-xs"><p class="font-black">Pratinjau persyaratan</p><p class="mt-1">Format: {{ $this->selectedLmsAssignment['allowed_mime_types'] ? implode(', ', $this->selectedLmsAssignment['allowed_mime_types']) : 'sesuai aturan LMS' }} · Batas ukuran: {{ $this->selectedLmsAssignment['max_file_bytes'] > 0 ? number_format($this->selectedLmsAssignment['max_file_bytes'] / 1048576, 1).' MB' : 'sesuai aturan LMS' }} · {{ $this->selectedLmsAssignment['resubmission_allowed'] ? 'pengiriman ulang diizinkan' : 'pengiriman ulang tidak diizinkan' }}</p></div>@endif
                    <button type="button" wire:click="submitToLms" wire:confirm="Kirim file final ini ke assignment LMS yang dipilih? TUGAS!OS akan mencatat berhasil hanya setelah LMS mengonfirmasi pengiriman." wire:loading.attr="disabled" class="w-full border-2 border-black bg-[#facc15] px-4 py-3 text-sm font-black shadow-[3px_3px_0_#171717]">Konfirmasi dan kirim</button>
                </form></section>
            @endif
            <section class="border-2 border-black bg-white p-5 shadow-[5px_5px_0_#171717]"><h2 class="text-2xl font-black">Catat pengumpulan manual</h2><p class="mt-2 text-sm text-[#555]">Gunakan setelah kamu mengirim tugas melalui LMS secara mandiri.</p><form wire:submit="recordManualSubmission" class="mt-5 space-y-3">
                <label class="grid gap-1 text-sm font-bold">Tugas<select wire:model="taskId" required class="border-2 border-black px-3 py-2 font-normal"><option value="">Pilih tugas</option>@foreach($this->tasks as $task)<option value="{{ $task->id }}">{{ $task->title }}</option>@endforeach</select>@error('taskId')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                <label class="grid gap-1 text-sm font-bold">File final atau bukti<select wire:model="proofFileId" class="border-2 border-black px-3 py-2 font-normal"><option value="">Tanpa lampiran bukti</option>@foreach($this->files as $file)<option value="{{ $file->id }}">{{ $file->task->title }} · {{ $file->original_name }}</option>@endforeach</select>@error('proofFileId')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                <label class="grid gap-1 text-sm font-bold">Link LMS / bukti URL<input wire:model="submissionUrl" type="url" placeholder="https://…" class="border-2 border-black px-3 py-2 font-normal">@error('submissionUrl')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                <label class="grid gap-1 text-sm font-bold">Nomor konfirmasi opsional<input wire:model="confirmationReference" maxlength="255" class="border-2 border-black px-3 py-2 font-normal">@error('confirmationReference')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                <label class="grid gap-1 text-sm font-bold">Waktu dikumpulkan<input wire:model="submittedAt" type="datetime-local" class="border-2 border-black px-3 py-2 font-normal">@error('submittedAt')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                <label class="grid gap-1 text-sm font-bold">Catatan<textarea wire:model="notes" rows="3" maxlength="2000" class="border-2 border-black px-3 py-2 font-normal"></textarea>@error('notes')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                <button class="w-full border-2 border-black bg-[#facc15] px-4 py-3 text-sm font-black shadow-[3px_3px_0_#171717]">Catat bukti pengumpulan</button>
            </form></section>
            <section class="border-2 border-black bg-white p-5 shadow-[5px_5px_0_#171717]"><div class="flex items-end justify-between gap-3"><div><p class="text-xs font-black uppercase tracking-widest text-[#666]">Riwayat</p><h2 class="mt-1 text-2xl font-black">Semua catatan pengumpulan</h2></div><span class="border-2 border-black bg-[#b8d8ff] px-2 py-1 text-xs font-black">{{ $this->submissions->count() }} CATATAN</span></div>
                <ul class="mt-4 divide-y-2 divide-black border-t-2 border-black">
                    @forelse($this->submissions as $submission)
                        <li wire:key="submission-{{ $submission->id }}" class="space-y-2 py-4">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <h3 class="font-black">{{ $submission->task->title }}</h3>
                                    <p class="text-xs text-[#555]">{{ $submission->submitted_at?->format('d M Y, H:i') ?? 'Belum ada waktu' }} · {{ strtoupper($submission->platform) }}</p>
                                </div>
                                <span class="border-2 border-black bg-[#facc15] px-2 py-1 text-[10px] font-black uppercase">{{ str_replace('_', ' ', $submission->status) }}</span>
                            </div>

                            @if($submission->status === 'requires_manual_action')
                                <p role="status" class="border-2 border-black bg-[#fff1b8] px-3 py-2 text-xs font-bold">LMS belum mengonfirmasi pengiriman ini. Periksa assignment secara manual sebelum mencoba mengirim ulang.</p>
                            @endif

                            <label class="flex flex-wrap items-center gap-2 text-xs font-bold">Status penilaian
                                <select aria-label="Status penilaian untuk {{ $submission->task->title }}" wire:change="updateAssessmentStatus({{ $submission->id }}, $event.target.value)" class="border-2 border-black bg-white px-2 py-1">
                                    <option value="pending" @selected($submission->assessment_status === 'pending')>Menunggu</option>
                                    <option value="graded" @selected($submission->assessment_status === 'graded')>Dinilai</option>
                                    <option value="revision_required" @selected($submission->assessment_status === 'revision_required')>Perlu revisi</option>
                                </select>
                                @if($submission->assessed_at)<span class="text-[#555]">Diperbarui {{ $submission->assessed_at->diffForHumans() }}</span>@endif
                            </label>

                            <div class="border-l-2 border-black pl-3">
                                <p class="text-xs font-black uppercase tracking-wide">Riwayat penilaian</p>
                                @php($assessmentLabels = ['pending' => 'Menunggu', 'graded' => 'Dinilai', 'revision_required' => 'Perlu revisi'])
                                <ol class="mt-1 space-y-1">
                                    @foreach($submission->assessmentEvents as $assessmentEvent)
                                        @php($assessmentEventLabel = match($assessmentEvent->event_type) {
                                            'snapshot' => 'Status saat histori diaktifkan: '.($assessmentLabels[$assessmentEvent->to_status] ?? $assessmentEvent->to_status),
                                            'initial' => 'Status awal: '.($assessmentLabels[$assessmentEvent->to_status] ?? $assessmentEvent->to_status),
                                            default => ($assessmentLabels[$assessmentEvent->from_status] ?? $assessmentEvent->from_status).' → '.($assessmentLabels[$assessmentEvent->to_status] ?? $assessmentEvent->to_status),
                                        })
                                        <li wire:key="assessment-event-{{ $assessmentEvent->id }}" class="text-xs text-[#555]">{{ $assessmentEvent->created_at->format('d M Y, H:i') }} · {{ $assessmentEventLabel }}</li>
                                    @endforeach
                                </ol>
                            </div>

                            @if($submission->proofFile)
                                <a class="text-sm font-bold underline" href="{{ route('files.download', $submission->proofFile) }}">Bukti: {{ $submission->proofFile->original_name }}</a>
                            @endif

                            @if($submission->submission_url)
                                <a class="block break-all text-sm underline" href="{{ $submission->submission_url }}" target="_blank" rel="noopener noreferrer">{{ $submission->submission_url }}</a>
                            @endif

                            @if($submission->confirmation_reference)
                                <p class="text-xs">Referensi: {{ $submission->confirmation_reference }}</p>
                            @endif

                            @if($submission->notes)
                                <p class="whitespace-pre-line text-sm text-[#555]">{{ $submission->notes }}</p>
                            @endif

                            <div>
                                <p class="text-xs font-black uppercase tracking-wide">Percobaan ({{ $submission->attempts->count() }})</p>
                                <ul class="mt-1 space-y-1">
                                    @foreach($submission->attempts->sortBy('attempt_number') as $attempt)
                                        <li wire:key="submission-attempt-{{ $attempt->id }}" class="text-xs text-[#555]">
                                            #{{ $attempt->attempt_number }} · {{ str_replace('_', ' ', $attempt->status) }} · {{ $attempt->completed_at?->format('d M Y, H:i') ?? 'belum selesai' }}
                                            @if(data_get($attempt->response_payload, 'lms_verified') === false)
                                                · belum diverifikasi LMS
                                            @endif
                                            @if($attempt->failure_reason)<span class="block font-semibold text-red-800">{{ $attempt->failure_reason }}</span>@endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>

                            @if($submission->platform === 'manual')
                                <p class="text-xs font-bold text-[#9a3b25]">Dicatat manual olehmu; verifikasi LMS belum tersedia.</p>
                            @endif

                            @if($submission->status === 'failed' && array_key_exists($submission->platform, $this->lmsAdapters))
                                <button type="button" wire:click="retryLmsSubmission({{ $submission->id }})" wire:confirm="Coba kirim ulang tugas ini menggunakan idempotency key yang sama agar tidak membuat pengumpulan ganda?" wire:loading.attr="disabled" class="border-2 border-black bg-[#facc15] px-3 py-2 text-xs font-black">Coba lagi dengan aman</button>
                            @endif
                        </li>
                    @empty
                        <li class="py-8 text-sm text-[#555]">Belum ada riwayat. Pengumpulan manual pertama bisa dicatat dari formulir ini.</li>
                    @endforelse
                </ul>
            </section>
        </div>
    </div>
</div>

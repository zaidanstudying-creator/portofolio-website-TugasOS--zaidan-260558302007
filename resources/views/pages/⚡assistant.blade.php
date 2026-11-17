<?php

use App\Exceptions\Services\Ai\AiProviderUnavailableException;
use App\Exceptions\Services\Ai\AiRateLimitException;
use App\Models\TaskFile;
use App\Services\Ai\AiAssistantService;
use App\Services\Ai\AiProviderRegistry;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Illuminate\Validation\Rule;

new class extends Component
{
    public string $instruction = '';

    public bool $hasConsent = false;

    public bool $hasPriorityConsent = false;

    public bool $hasDocumentConsent = false;

    public bool $hasInsightConsent = false;

    public bool $hasChatConsent = false;

    public ?int $activeProposalId = null;

    public ?int $activePriorityAdviceId = null;

    public ?int $activeDocumentProposalId = null;

    public ?int $activeInsightId = null;

    public array $chatMessages = [];

    public string $chatQuestion = '';

    public string $documentFileId = '';

    public string $confirmedDocumentDueAt = '';

    public string $insightStartDate = '';

    public string $insightEndDate = '';

    public string $insightGroupBy = 'deadline';

    #[Computed]
    public function aiAvailable(): bool
    {
        return app(AiProviderRegistry::class)->availableFor(auth()->user()) !== [];
    }

    #[Computed]
    public function proposals(): Collection
    {
        return auth()->user()->aiRequests()
            ->where('purpose', 'task_breakdown')
            ->where('status', 'proposed')
            ->latest('created_at')
            ->limit(10)
            ->get();
    }

    #[Computed]
    public function priorityAdvice(): Collection
    {
        return auth()->user()->aiRequests()
            ->where('purpose', 'priority_assistant')
            ->where('status', 'proposed')
            ->latest('created_at')
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function documents(): Collection
    {
        return auth()->user()->taskFiles()
            ->with('task')
            ->latest('created_at')
            ->limit(100)
            ->get()
            ->filter(fn (TaskFile $file): bool => in_array(
                strtolower(pathinfo($file->original_name, PATHINFO_EXTENSION)),
                ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'md'],
                true,
            ))
            ->values();
    }

    #[Computed]
    public function documentProposals(): Collection
    {
        return auth()->user()->aiRequests()
            ->where('purpose', 'document_understanding')
            ->where('status', 'proposed')
            ->latest('created_at')
            ->limit(10)
            ->get();
    }

    #[Computed]
    public function documentRequests(): Collection
    {
        return auth()->user()->aiRequests()
            ->where('purpose', 'document_understanding')
            ->whereIn('status', ['queued', 'processing', 'failed'])
            ->latest('created_at')
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function productivityInsights(): Collection
    {
        return auth()->user()->aiRequests()
            ->where('purpose', 'productivity_insight')
            ->where('status', 'proposed')
            ->latest('created_at')
            ->limit(10)
            ->get();
    }

    #[Computed]
    public function chatTaskNames(): array
    {
        $taskIds = collect($this->chatMessages)
            ->flatMap(fn (array $message): array => $message['task_ids'] ?? [])
            ->unique()
            ->values();

        return auth()->user()->tasks()->whereIn('id', $taskIds)->pluck('title', 'id')->all();
    }

    #[Computed]
    public function activePriorityTaskNames(): array
    {
        if ($this->activePriorityAdviceId === null) {
            return [];
        }

        $advice = auth()->user()->aiRequests()
            ->where('purpose', 'priority_assistant')
            ->where('status', 'proposed')
            ->find($this->activePriorityAdviceId);

        if ($advice === null) {
            return [];
        }

        $taskIds = collect($advice->result['recommendations'] ?? [])->pluck('task_id');

        return auth()->user()->tasks()->whereIn('id', $taskIds)->pluck('title', 'id')->all();
    }

    public function createProposal(AiAssistantService $assistant): void
    {
        $validated = $this->validate([
            'instruction' => ['required', 'string', 'min:20', 'max:12000'],
            'hasConsent' => ['accepted'],
        ]);

        try {
            $proposal = $assistant->proposeTaskBreakdown(
                auth()->user(),
                $validated['instruction'],
                $validated['hasConsent'],
            );

            $this->activeProposalId = $proposal->getKey();
            $this->reset('instruction', 'hasConsent');
            unset($this->proposals);
            session()->flash('status', 'Usulan AI siap ditinjau. Belum ada tugas yang dibuat.');
        } catch (AiProviderUnavailableException) {
            $this->addError('assistant', 'AI belum tersedia. Hubungkan API key dan model OpenAI di konfigurasi aplikasi.');
        } catch (AiRateLimitException $exception) {
            $this->addError('assistant', 'Batas penggunaan AI tercapai. Coba lagi dalam '.$exception->retryAfterSeconds.' detik.');
        } catch (Throwable) {
            $this->addError('assistant', 'Usulan gagal dibuat. Periksa koneksi atau konfigurasi AI, lalu coba lagi.');
        }
    }

    public function acceptProposal(AiAssistantService $assistant): void
    {
        if ($this->activeProposalId === null) {
            $this->addError('assistant', 'Pilih usulan yang masih tersedia.');

            return;
        }

        $proposal = auth()->user()->aiRequests()->findOrFail($this->activeProposalId);

        try {
            $assistant->acceptTaskBreakdown(auth()->user(), $proposal);
            session()->flash('status', 'Tugas dan checklist dari usulan berhasil disimpan. Deadline tidak diisi otomatis.');
            $this->redirect(route('tasks.index'));
        } catch (Throwable) {
            $this->addError('assistant', 'Usulan ini sudah tidak bisa disimpan. Muat ulang halaman untuk melihat usulan yang masih aktif.');
        }
    }

    public function createPriorityAdvice(AiAssistantService $assistant): void
    {
        $validated = $this->validate([
            'hasPriorityConsent' => ['accepted'],
        ]);

        try {
            $advice = $assistant->recommendTaskPriority(auth()->user(), $validated['hasPriorityConsent']);
            $this->activePriorityAdviceId = $advice->getKey();
            $this->reset('hasPriorityConsent');
            unset($this->priorityAdvice);
            unset($this->activePriorityTaskNames);
            session()->flash('priority-status', 'Urutan prioritas siap ditinjau. Tugas, deadline, dan prioritas manual tidak diubah.');
        } catch (AiProviderUnavailableException) {
            $this->addError('priority', 'AI belum tersedia. Hubungkan API key dan model OpenAI di konfigurasi aplikasi.');
        } catch (AiRateLimitException $exception) {
            $this->addError('priority', 'Batas penggunaan AI tercapai. Coba lagi dalam '.$exception->retryAfterSeconds.' detik.');
        } catch (Throwable) {
            $this->addError('priority', 'Saran prioritas gagal dibuat. Pastikan ada tugas aktif dan coba lagi.');
        }
    }

    public function selectPriorityAdvice(int $adviceId): void
    {
        $advice = auth()->user()->aiRequests()
            ->where('purpose', 'priority_assistant')
            ->where('status', 'proposed')
            ->findOrFail($adviceId);

        $this->activePriorityAdviceId = $advice->getKey();
        unset($this->activePriorityTaskNames);
    }

    public function analyzeDocument(AiAssistantService $assistant): void
    {
        $validated = $this->validate([
            'documentFileId' => ['required', 'integer', Rule::exists('task_files', 'id')->where('user_id', auth()->id())],
            'hasDocumentConsent' => ['accepted'],
        ]);

        try {
            $file = auth()->user()->taskFiles()->findOrFail($validated['documentFileId']);
            $request = $assistant->queueTaskDocumentUnderstanding(auth()->user(), $file, $validated['hasDocumentConsent']);
            $this->activeDocumentProposalId = null;
            $this->reset('documentFileId', 'hasDocumentConsent', 'confirmedDocumentDueAt');
            unset($this->documentProposals);
            unset($this->documentRequests);
            session()->flash('document-status', 'Dokumen masuk antrean analisis. Hasilnya akan muncul di bagian Review sumber setelah selesai.');
        } catch (AiProviderUnavailableException) {
            $this->addError('document', 'AI belum tersedia. Hubungkan API key dan model OpenAI di konfigurasi aplikasi.');
        } catch (AiRateLimitException $exception) {
            $this->addError('document', 'Batas penggunaan AI tercapai. Coba lagi dalam '.$exception->retryAfterSeconds.' detik.');
        } catch (Throwable) {
            $this->addError('document', 'Dokumen tidak dapat dianalisis. Pastikan format dan ukuran file didukung, lalu coba lagi.');
        }
    }

    public function selectDocumentProposal(int $proposalId): void
    {
        $proposal = auth()->user()->aiRequests()
            ->where('purpose', 'document_understanding')
            ->where('status', 'proposed')
            ->findOrFail($proposalId);

        $this->activeDocumentProposalId = $proposal->getKey();
        $this->confirmedDocumentDueAt = '';
    }

    public function acceptDocumentProposal(AiAssistantService $assistant): void
    {
        $validated = $this->validate([
            'confirmedDocumentDueAt' => ['nullable', 'date'],
        ]);

        if ($this->activeDocumentProposalId === null) {
            $this->addError('document', 'Pilih hasil dokumen yang masih tersedia.');

            return;
        }

        $proposal = auth()->user()->aiRequests()->findOrFail($this->activeDocumentProposalId);

        try {
            $assistant->acceptDocumentUnderstanding(
                auth()->user(),
                $proposal,
                filled($validated['confirmedDocumentDueAt']) ? $validated['confirmedDocumentDueAt'] : null,
            );
            session()->flash('status', 'Tugas dari dokumen berhasil dibuat setelah persetujuanmu.');
            $this->redirect(route('tasks.index'));
        } catch (Throwable) {
            $this->addError('document', 'Hasil dokumen ini sudah tidak bisa disimpan. Muat ulang halaman untuk melihat usulan yang masih aktif.');
        }
    }

    public function createProductivityInsight(AiAssistantService $assistant): void
    {
        $validated = $this->validate([
            'insightStartDate' => ['required', 'date', 'before_or_equal:insightEndDate'],
            'insightEndDate' => ['required', 'date', 'after_or_equal:insightStartDate'],
            'insightGroupBy' => ['required', Rule::in(['created', 'deadline', 'completed'])],
            'hasInsightConsent' => ['accepted'],
        ]);

        try {
            $insight = $assistant->generateProductivityInsight(
                auth()->user(),
                $validated['insightStartDate'],
                $validated['insightEndDate'],
                $validated['insightGroupBy'],
                $validated['hasInsightConsent'],
            );

            $this->activeInsightId = $insight->getKey();
            $this->reset('hasInsightConsent');
            unset($this->productivityInsights);
            session()->flash('insight-status', 'Ringkasan produktivitas siap ditinjau. Data tugas tidak diubah.');
        } catch (AiProviderUnavailableException) {
            $this->addError('insight', 'AI belum tersedia. Hubungkan API key dan model OpenAI di konfigurasi aplikasi.');
        } catch (AiRateLimitException $exception) {
            $this->addError('insight', 'Batas penggunaan AI tercapai. Coba lagi dalam '.$exception->retryAfterSeconds.' detik.');
        } catch (Throwable) {
            $this->addError('insight', 'Insight gagal dibuat. Pastikan rentang memiliki tugas dan coba lagi.');
        }
    }

    public function selectProductivityInsight(int $insightId): void
    {
        $insight = auth()->user()->aiRequests()
            ->where('purpose', 'productivity_insight')
            ->where('status', 'proposed')
            ->findOrFail($insightId);

        $this->activeInsightId = $insight->getKey();
    }

    public function askTaskQuestion(AiAssistantService $assistant): void
    {
        $validated = $this->validate([
            'chatQuestion' => ['required', 'string', 'max:2000'],
            'hasChatConsent' => ['accepted'],
        ]);

        $history = collect($this->chatMessages)
            ->slice(-10)
            ->map(fn (array $message): array => [
                'role' => $message['role'],
                'content' => $message['content'],
            ])
            ->values()
            ->all();
        $question = $validated['chatQuestion'];

        try {
            $response = $assistant->answerTaskQuestion(
                auth()->user(),
                [...$history, ['role' => 'user', 'content' => $question]],
                $validated['hasChatConsent'],
            );

            $this->chatMessages = [
                ...$history,
                ['role' => 'user', 'content' => $question],
                [
                    'role' => 'assistant',
                    'content' => $response->result['answer'],
                    'task_ids' => $response->result['task_ids'],
                ],
            ];
            $this->reset('chatQuestion', 'hasChatConsent');
            unset($this->chatTaskNames);
        } catch (AiProviderUnavailableException) {
            $this->addError('chat', 'AI belum tersedia. Hubungkan API key dan model OpenAI di konfigurasi aplikasi.');
        } catch (AiRateLimitException $exception) {
            $this->addError('chat', 'Batas penggunaan AI tercapai. Coba lagi dalam '.$exception->retryAfterSeconds.' detik.');
        } catch (Throwable) {
            $this->addError('chat', 'Jawaban gagal dibuat. Periksa koneksi AI dan coba lagi.');
        }
    }

    public function selectProposal(int $proposalId): void
    {
        $proposal = auth()->user()->aiRequests()
            ->where('purpose', 'task_breakdown')
            ->where('status', 'proposed')
            ->findOrFail($proposalId);

        $this->activeProposalId = $proposal->getKey();
    }
};
?>

<div class="min-h-screen bg-[#f7f4ec] px-4 py-8 text-[#171717] sm:px-8 lg:px-10">
    <div class="mx-auto max-w-7xl space-y-7">
        <header class="flex flex-col justify-between gap-5 border-b-2 border-black pb-6 sm:flex-row sm:items-end">
            <div><p class="text-xs font-black uppercase tracking-[0.24em] text-[#555]">TUGAS!OS / AI Assistant</p><h1 class="mt-2 text-4xl font-black tracking-tight sm:text-5xl">Pecah tugas jadi langkah.</h1><p class="mt-3 max-w-2xl text-sm leading-6 text-[#555]">AI menyusun checklist, milestone, urutan kerja, estimasi, dan ide jadwal. Kamu meninjau hasilnya sebelum ada data baru yang disimpan.</p></div>
            <span class="border-2 border-black bg-[#b8d8ff] px-4 py-3 text-sm font-black shadow-[4px_4px_0_#171717]">TAHAP 08 — TASK BREAKDOWN</span>
        </header>

        @if(session('status'))<div role="status" aria-live="polite" class="border-2 border-black bg-[#b8edce] px-4 py-3 font-bold shadow-[4px_4px_0_#171717]">{{ session('status') }}</div>@endif

        @if(! $this->aiAvailable)
            <aside role="status" class="border-2 border-black bg-[#fff1b8] p-5 shadow-[4px_4px_0_#171717]"><h2 class="text-lg font-black">Provider AI belum dikonfigurasi</h2><p class="mt-1 text-sm leading-6">Isi <code>OPENAI_API_KEY</code> dan <code>OPENAI_MODEL</code> di environment aplikasi untuk mengaktifkan usulan. Pengelolaan tugas manual tetap tersedia.</p><a href="{{ route('tasks.index') }}" wire:navigate class="mt-3 inline-block font-black underline">Buka Tugas →</a></aside>
        @endif

        @if(session('document-status'))<div role="status" aria-live="polite" class="border-2 border-black bg-[#b8edce] px-4 py-3 font-bold shadow-[4px_4px_0_#171717]">{{ session('document-status') }}</div>@endif

        <div class="grid gap-6 xl:grid-cols-[0.9fr_1.1fr]">
            <section class="border-2 border-black bg-white p-5 shadow-[5px_5px_0_#171717] sm:p-6" aria-labelledby="task-breakdown-heading">
                <p class="text-xs font-black uppercase tracking-widest text-[#666]">Task Breakdown</p><h2 id="task-breakdown-heading" class="mt-1 text-2xl font-black">Instruksi tugas</h2>
                <form wire:submit="createProposal" class="mt-5 space-y-4">
                    <label for="task-instruction" class="block text-sm font-bold">Salin instruksi atau deskripsi tugas</label>
                    <textarea id="task-instruction" wire:model="instruction" rows="9" maxlength="12000" required aria-describedby="instruction-help" class="w-full border-2 border-black px-3 py-3 text-sm leading-6" placeholder="Contoh: Buat aplikasi pencatatan inventaris dengan ERD, API, antarmuka, dan laporan pengujian. Tenggat resmi tercantum di LMS."></textarea>
                    <p id="instruction-help" class="text-xs leading-5 text-[#555]">Maksimal 12.000 karakter. Jangan masukkan informasi pribadi yang tidak dibutuhkan. Lampiran tidak dikirim ke provider.</p>
                    @error('instruction')<p role="alert" class="text-sm font-bold text-red-800">{{ $message }}</p>@enderror
                    <label class="flex items-start gap-3 border-2 border-black bg-[#fff1b8] p-3 text-sm leading-5"><input type="checkbox" wire:model="hasConsent" required class="mt-0.5 size-5 accent-black"><span>Saya setuju agar <strong>teks instruksi di atas</strong> dikirim ke OpenAI untuk dibuatkan usulan. Prompt mentah tidak disimpan di riwayat aplikasi.</span></label>
                    @error('hasConsent')<p role="alert" class="text-sm font-bold text-red-800">Persetujuan diperlukan sebelum teks dikirim.</p>@enderror
                    @error('assistant')<p role="alert" class="border-2 border-black bg-[#ffd9d1] p-3 text-sm font-bold">{{ $message }}</p>@enderror
                    <button type="submit" @disabled(! $this->aiAvailable) class="w-full border-2 border-black bg-[#facc15] px-4 py-3 font-black shadow-[3px_3px_0_#171717] disabled:cursor-not-allowed disabled:opacity-50"><span wire:loading.remove wire:target="createProposal">Buat usulan untuk ditinjau</span><span wire:loading wire:target="createProposal">Menyusun usulan…</span></button>
                </form>
            </section>

            <section class="space-y-5" aria-labelledby="proposal-heading">
                <div class="border-2 border-black bg-[#dceaff] p-5 shadow-[5px_5px_0_#171717] sm:p-6">
                    <div class="flex flex-wrap items-end justify-between gap-3"><div><p class="text-xs font-black uppercase tracking-widest">Review sebelum simpan</p><h2 id="proposal-heading" class="mt-1 text-2xl font-black">Usulan tersimpan sementara</h2></div><span class="border-2 border-black bg-white px-2 py-1 text-xs font-black">{{ $this->proposals->count() }} USULAN</span></div>
                    @if($this->proposals->isEmpty())<p class="mt-4 border-t-2 border-black pt-4 text-sm text-[#555]">Belum ada usulan untuk ditinjau. Hasil AI akan muncul di sini dan tidak membuat tugas otomatis.</p>@else
                        <ul class="mt-4 divide-y-2 divide-black border-t-2 border-black">@foreach($this->proposals as $proposal)<li wire:key="ai-proposal-{{ $proposal->id }}" class="py-4"><button type="button" wire:click="selectProposal({{ $proposal->id }})" class="flex w-full items-start justify-between gap-3 text-left"><span><span class="block font-black">{{ $proposal->result['title'] }}</span><span class="mt-1 block text-xs text-[#555]">{{ $proposal->created_at->format('d M Y, H:i') }} · {{ $proposal->result['estimated_minutes'] }} menit</span></span><span class="border-2 border-black px-2 py-1 text-[10px] font-black uppercase {{ $activeProposalId === $proposal->id ? 'bg-[#facc15]' : 'bg-white' }}">{{ $activeProposalId === $proposal->id ? 'DIPILIH' : 'TINJAU' }}</span></button>
                            @if($activeProposalId === $proposal->id)
                                <div class="mt-4 space-y-4 border-t border-black/30 pt-4 text-sm">
                                    <p class="leading-6">{{ $proposal->result['summary'] }}</p>
                                    @foreach(['deliverables' => 'Deliverables', 'checklist' => 'Checklist', 'milestones' => 'Milestone', 'suggested_sequence' => 'Urutan pengerjaan', 'schedule_suggestions' => 'Saran jadwal'] as $key => $label)
                                        @if($proposal->result[$key] !== [])<div><h3 class="font-black">{{ $label }}</h3><ol class="mt-1 list-inside list-decimal space-y-1">@foreach($proposal->result[$key] as $item)<li>{{ $item }}</li>@endforeach</ol></div>@endif
                                    @endforeach
                                    <p class="border-2 border-black bg-white p-3 text-xs font-bold">Deadline resmi tidak diisi atau diubah AI. Periksa semua saran sebelum menyimpan.</p>
                                    <button type="button" wire:click="acceptProposal" wire:loading.attr="disabled" wire:target="acceptProposal" class="w-full border-2 border-black bg-[#b8edce] px-4 py-3 font-black shadow-[3px_3px_0_#171717]"><span wire:loading.remove wire:target="acceptProposal">Setujui dan simpan tugas + checklist</span><span wire:loading wire:target="acceptProposal">Menyimpan…</span></button>
                                </div>
                            @endif
                        </li>@endforeach</ul>
                    @endif
                </div>
                <aside class="border-2 border-black bg-white p-5 text-sm leading-6 shadow-[4px_4px_0_#171717]"><h2 class="font-black">Batas AI</h2><p class="mt-1 text-[#555]">AI hanya memberi usulan dan tidak mengisi atau mengubah deadline resmi. Progres tetap dihitung oleh checklist aplikasi. Saran milestone, urutan, dan jadwal disimpan sebagai catatan tugas setelah kamu menyetujuinya.</p></aside>
            </section>
        </div>

        <section class="grid gap-6 border-t-2 border-black pt-7 xl:grid-cols-[0.9fr_1.1fr]" aria-labelledby="priority-heading">
            <div class="border-2 border-black bg-[#fff1b8] p-5 shadow-[5px_5px_0_#171717] sm:p-6"><p class="text-xs font-black uppercase tracking-widest">Priority Assistant</p><h2 id="priority-heading" class="mt-1 text-2xl font-black">Tentukan fokus berikutnya</h2><p class="mt-2 text-sm leading-6 text-[#555]">AI membandingkan hingga 100 tugas aktif berdasarkan deadline, prioritas manual, status, dan estimasi durasi. Saran tidak mengubah data tugas.</p>
                <form wire:submit="createPriorityAdvice" class="mt-4 space-y-3">
                    <label class="flex items-start gap-3 border-2 border-black bg-white p-3 text-sm leading-5"><input type="checkbox" wire:model="hasPriorityConsent" required class="mt-0.5 size-5 accent-black"><span>Saya setuju mengirim judul tugas aktif, deadline, prioritas, status, dan estimasi durasi ke OpenAI untuk mendapat saran urutan.</span></label>
                    @error('hasPriorityConsent')<p role="alert" class="text-sm font-bold text-red-800">Persetujuan diperlukan sebelum data tugas dikirim.</p>@enderror
                    @error('priority')<p role="alert" class="border-2 border-black bg-[#ffd9d1] p-3 text-sm font-bold">{{ $message }}</p>@enderror
                    <button type="submit" @disabled(! $this->aiAvailable) class="w-full border-2 border-black bg-white px-4 py-3 font-black shadow-[3px_3px_0_#171717] disabled:cursor-not-allowed disabled:opacity-50"><span wire:loading.remove wire:target="createPriorityAdvice">Buat saran prioritas</span><span wire:loading wire:target="createPriorityAdvice">Menganalisis tugas…</span></button>
                </form>
            </div>
            <div class="border-2 border-black bg-white p-5 shadow-[5px_5px_0_#171717] sm:p-6"><div class="flex items-end justify-between gap-3"><div><p class="text-xs font-black uppercase tracking-widest text-[#666]">Saran untuk ditinjau</p><h2 class="mt-1 text-2xl font-black">Urutan pengerjaan</h2></div><span class="border-2 border-black bg-[#b8d8ff] px-2 py-1 text-xs font-black">{{ $this->priorityAdvice->count() }} SARAN</span></div>
                @if(session('priority-status'))<p role="status" class="mt-3 border-2 border-black bg-[#b8edce] p-3 text-sm font-bold">{{ session('priority-status') }}</p>@endif
                @if($this->priorityAdvice->isEmpty())<p class="mt-4 border-t-2 border-black pt-4 text-sm text-[#555]">Belum ada saran prioritas.</p>@else
                    <ul class="mt-4 divide-y-2 divide-black border-t-2 border-black">@foreach($this->priorityAdvice as $advice)<li wire:key="priority-advice-{{ $advice->id }}" class="py-4"><button type="button" wire:click="selectPriorityAdvice({{ $advice->id }})" class="flex w-full items-center justify-between gap-3 text-left"><span class="font-black">Saran {{ $advice->created_at->format('d M Y, H:i') }}</span><span class="border-2 border-black px-2 py-1 text-[10px] font-black {{ $activePriorityAdviceId === $advice->id ? 'bg-[#facc15]' : 'bg-[#f7f4ec]' }}">{{ $activePriorityAdviceId === $advice->id ? 'DIPILIH' : 'TINJAU' }}</span></button>
                        @if($activePriorityAdviceId === $advice->id)<ol class="mt-3 space-y-3">@foreach(collect($advice->result['recommendations'])->sortBy('rank') as $recommendation)<li class="border-2 border-black p-3"><div class="flex items-start gap-3"><span class="grid size-8 shrink-0 place-items-center border-2 border-black bg-[#facc15] font-black">{{ $recommendation['rank'] }}</span><div><a href="{{ route('tasks.index') }}" wire:navigate class="font-black underline">{{ $this->activePriorityTaskNames[$recommendation['task_id']] ?? 'Tugas tidak tersedia' }}</a><p class="mt-1 text-sm">{{ $recommendation['reason'] }}</p><p class="mt-1 text-xs font-bold text-[#555]">Langkah berikutnya: {{ $recommendation['next_action'] }}</p></div></div></li>@endforeach</ol>@endif
                    </li>@endforeach</ul>
                @endif
            </div>
        </section>

        <section class="grid gap-6 border-t-2 border-black pt-7 xl:grid-cols-[0.9fr_1.1fr]" aria-labelledby="document-heading">
            <div class="border-2 border-black bg-[#ffd9d1] p-5 shadow-[5px_5px_0_#171717] sm:p-6"><p class="text-xs font-black uppercase tracking-widest">Document Understanding</p><h2 id="document-heading" class="mt-1 text-2xl font-black">Baca instruksi dari File Vault</h2><p class="mt-2 text-sm leading-6 text-[#555]">Pilih satu PDF, Word, Excel, PowerPoint, TXT, atau Markdown maksimal 20 MB. Hanya dokumen yang dipilih yang dikirim ke OpenAI setelah persetujuanmu.</p><p class="mt-2 border-2 border-black bg-white p-3 text-xs font-bold">AI tidak menyetel deadline kalender. Deadline yang diekstrak harus kamu verifikasi dan masukkan sendiri sebelum tugas dibuat.</p>
                <form wire:submit="analyzeDocument" class="mt-4 space-y-3">
                    <label for="document-file" class="block text-sm font-bold">Dokumen privat</label>
                    <select id="document-file" wire:model="documentFileId" required class="w-full border-2 border-black bg-white px-3 py-3 text-sm"><option value="">Pilih file dari File Vault</option>@foreach($this->documents as $document)<option wire:key="ai-document-option-{{ $document->id }}" value="{{ $document->id }}">{{ $document->original_name }} · {{ $document->task->title }}</option>@endforeach</select>
                    @if($this->documents->isEmpty())<p class="text-xs text-[#555]">Belum ada dokumen yang didukung di File Vault.</p>@endif
                    @error('documentFileId')<p role="alert" class="text-sm font-bold text-red-800">Pilih dokumen yang menjadi milik akunmu.</p>@enderror
                    <label class="flex items-start gap-3 border-2 border-black bg-white p-3 text-sm leading-5"><input type="checkbox" wire:model="hasDocumentConsent" required class="mt-0.5 size-5 accent-black"><span>Saya setuju mengirim <strong>isi file yang dipilih</strong> ke OpenAI untuk ekstraksi instruksi. Hasil ekstraksi akan disimpan sebagai usulan; isi dokumen mentah tidak disimpan di riwayat AI.</span></label>
                    @error('hasDocumentConsent')<p role="alert" class="text-sm font-bold text-red-800">Persetujuan diperlukan sebelum dokumen dikirim.</p>@enderror
                    @error('document')<p role="alert" class="border-2 border-black bg-white p-3 text-sm font-bold">{{ $message }}</p>@enderror
                    <button type="submit" @disabled(! $this->aiAvailable || $this->documents->isEmpty()) class="w-full border-2 border-black bg-white px-4 py-3 font-black shadow-[3px_3px_0_#171717] disabled:cursor-not-allowed disabled:opacity-50"><span wire:loading.remove wire:target="analyzeDocument">Ekstrak instruksi untuk ditinjau</span><span wire:loading wire:target="analyzeDocument">Membaca dokumen…</span></button>
                </form>
            </div>
            <div @if($this->documentRequests->contains(fn ($request) => in_array($request->status, ['queued', 'processing'], true))) wire:poll.5s @endif class="border-2 border-black bg-white p-5 shadow-[5px_5px_0_#171717] sm:p-6"><div class="flex items-end justify-between gap-3"><div><p class="text-xs font-black uppercase tracking-widest text-[#666]">Review sumber</p><h2 class="mt-1 text-2xl font-black">Hasil ekstraksi</h2></div><span class="border-2 border-black bg-[#fff1b8] px-2 py-1 text-xs font-black">{{ $this->documentProposals->count() }} USULAN</span></div>
                @if($this->documentRequests->isNotEmpty())<ul role="status" aria-live="polite" class="mt-4 space-y-2">@foreach($this->documentRequests as $documentRequest)<li wire:key="document-request-{{ $documentRequest->id }}" class="border-2 border-black px-3 py-2 text-sm font-bold">{{ match($documentRequest->status) {'queued' => 'Menunggu analisis dokumen…', 'processing' => 'Dokumen sedang dianalisis…', 'failed' => 'Analisis gagal. Periksa file dan konfigurasi AI, lalu kirim ulang.', default => ''} }}</li>@endforeach</ul>@endif
                @if($this->documentProposals->isEmpty())<p class="mt-4 border-t-2 border-black pt-4 text-sm text-[#555]">Belum ada hasil ekstraksi untuk ditinjau.</p>@else
                    <ul class="mt-4 divide-y-2 divide-black border-t-2 border-black">@foreach($this->documentProposals as $documentProposal)<li wire:key="document-proposal-{{ $documentProposal->id }}" class="py-4"><button type="button" wire:click="selectDocumentProposal({{ $documentProposal->id }})" class="flex w-full items-start justify-between gap-3 text-left"><span><span class="block font-black">{{ $documentProposal->result['title'] }}</span><span class="mt-1 block text-xs text-[#555]">{{ $documentProposal->created_at->format('d M Y, H:i') }}</span></span><span class="border-2 border-black px-2 py-1 text-[10px] font-black {{ $activeDocumentProposalId === $documentProposal->id ? 'bg-[#facc15]' : 'bg-white' }}">{{ $activeDocumentProposalId === $documentProposal->id ? 'DIPILIH' : 'TINJAU' }}</span></button>
                        @if($activeDocumentProposalId === $documentProposal->id)<div class="mt-4 space-y-3 border-t border-black/30 pt-4 text-sm"><p class="leading-6">{{ $documentProposal->result['summary'] }}</p><div><h3 class="font-black">Deliverables</h3>@if($documentProposal->result['deliverables'] === [])<p class="mt-1 text-[#555]">Tidak ditemukan di dokumen.</p>@else<ul class="mt-1 list-inside list-disc">@foreach($documentProposal->result['deliverables'] as $deliverable)<li>{{ $deliverable }}</li>@endforeach</ul>@endif</div><div><h3 class="font-black">Persyaratan</h3>@if($documentProposal->result['requirements'] === [])<p class="mt-1 text-[#555]">Tidak ditemukan di dokumen.</p>@else<ul class="mt-1 list-inside list-disc">@foreach($documentProposal->result['requirements'] as $requirement)<li>{{ $requirement }}</li>@endforeach</ul>@endif</div><p><strong>Format pengumpulan:</strong> {{ $documentProposal->result['submission_format'] ?: 'Tidak ditemukan' }}</p><p class="border-2 border-black bg-[#fff1b8] p-3"><strong>Deadline tertulis:</strong> {{ $documentProposal->result['deadline_text'] ?: 'Tidak ditemukan' }}. AI tidak mengubah teks ini menjadi deadline kalender.</p>
                                <form wire:submit="acceptDocumentProposal" class="space-y-2"><label for="confirmed-document-deadline" class="block font-bold">Deadline resmi yang sudah kamu verifikasi (opsional)<input id="confirmed-document-deadline" type="datetime-local" wire:model="confirmedDocumentDueAt" class="mt-1 w-full border-2 border-black px-3 py-2 font-normal"></label>@error('confirmedDocumentDueAt')<p role="alert" class="text-xs font-bold text-red-800">{{ $message }}</p>@enderror<button type="submit" wire:loading.attr="disabled" wire:target="acceptDocumentProposal" class="w-full border-2 border-black bg-[#b8edce] px-4 py-3 font-black shadow-[3px_3px_0_#171717]"><span wire:loading.remove wire:target="acceptDocumentProposal">Setujui hasil dan buat tugas</span><span wire:loading wire:target="acceptDocumentProposal">Menyimpan tugas…</span></button></form>
                            </div>@endif
                    </li>@endforeach</ul>
                @endif
            </div>
        </section>

        <section class="grid gap-6 border-t-2 border-black pt-7 xl:grid-cols-[0.9fr_1.1fr]" aria-labelledby="insight-heading">
            <div class="border-2 border-black bg-[#b8edce] p-5 shadow-[5px_5px_0_#171717] sm:p-6"><p class="text-xs font-black uppercase tracking-widest">Productivity Insight</p><h2 id="insight-heading" class="mt-1 text-2xl font-black">Lihat pola produktivitas</h2><p class="mt-2 text-sm leading-6 text-[#555]">Pilih periode dan dasar pengelompokan. AI hanya menerima status, prioritas, deadline, estimasi, progres, dan metrik agregat; judul tugas tidak dikirim.</p>
                <form wire:submit="createProductivityInsight" class="mt-4 space-y-3">
                    <div class="grid gap-3 sm:grid-cols-2"><label class="grid gap-1 text-sm font-bold">Dari tanggal<input type="date" wire:model="insightStartDate" required class="border-2 border-black bg-white px-3 py-2 font-normal"></label><label class="grid gap-1 text-sm font-bold">Sampai tanggal<input type="date" wire:model="insightEndDate" required class="border-2 border-black bg-white px-3 py-2 font-normal"></label></div>
                    @error('insightStartDate')<p role="alert" class="text-xs font-bold text-red-800">{{ $message }}</p>@enderror
                    @error('insightEndDate')<p role="alert" class="text-xs font-bold text-red-800">{{ $message }}</p>@enderror
                    <label class="grid gap-1 text-sm font-bold">Kelompokkan tugas berdasarkan<select wire:model="insightGroupBy" class="border-2 border-black bg-white px-3 py-2 font-normal"><option value="deadline">Tanggal deadline</option><option value="created">Tanggal dibuat</option><option value="completed">Tanggal selesai</option></select></label>
                    <label class="flex items-start gap-3 border-2 border-black bg-white p-3 text-sm leading-5"><input type="checkbox" wire:model="hasInsightConsent" required class="mt-0.5 size-5 accent-black"><span>Saya setuju mengirim ringkasan anonim progres tugas dalam periode yang dipilih ke OpenAI untuk mendapat insight produktivitas.</span></label>
                    @error('hasInsightConsent')<p role="alert" class="text-sm font-bold text-red-800">Persetujuan diperlukan sebelum data dikirim.</p>@enderror
                    @error('insight')<p role="alert" class="border-2 border-black bg-white p-3 text-sm font-bold">{{ $message }}</p>@enderror
                    <button type="submit" @disabled(! $this->aiAvailable) class="w-full border-2 border-black bg-white px-4 py-3 font-black shadow-[3px_3px_0_#171717] disabled:cursor-not-allowed disabled:opacity-50"><span wire:loading.remove wire:target="createProductivityInsight">Buat insight periode ini</span><span wire:loading wire:target="createProductivityInsight">Menganalisis pola…</span></button>
                </form>
            </div>
            <div class="border-2 border-black bg-white p-5 shadow-[5px_5px_0_#171717] sm:p-6"><div class="flex items-end justify-between gap-3"><div><p class="text-xs font-black uppercase tracking-widest text-[#666]">Review insight</p><h2 class="mt-1 text-2xl font-black">Pola dan saran</h2></div><span class="border-2 border-black bg-[#b8edce] px-2 py-1 text-xs font-black">{{ $this->productivityInsights->count() }} INSIGHT</span></div>
                @if(session('insight-status'))<p role="status" class="mt-3 border-2 border-black bg-[#b8edce] p-3 text-sm font-bold">{{ session('insight-status') }}</p>@endif
                @if($this->productivityInsights->isEmpty())<p class="mt-4 border-t-2 border-black pt-4 text-sm text-[#555]">Belum ada insight untuk ditinjau.</p>@else
                    <ul class="mt-4 divide-y-2 divide-black border-t-2 border-black">@foreach($this->productivityInsights as $insight)<li wire:key="productivity-insight-{{ $insight->id }}" class="py-4"><button type="button" wire:click="selectProductivityInsight({{ $insight->id }})" class="flex w-full items-center justify-between gap-3 text-left"><span class="font-black">{{ $insight->created_at->format('d M Y, H:i') }} · {{ $insight->result['period']['start'] ?? '' }}—{{ $insight->result['period']['end'] ?? '' }}</span><span class="border-2 border-black px-2 py-1 text-[10px] font-black {{ $activeInsightId === $insight->id ? 'bg-[#facc15]' : 'bg-[#f7f4ec]' }}">{{ $activeInsightId === $insight->id ? 'DIPILIH' : 'TINJAU' }}</span></button>
                        @if($activeInsightId === $insight->id)<div class="mt-3 space-y-3 border-t border-black/30 pt-3 text-sm"><p class="leading-6">{{ $insight->result['summary'] }}</p>@foreach(['patterns' => 'Pola yang terlihat', 'potential_delay_causes' => 'Kemungkinan penyebab keterlambatan', 'schedule_suggestions' => 'Saran jadwal'] as $key => $label)<div><h3 class="font-black">{{ $label }}</h3>@if($insight->result[$key] === [])<p class="mt-1 text-[#555]">Tidak ada pola yang cukup kuat pada data ini.</p>@else<ul class="mt-1 list-inside list-disc">@foreach($insight->result[$key] as $item)<li>{{ $item }}</li>@endforeach</ul>@endif</div>@endforeach<p class="border-2 border-black bg-[#fff1b8] p-3 text-xs"><strong>Batasan data:</strong> {{ $insight->result['data_limitations'] }}</p><p class="text-xs font-bold text-[#555]">Insight hanya saran berbasis data terpilih; tidak ada tugas atau deadline yang berubah.</p></div>@endif
                    </li>@endforeach</ul>
                @endif
            </div>
        </section>

        <section class="grid gap-6 border-t-2 border-black pt-7 xl:grid-cols-[0.75fr_1.25fr]" aria-labelledby="chat-heading">
            <div class="border-2 border-black bg-[#b8d8ff] p-5 shadow-[5px_5px_0_#171717] sm:p-6"><p class="text-xs font-black uppercase tracking-widest">AI Chat Assistant</p><h2 id="chat-heading" class="mt-1 text-2xl font-black">Tanya data tugasmu</h2><p class="mt-2 text-sm leading-6 text-[#555]">AI membaca maksimal 200 tugas induk milik akunmu: judul, status, prioritas, jadwal, progres, estimasi, mata kuliah, pemberi tugas, dan status pengumpulan. AI tidak memiliki akses untuk mengubah data.</p><p class="mt-2 text-xs font-bold text-[#555]">Pertanyaan mentah tidak disimpan. Percakapan hanya bertahan selama halaman ini terbuka.</p>
                <form wire:submit="askTaskQuestion" class="mt-4 space-y-3"><label for="task-chat-question" class="block text-sm font-bold">Pertanyaan</label><textarea id="task-chat-question" wire:model="chatQuestion" rows="4" maxlength="2000" required aria-describedby="task-chat-question-help" class="w-full border-2 border-black bg-white px-3 py-3 text-sm" placeholder="Contoh: Tugas apa yang deadline-nya paling dekat?"></textarea><p id="task-chat-question-help" class="text-xs text-[#555]">Maksimal 2.000 karakter.</p><label class="flex items-start gap-3 border-2 border-black bg-white p-3 text-sm leading-5"><input type="checkbox" wire:model="hasChatConsent" required class="mt-0.5 size-5 accent-black"><span>Saya setuju mengirim pertanyaan dan ringkasan metadata tugas di atas ke OpenAI untuk menjawab pertanyaan ini.</span></label>@error('chatQuestion')<p role="alert" class="text-xs font-bold text-red-800">{{ $message }}</p>@enderror @error('hasChatConsent')<p role="alert" class="text-xs font-bold text-red-800">Persetujuan diperlukan sebelum data dikirim.</p>@enderror @error('chat')<p role="alert" class="border-2 border-black bg-white p-3 text-sm font-bold">{{ $message }}</p>@enderror<button type="submit" @disabled(! $this->aiAvailable) class="w-full border-2 border-black bg-[#facc15] px-4 py-3 font-black shadow-[3px_3px_0_#171717] disabled:cursor-not-allowed disabled:opacity-50"><span wire:loading.remove wire:target="askTaskQuestion">Kirim pertanyaan</span><span wire:loading wire:target="askTaskQuestion">Mencari jawaban…</span></button></form>
            </div>
            <div class="border-2 border-black bg-white p-5 shadow-[5px_5px_0_#171717] sm:p-6"><h2 class="text-2xl font-black">Percakapan</h2><ol role="log" aria-live="polite" aria-relevant="additions" class="mt-4 max-h-[36rem] space-y-4 overflow-y-auto border-t-2 border-black pt-4">@forelse($this->chatMessages as $index => $message)<li wire:key="chat-message-{{ $index }}" class="max-w-3xl border-2 border-black p-4 {{ $message['role'] === 'assistant' ? 'bg-[#f7f4ec]' : 'ml-auto bg-[#fff1b8]' }}"><p class="text-[10px] font-black uppercase tracking-widest">{{ $message['role'] === 'assistant' ? 'TUGAS!OS AI' : 'Kamu' }}</p><p class="mt-2 whitespace-pre-line text-sm leading-6">{{ $message['content'] }}</p>@if($message['role'] === 'assistant' && ($message['task_ids'] ?? []) !== [])<ul class="mt-3 flex flex-wrap gap-2">@foreach($message['task_ids'] as $taskId)<li wire:key="chat-citation-{{ $index }}-{{ $taskId }}"><a href="{{ route('tasks.index') }}" wire:navigate class="inline-block border-2 border-black bg-white px-2 py-1 text-xs font-bold underline">{{ $this->chatTaskNames[$taskId] ?? 'Tugas tidak tersedia' }}</a></li>@endforeach</ul>@endif</li>@empty<li class="text-sm text-[#555]">Belum ada pesan. Tanyakan tentang deadline, progres, prioritas, atau pengumpulan.</li>@endforelse</ol></div>
        </section>
    </div>
</div>

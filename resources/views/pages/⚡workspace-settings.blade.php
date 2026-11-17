<?php

use App\Services\TaskFilePolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $semesterName = '';

    public string $semesterStartsAt = '';

    public string $semesterEndsAt = '';

    public bool $semesterIsActive = false;

    public string $categoryName = '';

    public string $categoryColor = '#FACC15';

    public string $categoryKind = 'academic';

    public string $courseName = '';

    public string $courseCode = '';

    public string $courseInstructor = '';

    public string $courseSemesterId = '';

    public string $assignerName = '';

    public string $assignerTitle = '';

    public string $assignerInstitution = '';

    public string $assignerEmail = '';

    public string $assignerPhone = '';

    public array $assignerRoles = [];

    public string $customAssignerRole = '';

    public string $criticalHours = '24';

    public string $urgentDays = '3';

    public string $upcomingDays = '7';

    public string $progressAggregation = 'weighted';

    public string $aiModel = '';

    public string $aiApiKey = '';

    public string $maxFileSizeMb = '1';

    /** @var list<string> */
    public array $allowedFileExtensions = [];

    public function mount(): void
    {
        $urgencySettings = auth()->user()->settings['deadline_urgency'] ?? [];
        $this->criticalHours = (string) ($urgencySettings['critical_hours'] ?? 24);
        $this->urgentDays = (string) ($urgencySettings['urgent_days'] ?? 3);
        $this->upcomingDays = (string) ($urgencySettings['upcoming_days'] ?? 7);
        $this->progressAggregation = (string) (auth()->user()->settings['progress_aggregation'] ?? 'weighted');
        $this->aiModel = (string) (auth()->user()->ai_model ?: config('services.ai.model', 'gpt-4.1-mini'));

        $fileSettings = app(TaskFilePolicy::class)->forUser(auth()->user());
        $this->maxFileSizeMb = (string) $fileSettings['max_size_mb'];
        $this->allowedFileExtensions = $fileSettings['extensions'];
    }

    #[Computed]
    public function maxAvailableFileSizeMb(): int
    {
        return app(TaskFilePolicy::class)->serverMaxSizeMb();
    }

    #[Computed]
    public function maxAvailableFileSizeLabel(): string
    {
        $maximumKilobytes = app(TaskFilePolicy::class)->serverMaxSizeKb();

        return $maximumKilobytes >= 1024
            ? number_format($maximumKilobytes / 1024, 2).' MB'
            : $maximumKilobytes.' KB';
    }

    #[Computed]
    public function semesters(): Collection
    {
        return auth()->user()->semesters()->latest('starts_at')->get();
    }

    #[Computed]
    public function categories(): Collection
    {
        return auth()->user()->categories()->orderBy('name')->get();
    }

    #[Computed]
    public function courses(): Collection
    {
        return auth()->user()->courses()->with('semester')->orderBy('name')->get();
    }

    #[Computed]
    public function assigners(): Collection
    {
        return auth()->user()->assigners()->with('roles')->orderBy('name')->get();
    }

    public function saveSemester(): void
    {
        $validated = $this->validate([
            'semesterName' => ['required', 'string', 'max:120'],
            'semesterStartsAt' => ['required', 'date'],
            'semesterEndsAt' => ['required', 'date', 'after_or_equal:semesterStartsAt'],
            'semesterIsActive' => ['boolean'],
        ]);

        DB::transaction(function () use ($validated): void {
            $user = auth()->user();

            if ($validated['semesterIsActive']) {
                $user->semesters()->update(['is_active' => false]);
            }

            $user->semesters()->create([
                'name' => $validated['semesterName'],
                'starts_at' => $validated['semesterStartsAt'],
                'ends_at' => $validated['semesterEndsAt'],
                'is_active' => $validated['semesterIsActive'],
            ]);
        });

        $this->reset(['semesterName', 'semesterStartsAt', 'semesterEndsAt', 'semesterIsActive']);
        unset($this->semesters);
        session()->flash('status', 'Semester berhasil ditambahkan.');
    }

    public function saveCategory(): void
    {
        $validated = $this->validate([
            'categoryName' => ['required', 'string', 'max:80', Rule::unique('categories', 'name')->where('user_id', auth()->id())],
            'categoryColor' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'categoryKind' => ['required', Rule::in(['academic', 'personal', 'organization', 'habit'])],
        ]);

        auth()->user()->categories()->create([
            'name' => $validated['categoryName'],
            'color' => $validated['categoryColor'],
            'kind' => $validated['categoryKind'],
        ]);

        $this->reset(['categoryName', 'categoryKind']);
        $this->categoryColor = '#FACC15';
        unset($this->categories);
        session()->flash('status', 'Kategori berhasil ditambahkan.');
    }

    public function saveCourse(): void
    {
        $validated = $this->validate([
            'courseName' => ['required', 'string', 'max:120'],
            'courseCode' => ['nullable', 'string', 'max:40'],
            'courseInstructor' => ['nullable', 'string', 'max:120'],
            'courseSemesterId' => ['nullable', Rule::exists('semesters', 'id')->where('user_id', auth()->id())],
        ]);

        auth()->user()->courses()->create([
            'name' => $validated['courseName'],
            'code' => $validated['courseCode'],
            'instructor' => $validated['courseInstructor'],
            'semester_id' => filled($validated['courseSemesterId']) ? $validated['courseSemesterId'] : null,
        ]);

        $this->reset(['courseName', 'courseCode', 'courseInstructor', 'courseSemesterId']);
        unset($this->courses);
        session()->flash('status', 'Mata kuliah berhasil ditambahkan.');
    }

    public function saveAssigner(): void
    {
        $validated = $this->validate([
            'assignerName' => ['required', 'string', 'max:120'],
            'assignerTitle' => ['nullable', 'string', 'max:120'],
            'assignerInstitution' => ['nullable', 'string', 'max:160'],
            'assignerEmail' => ['nullable', 'email', 'max:160'],
            'assignerPhone' => ['nullable', 'string', 'max:40'],
            'assignerRoles' => ['array'],
            'assignerRoles.*' => [Rule::in(['Dosen', 'Mentor', 'Kaprodi', 'Asisten dosen', 'Pembimbing', 'Ketua organisasi', 'Diri sendiri'])],
            'customAssignerRole' => ['nullable', 'string', 'max:80'],
        ]);

        DB::transaction(function () use ($validated): void {
            $assigner = auth()->user()->assigners()->create([
                'name' => $validated['assignerName'],
                'title' => $validated['assignerTitle'],
                'institution' => $validated['assignerInstitution'],
                'email' => $validated['assignerEmail'],
                'phone' => $validated['assignerPhone'],
            ]);

            $roles = collect($validated['assignerRoles'])
                ->when(filled($validated['customAssignerRole']), fn ($roles) => $roles->push(trim($validated['customAssignerRole'])))
                ->filter()
                ->unique()
                ->map(fn (string $role): array => ['name' => $role])
                ->all();

            $assigner->roles()->createMany($roles);
        });

        $this->reset(['assignerName', 'assignerTitle', 'assignerInstitution', 'assignerEmail', 'assignerPhone', 'assignerRoles', 'customAssignerRole']);
        unset($this->assigners);
        session()->flash('status', 'Pemberi tugas berhasil ditambahkan.');
    }

    public function saveDeadlineUrgency(): void
    {
        $validated = $this->validate([
            'criticalHours' => ['required', 'integer', 'min:1', 'max:168'],
            'urgentDays' => ['required', 'integer', 'min:1', 'max:30'],
            'upcomingDays' => ['required', 'integer', 'min:2', 'max:60'],
        ]);

        if ((int) $validated['criticalHours'] >= (int) $validated['urgentDays'] * 24) {
            $this->addError('criticalHours', 'Ambang critical harus lebih singkat daripada ambang urgent.');

            return;
        }

        if ((int) $validated['urgentDays'] >= (int) $validated['upcomingDays']) {
            $this->addError('upcomingDays', 'Ambang upcoming harus lebih lama daripada ambang urgent.');

            return;
        }

        $user = auth()->user();
        $settings = $user->settings ?? [];
        $settings['deadline_urgency'] = [
            'critical_hours' => (int) $validated['criticalHours'],
            'urgent_days' => (int) $validated['urgentDays'],
            'upcoming_days' => (int) $validated['upcomingDays'],
        ];
        $user->settings = $settings;
        $user->save();

        session()->flash('status', 'Ambang urgensi deadline berhasil disimpan.');
    }

    public function saveFileSettings(): void
    {
        $maximumAvailableSize = app(TaskFilePolicy::class)->serverMaxSizeMb();
        $validated = $this->validate([
            'maxFileSizeMb' => ['required', 'integer', 'min:1', 'max:'.$maximumAvailableSize],
            'allowedFileExtensions' => ['required', 'array', 'min:1'],
            'allowedFileExtensions.*' => ['required', 'string', Rule::in(TaskFilePolicy::ALLOWED_EXTENSIONS)],
        ]);

        $user = auth()->user();
        $settings = $user->settings ?? [];
        $settings['file_upload'] = [
            'max_size_mb' => (int) $validated['maxFileSizeMb'],
            'extensions' => array_values(array_unique($validated['allowedFileExtensions'])),
        ];
        $user->settings = $settings;
        $user->save();

        session()->flash('status', 'Batas dan format File Vault berhasil disimpan.');
    }

    public function saveProgressAggregation(): void
    {
        $validated = $this->validate([
            'progressAggregation' => ['required', Rule::in(['equal', 'weighted'])],
        ]);

        $user = auth()->user();

        DB::transaction(function () use ($user, $validated): void {
            $settings = $user->settings ?? [];
            $previousAggregation = $settings['progress_aggregation'] ?? 'weighted';
            $nextAggregation = $validated['progressAggregation'];

            if ($previousAggregation === $nextAggregation) {
                return;
            }

            $parentTasks = $user->tasks()
                ->whereHas('children')
                ->with(['children.children', 'items'])
                ->lockForUpdate()
                ->get();
            $previousProgresses = $parentTasks->mapWithKeys(fn ($task): array => [
                $task->id => $task->computedProgress($previousAggregation),
            ]);

            $settings['progress_aggregation'] = $nextAggregation;
            $user->settings = $settings;
            $user->save();

            foreach ($parentTasks as $task) {
                $nextProgress = $task->computedProgress($nextAggregation);
                $previousProgress = $previousProgresses->get($task->id);

                if ($previousProgress !== $nextProgress) {
                    $task->progressEvents()->create([
                        'user_id' => $user->id,
                        'from_progress' => $previousProgress,
                        'to_progress' => $nextProgress,
                    ]);
                }
            }
        });

        session()->flash('status', 'Metode agregasi progres berhasil disimpan.');
    }

    public function saveAiSettings(): void
    {
        $apiKey = $this->aiApiKey;
        $this->aiApiKey = '';

        $validated = Validator::make([
            'aiModel' => $this->aiModel,
            'aiApiKey' => $apiKey,
        ], [
            'aiModel' => ['required', 'string', 'max:120'],
            'aiApiKey' => ['nullable', 'string', 'max:500'],
        ])->validate();

        $user = auth()->user();
        $user->ai_provider = 'openai';
        $user->ai_model = $validated['aiModel'];

        if (filled($validated['aiApiKey'])) {
            $user->ai_api_key = $validated['aiApiKey'];
        }

        $user->save();

        session()->flash('status', 'Pengaturan AI berhasil disimpan.');
    }

    public function removeAiApiKey(): void
    {
        $user = auth()->user();
        $user->ai_api_key = null;
        $user->save();

        session()->flash('status', 'Kunci AI pribadi dihapus. Konfigurasi server akan digunakan jika tersedia.');
    }

    public function deleteRecord(string $type, int $id): void
    {
        $relation = match ($type) {
            'semester' => auth()->user()->semesters(),
            'category' => auth()->user()->categories(),
            'course' => auth()->user()->courses(),
            'assigner' => auth()->user()->assigners(),
            default => abort(404),
        };

        $relation->findOrFail($id)->delete();
        unset($this->{$type.'s'});
        session()->flash('status', 'Data berhasil dihapus.');
    }
};
?>

<div class="min-h-screen bg-[#f7f4ec] px-4 py-8 text-[#171717] sm:px-8 lg:px-10">
    <div class="mx-auto max-w-7xl space-y-8">
        <header class="flex flex-col justify-between gap-5 border-b-2 border-black pb-6 sm:flex-row sm:items-end">
            <div>
                <p class="text-xs font-black uppercase tracking-[0.24em] text-[#555]">TUGAS!OS / Foundation</p>
                <h1 class="mt-2 text-4xl font-black tracking-tight sm:text-5xl">Ruang kerjamu.</h1>
                <p class="mt-3 max-w-xl text-sm text-[#555]">Siapkan semester, mata kuliah, kategori aktivitas, dan orang yang memberi tugas.</p>
            </div>
            <div class="border-2 border-black bg-[#facc15] px-4 py-3 text-sm font-bold shadow-[4px_4px_0_#171717]">TAHAP 01 — FOUNDATION</div>
        </header>

        @if (session('status'))
            <div role="status" aria-live="polite" class="border-2 border-black bg-[#b8edce] px-4 py-3 font-bold shadow-[4px_4px_0_#171717]">{{ session('status') }}</div>
        @endif

        <section class="border-2 border-black bg-[#b8d8ff] p-5 shadow-[5px_5px_0_#171717] sm:p-6" aria-labelledby="deadline-settings-heading">
            <div class="mb-5"><p class="text-xs font-black uppercase tracking-widest">Pengaturan deadline</p><h2 id="deadline-settings-heading" class="mt-1 text-2xl font-black">Ambang urgensi</h2><p class="mt-2 text-sm">Atur kapan tugas masuk kategori critical, urgent, dan upcoming. Deadline yang sudah lewat selalu masuk overdue.</p></div>
            <form wire:submit="saveDeadlineUrgency" class="grid gap-3 sm:grid-cols-3">
                <label class="grid gap-1 text-sm font-bold">Critical (jam)<input type="number" min="1" max="168" required wire:model="criticalHours" class="border-2 border-black bg-white px-3 py-2 font-normal">@error('criticalHours')<span class="text-xs text-red-800">{{ $message }}</span>@enderror</label>
                <label class="grid gap-1 text-sm font-bold">Urgent (hari)<input type="number" min="1" max="30" required wire:model="urgentDays" class="border-2 border-black bg-white px-3 py-2 font-normal">@error('urgentDays')<span class="text-xs text-red-800">{{ $message }}</span>@enderror</label>
                <label class="grid gap-1 text-sm font-bold">Upcoming (hari)<input type="number" min="2" max="60" required wire:model="upcomingDays" class="border-2 border-black bg-white px-3 py-2 font-normal">@error('upcomingDays')<span class="text-xs text-red-800">{{ $message }}</span>@enderror</label>
                <button class="border-2 border-black bg-white px-4 py-2 text-sm font-black shadow-[3px_3px_0_#171717] sm:col-span-3">Simpan ambang</button>
            </form>
        </section>

        <section class="border-2 border-black bg-[#fff0a6] p-5 shadow-[5px_5px_0_#171717] sm:p-6" aria-labelledby="ai-settings-heading">
            <div class="mb-5"><p class="text-xs font-black uppercase tracking-widest">AI Assistant</p><h2 id="ai-settings-heading" class="mt-1 text-2xl font-black">Provider AI</h2><p class="mt-2 text-sm">Atur model OpenAI dan kunci API akunmu. Kunci disimpan terenkripsi dan tidak pernah ditampilkan kembali.</p></div>
            <form wire:submit="saveAiSettings" class="grid gap-3 sm:grid-cols-2">
                <label class="grid gap-1 text-sm font-bold">Provider<input value="OpenAI" disabled class="border-2 border-black bg-[#eee] px-3 py-2 font-normal"></label>
                <label class="grid gap-1 text-sm font-bold">Model<input required maxlength="120" wire:model="aiModel" placeholder="gpt-4.1-mini" class="border-2 border-black bg-white px-3 py-2 font-normal">@error('aiModel')<span class="text-xs text-red-800">{{ $message }}</span>@enderror</label>
                <label class="grid gap-1 text-sm font-bold sm:col-span-2">Kunci API baru<input type="password" autocomplete="new-password" wire:model="aiApiKey" placeholder="Kosongkan untuk mempertahankan kunci tersimpan" class="border-2 border-black bg-white px-3 py-2 font-normal">@error('aiApiKey')<span class="text-xs text-red-800">{{ $message }}</span>@enderror</label>
                <p class="text-sm sm:col-span-2">{{ auth()->user()->ai_api_key ? 'Kunci pribadi tersimpan.' : (filled(config('services.ai.api_key')) ? 'Menggunakan kunci dari konfigurasi server.' : 'Belum ada kunci API. AI Assistant tidak aktif sampai kunci tersedia.') }}</p>
                <div class="flex flex-wrap gap-3 sm:col-span-2"><button class="border-2 border-black bg-white px-4 py-2 text-sm font-black shadow-[3px_3px_0_#171717]">Simpan provider</button>@if(auth()->user()->ai_api_key)<button type="button" wire:click="removeAiApiKey" wire:confirm="Hapus kunci API OpenAI pribadi?" class="border-2 border-black bg-[#ffd9d1] px-4 py-2 text-sm font-black shadow-[3px_3px_0_#171717]">Hapus kunci pribadi</button>@endif</div>
            </form>
        </section>

        <section class="border-2 border-black bg-[#b8edce] p-5 shadow-[5px_5px_0_#171717] sm:p-6" aria-labelledby="progress-settings-heading">
            <div class="mb-5"><p class="text-xs font-black uppercase tracking-widest">Perhitungan pekerjaan</p><h2 id="progress-settings-heading" class="mt-1 text-2xl font-black">Agregasi progres proyek</h2><p class="mt-2 text-sm">Pilih cara tugas anak dan milestone membentuk progres induk. Perubahan angka progres tercatat di histori.</p></div>
            <form wire:submit="saveProgressAggregation" class="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
                <label class="grid gap-1 text-sm font-bold">Metode<select wire:model="progressAggregation" class="border-2 border-black bg-white px-3 py-2 font-normal"><option value="equal">Rata per tugas atau milestone</option><option value="weighted">Mengikuti bobot tugas atau milestone</option></select>@error('progressAggregation')<span class="text-xs text-red-800">{{ $message }}</span>@enderror</label>
                <button class="border-2 border-black bg-white px-4 py-2 text-sm font-black shadow-[3px_3px_0_#171717]">Simpan metode</button>
            </form>
        </section>

        <section class="border-2 border-black bg-[#ffd9d1] p-5 shadow-[5px_5px_0_#171717] sm:p-6" aria-labelledby="file-settings-heading">
            <div class="mb-5"><p class="text-xs font-black uppercase tracking-widest">Penyimpanan privat</p><h2 id="file-settings-heading" class="mt-1 text-2xl font-black">Batas File Vault</h2><p id="file-size-help" class="mt-2 text-sm">Batas maksimum server saat ini {{ $this->maxAvailableFileSizeLabel }}, mengikuti konfigurasi PHP. Format yang tidak dipilih akan ditolak saat disimpan.</p></div>
            <form wire:submit="saveFileSettings" class="grid gap-4 lg:grid-cols-[220px_1fr]">
                <div class="grid content-start gap-1">
                    <label for="max-file-size-mb" class="text-sm font-bold">Maksimum ukuran (MB)</label>
                    <input id="max-file-size-mb" type="number" min="1" max="{{ $this->maxAvailableFileSizeMb }}" required wire:model="maxFileSizeMb" aria-describedby="file-size-help file-size-error" class="border-2 border-black bg-white px-3 py-2 font-normal">
                    <p id="file-size-error" role="alert" class="text-xs text-red-800">@error('maxFileSizeMb'){{ $message }}@enderror</p>
                </div>
                <fieldset aria-describedby="file-format-help file-format-error" class="grid gap-2">
                    <legend class="mb-1 text-sm font-bold">Format yang diizinkan</legend>
                    <p id="file-format-help" class="sr-only">Pilih satu atau lebih ekstensi berkas yang boleh disimpan.</p>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">@foreach(TaskFilePolicy::ALLOWED_EXTENSIONS as $extension)<label wire:key="file-extension-{{ $extension }}" class="flex items-center gap-2 border border-black bg-white px-2 py-2 text-sm"><input type="checkbox" wire:model="allowedFileExtensions" value="{{ $extension }}" class="accent-black">.{{ $extension }}</label>@endforeach</div>
                    <p id="file-format-error" role="alert" class="text-xs text-red-800">@error('allowedFileExtensions')Pilih setidaknya satu format.@enderror @error('allowedFileExtensions.*')Ada format yang tidak didukung.@enderror</p>
                </fieldset>
                <button class="border-2 border-black bg-white px-4 py-2 text-sm font-black shadow-[3px_3px_0_#171717] lg:col-span-2">Simpan batas file</button>
            </form>
        </section>

        <section class="flex flex-col justify-between gap-4 border-2 border-black bg-white p-5 shadow-[5px_5px_0_#171717] sm:flex-row sm:items-center sm:p-6" aria-labelledby="backup-heading">
            <div><p class="text-xs font-black uppercase tracking-widest text-[#666]">Data & pemulihan</p><h2 id="backup-heading" class="mt-1 text-2xl font-black">Backup dan ekspor</h2><p class="mt-2 max-w-2xl text-sm text-[#555]">Unduh salinan data akun, histori, dan lampiran File Vault dalam ZIP. Token layanan eksternal dan kredensial tidak disertakan.</p></div>
            <a href="{{ route('backup.download') }}" class="shrink-0 border-2 border-black bg-[#facc15] px-4 py-3 text-sm font-black shadow-[3px_3px_0_#171717]">Unduh backup ZIP</a>
        </section>

        <div class="grid gap-6 xl:grid-cols-2">
            <section class="border-2 border-black bg-white p-5 shadow-[5px_5px_0_#171717] sm:p-6">
                <div class="mb-5 flex items-start justify-between gap-3"><div><p class="text-xs font-black uppercase tracking-widest text-[#666]">01 / Kalender akademik</p><h2 class="mt-1 text-2xl font-black">Semester</h2></div><span class="rounded-full border-2 border-black bg-[#b8d8ff] px-3 py-1 text-xs font-bold">{{ $this->semesters->count() }} tersimpan</span></div>
                <form wire:submit="saveSemester" class="grid gap-3 sm:grid-cols-2">
                    <label class="grid gap-1 text-sm font-bold sm:col-span-2">Nama semester<input required maxlength="120" wire:model="semesterName" placeholder="2026/2027 Ganjil" class="border-2 border-black px-3 py-2 text-base font-normal focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-black">@error('semesterName')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                    <label class="grid gap-1 text-sm font-bold">Mulai<input required type="date" wire:model="semesterStartsAt" class="border-2 border-black px-3 py-2 text-base font-normal focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-black">@error('semesterStartsAt')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                    <label class="grid gap-1 text-sm font-bold">Berakhir<input required type="date" wire:model="semesterEndsAt" class="border-2 border-black px-3 py-2 text-base font-normal focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-black">@error('semesterEndsAt')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                    <label class="flex items-center gap-2 text-sm font-semibold sm:col-span-2"><input type="checkbox" wire:model="semesterIsActive" class="size-4 accent-black"> Jadikan semester aktif</label>
                    <button class="border-2 border-black bg-[#facc15] px-4 py-2 text-sm font-black shadow-[3px_3px_0_#171717] hover:translate-x-0.5 hover:translate-y-0.5 hover:shadow-none sm:col-span-2">Simpan semester</button>
                </form>
                <ul class="mt-5 divide-y-2 divide-black border-t-2 border-black">@forelse($this->semesters as $semester)<li wire:key="semester-{{ $semester->id }}" class="flex items-center justify-between gap-3 py-3"><div><p class="font-bold">{{ $semester->name }} @if($semester->is_active)<span class="ml-1 bg-[#b8edce] px-2 py-0.5 text-xs">AKTIF</span>@endif</p><p class="text-xs text-[#666]">{{ $semester->starts_at->format('d M Y') }} — {{ $semester->ends_at->format('d M Y') }}</p></div><button wire:click="deleteRecord('semester', {{ $semester->id }})" wire:confirm="Hapus semester ini?" aria-label="Hapus semester {{ $semester->name }}" class="border-2 border-black px-2 py-1 text-xs font-bold hover:bg-[#ff8c78]">Hapus</button></li>@empty<li class="py-4 text-sm text-[#666]">Belum ada semester.</li>@endforelse</ul>
            </section>

            <section class="border-2 border-black bg-[#fff1b8] p-5 shadow-[5px_5px_0_#171717] sm:p-6">
                <div class="mb-5 flex items-start justify-between gap-3"><div><p class="text-xs font-black uppercase tracking-widest text-[#666]">02 / Taksonomi</p><h2 class="mt-1 text-2xl font-black">Kategori aktivitas</h2></div><span class="rounded-full border-2 border-black bg-white px-3 py-1 text-xs font-bold">{{ $this->categories->count() }} tersimpan</span></div>
                <form wire:submit="saveCategory" class="grid gap-3 sm:grid-cols-[1fr_auto_auto]">
                    <label class="grid gap-1 text-sm font-bold">Nama<input required maxlength="80" wire:model="categoryName" placeholder="Kuliah, organisasi..." class="border-2 border-black bg-white px-3 py-2 text-base font-normal focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-black">@error('categoryName')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                    <label class="grid gap-1 text-sm font-bold">Jenis<select wire:model="categoryKind" class="border-2 border-black bg-white px-3 py-2 font-normal"><option value="academic">Akademik</option><option value="personal">Pribadi</option><option value="organization">Organisasi</option><option value="habit">Kebiasaan</option></select></label>
                    <label class="grid gap-1 text-sm font-bold">Warna<input type="color" wire:model="categoryColor" class="h-10 w-14 border-2 border-black bg-white p-1"></label>
                    <button class="border-2 border-black bg-white px-4 py-2 text-sm font-black shadow-[3px_3px_0_#171717] sm:col-span-3">Tambah kategori</button>
                </form>
                <div class="mt-5 flex flex-wrap gap-2">@forelse($this->categories as $category)<div wire:key="category-{{ $category->id }}" class="flex items-center gap-2 border-2 border-black bg-white px-3 py-2 text-sm font-bold"><span class="size-3 border border-black" style="background-color: {{ $category->color }}"></span>{{ $category->name }}<button wire:click="deleteRecord('category', {{ $category->id }})" wire:confirm="Hapus kategori ini?" aria-label="Hapus kategori {{ $category->name }}" class="ml-1 font-black text-[#777] hover:text-red-700">×</button></div>@empty<p class="text-sm text-[#666]">Tambahkan kategori pertamamu.</p>@endforelse</div>
            </section>

            <section class="border-2 border-black bg-[#dceaff] p-5 shadow-[5px_5px_0_#171717] sm:p-6">
                <div class="mb-5 flex items-start justify-between gap-3"><div><p class="text-xs font-black uppercase tracking-widest text-[#666]">03 / Akademik</p><h2 class="mt-1 text-2xl font-black">Mata kuliah</h2></div><span class="rounded-full border-2 border-black bg-white px-3 py-1 text-xs font-bold">{{ $this->courses->count() }} tersimpan</span></div>
                <form wire:submit="saveCourse" class="grid gap-3 sm:grid-cols-2">
                    <label class="grid gap-1 text-sm font-bold">Nama mata kuliah<input required maxlength="120" wire:model="courseName" placeholder="Rekayasa Perangkat Lunak" class="border-2 border-black bg-white px-3 py-2 text-base font-normal focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-black">@error('courseName')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                    <label class="grid gap-1 text-sm font-bold">Kode<input wire:model="courseCode" placeholder="IF-302" class="border-2 border-black bg-white px-3 py-2 font-normal"></label>
                    <label class="grid gap-1 text-sm font-bold">Dosen pengampu<input wire:model="courseInstructor" placeholder="Nama dosen" class="border-2 border-black bg-white px-3 py-2 font-normal"></label>
                    <label class="grid gap-1 text-sm font-bold">Semester<select wire:model="courseSemesterId" class="border-2 border-black bg-white px-3 py-2 font-normal"><option value="">Belum ditentukan</option>@foreach($this->semesters as $semester)<option value="{{ $semester->id }}">{{ $semester->name }}</option>@endforeach</select></label>
                    <button class="border-2 border-black bg-white px-4 py-2 text-sm font-black shadow-[3px_3px_0_#171717] sm:col-span-2">Tambah mata kuliah</button>
                </form>
                <ul class="mt-5 divide-y-2 divide-black border-t-2 border-black">@forelse($this->courses as $course)<li wire:key="course-{{ $course->id }}" class="flex items-center justify-between gap-3 py-3"><div><p class="font-bold">{{ $course->name }} @if($course->code)<span class="text-xs font-normal text-[#666]">{{ $course->code }}</span>@endif</p><p class="text-xs text-[#666]">{{ $course->semester?->name ?? 'Semester belum ditentukan' }} @if($course->instructor) · {{ $course->instructor }}@endif</p></div><button wire:click="deleteRecord('course', {{ $course->id }})" wire:confirm="Hapus mata kuliah ini?" aria-label="Hapus mata kuliah {{ $course->name }}" class="border-2 border-black px-2 py-1 text-xs font-bold hover:bg-[#ff8c78]">Hapus</button></li>@empty<li class="py-4 text-sm text-[#666]">Belum ada mata kuliah.</li>@endforelse</ul>
            </section>

            <section class="border-2 border-black bg-[#ffd9d1] p-5 shadow-[5px_5px_0_#171717] sm:p-6">
                <div class="mb-5 flex items-start justify-between gap-3"><div><p class="text-xs font-black uppercase tracking-widest text-[#666]">04 / Kontak</p><h2 class="mt-1 text-2xl font-black">Pemberi tugas</h2></div><span class="rounded-full border-2 border-black bg-white px-3 py-1 text-xs font-bold">{{ $this->assigners->count() }} tersimpan</span></div>
                <form wire:submit="saveAssigner" class="grid gap-3 sm:grid-cols-2">
                    <label class="grid gap-1 text-sm font-bold">Nama<input required maxlength="120" autocomplete="name" wire:model="assignerName" placeholder="Nama lengkap" class="border-2 border-black bg-white px-3 py-2 text-base font-normal focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-black">@error('assignerName')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                    <label class="grid gap-1 text-sm font-bold">Jabatan<input wire:model="assignerTitle" placeholder="Dosen, mentor..." class="border-2 border-black bg-white px-3 py-2 font-normal"></label>
                    <label class="grid gap-1 text-sm font-bold">Instansi<input maxlength="160" wire:model="assignerInstitution" placeholder="Universitas / organisasi" class="border-2 border-black bg-white px-3 py-2 text-base font-normal focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-black"></label>
                    <label class="grid gap-1 text-sm font-bold">Email<input type="email" autocomplete="email" wire:model="assignerEmail" placeholder="nama@kampus.ac.id" class="border-2 border-black bg-white px-3 py-2 text-base font-normal focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-black">@error('assignerEmail')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                    <label class="grid gap-1 text-sm font-bold sm:col-span-2">Kontak telepon<input wire:model="assignerPhone" placeholder="Opsional" class="border-2 border-black bg-white px-3 py-2 font-normal"></label>
                    <fieldset class="grid gap-2 sm:col-span-2"><legend class="mb-2 text-sm font-bold">Peran — pilih satu atau lebih</legend><div class="flex flex-wrap gap-x-4 gap-y-2">@foreach(['Dosen', 'Mentor', 'Kaprodi', 'Asisten dosen', 'Pembimbing', 'Ketua organisasi', 'Diri sendiri'] as $role)<label wire:key="role-{{ $role }}" class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="assignerRoles" value="{{ $role }}" class="accent-black">{{ $role }}</label>@endforeach</div></fieldset>
                    <label class="grid gap-1 text-sm font-bold sm:col-span-2">Peran khusus<input wire:model="customAssignerRole" placeholder="Peran lain (opsional)" class="border-2 border-black bg-white px-3 py-2 font-normal"></label>
                    <button class="border-2 border-black bg-white px-4 py-2 text-sm font-black shadow-[3px_3px_0_#171717] sm:col-span-2">Tambah pemberi tugas</button>
                </form>
                <ul class="mt-5 divide-y-2 divide-black border-t-2 border-black">@forelse($this->assigners as $assigner)<li wire:key="assigner-{{ $assigner->id }}" class="flex items-center justify-between gap-3 py-3"><div><p class="font-bold">{{ $assigner->name }}</p><p class="text-xs text-[#666]">{{ $assigner->title ?? 'Peran belum diisi' }} · {{ $assigner->roles->pluck('name')->join(', ') ?: 'Tanpa label peran' }}</p></div><button wire:click="deleteRecord('assigner', {{ $assigner->id }})" wire:confirm="Hapus pemberi tugas ini?" aria-label="Hapus pemberi tugas {{ $assigner->name }}" class="border-2 border-black px-2 py-1 text-xs font-bold hover:bg-[#ff8c78]">Hapus</button></li>@empty<li class="py-4 text-sm text-[#666]">Belum ada pemberi tugas.</li>@endforelse</ul>
            </section>
        </div>
        <footer class="border-t-2 border-black pt-4 text-xs font-semibold text-[#666]">DATA PRIBADI · HANYA DAPAT DIAKSES OLEH AKUNMU</footer>
    </div>
</div>

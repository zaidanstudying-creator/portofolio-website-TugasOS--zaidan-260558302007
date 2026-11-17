<?php

use App\Models\Task;
use App\Services\GoogleCalendarSyncService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $title = '';

    public string $description = '';

    public string $type = 'flexible';

    public string $priority = 'medium';

    public string $status = 'todo';

    public string $startsAt = '';

    public string $dueAt = '';

    public string $remindAt = '';

    public string $reminderMessage = '';

    public string $estimatedMinutes = '';

    public string $weight = '1';

    public string $categoryId = '';

    public string $courseId = '';

    public string $semesterId = '';

    public string $parentTaskId = '';

    public array $assignerIds = [];

    public string $statusFilter = 'all';

    public bool $showArchived = false;

    public string $viewMode = 'deadline';

    public string $search = '';

    public ?int $editingTaskId = null;

    public string $newItemTitle = '';

    #[Computed]
    public function tasks(): Collection
    {
        $tasks = auth()->user()->tasks()
            ->whereNull('parent_task_id')
            ->whereNull('archived_at')
            ->with(['items', 'category', 'course', 'semester', 'assigners'])
            ->when($this->statusFilter !== 'all', fn ($query) => $query->where('status', $this->statusFilter))
            ->orderByRaw('due_at IS NULL')
            ->orderBy('due_at')
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->latest('created_at')
            ->get();

        $this->loadTaskTree($tasks);

        return filled($this->search)
            ? $tasks->filter(fn (Task $task): bool => $this->taskTreeMatchesSearch($task))->values()
            : $tasks;
    }

    #[Computed]
    public function archivedTasks(): Collection
    {
        $tasks = auth()->user()->tasks()
            ->whereNull('parent_task_id')
            ->whereNotNull('archived_at')
            ->with(['items', 'category', 'course', 'semester', 'assigners'])
            ->orderByDesc('archived_at')
            ->get();

        $this->loadTaskTree($tasks);

        return filled($this->search)
            ? $tasks->filter(fn (Task $task): bool => $this->taskTreeMatchesSearch($task))->values()
            : $tasks;
    }

    #[Computed]
    public function categories(): Collection
    {
        return auth()->user()->categories()->orderBy('name')->get();
    }

    #[Computed]
    public function courses(): Collection
    {
        return auth()->user()->courses()->orderBy('name')->get();
    }

    #[Computed]
    public function semesters(): Collection
    {
        return auth()->user()->semesters()->latest('starts_at')->get();
    }

    #[Computed]
    public function assigners(): Collection
    {
        return auth()->user()->assigners()->with('roles')->orderBy('name')->get();
    }

    #[Computed]
    public function parentTasks(): Collection
    {
        $tasks = auth()->user()->tasks()->whereNull('archived_at')->orderBy('title')->get();

        if ($this->editingTaskId === null) {
            return $tasks;
        }

        $childrenByParent = $tasks->groupBy('parent_task_id');
        $excludedIds = [$this->editingTaskId];
        $pendingIds = [$this->editingTaskId];

        while ($pendingIds !== []) {
            $parentId = array_pop($pendingIds);

            foreach ($childrenByParent->get($parentId, collect()) as $child) {
                if (! in_array($child->id, $excludedIds, true)) {
                    $excludedIds[] = $child->id;
                    $pendingIds[] = $child->id;
                }
            }
        }

        return $tasks->reject(fn (Task $task): bool => in_array($task->id, $excludedIds, true))->values();
    }

    #[Computed]
    public function counts(): array
    {
        $tasks = auth()->user()->tasks()->whereNull('parent_task_id');

        return [
            'active' => (clone $tasks)->whereNull('archived_at')->whereIn('status', ['todo', 'in_progress'])->count(),
            'done' => (clone $tasks)->whereNull('archived_at')->where('status', 'done')->count(),
            'overdue' => (clone $tasks)->whereNull('archived_at')->whereNotNull('due_at')->where('due_at', '<', now())->where('status', '!=', 'done')->count(),
        ];
    }

    public function saveTask(): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'type' => ['required', Rule::in(['daily', 'weekly', 'semester', 'flexible'])],
            'priority' => ['required', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'status' => ['required', Rule::in(['todo', 'in_progress', 'done'])],
            'startsAt' => ['nullable', 'date'],
            'dueAt' => ['nullable', 'date', 'after_or_equal:startsAt'],
            'remindAt' => ['nullable', 'date'],
            'reminderMessage' => ['nullable', 'string', 'max:255'],
            'estimatedMinutes' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'weight' => ['required', 'integer', 'min:1', 'max:65535'],
            'categoryId' => ['nullable', Rule::exists('categories', 'id')->where('user_id', auth()->id())],
            'courseId' => ['nullable', Rule::exists('courses', 'id')->where('user_id', auth()->id())],
            'semesterId' => ['nullable', Rule::exists('semesters', 'id')->where('user_id', auth()->id())],
            'parentTaskId' => ['nullable', Rule::exists('tasks', 'id')->where('user_id', auth()->id())->whereNull('archived_at')],
            'assignerIds' => ['array'],
            'assignerIds.*' => [Rule::exists('assigners', 'id')->where('user_id', auth()->id())],
        ]);

        $isEditing = $this->editingTaskId !== null;
        $saved = DB::transaction(function () use ($validated, $isEditing): bool {
            $task = $isEditing
                ? auth()->user()->tasks()->whereNull('archived_at')->lockForUpdate()->whereKey($this->editingTaskId)->firstOrFail()
                : new Task;

            if ($validated['parentTaskId'] && $this->wouldCreateParentCycle($task, (int) $validated['parentTaskId'])) {
                $this->addError('parentTaskId', 'Tugas tidak dapat dipindahkan ke dirinya sendiri atau ke dalam subtask-nya.');

                return false;
            }

            $previousStatus = $task->exists ? $task->status : null;
            $previousProgresses = $this->progressSnapshot($task, filled($validated['parentTaskId']) ? (int) $validated['parentTaskId'] : null);

            $task->fill([
                'title' => $validated['title'],
                'description' => $validated['description'],
                'type' => $validated['type'],
                'priority' => $validated['priority'],
                'status' => $validated['status'],
                'starts_at' => filled($validated['startsAt']) ? $validated['startsAt'] : null,
                'due_at' => filled($validated['dueAt']) ? $validated['dueAt'] : null,
                'completed_at' => $validated['status'] === 'done' ? ($task->completed_at ?? now()) : null,
                'estimated_minutes' => filled($validated['estimatedMinutes']) ? $validated['estimatedMinutes'] : null,
                'weight' => $validated['weight'],
                'category_id' => filled($validated['categoryId']) ? $validated['categoryId'] : null,
                'course_id' => filled($validated['courseId']) ? $validated['courseId'] : null,
                'semester_id' => filled($validated['semesterId']) ? $validated['semesterId'] : null,
                'parent_task_id' => filled($validated['parentTaskId']) ? $validated['parentTaskId'] : null,
            ]);

            if (! $task->exists) {
                auth()->user()->tasks()->save($task);
            } else {
                $task->save();
            }

            $task->assigners()->sync($validated['assignerIds']);

            if (filled($validated['remindAt'])) {
                $task->reminder()->updateOrCreate([], [
                    'user_id' => auth()->id(),
                    'remind_at' => $validated['remindAt'],
                    'message' => filled($validated['reminderMessage']) ? $validated['reminderMessage'] : null,
                    'dismissed_at' => null,
                ]);
            } else {
                $task->reminder()->delete();
            }

            if ($previousStatus !== $task->status) {
                $task->statusEvents()->create([
                    'user_id' => auth()->id(),
                    'from_status' => $previousStatus,
                    'to_status' => $task->status,
                ]);
            }

            $this->recordProgressChanges($previousProgresses);

            return true;
        });

        if (! $saved) {
            return;
        }

        $this->resetForm();
        unset($this->tasks, $this->counts, $this->parentTasks);
        session()->flash('status', $isEditing ? 'Perubahan tugas disimpan.' : 'Tugas berhasil dibuat.');
    }

    public function editTask(int $taskId): void
    {
        $task = auth()->user()->tasks()->whereNull('archived_at')->with('assigners')->findOrFail($taskId);
        $this->editingTaskId = $task->id;
        $this->title = $task->title;
        $this->description = $task->description ?? '';
        $this->type = $task->type;
        $this->priority = $task->priority;
        $this->status = $task->status;
        $this->startsAt = $task->starts_at?->format('Y-m-d\TH:i') ?? '';
        $this->dueAt = $task->due_at?->format('Y-m-d\TH:i') ?? '';
        $this->remindAt = $task->reminder?->remind_at?->format('Y-m-d\TH:i') ?? '';
        $this->reminderMessage = $task->reminder?->message ?? '';
        $this->estimatedMinutes = (string) ($task->estimated_minutes ?? '');
        $this->weight = (string) $task->weight;
        $this->categoryId = (string) ($task->category_id ?? '');
        $this->courseId = (string) ($task->course_id ?? '');
        $this->semesterId = (string) ($task->semester_id ?? '');
        $this->parentTaskId = (string) ($task->parent_task_id ?? '');
        $this->assignerIds = $task->assigners->modelKeys();
    }

    public function cancelEdit(): void
    {
        $this->resetForm();
        $this->resetValidation();
    }

    public function deleteTask(int $taskId): void
    {
        $fileLocations = DB::transaction(function () use ($taskId): array {
            $task = auth()->user()->tasks()->whereNull('archived_at')->with('parent')->lockForUpdate()->whereKey($taskId)->firstOrFail();
            $previousProgresses = $task->parent ? $this->progressSnapshot($task->parent) : [];
            $taskIds = $this->descendantTaskIds($task->id);
            $fileLocations = auth()->user()->taskFiles()
                ->whereIn('task_id', $taskIds)
                ->get(['disk', 'path'])
                ->map(fn ($file): array => ['disk' => $file->disk, 'path' => $file->path])
                ->all();

            $task->delete();
            $this->recordProgressChanges($previousProgresses);

            return $fileLocations;
        });

        foreach ($fileLocations as $fileLocation) {
            Storage::disk($fileLocation['disk'])->delete($fileLocation['path']);
        }

        unset($this->tasks, $this->counts, $this->parentTasks, $this->archivedTasks);
        session()->flash('status', 'Tugas dan subtask-nya dihapus.');
    }

    public function updateStatus(int $taskId, string $newStatus): void
    {
        abort_unless(in_array($newStatus, ['todo', 'in_progress', 'done'], true), 422);

        DB::transaction(function () use ($taskId, $newStatus): void {
            $task = auth()->user()->tasks()->whereNull('archived_at')->lockForUpdate()->whereKey($taskId)->firstOrFail();
            $previousStatus = $task->status;

            if ($previousStatus === $newStatus) {
                return;
            }

            $previousProgresses = $this->progressSnapshot($task);
            $task->update([
                'status' => $newStatus,
                'completed_at' => $newStatus === 'done' ? now() : null,
            ]);
            $task->statusEvents()->create([
                'user_id' => auth()->id(),
                'from_status' => $previousStatus,
                'to_status' => $newStatus,
            ]);

            $this->recordProgressChanges($previousProgresses);
        });

        unset($this->tasks, $this->counts);
    }

    public function addItem(int $taskId): void
    {
        $validated = $this->validate([
            'newItemTitle' => ['required', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($taskId, $validated): void {
            $task = auth()->user()->tasks()->whereNull('archived_at')->lockForUpdate()->whereKey($taskId)->firstOrFail();
            $previousProgresses = $this->progressSnapshot($task);
            $task->items()->create([
                'title' => $validated['newItemTitle'],
                'position' => $task->items()->count(),
            ]);

            $task->unsetRelation('items');
            $this->recordProgressChanges($previousProgresses);
        });
        $this->newItemTitle = '';
        unset($this->tasks);
    }

    public function toggleItem(int $taskId, int $itemId): void
    {
        DB::transaction(function () use ($taskId, $itemId): void {
            $task = auth()->user()->tasks()->whereNull('archived_at')->lockForUpdate()->whereKey($taskId)->firstOrFail();
            $item = $task->items()->lockForUpdate()->whereKey($itemId)->firstOrFail();
            $previousProgresses = $this->progressSnapshot($task);

            $item->update([
                'is_completed' => ! $item->is_completed,
                'completed_at' => $item->is_completed ? null : now(),
            ]);

            $task->unsetRelation('items');
            $this->recordProgressChanges($previousProgresses);
        });
        unset($this->tasks, $this->counts);
    }

    public function deleteItem(int $taskId, int $itemId): void
    {
        DB::transaction(function () use ($taskId, $itemId): void {
            $task = auth()->user()->tasks()->whereNull('archived_at')->lockForUpdate()->whereKey($taskId)->firstOrFail();
            $item = $task->items()->lockForUpdate()->whereKey($itemId)->firstOrFail();
            $previousProgresses = $this->progressSnapshot($task);

            $item->delete();
            $task->unsetRelation('items');
            $this->recordProgressChanges($previousProgresses);
        });

        unset($this->tasks, $this->counts);
        session()->flash('status', 'Checklist dihapus dan progres diperbarui.');
    }

    /**
     * @return array<int, int>
     */
    private function progressSnapshot(Task $task, ?int $additionalParentId = null): array
    {
        $pendingIds = array_values(array_filter([
            $task->id === null ? null : (int) $task->id,
            $task->parent_task_id === null ? null : (int) $task->parent_task_id,
            $additionalParentId,
        ], fn (?int $id): bool => $id !== null));
        $taskIds = [];

        while ($pendingIds !== []) {
            $taskId = (int) array_pop($pendingIds);

            if (in_array($taskId, $taskIds, true)) {
                continue;
            }

            $taskIds[] = $taskId;
            $parentTaskId = auth()->user()->tasks()->whereKey($taskId)->value('parent_task_id');

            if ($parentTaskId !== null) {
                $pendingIds[] = (int) $parentTaskId;
            }
        }

        return collect($taskIds)->mapWithKeys(function (int $taskId): array {
            $trackedTask = auth()->user()->tasks()->findOrFail($taskId);

            return [$taskId => $trackedTask->computedProgress()];
        })->all();
    }

    /**
     * @param  array<int, int>  $previousProgresses
     */
    private function recordProgressChanges(array $previousProgresses): void
    {
        foreach ($previousProgresses as $taskId => $previousProgress) {
            $task = auth()->user()->tasks()->findOrFail($taskId);
            $progress = $task->computedProgress();

            if ($progress !== $previousProgress) {
                $task->progressEvents()->create([
                    'user_id' => auth()->id(),
                    'from_progress' => $previousProgress,
                    'to_progress' => $progress,
                ]);
            }
        }
    }

    private function resetForm(): void
    {
        $this->reset(['title', 'description', 'type', 'priority', 'status', 'startsAt', 'dueAt', 'remindAt', 'reminderMessage', 'estimatedMinutes', 'weight', 'categoryId', 'courseId', 'semesterId', 'parentTaskId', 'assignerIds', 'editingTaskId']);
        $this->type = 'flexible';
        $this->priority = 'medium';
        $this->status = 'todo';
    }

    private function loadTaskTree(Collection $tasks): void
    {
        $frontier = $tasks;
        $visitedIds = [];

        while ($frontier->isNotEmpty()) {
            $frontier = $frontier
                ->reject(fn (Task $task): bool => in_array((int) $task->id, $visitedIds, true))
                ->values();

            if ($frontier->isEmpty()) {
                break;
            }

            foreach ($frontier as $task) {
                $task->loadMissing(['items', 'category', 'course', 'semester', 'assigners', 'children']);
            }

            $visitedIds = array_merge($visitedIds, $frontier->pluck('id')->map(fn ($taskId): int => (int) $taskId)->all());
            $frontier = $frontier->flatMap(fn (Task $task) => $task->children)->values();
        }
    }

    private function taskTreeMatchesSearch(Task $task): bool
    {
        $search = mb_strtolower($this->search);

        if (str_contains(mb_strtolower($task->title), $search)) {
            return true;
        }

        return $task->children->contains(fn (Task $child): bool => $this->taskTreeMatchesSearch($child));
    }

    public function archiveTask(int $taskId): void
    {
        DB::transaction(function () use ($taskId): void {
            $task = auth()->user()->tasks()
                ->whereNull('parent_task_id')
                ->whereNull('archived_at')
                ->lockForUpdate()
                ->whereKey($taskId)
                ->firstOrFail();

            auth()->user()->recurrenceRules()->where('template_task_id', $task->id)->update(['is_active' => false]);
            auth()->user()->tasks()->whereIn('id', $this->descendantTaskIds($task->id))->update(['archived_at' => now()]);
        });

        app(GoogleCalendarSyncService::class)->queueSyncIfConnected(auth()->user());

        unset($this->tasks, $this->counts, $this->archivedTasks, $this->parentTasks);
        session()->flash('status', 'Tugas dan seluruh subtask diarsipkan. Riwayat, file, dan relasinya tetap tersimpan.');
    }

    public function restoreTask(int $taskId): void
    {
        DB::transaction(function () use ($taskId): void {
            $task = auth()->user()->tasks()
                ->whereNull('parent_task_id')
                ->whereNotNull('archived_at')
                ->lockForUpdate()
                ->whereKey($taskId)
                ->firstOrFail();

            auth()->user()->tasks()->whereIn('id', $this->descendantTaskIds($task->id))->update(['archived_at' => null]);
        });

        app(GoogleCalendarSyncService::class)->queueSyncIfConnected(auth()->user());

        unset($this->tasks, $this->counts, $this->archivedTasks, $this->parentTasks);
        session()->flash('status', 'Tugas dan seluruh subtask dipulihkan ke daftar aktif.');
    }

    /**
     * @return list<int>
     */
    private function descendantTaskIds(int $rootTaskId): array
    {
        $taskIds = [$rootTaskId];
        $frontierIds = [$rootTaskId];

        while ($frontierIds !== []) {
            $childIds = auth()->user()->tasks()
                ->whereIn('parent_task_id', $frontierIds)
                ->pluck('id')
                ->map(fn ($taskId): int => (int) $taskId)
                ->all();
            $newIds = array_values(array_diff($childIds, $taskIds));

            if ($newIds === []) {
                break;
            }

            $taskIds = array_merge($taskIds, $newIds);
            $frontierIds = $newIds;
        }

        return $taskIds;
    }

    private function wouldCreateParentCycle(Task $task, int $parentTaskId): bool
    {
        $visitedIds = [];
        $currentTaskId = $parentTaskId;

        while ($currentTaskId !== null) {
            if ($currentTaskId === $task->id || in_array($currentTaskId, $visitedIds, true)) {
                return true;
            }

            $visitedIds[] = $currentTaskId;
            $currentTaskId = auth()->user()->tasks()->whereKey($currentTaskId)->value('parent_task_id');

            if ($currentTaskId !== null) {
                $currentTaskId = (int) $currentTaskId;
            }
        }

        return false;
    }
};
?>

<div class="min-h-screen bg-[#f7f4ec] px-4 py-8 text-[#171717] sm:px-8 lg:px-10">
    <div class="mx-auto max-w-7xl space-y-8">
        <header class="flex flex-col justify-between gap-5 border-b-2 border-black pb-6 sm:flex-row sm:items-end">
            <div>
                <p class="text-xs font-black uppercase tracking-[0.24em] text-[#555]">TUGAS!OS / Task Engine</p>
                <h1 class="mt-2 text-4xl font-black tracking-tight sm:text-5xl">Semua tugas, jelas.</h1>
                <p class="mt-3 max-w-xl text-sm text-[#555]">Atur deadline, checklist, prioritas, kelas, dan orang yang memberi tugas dari satu tempat.</p>
            </div>
            <div class="border-2 border-black bg-[#facc15] px-4 py-3 text-sm font-bold shadow-[4px_4px_0_#171717]">TAHAP 02 — TASK ENGINE</div>
        </header>

        @if (session('status'))
            <div role="status" aria-live="polite" class="border-2 border-black bg-[#b8edce] px-4 py-3 font-bold shadow-[4px_4px_0_#171717]">{{ session('status') }}</div>
        @endif

        <section aria-label="Ringkasan tugas" class="grid gap-4 sm:grid-cols-3">
            <article class="border-2 border-black bg-white p-4 shadow-[4px_4px_0_#171717]"><p class="text-xs font-black uppercase tracking-widest text-[#555]">Aktif</p><p class="mt-2 text-4xl font-black">{{ $this->counts['active'] }}</p></article>
            <article class="border-2 border-black bg-[#b8edce] p-4 shadow-[4px_4px_0_#171717]"><p class="text-xs font-black uppercase tracking-widest text-[#555]">Selesai</p><p class="mt-2 text-4xl font-black">{{ $this->counts['done'] }}</p></article>
            <article class="border-2 border-black bg-[#ff8c78] p-4 shadow-[4px_4px_0_#171717]"><p class="text-xs font-black uppercase tracking-widest text-[#555]">Terlambat</p><p class="mt-2 text-4xl font-black">{{ $this->counts['overdue'] }}</p></article>
        </section>

        <section class="border-2 border-black bg-white p-5 shadow-[5px_5px_0_#171717] sm:p-6">
            <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
                <div><p class="text-xs font-black uppercase tracking-widest text-[#666]">{{ $editingTaskId ? 'Perbarui detail' : 'Rencanakan pekerjaan' }}</p><h2 class="mt-1 text-2xl font-black">{{ $editingTaskId ? 'Edit tugas' : 'Tugas baru' }}</h2></div>
                @if ($editingTaskId)<button type="button" wire:click="cancelEdit" class="border-2 border-black px-3 py-2 text-sm font-bold">Batal edit</button>@endif
            </div>
            <form wire:submit="saveTask" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <label class="grid gap-1 text-sm font-bold sm:col-span-2 lg:col-span-3">Judul tugas<input required maxlength="255" wire:model="title" placeholder="Contoh: Laporan praktikum basis data" class="border-2 border-black px-3 py-2 text-base font-normal focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-black">@error('title')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                <label class="grid gap-1 text-sm font-bold sm:col-span-2 lg:col-span-3">Deskripsi<textarea rows="2" wire:model="description" placeholder="Instruksi atau catatan singkat" class="border-2 border-black px-3 py-2 text-base font-normal focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-black"></textarea>@error('description')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                <label class="grid gap-1 text-sm font-bold">Jenis<select wire:model="type" class="border-2 border-black px-3 py-2 font-normal"><option value="daily">Harian</option><option value="weekly">Mingguan</option><option value="semester">Semesteran</option><option value="flexible">Fleksibel</option></select></label>
                <label class="grid gap-1 text-sm font-bold">Prioritas<select wire:model="priority" class="border-2 border-black px-3 py-2 font-normal"><option value="low">Rendah</option><option value="medium">Normal</option><option value="high">Tinggi</option><option value="urgent">Mendesak</option></select></label>
                <label class="grid gap-1 text-sm font-bold">Status<select wire:model="status" class="border-2 border-black px-3 py-2 font-normal"><option value="todo">Belum dimulai</option><option value="in_progress">Sedang dikerjakan</option><option value="done">Selesai</option></select></label>
                <label class="grid gap-1 text-sm font-bold">Mulai dikerjakan<input type="datetime-local" wire:model="startsAt" class="border-2 border-black px-3 py-2 font-normal">@error('startsAt')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                <label class="grid gap-1 text-sm font-bold">Deadline resmi<input type="datetime-local" wire:model="dueAt" class="border-2 border-black px-3 py-2 font-normal">@error('dueAt')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                <label class="grid gap-1 text-sm font-bold">Pengingat<input type="datetime-local" wire:model="remindAt" class="border-2 border-black px-3 py-2 font-normal">@error('remindAt')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                <label class="grid gap-1 text-sm font-bold">Catatan pengingat<input maxlength="255" wire:model="reminderMessage" placeholder="Contoh: Baca ulang instruksi" class="border-2 border-black px-3 py-2 font-normal">@error('reminderMessage')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                <label class="grid gap-1 text-sm font-bold">Estimasi (menit)<input type="number" min="1" wire:model="estimatedMinutes" placeholder="Opsional" class="border-2 border-black px-3 py-2 font-normal">@error('estimatedMinutes')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                <label class="grid gap-1 text-sm font-bold">Bobot progres<input type="number" min="1" max="65535" required wire:model="weight" class="border-2 border-black px-3 py-2 font-normal">@error('weight')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                <label class="grid gap-1 text-sm font-bold">Kategori<select wire:model="categoryId" class="border-2 border-black px-3 py-2 font-normal"><option value="">Tanpa kategori</option>@foreach($this->categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></label>
                <label class="grid gap-1 text-sm font-bold">Mata kuliah<select wire:model="courseId" class="border-2 border-black px-3 py-2 font-normal"><option value="">Personal / tanpa kelas</option>@foreach($this->courses as $course)<option value="{{ $course->id }}">{{ $course->name }}</option>@endforeach</select></label>
                <label class="grid gap-1 text-sm font-bold">Semester<select wire:model="semesterId" class="border-2 border-black px-3 py-2 font-normal"><option value="">Tanpa semester</option>@foreach($this->semesters as $semester)<option value="{{ $semester->id }}">{{ $semester->name }}</option>@endforeach</select>@error('semesterId')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                <label class="grid gap-1 text-sm font-bold">Proyek induk<select wire:model="parentTaskId" class="border-2 border-black px-3 py-2 font-normal"><option value="">Tugas utama</option>@foreach($this->parentTasks as $parent)<option value="{{ $parent->id }}">{{ $parent->title }}</option>@endforeach</select>@error('parentTaskId')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
                <fieldset class="grid gap-2 sm:col-span-2 lg:col-span-3"><legend class="text-sm font-bold">Pemberi tugas</legend><div class="flex flex-wrap gap-2">@forelse($this->assigners as $assigner)<label wire:key="assigner-option-{{ $assigner->id }}" class="flex items-center gap-2 border-2 border-black px-3 py-2 text-sm"><input type="checkbox" value="{{ $assigner->id }}" wire:model="assignerIds" class="accent-black">{{ $assigner->name }}</label>@empty<a href="{{ route('workspace') }}" class="text-sm underline">Tambahkan pemberi tugas di Ruang kerja</a>@endforelse</div></fieldset>
                <button class="border-2 border-black bg-[#facc15] px-4 py-2 text-sm font-black shadow-[3px_3px_0_#171717] hover:translate-x-0.5 hover:translate-y-0.5 hover:shadow-none sm:col-span-2 lg:col-span-3">{{ $editingTaskId ? 'Simpan perubahan' : 'Buat tugas' }}</button>
            </form>
        </section>

        <section class="space-y-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div><p class="text-xs font-black uppercase tracking-widest text-[#666]">Papan kerja</p><h2 class="text-2xl font-black">Daftar tugas</h2></div>
            <div class="grid gap-2 sm:grid-cols-3">
                    <label class="sr-only" for="task-search">Cari tugas</label><input id="task-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Cari tugas..." class="border-2 border-black bg-white px-3 py-2">
                    <label class="sr-only" for="task-status">Filter status</label><select id="task-status" wire:model.live="statusFilter" class="border-2 border-black bg-white px-3 py-2"><option value="all">Semua status</option><option value="todo">Belum dimulai</option><option value="in_progress">Sedang dikerjakan</option><option value="done">Selesai</option></select>
                    <button type="button" wire:click="$toggle('showArchived')" aria-pressed="{{ $showArchived ? 'true' : 'false' }}" class="border-2 border-black px-3 py-2 text-sm font-black {{ $showArchived ? 'bg-[#b8d8ff]' : 'bg-white' }}">{{ $showArchived ? '← Tugas aktif' : 'Lihat arsip' }}</button>
                </div>
            </div>

            @if($showArchived)
                <div class="grid gap-4 lg:grid-cols-2">
                    @forelse($this->archivedTasks as $task)
                        <article wire:key="archived-task-{{ $task->id }}" class="border-2 border-black bg-[#eeeae0] p-5 shadow-[4px_4px_0_#171717]"><div class="flex items-start justify-between gap-3"><div><p class="text-xs font-black uppercase tracking-wide">Diarsipkan {{ $task->archived_at?->format('d M Y, H:i') }}</p><h3 class="mt-1 text-xl font-black">{{ $task->title }}</h3><p class="mt-1 text-sm text-[#555]">{{ $task->children->count() }} subtask · progres {{ $task->computedProgress() }}%</p></div><button type="button" wire:click="restoreTask({{ $task->id }})" class="shrink-0 border-2 border-black bg-[#b8edce] px-3 py-2 text-sm font-black">Pulihkan</button></div></article>
                    @empty
                        <div class="border-2 border-dashed border-black bg-white p-8 text-center lg:col-span-2"><p class="font-black">Arsip masih kosong.</p><p class="mt-1 text-sm text-[#555]">Tugas yang diarsipkan akan tetap menyimpan histori dan lampirannya.</p></div>
                    @endforelse
                </div>
            @else
            <div class="flex flex-wrap gap-2" role="group" aria-label="Mode tampilan tugas">
                <button type="button" wire:click="$set('viewMode', 'table')" aria-pressed="{{ $viewMode === 'table' ? 'true' : 'false' }}" class="border-2 border-black px-3 py-2 text-sm font-bold {{ $viewMode === 'table' ? 'bg-[#facc15]' : 'bg-white' }}">Table</button>
                <button type="button" wire:click="$set('viewMode', 'kanban')" aria-pressed="{{ $viewMode === 'kanban' ? 'true' : 'false' }}" class="border-2 border-black px-3 py-2 text-sm font-bold {{ $viewMode === 'kanban' ? 'bg-[#facc15]' : 'bg-white' }}">Kanban</button>
                <button type="button" wire:click="$set('viewMode', 'deadline')" aria-pressed="{{ $viewMode === 'deadline' ? 'true' : 'false' }}" class="border-2 border-black px-3 py-2 text-sm font-bold {{ $viewMode === 'deadline' ? 'bg-[#facc15]' : 'bg-white' }}">Deadline</button>
            </div>

            @if($viewMode === 'table')
                <div class="overflow-x-auto border-2 border-black bg-white shadow-[4px_4px_0_#171717]"><table class="w-full min-w-[760px] border-collapse text-left text-sm"><thead class="bg-[#facc15]"><tr><th class="border-b-2 border-black px-3 py-3 font-black">Tugas</th><th class="border-b-2 border-black px-3 py-3 font-black">Kelas / semester / kategori</th><th class="border-b-2 border-black px-3 py-3 font-black">Prioritas</th><th class="border-b-2 border-black px-3 py-3 font-black">Deadline</th><th class="border-b-2 border-black px-3 py-3 font-black">Progres</th><th class="border-b-2 border-black px-3 py-3 font-black">Aksi</th></tr></thead><tbody>@forelse($this->tasks as $task)<tr wire:key="task-row-{{ $task->id }}" class="border-b border-black"><td class="px-3 py-3"><p class="font-bold">{{ $task->title }}</p><p class="text-xs text-[#666]">{{ str_replace('_', ' ', $task->status) }}</p></td><td class="px-3 py-3">{{ collect([$task->course?->name, $task->semester?->name, $task->category?->name])->filter()->join(' · ') ?: 'Personal' }}</td><td class="px-3 py-3 font-bold uppercase">{{ $task->priority }}</td><td class="px-3 py-3 {{ $task->due_at && $task->due_at->isPast() && $task->status !== 'done' ? 'font-bold text-red-700' : '' }}">{{ $task->due_at?->format('d M Y, H:i') ?? '—' }}</td><td class="px-3 py-3">{{ $task->computedProgress() }}%</td><td class="px-3 py-3"><button type="button" wire:click="editTask({{ $task->id }})" class="font-bold underline">Edit</button> <button type="button" wire:click="archiveTask({{ $task->id }})" wire:confirm="Arsipkan tugas ini beserta seluruh subtask-nya?" class="font-bold underline">Arsip</button></td></tr>@empty<tr><td colspan="6" class="px-3 py-8 text-center">Belum ada tugas.</td></tr>@endforelse</tbody></table></div>
            @elseif($viewMode === 'kanban')
                <div class="grid gap-4 lg:grid-cols-3">@foreach(['todo' => 'Belum dimulai', 'in_progress' => 'Sedang dikerjakan', 'done' => 'Selesai'] as $columnStatus => $columnLabel)<section wire:key="kanban-{{ $columnStatus }}" class="min-h-48 border-2 border-black bg-[#eeeae0] p-3"><h3 class="mb-3 border-b-2 border-black pb-2 font-black">{{ $columnLabel }} <span class="float-right">{{ $this->tasks->where('status', $columnStatus)->count() }}</span></h3><div class="space-y-3">@forelse($this->tasks->where('status', $columnStatus) as $task)<article wire:key="kanban-task-{{ $task->id }}" class="border-2 border-black bg-white p-3 shadow-[3px_3px_0_#171717]"><p class="font-black">{{ $task->title }}</p><p class="mt-1 text-xs">{{ $task->due_at?->format('d M, H:i') ?? 'Tanpa deadline' }} · {{ $task->priority }}</p><div class="mt-2 flex items-center justify-between text-xs"><span>{{ $task->computedProgress() }}%</span><button type="button" wire:click="editTask({{ $task->id }})" class="font-bold underline">Edit</button></div></article>@empty<p class="text-sm text-[#666]">Kosong.</p>@endforelse</div></section>@endforeach</div>
            @else

            <div class="grid gap-4 lg:grid-cols-2">
                @forelse($this->tasks as $task)
                    @php($progress = $task->computedProgress())
                    <article wire:key="task-{{ $task->id }}" class="border-2 border-black bg-white p-5 shadow-[4px_4px_0_#171717] {{ $task->priority === 'urgent' ? 'border-s-8 border-s-[#f0442c]' : '' }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="mb-2 flex flex-wrap gap-2 text-[11px] font-black uppercase tracking-wide"><span class="border border-black bg-[#b8d8ff] px-2 py-1">{{ str_replace('_', ' ', $task->status) }}</span><span class="border border-black bg-[#facc15] px-2 py-1">{{ $task->priority }}</span>@if($task->category)<span class="border border-black px-2 py-1" style="background-color: {{ $task->category->color }}">{{ $task->category->name }}</span>@endif</div>
                                <h3 class="text-xl font-black leading-tight">{{ $task->title }}</h3>
                                @if($task->description)<p class="mt-2 whitespace-pre-line text-sm text-[#555]">{{ $task->description }}</p>@endif
                            </div>
                            <div class="flex shrink-0 gap-1"><button type="button" wire:click="editTask({{ $task->id }})" aria-label="Edit {{ $task->title }}" class="border-2 border-black px-2 py-1 text-xs font-bold hover:bg-[#b8d8ff]">Edit</button><button type="button" wire:click="archiveTask({{ $task->id }})" wire:confirm="Arsipkan tugas ini beserta seluruh subtask-nya?" aria-label="Arsipkan {{ $task->title }}" class="border-2 border-black px-2 py-1 text-xs font-bold hover:bg-[#b8edce]">Arsip</button><button type="button" wire:click="deleteTask({{ $task->id }})" wire:confirm="Hapus tugas ini beserta checklist dan subtask-nya?" aria-label="Hapus {{ $task->title }}" class="border-2 border-black px-2 py-1 text-xs font-bold hover:bg-[#ff8c78]">Hapus</button></div>
                        </div>

                        <div class="mt-4 grid gap-2 text-xs sm:grid-cols-2">
                            <p><span class="font-black">Mulai:</span> {{ $task->starts_at?->format('d M Y, H:i') ?? 'Belum dijadwalkan' }}</p>
                            <p class="{{ $task->due_at && $task->due_at->isPast() && $task->status !== 'done' ? 'font-black text-red-700' : '' }}"><span class="font-black">Deadline:</span> {{ $task->due_at?->format('d M Y, H:i') ?? 'Belum ditentukan' }}</p>
                            <p><span class="font-black">Mata kuliah:</span> {{ $task->course?->name ?? 'Personal' }}</p>
                            <p><span class="font-black">Semester:</span> {{ $task->semester?->name ?? '—' }}</p>
                            <p><span class="font-black">Pemberi:</span> {{ $task->assigners->pluck('name')->join(', ') ?: 'Belum dipilih' }}</p>
                        </div>

                        <div class="mt-4">
                            <div class="mb-1 flex items-center justify-between text-xs font-bold"><span>Progres otomatis</span><span>{{ $progress }}%</span></div>
                            <progress value="{{ $progress }}" max="100" aria-label="Progres {{ $task->title }}" class="h-3 w-full accent-[#facc15]">{{ $progress }}%</progress>
                        </div>

                        @if($task->items->isNotEmpty())
                            <p class="mt-3 text-[10px] font-black uppercase tracking-wide">Checklist @if($task->children->isNotEmpty())<span class="font-normal normal-case">— progres induk mengikuti subtask agar tidak terhitung ganda</span>@endif</p>
                            <ul class="mt-3 divide-y divide-black border-y border-black">@foreach($task->items as $item)<li wire:key="task-item-{{ $item->id }}" class="flex items-start gap-2 py-2"><input id="task-item-{{ $item->id }}" type="checkbox" @checked($item->is_completed) wire:click="toggleItem({{ $task->id }}, {{ $item->id }})" class="mt-1 size-4 accent-black"><label for="task-item-{{ $item->id }}" class="flex-1 text-sm {{ $item->is_completed ? 'text-[#777] line-through' : '' }}">{{ $item->title }}</label><button type="button" wire:click="deleteItem({{ $task->id }}, {{ $item->id }})" wire:confirm="Hapus checklist ini? Progres tugas akan dihitung ulang." aria-label="Hapus checklist {{ $item->title }}" class="text-xs font-bold underline">Hapus</button></li>@endforeach</ul>
                        @endif
                        @if($task->children->isNotEmpty())
                            <div class="mt-3 space-y-3"><p class="text-xs font-black uppercase tracking-wide">Proyek dan subtask</p>@foreach($task->children as $child)<x-task-tree :task="$child" />@endforeach</div>
                        @endif
                        <div class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t-2 border-black pt-3">
                            <form wire:submit="addItem({{ $task->id }})" class="flex min-w-0 flex-1 gap-2"><label class="sr-only" for="new-item-{{ $task->id }}">Checklist baru untuk {{ $task->title }}</label><input id="new-item-{{ $task->id }}" type="text" wire:model="newItemTitle" placeholder="Tambah checklist" class="min-w-0 flex-1 border-2 border-black px-2 py-1 text-sm"><button class="border-2 border-black px-2 py-1 text-xs font-black hover:bg-[#b8edff]">Tambah</button></form>
                            @if($task->status !== 'done')<button type="button" wire:click="updateStatus({{ $task->id }}, '{{ $task->status === 'todo' ? 'in_progress' : 'done' }}')" class="border-2 border-black bg-[#b8edce] px-3 py-1 text-xs font-black">{{ $task->status === 'todo' ? 'Mulai' : 'Tandai selesai' }}</button>@else<button type="button" wire:click="updateStatus({{ $task->id }}, 'todo')" class="border-2 border-black px-3 py-1 text-xs font-black">Buka kembali</button>@endif
                        </div>
                    </article>
                @empty
                    <div class="border-2 border-dashed border-black bg-white p-8 text-center sm:col-span-2"><p class="text-lg font-black">Belum ada tugas di sini.</p><p class="mt-1 text-sm text-[#555]">Isi formulir di atas untuk mencatat pekerjaan pertamamu.</p></div>
                @endforelse
            </div>
            @endif
            @endif
        </section>
    </div>
</div>

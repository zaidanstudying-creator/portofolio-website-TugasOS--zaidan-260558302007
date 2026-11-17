<?php

use App\Models\Task;
use App\Models\TaskProgressEvent;
use App\Models\TaskStatusEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $period = 'monthly';

    public string $startDate = '';

    public string $endDate = '';

    public string $groupBy = 'due_at';

    public string $aggregationMode = 'count';

    public string $categoryId = '';

    public string $assignerId = '';

    public string $assignerRole = '';

    public string $courseId = '';

    public string $semesterId = '';

    public string $priorityFilter = '';

    public string $statusFilter = '';

    public string $typeFilter = '';

    public function updatedAggregationMode(): void
    {
        abort_unless(in_array($this->aggregationMode, ['count', 'weighted'], true), 422);

        unset($this->summary);
    }

    public function mount(): void
    {
        $this->startDate = now()->startOfMonth()->toDateString();
        $this->endDate = today()->toDateString();
    }

    #[Computed]
    public function tasks(): Collection
    {
        $matchingTaskIds = $this->matchingTaskIds();
        $dateColumn = $this->dateColumn();
        $taskParents = auth()->user()->tasks()->pluck('parent_task_id', 'id');
        $rootIds = $matchingTaskIds->map(function (int $taskId) use ($taskParents): int {
            $visitedIds = [];

            while (($parentTaskId = $taskParents->get($taskId)) !== null && ! in_array($taskId, $visitedIds, true)) {
                $visitedIds[] = $taskId;
                $taskId = (int) $parentTaskId;
            }

            return $taskId;
        })->unique();

        return auth()->user()->tasks()
            ->whereNull('parent_task_id')
            ->whereNull('archived_at')
            ->whereIn('id', $rootIds)
            ->with(['items', 'children.items', 'category', 'course', 'assigners.roles', 'submissions'])
            ->orderBy($dateColumn)
            ->get();
    }

    #[Computed]
    public function metricTasks(): Collection
    {
        $matchingTaskIds = $this->matchingTaskIds();
        $matchingTaskLookup = $matchingTaskIds->flip();
        $taskParents = auth()->user()->tasks()->pluck('parent_task_id', 'id');
        $matchingAncestors = [];

        foreach ($matchingTaskIds as $taskId) {
            $parentTaskId = $taskParents->get($taskId);

            while ($parentTaskId !== null) {
                if ($matchingTaskLookup->has((int) $parentTaskId)) {
                    $matchingAncestors[(int) $parentTaskId] = true;
                }

                $parentTaskId = $taskParents->get((int) $parentTaskId);
            }
        }

        $metricTaskIds = $matchingTaskIds
            ->reject(fn (int $taskId): bool => isset($matchingAncestors[$taskId]));

        return auth()->user()->tasks()
            ->whereIn('id', $metricTaskIds)
            ->with(['category', 'assigners', 'submissions'])
            ->get();
    }

    #[Computed]
    public function categories(): Collection
    {
        return auth()->user()->categories()->orderBy('name')->get();
    }

    #[Computed]
    public function assigners(): Collection
    {
        return auth()->user()->assigners()->with('roles')->orderBy('name')->get();
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
    public function roles(): SupportCollection
    {
        return auth()->user()->assigners()->with('roles')->get()->flatMap->roles->unique('name')->sortBy('name')->values();
    }

    #[Computed]
    public function summary(): array
    {
        $tasks = $this->tasks;
        $metricTasks = $this->metricTasks;

        return [
            'progress' => $this->aggregateProgress($this->progressValues($tasks)),
            'completion' => $metricTasks->isEmpty() ? 0 : (int) round($metricTasks->where('status', 'done')->count() / $metricTasks->count() * 100),
            'on_time' => $this->onTimeSubmissionRate($metricTasks),
            'created' => $metricTasks->count(),
            'completed' => $metricTasks->where('status', 'done')->count(),
            'overdue' => $metricTasks->filter(fn (Task $task): bool => $task->due_at && $task->due_at->isPast() && $task->status !== 'done')->count(),
            'by_category' => $metricTasks->groupBy(fn (Task $task): string => $task->category?->name ?? 'Tanpa kategori')
                ->map(fn (SupportCollection $group): int => $this->aggregateProgress($this->progressValues($group))),
            'by_assigner' => $metricTasks->flatMap(fn (Task $task) => $task->assigners->map(fn ($assigner): array => ['name' => $assigner->name, 'task' => $task]))
                ->groupBy('name')
                ->map(fn (SupportCollection $group): int => $this->aggregateProgress($this->progressValues($group->pluck('task')))),
        ];
    }

    /**
     * @return SupportCollection<int, int>
     */
    private function matchingTaskIds(): SupportCollection
    {
        [$start, $end] = $this->periodBounds();
        $dateColumn = $this->dateColumn();

        return auth()->user()->tasks()
            ->whereNull('archived_at')
            ->whereBetween($dateColumn, [$start, $end])
            ->when($this->categoryId !== '', fn ($query) => $query->where('category_id', $this->categoryId))
            ->when($this->assignerId !== '', fn ($query) => $query->whereHas('assigners', fn ($assigners) => $assigners->whereKey($this->assignerId)))
            ->when($this->assignerRole !== '', fn ($query) => $query->whereHas('assigners.roles', fn ($roles) => $roles->where('name', $this->assignerRole)))
            ->when($this->courseId !== '', fn ($query) => $query->where('course_id', $this->courseId))
            ->when($this->semesterId !== '', fn ($query) => $query->where('semester_id', $this->semesterId))
            ->when($this->priorityFilter !== '', fn ($query) => $query->where('priority', $this->priorityFilter))
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->when($this->typeFilter !== '', fn ($query) => $query->where('type', $this->typeFilter))
            ->pluck('id')
            ->map(fn ($taskId): int => (int) $taskId);
    }

    private function dateColumn(): string
    {
        return match ($this->groupBy) {
            'created_at' => 'created_at',
            'completed_at' => 'completed_at',
            default => 'due_at',
        };
    }

    private function onTimeSubmissionRate(Collection $tasks): int
    {
        $successfulSubmissions = $tasks->mapWithKeys(function (Task $task): array {
            $submission = $task->submissions
                ->whereIn('status', ['manual_recorded', 'verified'])
                ->filter(fn ($submission): bool => $submission->submitted_at !== null)
                ->sortByDesc('submitted_at')
                ->first();

            return $submission ? [$task->getKey() => $submission] : [];
        });
        $eligibleTasks = $tasks->filter(fn (Task $task): bool => $task->due_at !== null
            && ($task->due_at->lessThanOrEqualTo(now()) || $successfulSubmissions->has($task->getKey())));

        if ($eligibleTasks->isEmpty()) {
            return 0;
        }

        $onTimeSubmissions = $eligibleTasks->filter(fn (Task $task): bool => $successfulSubmissions->has($task->getKey())
            && $successfulSubmissions->get($task->getKey())->submitted_at->lessThanOrEqualTo($task->due_at));

        return (int) round($onTimeSubmissions->count() / $eligibleTasks->count() * 100);
    }

    #[Computed]
    public function history(): Collection
    {
        return TaskProgressEvent::query()
            ->where('user_id', auth()->id())
            ->whereIn('task_id', $this->selectedTaskTreeIds)
            ->with('task')
            ->latest()
            ->limit(12)
            ->get();
    }

    #[Computed]
    public function statusHistory(): Collection
    {
        return TaskStatusEvent::query()
            ->where('user_id', auth()->id())
            ->whereIn('task_id', $this->selectedTaskTreeIds)
            ->with('task')
            ->latest()
            ->limit(12)
            ->get();
    }

    #[Computed]
    public function progressTrend(): SupportCollection
    {
        [$start, $end] = $this->periodBounds();
        $tasks = $this->tasks;

        if ($tasks->isEmpty()) {
            return collect();
        }

        $tasksById = $tasks->keyBy(fn (Task $task): int => (int) $task->getKey());
        $taskTree = auth()->user()->tasks()
            ->whereIn('id', $this->selectedTaskTreeIds)
            ->get(['id', 'parent_task_id', 'weight']);
        $taskIds = $taskTree->modelKeys();
        $childrenByParent = $taskTree->groupBy('parent_task_id');
        $progressByTask = TaskProgressEvent::query()
            ->where('user_id', auth()->id())
            ->whereIn('task_id', $taskIds)
            ->where('created_at', '<', $start)
            ->orderBy('created_at')
            ->get(['task_id', 'to_progress'])
            ->groupBy('task_id')
            ->map(fn (SupportCollection $events): int => (int) $events->last()->to_progress)
            ->all();

        return TaskProgressEvent::query()
            ->where('user_id', auth()->id())
            ->whereIn('task_id', $taskIds)
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('created_at')
            ->get(['task_id', 'to_progress', 'created_at'])
            ->groupBy(fn (TaskProgressEvent $event): string => $event->created_at->toDateString())
            ->map(function (SupportCollection $events, string $date) use ($tasks, $tasksById, $childrenByParent, &$progressByTask): array {
                foreach ($events as $event) {
                    $progressByTask[(int) $event->task_id] = (int) $event->to_progress;
                }

                $dateEnd = CarbonImmutable::parse($date)->endOfDay();
                $eligibleTasks = $tasks->filter(fn (Task $task): bool => $task->created_at->lessThanOrEqualTo($dateEnd));
                $latestDailyValues = $eligibleTasks->map(function (Task $task) use ($childrenByParent, $progressByTask, $tasksById): array {
                    $progress = function (int $taskId) use (&$progress, $childrenByParent, $progressByTask): int {
                        $children = $childrenByParent->get($taskId, collect());

                        if ($children->isEmpty()) {
                            return $progressByTask[$taskId] ?? 0;
                        }

                        if ($this->aggregationMode === 'weighted') {
                            $totalWeight = max(1, (int) $children->sum(fn (Task $child): int => max(1, (int) $child->weight)));

                            return (int) round($children->sum(fn (Task $child): float => $progress((int) $child->id) * max(1, (int) $child->weight)) / $totalWeight);
                        }

                        return (int) round($children->average(fn (Task $child): int => $progress((int) $child->id)));
                    };

                    return [
                        'progress' => $progress((int) $task->id),
                        'weight' => max(1, (int) $tasksById->get((int) $task->id)?->weight),
                    ];
                });

                return [
                    'date' => $date,
                    'label' => CarbonImmutable::parse($date)->translatedFormat('d M'),
                    'progress' => $this->aggregateProgress($latestDailyValues),
                    'changes' => $events->count(),
                ];
            })
            ->values();
    }

    #[Computed]
    public function selectedTaskTreeIds(): array
    {
        $taskIds = $this->tasks->modelKeys();
        $frontierIds = $taskIds;

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

    private function periodBounds(): array
    {
        if ($this->period === 'custom') {
            $start = strtotime($this->startDate) ? CarbonImmutable::parse($this->startDate)->startOfDay() : CarbonImmutable::today();
            $end = strtotime($this->endDate) ? CarbonImmutable::parse($this->endDate)->endOfDay() : $start->endOfDay();

            return [$start, $end->lessThan($start) ? $start->endOfDay() : $end];
        }

        return match ($this->period) {
            'daily' => [CarbonImmutable::today(), CarbonImmutable::tomorrow()->subSecond()],
            'weekly' => [CarbonImmutable::now()->startOfWeek(), CarbonImmutable::now()->endOfWeek()],
            'semester' => $this->semesterBounds(),
            default => [CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->endOfMonth()],
        };
    }

    private function semesterBounds(): array
    {
        $semester = auth()->user()->semesters()->where('is_active', true)->first();

        return $semester
            ? [CarbonImmutable::parse($semester->starts_at)->startOfDay(), CarbonImmutable::parse($semester->ends_at)->endOfDay()]
            : [CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->endOfMonth()];
    }

    /**
     * @param  iterable<Task>  $tasks
     * @return SupportCollection<int, array{progress: int, weight: int}>
     */
    private function progressValues(iterable $tasks): SupportCollection
    {
        return collect($tasks)->map(fn (Task $task): array => [
            'progress' => $task->computedProgress(),
            'weight' => max(1, (int) $task->weight),
        ]);
    }

    /**
     * @param  SupportCollection<int, array{progress: int, weight: int}>  $values
     */
    private function aggregateProgress(SupportCollection $values): int
    {
        if ($values->isEmpty()) {
            return 0;
        }

        if ($this->aggregationMode === 'weighted') {
            $totalWeight = $values->sum('weight');

            return (int) round($values->sum(fn (array $value): int => $value['progress'] * $value['weight']) / max(1, $totalWeight));
        }

        return (int) round($values->avg('progress'));
    }
};
?>

<div class="min-h-screen bg-[#f7f4ec] px-4 py-8 text-[#171717] sm:px-8 lg:px-10"><div class="mx-auto max-w-7xl space-y-7">
    <header class="flex flex-col justify-between gap-5 border-b-2 border-black pb-6 sm:flex-row sm:items-end"><div><p class="text-xs font-black uppercase tracking-[0.24em] text-[#555]">TUGAS!OS / Analytics</p><h1 class="mt-2 text-4xl font-black sm:text-5xl">Lihat pola kerjamu.</h1><p class="mt-3 max-w-xl text-sm text-[#555]">Progres dihitung dari tugas utama agar pekerjaan anak tidak dihitung dua kali.</p></div><div class="border-2 border-black bg-[#b8d8ff] px-4 py-3 text-sm font-bold shadow-[4px_4px_0_#171717]">TAHAP 05 — ANALYTICS</div></header>
    <section class="border-2 border-black bg-white p-4 shadow-[4px_4px_0_#171717]"><div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"><label class="grid gap-1 text-sm font-bold">Periode<select wire:model.live="period" class="border-2 border-black px-3 py-2 font-normal"><option value="daily">Harian</option><option value="weekly">Mingguan</option><option value="monthly">Bulanan</option><option value="semester">Semester aktif</option><option value="custom">Rentang tanggal</option></select></label>@if($period === 'custom')<label class="grid gap-1 text-sm font-bold">Mulai<input wire:model.live="startDate" type="date" class="border-2 border-black px-3 py-2 font-normal"></label><label class="grid gap-1 text-sm font-bold">Sampai<input wire:model.live="endDate" type="date" class="border-2 border-black px-3 py-2 font-normal"></label>@endif<label class="grid gap-1 text-sm font-bold">Kelompokkan berdasarkan<select wire:model.live="groupBy" class="border-2 border-black px-3 py-2 font-normal"><option value="created_at">Tanggal dibuat</option><option value="due_at">Deadline</option><option value="completed_at">Tanggal selesai</option></select></label><label class="grid gap-1 text-sm font-bold">Agregasi progres<select wire:model.live="aggregationMode" class="border-2 border-black px-3 py-2 font-normal"><option value="count">Berdasarkan jumlah tugas</option><option value="weighted">Berdasarkan bobot tugas</option></select></label>
        <label class="grid gap-1 text-sm font-bold">Kategori<select wire:model.live="categoryId" class="border-2 border-black px-3 py-2 font-normal"><option value="">Semua</option>@foreach($this->categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></label><label class="grid gap-1 text-sm font-bold">Pemberi tugas<select wire:model.live="assignerId" class="border-2 border-black px-3 py-2 font-normal"><option value="">Semua</option>@foreach($this->assigners as $assigner)<option value="{{ $assigner->id }}">{{ $assigner->name }}</option>@endforeach</select></label><label class="grid gap-1 text-sm font-bold">Jabatan<select wire:model.live="assignerRole" class="border-2 border-black px-3 py-2 font-normal"><option value="">Semua</option>@foreach($this->roles as $role)<option value="{{ $role->name }}">{{ $role->name }}</option>@endforeach</select></label><label class="grid gap-1 text-sm font-bold">Mata kuliah<select wire:model.live="courseId" class="border-2 border-black px-3 py-2 font-normal"><option value="">Semua</option>@foreach($this->courses as $course)<option value="{{ $course->id }}">{{ $course->name }}</option>@endforeach</select></label><label class="grid gap-1 text-sm font-bold">Semester<select wire:model.live="semesterId" class="border-2 border-black px-3 py-2 font-normal"><option value="">Semua</option>@foreach($this->semesters as $semester)<option value="{{ $semester->id }}">{{ $semester->name }}</option>@endforeach</select></label><label class="grid gap-1 text-sm font-bold">Prioritas<select wire:model.live="priorityFilter" class="border-2 border-black px-3 py-2 font-normal"><option value="">Semua</option>@foreach(['low', 'medium', 'high', 'urgent'] as $priority)<option value="{{ $priority }}">{{ ucfirst($priority) }}</option>@endforeach</select></label><label class="grid gap-1 text-sm font-bold">Status<select wire:model.live="statusFilter" class="border-2 border-black px-3 py-2 font-normal"><option value="">Semua</option><option value="todo">Belum dimulai</option><option value="in_progress">Sedang dikerjakan</option><option value="done">Selesai</option></select></label><label class="grid gap-1 text-sm font-bold">Jenis<select wire:model.live="typeFilter" class="border-2 border-black px-3 py-2 font-normal"><option value="">Semua</option><option value="daily">Harian</option><option value="weekly">Mingguan</option><option value="semester">Semesteran</option><option value="flexible">Fleksibel</option></select></label></div></section>
    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">@foreach([['Work progress', $this->summary['progress'].'%', 'bg-[#facc15]'], ['Completion rate', $this->summary['completion'].'%', 'bg-[#b8edce]'], ['On-time submission', $this->summary['on_time'].'%', 'bg-[#b8d8ff]'], ['Task masuk', $this->summary['created'], 'bg-white'], ['Task selesai', $this->summary['completed'], 'bg-white'], ['Task terlambat', $this->summary['overdue'], 'bg-[#ffb7a9]']] as [$label, $value, $color])<article class="border-2 border-black {{ $color }} p-4 shadow-[4px_4px_0_#171717]"><p class="text-xs font-black uppercase tracking-widest">{{ $label }}</p><p class="mt-2 text-3xl font-black">{{ $value }}</p></article>@endforeach</section>
    <section aria-labelledby="progress-trend-heading" class="border-2 border-black bg-white p-5 shadow-[4px_4px_0_#171717]"><div class="flex flex-wrap items-end justify-between gap-3"><div><p class="text-xs font-black uppercase tracking-widest text-[#666]">Histori yang tercatat</p><h2 id="progress-trend-heading" class="mt-1 text-2xl font-black">Tren progres historis</h2></div><p class="text-xs text-[#555]">Rata-rata progres tugas utama pada perubahan terakhir setiap hari.</p></div>@if($this->progressTrend->isNotEmpty())<div class="mt-5 flex h-48 items-end gap-2 overflow-x-auto border-b-2 border-black pb-2" role="img" aria-label="Tren rata-rata progres historis berdasarkan perubahan yang tercatat">@foreach($this->progressTrend as $day)<div wire:key="progress-trend-{{ $day['date'] }}" class="flex h-full min-w-12 flex-1 flex-col items-center justify-end gap-2"><span class="text-xs font-bold">{{ $day['progress'] }}%</span><div class="w-full max-w-12 border-2 border-black bg-[#b8d8ff]" style="height: {{ max(6, $day['progress']) }}%"></div><span class="text-[10px] font-black">{{ $day['label'] }}</span></div>@endforeach</div>@else<p class="mt-4 border-t-2 border-black py-4 text-sm text-[#555]">Belum ada perubahan progres pada periode dan filter ini.</p>@endif</section>
    <section class="grid gap-5 lg:grid-cols-2"><article class="border-2 border-black bg-white p-5 shadow-[4px_4px_0_#171717]"><h2 class="text-2xl font-black">Rata-rata progres per kategori</h2><div class="mt-4 space-y-3">@forelse($this->summary['by_category'] as $category => $progress)<div wire:key="category-{{ $category }}"><div class="mb-1 flex justify-between text-sm"><span class="font-bold">{{ $category }}</span><span>{{ $progress }}%</span></div><progress value="{{ $progress }}" max="100" aria-label="{{ $category }} {{ $progress }} persen" class="h-3 w-full accent-[#facc15]">{{ $progress }}%</progress></div>@empty<p class="py-4 text-sm text-[#555]">Belum ada data dalam filter ini.</p>@endforelse</div></article><article class="border-2 border-black bg-white p-5 shadow-[4px_4px_0_#171717]"><h2 class="text-2xl font-black">Rata-rata progres per pemberi</h2><ul class="mt-4 divide-y-2 divide-black border-t-2 border-black">@forelse($this->summary['by_assigner'] as $assigner => $progress)<li wire:key="assigner-{{ $assigner }}" class="flex justify-between gap-3 py-3"><span class="font-bold">{{ $assigner }}</span><span class="font-black">{{ $progress }}%</span></li>@empty<li class="py-4 text-sm text-[#555]">Belum ada pemberi tugas pada filter ini.</li>@endforelse</ul></article></section>
    <div class="grid gap-5 lg:grid-cols-2">
        <section class="border-2 border-black bg-white p-5 shadow-[4px_4px_0_#171717]"><div class="flex items-end justify-between gap-3"><div><p class="text-xs font-black uppercase tracking-widest text-[#666]">Perubahan aktual</p><h2 class="mt-1 text-2xl font-black">Histori progres</h2></div><span class="text-xs text-[#555]">{{ $this->tasks->count() }} tugas utama terpilih</span></div><div class="mt-4 grid gap-2">@forelse($this->history as $event)<article wire:key="progress-event-{{ $event->id }}" class="border-2 border-black bg-[#f7f4ec] p-3"><p class="truncate font-bold">{{ $event->task?->title ?? 'Tugas dihapus' }}</p><p class="mt-1 text-sm">{{ $event->from_progress }}% → <strong>{{ $event->to_progress }}%</strong></p><time class="text-xs text-[#555]">{{ $event->created_at->diffForHumans() }}</time></article>@empty<p class="py-5 text-sm text-[#555]">Histori perubahan progres akan muncul setelah checklist atau subtask berubah.</p>@endforelse</div></section>
        <section class="border-2 border-black bg-white p-5 shadow-[4px_4px_0_#171717]"><div><p class="text-xs font-black uppercase tracking-widest text-[#666]">Perubahan status</p><h2 class="mt-1 text-2xl font-black">Histori aktivitas</h2></div><div class="mt-4 grid gap-2">@forelse($this->statusHistory as $event)<article wire:key="status-event-{{ $event->id }}" class="border-2 border-black bg-[#f7f4ec] p-3"><p class="truncate font-bold">{{ $event->task?->title ?? 'Tugas dihapus' }}</p><p class="mt-1 text-sm">{{ $event->from_status ? str_replace('_', ' ', $event->from_status) : 'tugas dibuat' }} → <strong>{{ str_replace('_', ' ', $event->to_status) }}</strong></p><time class="text-xs text-[#555]">{{ $event->created_at->diffForHumans() }}</time></article>@empty<p class="py-5 text-sm text-[#555]">Histori perubahan status akan muncul setelah tugas dibuat atau statusnya diubah.</p>@endforelse</div></section>
    </div>
</div></div>

<?php

use App\Models\Task;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $currentDate = '';

    public string $viewMode = 'month';

    public string $selectedDate = '';

    public string $newTaskTitle = '';

    public string $categoryFilter = '';

    public string $assignerFilter = '';

    public ?int $selectedTaskId = null;

    public array $scheduleDates = [];

    public function mount(): void
    {
        $this->currentDate = today()->toDateString();
        $this->initializeScheduleDates();
    }

    #[Computed]
    public function dates(): array
    {
        $this->validate([
            'currentDate' => ['required', 'date_format:Y-m-d'],
            'viewMode' => ['required', 'in:month,week,day,agenda'],
        ]);

        $focus = CarbonImmutable::parse($this->currentDate);
        $start = match ($this->viewMode) {
            'week' => $focus->startOfWeek(Carbon::MONDAY),
            'day' => $focus->startOfDay(),
            'agenda' => $focus->startOfDay(),
            default => $focus->startOfMonth()->startOfWeek(Carbon::MONDAY),
        };
        $count = match ($this->viewMode) {
            'week' => 7,
            'day' => 1,
            'agenda' => 30,
            default => 42,
        };

        return collect(range(0, $count - 1))->map(fn (int $offset): string => $start->addDays($offset)->toDateString())->all();
    }

    #[Computed]
    public function events(): array
    {
        $dates = $this->dates;
        $from = CarbonImmutable::parse($dates[0])->startOfDay();
        $until = CarbonImmutable::parse($dates[array_key_last($dates)])->endOfDay();
        $taskQuery = auth()->user()->tasks()
            ->whereNull('archived_at')
            ->with(['category', 'assigners'])
            ->when($this->categoryFilter !== '', fn ($query) => $query->where('category_id', $this->categoryFilter))
            ->when($this->assignerFilter !== '', fn ($query) => $query->whereHas('assigners', fn ($assigners) => $assigners->whereKey($this->assignerFilter)));

        $events = [];

        foreach ((clone $taskQuery)->whereBetween('due_at', [$from, $until])->get() as $task) {
            $events[$task->due_at->toDateString()][] = [
                'id' => 'deadline-'.$task->id,
                'task_id' => $task->id,
                'title' => $task->title,
                'kind' => 'deadline',
                'time' => $task->due_at->format('H:i'),
                'priority' => $task->priority,
                'overdue' => $task->status !== 'done' && $task->due_at->isPast(),
                'urgent' => $this->isUrgent($task),
                'color' => $task->category?->color ?? match ($task->priority) {
                    'urgent' => '#ff8c78', 'high' => '#ffd9d1', 'medium' => '#facc15', default => '#b8d8ff'
                },
            ];
        }

        $schedules = auth()->user()->taskSchedules()
            ->with(['task.category', 'task.assigners'])
            ->whereHas('task', fn ($query) => $query->whereNull('archived_at'))
            ->where('starts_at', '<=', $until)
            ->where('ends_at', '>', $from)
            ->when($this->categoryFilter !== '', fn ($query) => $query->whereHas('task', fn ($tasks) => $tasks->where('category_id', $this->categoryFilter)))
            ->when($this->assignerFilter !== '', fn ($query) => $query->whereHas('task.assigners', fn ($assigners) => $assigners->whereKey($this->assignerFilter)))
            ->orderBy('starts_at')
            ->get();

        foreach ($schedules as $schedule) {
            $startDate = $schedule->starts_at->toDateString();
            $lastScheduleDate = $schedule->ends_at->subSecond()->toDateString();
            $firstVisibleDate = max($startDate, $from->toDateString());
            $lastVisibleDate = min($lastScheduleDate, $until->toDateString());

            if ($lastVisibleDate < $firstVisibleDate) {
                continue;
            }

            $visibleDate = CarbonImmutable::parse($firstVisibleDate);
            $visibleEnd = CarbonImmutable::parse($lastVisibleDate);

            while ($visibleDate->lessThanOrEqualTo($visibleEnd)) {
                $date = $visibleDate->toDateString();
                $endLabel = $schedule->ends_at->format('H:i:s') === '00:00:00'
                    ? 'Sampai 00:00 besok'
                    : 'Sampai '.$schedule->ends_at->format('H:i');
                $time = match (true) {
                    $schedule->is_all_day => 'Seharian',
                    $date === $startDate => $schedule->starts_at->format('H:i'),
                    $date === $lastScheduleDate => $endLabel,
                    default => 'Berlanjut',
                };

                $events[$date][] = [
                    'id' => 'schedule-'.$schedule->id.'-'.$date,
                    'task_id' => $schedule->task_id,
                    'schedule_id' => $schedule->id,
                    'title' => $schedule->task->title,
                    'kind' => 'schedule',
                    'date' => $startDate,
                    'time' => $time,
                    'priority' => $schedule->task->priority,
                    'overdue' => $schedule->task->status !== 'done' && $schedule->task->due_at?->isPast(),
                    'urgent' => $this->isUrgent($schedule->task),
                    'color' => $schedule->task->category?->color ?? match ($schedule->task->priority) {
                        'urgent' => '#ff8c78', 'high' => '#ffd9d1', 'medium' => '#facc15', default => '#b8d8ff'
                    },
                ];

                $visibleDate = $visibleDate->addDay();
            }
        }

        return $events;
    }

    #[Computed]
    public function categories(): Collection
    {
        return auth()->user()->categories()->orderBy('name')->get();
    }

    #[Computed]
    public function assigners(): Collection
    {
        return auth()->user()->assigners()->orderBy('name')->get();
    }

    #[Computed]
    public function unscheduledTasks(): Collection
    {
        return auth()->user()->tasks()
            ->whereNull('archived_at')
            ->where('status', '!=', 'done')
            ->whereDoesntHave('schedules')
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->limit(8)
            ->get();
    }

    #[Computed]
    public function periodProgress(): array
    {
        $dates = $this->dates;
        $from = CarbonImmutable::parse($dates[0])->startOfDay();
        $until = CarbonImmutable::parse($dates[array_key_last($dates)])->endOfDay();
        $taskParents = auth()->user()->tasks()->pluck('parent_task_id', 'id');
        $topLevelTaskId = static function (int $taskId) use ($taskParents): int {
            $visitedIds = [];

            while (($parentTaskId = $taskParents->get($taskId)) !== null && ! in_array($taskId, $visitedIds, true)) {
                $visitedIds[] = $taskId;
                $taskId = (int) $parentTaskId;
            }

            return $taskId;
        };
        $filteredTasks = auth()->user()->tasks()
            ->whereNull('archived_at')
            ->when($this->categoryFilter !== '', fn ($query) => $query->where('category_id', $this->categoryFilter))
            ->when($this->assignerFilter !== '', fn ($query) => $query->whereHas('assigners', fn ($assigners) => $assigners->whereKey($this->assignerFilter)));
        $dueTaskIds = (clone $filteredTasks)
            ->whereBetween('due_at', [$from, $until])
            ->pluck('id');
        $scheduledTaskIds = auth()->user()->taskSchedules()
            ->whereHas('task', fn ($query) => $query
                ->whereNull('archived_at')
                ->when($this->categoryFilter !== '', fn ($tasks) => $tasks->where('category_id', $this->categoryFilter))
                ->when($this->assignerFilter !== '', fn ($tasks) => $tasks->whereHas('assigners', fn ($assigners) => $assigners->whereKey($this->assignerFilter))))
            ->where('starts_at', '<=', $until)
            ->where('ends_at', '>', $from)
            ->pluck('task_id');
        $periodRootIds = $dueTaskIds
            ->merge($scheduledTaskIds)
            ->map(fn ($taskId): int => $topLevelTaskId((int) $taskId))
            ->unique();
        $tasks = auth()->user()->tasks()
            ->whereNull('parent_task_id')
            ->whereNull('archived_at')
            ->whereIn('id', $periodRootIds)
            ->with(['items', 'children.items'])
            ->get();

        return [
            'total' => $tasks->count(),
            'average' => $tasks->isEmpty() ? 0 : (int) round($tasks->average(fn (Task $task): int => $task->computedProgress())),
            'completed' => $tasks->filter(fn (Task $task): bool => $task->computedProgress() === 100)->count(),
        ];
    }

    public function movePeriod(int $direction): void
    {
        abort_unless(in_array($direction, [-1, 1], true), 422);

        $focus = CarbonImmutable::parse($this->currentDate);
        $this->currentDate = match ($this->viewMode) {
            'week' => $focus->addWeeks($direction)->toDateString(),
            'day' => $focus->addDays($direction)->toDateString(),
            'agenda' => $focus->addDays($direction * 30)->toDateString(),
            default => $focus->addMonths($direction)->toDateString(),
        };

        unset($this->dates, $this->events, $this->periodProgress);
        $this->initializeScheduleDates();
    }

    public function updatedViewMode(): void
    {
        abort_unless(in_array($this->viewMode, ['month', 'week', 'day', 'agenda'], true), 422);

        unset($this->dates, $this->events, $this->periodProgress);
        $this->initializeScheduleDates();
    }

    public function selectDate(string $date): void
    {
        validator(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();

        $this->selectedDate = CarbonImmutable::parse($date)->toDateString();
    }

    #[Computed]
    public function selectedTask(): ?Task
    {
        if ($this->selectedTaskId === null) {
            return null;
        }

        return auth()->user()->tasks()
            ->whereNull('archived_at')
            ->with(['items', 'children.items', 'category', 'course', 'semester', 'assigners', 'schedules', 'files', 'links', 'taskNotes'])
            ->findOrFail($this->selectedTaskId);
    }

    public function showTask(int $taskId): void
    {
        auth()->user()->tasks()->whereNull('archived_at')->findOrFail($taskId);
        $this->selectedTaskId = $taskId;
        unset($this->selectedTask);
    }

    public function createTaskOnDate(): void
    {
        $validated = $this->validate([
            'newTaskTitle' => ['required', 'string', 'max:255'],
            'selectedDate' => ['required', 'date_format:Y-m-d'],
        ]);

        $startsAt = CarbonImmutable::parse($validated['selectedDate'])->setTime(9, 0);

        DB::transaction(function () use ($validated, $startsAt): void {
            $task = auth()->user()->tasks()->create([
                'title' => $validated['newTaskTitle'],
                'status' => 'todo',
                'type' => 'flexible',
                'priority' => 'medium',
            ]);

            $task->statusEvents()->create([
                'user_id' => auth()->id(),
                'from_status' => null,
                'to_status' => 'todo',
            ]);
            $task->schedules()->create([
                'user_id' => auth()->id(),
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addHour(),
            ]);
        });

        $this->reset(['newTaskTitle', 'selectedDate']);
        unset($this->events, $this->unscheduledTasks, $this->periodProgress);
        session()->flash('status', 'Tugas dan jadwal pengerjaan berhasil dibuat. Deadline resminya belum diatur.');
    }

    public function scheduleTask(int $taskId, string $date, ?int $scheduleId = null): void
    {
        validator(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();

        $task = auth()->user()->tasks()->whereNull('archived_at')->findOrFail($taskId);
        $day = CarbonImmutable::parse($date)->startOfDay();
        $schedule = $scheduleId === null
            ? $task->schedules()->latest('starts_at')->first()
            : $task->schedules()->findOrFail($scheduleId);

        if ($schedule) {
            $duration = max(30, $schedule->starts_at->diffInMinutes($schedule->ends_at, absolute: true));
            $start = $day->setTime(
                (int) $schedule->starts_at->format('H'),
                (int) $schedule->starts_at->format('i'),
                (int) $schedule->starts_at->format('s'),
            );
            $schedule->update([
                'starts_at' => $start,
                'ends_at' => $start->addMinutes($duration),
            ]);
        } else {
            $schedule = $task->schedules()->create([
                'user_id' => auth()->id(),
                'starts_at' => $day->setTime(9, 0),
                'ends_at' => $day->setTime(10, 0),
            ]);
        }

        $this->scheduleDates[$schedule->id] = $date;
        unset($this->events, $this->unscheduledTasks, $this->periodProgress);
        session()->flash('status', 'Jadwal pengerjaan dipindahkan. Deadline tugas tidak berubah.');
    }

    public function moveScheduleFromCalendar(int $taskId, int $scheduleId): void
    {
        $validated = validator(
            ['date' => $this->scheduleDates[$scheduleId] ?? null],
            ['date' => ['required', 'date_format:Y-m-d']],
        )->validate();

        $this->scheduleTask($taskId, $validated['date'], $scheduleId);
    }

    private function isUrgent(Task $task): bool
    {
        if (in_array($task->priority, ['urgent', 'high'], true)) {
            return true;
        }

        if ($task->status === 'done' || $task->due_at === null || $task->due_at->isPast()) {
            return false;
        }

        $urgencySettings = auth()->user()->settings['deadline_urgency'] ?? [];
        $urgentDays = max(1, (int) ($urgencySettings['urgent_days'] ?? 3));

        return $task->due_at->lessThanOrEqualTo(now()->addDays($urgentDays));
    }

    private function initializeScheduleDates(): void
    {
        $dates = $this->dates;
        $from = CarbonImmutable::parse($dates[0])->startOfDay();
        $until = CarbonImmutable::parse($dates[array_key_last($dates)])->endOfDay();

        $this->scheduleDates = auth()->user()->taskSchedules()
            ->where('starts_at', '<=', $until)
            ->where('ends_at', '>', $from)
            ->get(['id', 'starts_at'])
            ->mapWithKeys(fn ($schedule): array => [$schedule->id => $schedule->starts_at->toDateString()])
            ->all();
    }
};
?>

<div class="min-h-screen bg-[#f7f4ec] px-4 py-8 text-[#171717] sm:px-8 lg:px-10">
    <div class="mx-auto max-w-7xl space-y-7">
        <header class="flex flex-col justify-between gap-5 border-b-2 border-black pb-6 sm:flex-row sm:items-end">
            <div><p class="text-xs font-black uppercase tracking-[0.24em] text-[#555]">TUGAS!OS / Calendar</p><h1 class="mt-2 text-4xl font-black tracking-tight sm:text-5xl">Waktu yang terencana.</h1><p class="mt-3 max-w-xl text-sm text-[#555]">Jadwal pengerjaan dan deadline resmi ditampilkan terpisah. Geser jadwal tanpa mengubah tenggat tugas.</p></div>
            <div class="border-2 border-black bg-[#b8d8ff] px-4 py-3 text-sm font-bold shadow-[4px_4px_0_#171717]">TAHAP 03 — KALENDER</div>
        </header>

        @if (session('status'))<div role="status" aria-live="polite" class="border-2 border-black bg-[#b8edce] px-4 py-3 font-bold shadow-[4px_4px_0_#171717]">{{ session('status') }}</div>@endif

        <section class="grid gap-4 md:grid-cols-3" aria-label="Ringkasan periode kalender">
            <article class="border-2 border-black bg-white p-4 shadow-[4px_4px_0_#171717]"><p class="text-xs font-black uppercase tracking-widest">Tugas di periode ini</p><p class="mt-2 text-3xl font-black">{{ $this->periodProgress['total'] }}</p></article>
            <article class="border-2 border-black bg-[#facc15] p-4 shadow-[4px_4px_0_#171717]"><p class="text-xs font-black uppercase tracking-widest">Rata-rata progres</p><p class="mt-2 text-3xl font-black">{{ $this->periodProgress['average'] }}%</p></article>
            <article class="border-2 border-black bg-[#b8edce] p-4 shadow-[4px_4px_0_#171717]"><p class="text-xs font-black uppercase tracking-widest">Progres penuh</p><p class="mt-2 text-3xl font-black">{{ $this->periodProgress['completed'] }}</p></article>
        </section>

        <section class="grid gap-5 xl:grid-cols-[1fr_280px]">
            <div class="min-w-0 space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3 border-2 border-black bg-white p-3 shadow-[4px_4px_0_#171717]">
                    <div class="flex items-center gap-2"><button type="button" wire:click="movePeriod(-1)" aria-label="Periode sebelumnya" class="border-2 border-black px-3 py-2 font-black">←</button><button type="button" wire:click="movePeriod(1)" aria-label="Periode berikutnya" class="border-2 border-black px-3 py-2 font-black">→</button><h2 class="ms-2 text-xl font-black">{{ CarbonImmutable::parse($currentDate)->translatedFormat($viewMode === 'month' ? 'F Y' : 'd M Y') }}</h2></div>
                    <div class="flex flex-wrap gap-2">@foreach(['month' => 'Bulan', 'week' => 'Minggu', 'day' => 'Hari', 'agenda' => 'Agenda'] as $mode => $label)<button type="button" wire:click="$set('viewMode', '{{ $mode }}')" aria-pressed="{{ $viewMode === $mode ? 'true' : 'false' }}" class="border-2 border-black px-3 py-2 text-sm font-bold {{ $viewMode === $mode ? 'bg-[#facc15]' : 'bg-white' }}">{{ $label }}</button>@endforeach</div>
                </div>

                <div class="flex flex-wrap gap-2"><label class="sr-only" for="calendar-category">Filter kategori</label><select id="calendar-category" wire:model.live="categoryFilter" class="border-2 border-black bg-white px-3 py-2 text-sm"><option value="">Semua kategori</option>@foreach($this->categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select><label class="sr-only" for="calendar-assigner">Filter pemberi tugas</label><select id="calendar-assigner" wire:model.live="assignerFilter" class="border-2 border-black bg-white px-3 py-2 text-sm"><option value="">Semua pemberi tugas</option>@foreach($this->assigners as $assigner)<option value="{{ $assigner->id }}">{{ $assigner->name }}</option>@endforeach</select></div>

                <div class="grid {{ in_array($viewMode, ['day', 'agenda'], true) ? 'grid-cols-1' : 'grid-cols-7' }} border-2 border-black bg-black gap-px" aria-label="Kalender {{ $viewMode }}">
                    @if($viewMode === 'month')@foreach(['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'] as $weekday)<div class="bg-[#facc15] px-2 py-2 text-center text-xs font-black uppercase">{{ $weekday }}</div>@endforeach@endif
                    @foreach($this->dates as $date)
                        @php($dateObject = CarbonImmutable::parse($date))
                        <div wire:key="calendar-day-{{ $date }}" x-on:dragover.prevent x-on:drop.prevent="$wire.scheduleTask(Number($event.dataTransfer.getData('task-id')), '{{ $date }}', Number($event.dataTransfer.getData('schedule-id')) || null)" class="min-h-28 bg-white p-1.5 sm:min-h-36 sm:p-2 {{ $viewMode === 'month' && $dateObject->month !== CarbonImmutable::parse($currentDate)->month ? 'bg-[#eeeae0] text-[#777]' : '' }}">
                            <button type="button" wire:click="selectDate('{{ $date }}')" class="mb-2 flex size-7 items-center justify-center border-2 border-transparent text-xs font-black hover:border-black {{ $date === today()->toDateString() ? 'bg-[#facc15] text-black' : '' }}" aria-label="Buat tugas pada {{ $dateObject->translatedFormat('d F Y') }}">{{ $dateObject->day }}</button>
                            <div class="space-y-1">
                                @foreach(($this->events[$date] ?? []) as $event)
                                    <div wire:key="{{ $event['id'] }}">
                                        <button type="button" wire:click="showTask({{ $event['task_id'] }})" @if($event['kind'] === 'schedule') draggable="true" x-on:dragstart="$event.dataTransfer.setData('task-id', '{{ $event['task_id'] }}'); $event.dataTransfer.setData('schedule-id', '{{ $event['schedule_id'] }}')" @endif title="{{ $event['kind'] === 'deadline' ? 'Deadline resmi' : 'Jadwal pengerjaan' }} · {{ $event['title'] }} · Klik untuk detail" class="block w-full truncate border border-black px-1 py-1 text-left text-[10px] leading-tight text-black sm:text-xs" style="background-color: {{ $event['color'] }}"><span class="font-black">{{ $event['kind'] === 'deadline' ? ($event['overdue'] ? 'OVERDUE' : ($event['urgent'] ? 'URGENT' : 'DEADLINE')) : $event['time'] }}</span> {{ $event['title'] }}</button>
                                        @if($event['kind'] === 'schedule')
                                            <details class="border-x border-b border-black bg-white p-1 text-[10px]">
                                                <summary class="cursor-pointer font-bold underline">Pindahkan jadwal</summary>
                                                <label class="mt-1 block font-bold" for="schedule-date-{{ $event['schedule_id'] }}">Tanggal baru</label>
                                                <input id="schedule-date-{{ $event['schedule_id'] }}" type="date" value="{{ $event['date'] }}" wire:model="scheduleDates.{{ $event['schedule_id'] }}" class="mt-1 w-full border border-black px-1 py-1 text-[10px]">
                                                @error('date')<span class="block font-bold text-red-700">{{ $message }}</span>@enderror
                                                <button type="button" wire:click="moveScheduleFromCalendar({{ $event['task_id'] }}, {{ $event['schedule_id'] }})" class="mt-1 border border-black bg-[#facc15] px-1 py-1 font-black">Simpan</button>
                                            </details>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="flex flex-wrap gap-4 text-xs font-bold"><span><i class="mr-1 inline-block size-3 border border-black bg-[#b8d8ff]"></i> Jadwal pengerjaan</span><span><i class="mr-1 inline-block size-3 border border-black bg-[#ff8c78]"></i> Deadline resmi</span><span class="text-[#555]">Seret tugas yang sudah dijadwalkan ke tanggal lain. Deadline tidak ikut berubah.</span></div>
            </div>

            <aside class="space-y-4">
                @if($selectedDate)
                    <section class="border-2 border-black bg-[#facc15] p-4 shadow-[4px_4px_0_#171717]"><p class="text-xs font-black uppercase tracking-widest">Buat pada {{ CarbonImmutable::parse($selectedDate)->translatedFormat('d F') }}</p><form wire:submit="createTaskOnDate" class="mt-3 space-y-2"><label for="new-calendar-task" class="sr-only">Judul tugas</label><input id="new-calendar-task" wire:model="newTaskTitle" required maxlength="255" placeholder="Judul tugas baru" class="w-full border-2 border-black bg-white px-3 py-2">@error('newTaskTitle')<p class="text-xs font-bold text-red-800">{{ $message }}</p>@enderror<button class="w-full border-2 border-black bg-white px-3 py-2 text-sm font-black">Buat + jadwalkan</button></form></section>
                @endif
                <section class="border-2 border-black bg-white p-4 shadow-[4px_4px_0_#171717]"><p class="text-xs font-black uppercase tracking-widest">Belum dijadwalkan</p><p class="mt-1 text-xs text-[#555]">Seret tugas ke tanggal pada kalender.</p><div class="mt-3 space-y-2">@forelse($this->unscheduledTasks as $task)<div wire:key="unscheduled-{{ $task->id }}" draggable="true" x-on:dragstart="$event.dataTransfer.setData('task-id', '{{ $task->id }}'); $event.dataTransfer.setData('schedule-id', '')" class="cursor-grab border-2 border-black bg-[#eeeae0] p-2 text-sm font-bold active:cursor-grabbing">{{ $task->title }}<span class="mt-1 block text-[10px] font-normal uppercase">{{ $task->priority }} · deadline {{ $task->due_at?->format('d M') ?? 'belum ada' }}</span></div>@empty<p class="py-3 text-sm text-[#555]">Semua tugas aktif sudah punya jadwal.</p>@endforelse</div></section>
                @if($this->selectedTask)
                    <section aria-labelledby="calendar-task-detail" class="border-2 border-black bg-white p-4 shadow-[4px_4px_0_#171717]">
                        <div class="flex items-start justify-between gap-3"><div><p class="text-xs font-black uppercase tracking-widest text-[#666]">Detail tugas</p><h3 id="calendar-task-detail" class="mt-1 text-xl font-black">{{ $this->selectedTask->title }}</h3></div><button type="button" wire:click="$set('selectedTaskId', null)" aria-label="Tutup detail tugas" class="border-2 border-black px-2 py-1 text-xs font-black">Tutup</button></div>
                        @if($this->selectedTask->description)<p class="mt-3 whitespace-pre-line text-sm leading-6">{{ $this->selectedTask->description }}</p>@endif
                        <div class="mt-3 grid grid-cols-2 gap-2 text-xs"><p class="border border-black p-2"><strong>Status</strong><br>{{ str_replace('_', ' ', $this->selectedTask->status) }}</p><p class="border border-black p-2"><strong>Prioritas</strong><br>{{ $this->selectedTask->priority }}</p><p class="border border-black p-2"><strong>Mulai</strong><br>{{ $this->selectedTask->starts_at?->format('d M Y, H:i') ?? 'Belum dijadwalkan' }}</p><p class="border border-black p-2"><strong>Deadline resmi</strong><br>{{ $this->selectedTask->due_at?->format('d M Y, H:i') ?? 'Belum ada' }}</p></div>
                        <div class="mt-3"><div class="flex justify-between text-xs font-bold"><span>Progres pekerjaan</span><span>{{ $this->selectedTask->computedProgress() }}%</span></div><progress value="{{ $this->selectedTask->computedProgress() }}" max="100" aria-label="Progres {{ $this->selectedTask->title }}" class="mt-1 h-3 w-full accent-[#facc15]">{{ $this->selectedTask->computedProgress() }}%</progress></div>
                        @if($this->selectedTask->category || $this->selectedTask->course || $this->selectedTask->semester || $this->selectedTask->assigners->isNotEmpty())<p class="mt-3 text-xs text-[#555]">{{ collect([$this->selectedTask->category?->name, $this->selectedTask->course?->name, $this->selectedTask->semester?->name, $this->selectedTask->assigners->pluck('name')->join(', ')])->filter()->join(' · ') }}</p>@endif
                        @if($this->selectedTask->schedules->isNotEmpty())<div class="mt-3 border-t border-black pt-3"><p class="text-xs font-black uppercase">Jadwal pengerjaan</p><ul class="mt-1 space-y-1">@foreach($this->selectedTask->schedules as $schedule)<li wire:key="calendar-task-schedule-{{ $schedule->id }}" class="text-xs">{{ $schedule->starts_at->format('d M Y, H:i') }}–{{ $schedule->ends_at->format('H:i') }}{{ $schedule->label ? ' · '.$schedule->label : '' }}</li>@endforeach</ul></div>@endif
                        @if($this->selectedTask->items->isNotEmpty())<ul class="mt-3 space-y-1 border-t border-black pt-3">@foreach($this->selectedTask->items as $item)<li wire:key="calendar-task-item-{{ $item->id }}" class="flex items-start gap-2 text-sm"><span aria-hidden="true" class="font-black">{{ $item->is_completed ? '✓' : '○' }}</span><span class="{{ $item->is_completed ? 'line-through text-[#777]' : '' }}">{{ $item->title }}</span></li>@endforeach</ul>@endif
                        @if($this->selectedTask->children->isNotEmpty())<div class="mt-3 border-t border-black pt-3"><p class="text-xs font-black uppercase">Subtask dan milestone</p><ul class="mt-1 space-y-2">@foreach($this->selectedTask->children as $child)<li wire:key="calendar-task-child-{{ $child->id }}" class="flex justify-between gap-2 text-xs"><span>{{ $child->title }}</span><span class="shrink-0 font-bold">{{ $child->computedProgress() }}%</span></li>@endforeach</ul></div>@endif
                        @if($this->selectedTask->files->isNotEmpty())<ul class="mt-3 space-y-1 border-t border-black pt-3">@foreach($this->selectedTask->files as $file)<li wire:key="calendar-task-file-{{ $file->id }}"><a class="break-all text-xs font-bold underline" href="{{ route('files.download', $file) }}">{{ $file->original_name }}</a></li>@endforeach</ul>@endif
                        @if($this->selectedTask->links->isNotEmpty())<ul class="mt-3 space-y-1 border-t border-black pt-3">@foreach($this->selectedTask->links as $link)<li wire:key="calendar-task-link-{{ $link->id }}"><a class="break-all text-xs font-bold underline" href="{{ $link->url }}" target="_blank" rel="noopener noreferrer">{{ $link->label }}</a></li>@endforeach</ul>@endif
                        @if($this->selectedTask->taskNotes->isNotEmpty())<ul class="mt-3 space-y-2 border-t border-black pt-3">@foreach($this->selectedTask->taskNotes as $note)<li wire:key="calendar-task-note-{{ $note->id }}" class="whitespace-pre-line text-xs">{{ $note->body }}</li>@endforeach</ul>@endif
                    </section>
                @endif
                <a href="{{ route('tasks.index') }}" wire:navigate class="block border-2 border-black bg-[#b8d8ff] px-4 py-3 text-sm font-black shadow-[4px_4px_0_#171717]">Buka pengelolaan tugas →</a>
            </aside>
        </section>
    </div>
</div>

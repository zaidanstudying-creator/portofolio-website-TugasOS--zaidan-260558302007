<x-layouts::app :title="__('Dashboard')">
    <div class="min-h-full bg-[#f7f4ec] px-4 py-8 sm:px-8 lg:px-10">
        <div class="mx-auto max-w-7xl space-y-7">
            <header class="flex flex-col justify-between gap-5 border-b-2 border-black pb-6 sm:flex-row sm:items-end">
                <div><p class="text-xs font-black uppercase tracking-[0.24em] text-[#555]">TUGAS!OS / Overview</p><h1 class="mt-2 text-4xl font-black tracking-tight sm:text-5xl">Halo, {{ auth()->user()->name }}.</h1><p class="mt-3 text-sm text-[#555]">{{ now()->translatedFormat('l, d F Y') }} · Ini ringkasan ruang kerjamu.</p></div>
                <a href="{{ route('tasks.index') }}" wire:navigate class="inline-flex border-2 border-black bg-[#facc15] px-4 py-3 text-sm font-black shadow-[4px_4px_0_#171717] transition hover:translate-x-0.5 hover:translate-y-0.5 hover:shadow-none">+ Catat tugas</a>
            </header>

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Ringkasan tugas">
                @foreach ([['Tugas aktif', $activeTasks, 'bg-white'], ['Selesai', $completedTasks, 'bg-[#b8edce]'], ['Terlambat', $overdueTasks, 'bg-[#ffb7a9]'], ['Prioritas tertinggi', $priorityTasks->first()?->title ?? 'Kosong', 'bg-[#facc15]']] as [$label, $value, $color])
                    <article class="border-2 border-black {{ $color }} p-5 shadow-[4px_4px_0_#171717]"><p class="text-xs font-black uppercase tracking-widest">{{ $label }}</p><p class="mt-3 truncate text-2xl font-black" title="{{ $value }}">{{ $value }}</p></article>
                @endforeach
            </section>

            <section aria-labelledby="period-progress-heading" class="border-2 border-black bg-white p-5 shadow-[5px_5px_0_#171717] sm:p-6">
                <div class="mb-5 flex flex-wrap items-end justify-between gap-3"><div><p class="text-xs font-black uppercase tracking-widest text-[#666]">Penyelesaian pekerjaan</p><h2 id="period-progress-heading" class="mt-1 text-2xl font-black">Progres periode</h2></div><a href="{{ route('calendar') }}" wire:navigate class="text-sm font-bold underline decoration-2 underline-offset-4">Lihat kalender ↗</a></div>
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach($progressPeriods as $label => $progress)
                        <div wire:key="progress-period-{{ $label }}" class="border-2 border-black p-4"><div class="mb-2 flex justify-between gap-2"><span class="font-black">{{ $label }}</span><span class="font-bold">{{ $progress }}%</span></div><progress value="{{ $progress }}" max="100" aria-label="Progres {{ $label }}" class="h-3 w-full accent-[#facc15]">{{ $progress }}%</progress>@if($label === 'Semester')<p class="mt-2 text-xs text-[#555]">{{ $activeSemester?->name ?? 'Pilih semester aktif di Ruang kerja' }}</p>@endif</div>
                    @endforeach
                </div>
            </section>

            <section class="grid gap-5 xl:grid-cols-[1.2fr_0.8fr]">
                <article class="border-2 border-black bg-white p-5 shadow-[5px_5px_0_#171717] sm:p-6">
                    <div class="mb-5 flex items-end justify-between gap-3"><div><p class="text-xs font-black uppercase tracking-widest text-[#666]">{{ now()->subDays(6)->translatedFormat('d M') }} — {{ now()->translatedFormat('d M') }}</p><h2 class="mt-1 text-2xl font-black">Penyelesaian mingguan</h2></div><span class="bg-[#b8d8ff] px-3 py-1 text-xs font-black">Aktivitas</span></div>
                    <div class="flex h-44 items-end gap-2 border-b-2 border-black pb-2" role="img" aria-label="Jumlah tugas yang ditandai selesai setiap hari dalam tujuh hari terakhir">@foreach($weeklyCompletions as $day)<div wire:key="week-chart-{{ $day['label'] }}-{{ $loop->index }}" class="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-2"><span class="text-xs font-bold">{{ $day['count'] }}</span><div class="w-full max-w-12 border-2 border-black bg-[#facc15]" style="height: {{ max(6, (int) ($day['count'] / $maxCompletions * 100)) }}%"></div><span class="text-[10px] font-black uppercase">{{ $day['label'] }}</span></div>@endforeach</div>
                </article>

                <article class="border-2 border-black bg-[#171717] p-5 text-white shadow-[5px_5px_0_#facc15] sm:p-6">
                    <p class="text-xs font-black uppercase tracking-widest text-[#facc15]">Setup / Tahap 01</p><h2 class="mt-1 text-2xl font-black">Fondasi ruang kerja</h2>
                    <div class="mt-5 grid grid-cols-2 gap-3">@foreach ([['Semester', 'semesters'], ['Mata kuliah', 'courses'], ['Kategori', 'categories'], ['Pemberi tugas', 'assigners']] as [$label, $key])<div class="border border-white/40 p-3"><p class="text-xs text-white/70">{{ $label }}</p><p class="mt-1 text-2xl font-black">{{ $foundationCounts[$key] }}</p></div>@endforeach</div>
                    <a href="{{ route('workspace') }}" wire:navigate class="mt-5 inline-block border-2 border-white bg-[#facc15] px-4 py-2.5 text-sm font-black text-black">Kelola ruang kerja →</a>
                </article>
            </section>

            <section class="grid gap-5 lg:grid-cols-2">
                <article class="border-2 border-black bg-white p-5 shadow-[4px_4px_0_#171717] sm:p-6"><div class="mb-4 flex items-end justify-between"><div><p class="text-xs font-black uppercase tracking-widest text-[#666]">Focus Today</p><h2 class="mt-1 text-2xl font-black">Fokus hari ini</h2></div><span class="border-2 border-black bg-[#facc15] px-2 py-1 text-xs font-black">{{ $todayTasks->count() }}</span></div>@forelse($todayTasks as $task)<a wire:key="today-task-{{ $task->id }}" href="{{ route('tasks.index') }}" wire:navigate class="flex items-center justify-between gap-3 border-t-2 border-black py-3"><span class="font-bold">{{ $task->title }}</span><span class="shrink-0 text-xs font-black">{{ $task->schedules->first()?->starts_at?->format('H:i') ?? $task->due_at?->format('H:i') ?? 'Jadwal' }}</span></a>@empty<p class="border-t-2 border-black py-4 text-sm text-[#555]">Belum ada tugas terjadwal untuk hari ini.</p>@endforelse</article>

                <article class="border-2 border-black bg-white p-5 shadow-[4px_4px_0_#171717] sm:p-6"><div class="mb-4 flex items-end justify-between"><div><p class="text-xs font-black uppercase tracking-widest text-[#666]">Deadline tracker</p><h2 class="mt-1 text-2xl font-black">Urgensi tugas</h2></div><a href="{{ route('tasks.index') }}" wire:navigate class="text-sm font-bold underline">Semua →</a></div><div class="max-h-[38rem] space-y-4 overflow-y-auto">@foreach($deadlineGroups as $urgency => $group)<section wire:key="deadline-group-{{ str($urgency)->slug() }}" class="border-t-2 border-black pt-3"><h3 class="flex items-center justify-between gap-3 text-sm font-black uppercase"><span>{{ $urgency }}</span><span class="border border-black px-2 py-0.5">{{ $group->count() }}</span></h3>@forelse($group as $task)<div wire:key="deadline-{{ $urgency }}-{{ $task->id }}" class="flex items-center justify-between gap-3 py-2"><div class="min-w-0"><p class="truncate font-bold">{{ $task->title }}</p><p class="truncate text-xs text-[#555]">{{ $task->course?->name ?? 'Personal' }} · {{ $task->priority }} · progres {{ $task->computedProgress() }}%</p></div><time datetime="{{ $task->due_at->toIso8601String() }}" class="shrink-0 border-2 border-black {{ $urgency === 'Overdue' ? 'bg-[#ff8c78]' : ($urgency === 'Critical' ? 'bg-[#ffd9d1]' : 'bg-[#facc15]') }} px-2 py-1 text-xs font-black">{{ $task->due_at->diffForHumans() }}</time></div>@empty<p class="py-1 text-xs text-[#666]">Tidak ada tugas.</p>@endforelse</section>@endforeach</div></article>
            </section>

            <section aria-labelledby="calendar-preview-heading" class="border-2 border-black bg-[#fff1b8] p-5 shadow-[5px_5px_0_#171717] sm:p-6">
                <div class="mb-4 flex flex-wrap items-end justify-between gap-3"><div><p class="text-xs font-black uppercase tracking-widest text-[#666]">Ringkasan kalender · 7 hari</p><h2 id="calendar-preview-heading" class="mt-1 text-2xl font-black">Agenda terdekat</h2></div><a href="{{ route('calendar') }}" wire:navigate class="text-sm font-bold underline">Buka kalender →</a></div>
                <ul class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    @forelse($calendarPreview as $event)
                        <li wire:key="calendar-preview-{{ $loop->index }}" class="border-2 border-black bg-white p-3"><p class="text-xs font-black uppercase">{{ $event['kind'] }} · {{ $event['priority'] }}</p><p class="mt-1 font-bold">{{ $event['task'] }}</p><time class="mt-1 block text-xs text-[#555]" datetime="{{ $event['at']->toIso8601String() }}">{{ $event['at']->translatedFormat('D, d M · H:i') }}</time></li>
                    @empty
                        <li class="border-2 border-dashed border-black bg-white p-4 text-sm text-[#555] sm:col-span-2 xl:col-span-4">Belum ada jadwal pengerjaan atau deadline dalam tujuh hari ke depan.</li>
                    @endforelse
                </ul>
            </section>

            <section class="grid gap-5 lg:grid-cols-2">
                <article class="border-2 border-black bg-white p-5 shadow-[4px_4px_0_#171717] sm:p-6"><div class="mb-4 flex items-end justify-between"><div><p class="text-xs font-black uppercase tracking-widest text-[#666]">Log perubahan</p><h2 class="mt-1 text-2xl font-black">Aktivitas terakhir</h2></div></div>@forelse($recentActivity as $event)<div wire:key="activity-{{ $event->id }}" class="border-t-2 border-black py-3"><p class="font-bold">{{ $event->task?->title ?? 'Tugas dihapus' }}</p><p class="text-xs text-[#555]">{{ $event->from_status ? str_replace('_', ' ', $event->from_status).' → ' : '' }}{{ str_replace('_', ' ', $event->to_status) }} · {{ $event->created_at->diffForHumans() }}</p></div>@empty<p class="border-t-2 border-black py-4 text-sm text-[#555]">Perubahan status tugas akan tercatat di sini.</p>@endforelse</article>
                <article class="border-2 border-black bg-[#b8d8ff] p-5 shadow-[4px_4px_0_#171717] sm:p-6"><div class="flex items-end justify-between gap-3"><div><p class="text-xs font-black uppercase tracking-widest">Pengingat dashboard</p><h2 class="mt-1 text-2xl font-black">{{ $reminders->isNotEmpty() ? 'Jangan lewatkan ini.' : 'Atur pengingat.' }}</h2></div><a href="{{ route('tasks.index') }}" wire:navigate class="text-sm font-bold underline">Kelola tugas</a></div>@if($reminders->isNotEmpty())<ul class="mt-4 divide-y-2 divide-black border-t-2 border-black">@foreach($reminders as $reminder)<li wire:key="reminder-{{ $reminder->id }}" class="flex items-start justify-between gap-3 py-3"><div class="min-w-0"><p class="truncate font-bold">{{ $reminder->task->title }}</p>@if($reminder->message)<p class="truncate text-xs">{{ $reminder->message }}</p>@endif<time datetime="{{ $reminder->remind_at->toIso8601String() }}" class="text-xs {{ $reminder->remind_at->isPast() ? 'font-black text-red-800' : 'text-[#444]' }}">{{ $reminder->remind_at->diffForHumans() }} · {{ $reminder->remind_at->format('d M, H:i') }}</time></div><form method="POST" action="{{ route('reminders.dismiss', $reminder) }}">@csrf<button class="shrink-0 border-2 border-black bg-white px-2 py-1 text-xs font-black">Selesai</button></form></li>@endforeach</ul>@else<p class="mt-3 text-sm leading-6">Tambahkan waktu pengingat saat membuat atau mengedit tugas agar muncul di sini.</p>@endif</article>
            </section>

            <section class="border-2 border-black bg-white p-5 shadow-[4px_4px_0_#171717] sm:p-6" aria-labelledby="integration-status-heading">
                <div class="flex flex-wrap items-end justify-between gap-3"><div><p class="text-xs font-black uppercase tracking-widest text-[#666]">Konektor eksternal</p><h2 id="integration-status-heading" class="mt-1 text-2xl font-black">Status sinkronisasi</h2></div><a href="{{ route('integrations.index') }}" wire:navigate class="text-sm font-bold underline">Kelola integrasi →</a></div>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    @foreach ([['Google Calendar & Drive', 'google'], ['GitHub', 'github']] as [$label, $provider])
                        @php($connection = $integrationConnections->firstWhere('provider', $provider))
                        <article wire:key="dashboard-integration-{{ $provider }}" class="border-2 border-black p-3">
                            <div class="flex items-start justify-between gap-3"><p class="font-black">{{ $label }}</p><span class="shrink-0 border border-black px-2 py-1 text-[10px] font-black uppercase {{ $connection?->status === 'connected' && ! $connection?->last_error ? 'bg-[#b8edce]' : ($connection?->last_error ? 'bg-[#ffb7a9]' : 'bg-[#eeeae0]') }}">{{ $connection?->last_error ? 'Perlu perhatian' : ($connection?->status === 'connected' ? 'Terhubung' : 'Belum terhubung') }}</span></div>
                            <p class="mt-2 text-xs text-[#555]">{{ $connection?->last_synced_at ? 'Sync terakhir '.$connection->last_synced_at->diffForHumans() : 'Belum ada sinkronisasi' }}</p>
                        </article>
                    @endforeach
                </div>
            </section>
        </div>
    </div>
</x-layouts::app>

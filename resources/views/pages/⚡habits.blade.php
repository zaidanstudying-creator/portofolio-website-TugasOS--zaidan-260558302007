<?php

use App\Models\Habit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $name = '';

    public string $description = '';

    public string $targetPerWeek = '5';

    public string $checkInNote = '';

    public string $periodDays = '30';

    public function updatedPeriodDays(): void
    {
        abort_unless(in_array($this->periodDays, ['7', '30', '90'], true), 422);

        unset($this->habits);
    }

    #[Computed]
    public function habits(): Collection
    {
        return auth()->user()->habits()
            ->where('is_active', true)
            ->with('entries')
            ->orderBy('name')
            ->get();
    }

    public function createHabit(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'targetPerWeek' => ['required', 'integer', 'min:1', 'max:7'],
        ]);

        auth()->user()->habits()->create([
            'name' => $validated['name'],
            'description' => $validated['description'],
            'target_per_week' => $validated['targetPerWeek'],
        ]);

        $this->reset(['name', 'description']);
        $this->targetPerWeek = '5';
        unset($this->habits);
        session()->flash('status', 'Habit berhasil dibuat.');
    }

    public function checkIn(int $habitId): void
    {
        $validated = $this->validate([
            'checkInNote' => ['nullable', 'string', 'max:500'],
        ]);

        $checkedIn = DB::transaction(function () use ($habitId, $validated): bool {
            $habit = auth()->user()->habits()->lockForUpdate()->whereKey($habitId)->firstOrFail();
            $entry = $habit->entries()->whereDate('entry_date', today())->lockForUpdate()->first();

            if ($entry) {
                $entry->delete();

                return false;
            }

            $habit->entries()->create([
                'entry_date' => today()->toDateString(),
                'note' => filled($validated['checkInNote']) ? trim($validated['checkInNote']) : null,
            ]);

            return true;
        });

        if (! $checkedIn) {
            session()->flash('status', 'Check-in hari ini dibatalkan.');
        } else {
            $this->checkInNote = '';
            session()->flash('status', 'Check-in hari ini dicatat.');
        }

        unset($this->habits);
    }

    public function archiveHabit(int $habitId): void
    {
        auth()->user()->habits()->findOrFail($habitId)->update(['is_active' => false]);
        unset($this->habits);
        session()->flash('status', 'Habit dipindahkan dari daftar aktif.');
    }
};
?>

<div class="min-h-screen bg-[#f7f4ec] px-4 py-8 text-[#171717] sm:px-8 lg:px-10"><div class="mx-auto max-w-7xl space-y-7">
    <header class="flex flex-col justify-between gap-5 border-b-2 border-black pb-6 sm:flex-row sm:items-end"><div><p class="text-xs font-black uppercase tracking-[0.24em] text-[#555]">TUGAS!OS / Habit Tracker</p><h1 class="mt-2 text-4xl font-black sm:text-5xl">Kebiasaan tumbuh sedikit-sedikit.</h1><p class="mt-3 max-w-xl text-sm text-[#555]">Catat rutinitas, lihat streak, dan ukur konsistensi pada periode yang kamu pilih.</p></div><div class="border-2 border-black bg-[#b8edce] px-4 py-3 text-sm font-bold shadow-[4px_4px_0_#171717]">TAHAP 05 — HABIT TRACKER</div></header>
    @if(session('status'))<div role="status" aria-live="polite" class="border-2 border-black bg-[#b8edce] px-4 py-3 font-bold">{{ session('status') }}</div>@endif
    <section class="border-2 border-black bg-white p-5 shadow-[5px_5px_0_#171717]"><h2 class="text-2xl font-black">Habit baru</h2><form wire:submit="createHabit" class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"><label class="grid gap-1 text-sm font-bold sm:col-span-2">Nama kebiasaan<input wire:model="name" required maxlength="120" placeholder="Baca materi 20 menit" class="border-2 border-black px-3 py-2 font-normal">@error('name')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label><label class="grid gap-1 text-sm font-bold">Target per minggu<input wire:model="targetPerWeek" type="number" min="1" max="7" required class="border-2 border-black px-3 py-2 font-normal">@error('targetPerWeek')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label><label class="grid gap-1 text-sm font-bold">Catatan singkat<input wire:model="description" maxlength="1000" class="border-2 border-black px-3 py-2 font-normal"></label><button class="border-2 border-black bg-[#facc15] px-4 py-2 text-sm font-black shadow-[3px_3px_0_#171717] sm:col-span-2 lg:col-span-4">Tambah habit</button></form></section>
    <section><div class="mb-4 flex flex-wrap items-end justify-between gap-3"><div><p class="text-xs font-black uppercase tracking-widest text-[#666]">Konsistensi harian</p><h2 class="text-2xl font-black">Habit aktif</h2></div><div class="flex flex-wrap items-end gap-3"><label class="grid gap-1 border-2 border-black bg-white px-3 py-2 text-sm font-bold">Periode statistik<select wire:model.live="periodDays" class="border-b-2 border-black py-1 font-normal"><option value="7">7 hari</option><option value="30">30 hari</option><option value="90">90 hari</option></select></label><label class="flex items-center gap-2 border-2 border-black bg-white px-3 py-2 text-sm font-bold">Catatan check-in hari ini<input wire:model="checkInNote" maxlength="500" placeholder="Opsional" class="max-w-44 border-b-2 border-black px-2 py-1 font-normal"></label></div></div>
        <div class="grid gap-4 lg:grid-cols-2">@forelse($this->habits as $habit)@php($todayEntry = $habit->entries->first(fn ($entry): bool => $entry->entry_date->isToday()))<article wire:key="habit-{{ $habit->id }}" class="border-2 border-black bg-white p-5 shadow-[4px_4px_0_#171717]"><div class="flex items-start justify-between gap-3"><div><h3 class="text-xl font-black">{{ $habit->name }}</h3>@if($habit->description)<p class="mt-1 text-sm text-[#555]">{{ $habit->description }}</p>@endif</div><button type="button" wire:click="archiveHabit({{ $habit->id }})" wire:confirm="Arsipkan habit ini?" class="border-2 border-black px-2 py-1 text-xs font-bold">Arsipkan</button></div><div class="mt-4 grid grid-cols-3 gap-2"><div class="border-2 border-black bg-[#facc15] p-3"><p class="text-[10px] font-black uppercase">Streak</p><p class="mt-1 text-2xl font-black">{{ $habit->currentStreak() }} <span class="text-xs">hari</span></p></div><div class="border-2 border-black bg-[#b8d8ff] p-3"><p class="text-[10px] font-black uppercase">Konsistensi {{ $periodDays }} hari</p><p class="mt-1 text-2xl font-black">{{ $habit->consistency((int) $periodDays) }}%</p></div><div class="border-2 border-black bg-[#b8edce] p-3"><p class="text-[10px] font-black uppercase">Target mingguan</p><p class="mt-1 text-2xl font-black">{{ $habit->target_per_week }}×</p></div></div><div class="mt-4 flex items-center justify-between gap-3"><p class="text-sm">{{ $todayEntry ? 'Sudah dicatat hari ini' : 'Belum dicatat hari ini' }}</p><button type="button" wire:click="checkIn({{ $habit->id }})" class="border-2 border-black px-4 py-2 text-sm font-black {{ $todayEntry ? 'bg-[#eeeae0]' : 'bg-[#facc15]' }}">{{ $todayEntry ? 'Batalkan' : 'Check-in hari ini' }}</button></div><div class="mt-4 grid grid-cols-7 gap-1" aria-label="Check-in tujuh hari terakhir">@foreach(range(6, 0) as $daysAgo)@php($date = today()->subDays($daysAgo))@php($didComplete = $habit->entries->contains(fn ($entry): bool => $entry->entry_date->isSameDay($date)))<div wire:key="habit-{{ $habit->id }}-day-{{ $date->toDateString() }}" title="{{ $date->translatedFormat('d M') }}" class="h-3 border border-black {{ $didComplete ? 'bg-[#171717]' : 'bg-white' }}"></div>@endforeach</div></article>@empty<div class="border-2 border-dashed border-black bg-white p-8 text-center lg:col-span-2"><p class="font-black">Belum ada habit aktif.</p><p class="mt-1 text-sm text-[#555]">Mulai dari satu kebiasaan kecil yang ingin kamu jaga.</p></div>@endforelse</div>
    </section>
</div></div>

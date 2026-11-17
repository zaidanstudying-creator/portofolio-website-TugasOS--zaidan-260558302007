<?php

use App\Models\RecurrenceRule;
use App\Services\RecurringTaskGenerator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $templateTaskId = '';

    public string $frequency = 'weekly';

    public string $interval = '1';

    public array $weekdays = [];

    public string $startsOn = '';

    public string $endsOn = '';

    public function mount(): void
    {
        $this->startsOn = today()->toDateString();
    }

    #[Computed]
    public function templates(): Collection
    {
        return auth()->user()->tasks()->whereNull('parent_task_id')->whereNull('archived_at')->orderBy('title')->get();
    }

    #[Computed]
    public function rules(): Collection
    {
        return auth()->user()->recurrenceRules()->with('templateTask')->latest()->get();
    }

    public function saveRule(): void
    {
        $validated = $this->validate([
            'templateTaskId' => [
                'required',
                Rule::exists('tasks', 'id')->where('user_id', auth()->id())->whereNull('parent_task_id')->whereNull('archived_at'),
                Rule::unique('recurrence_rules', 'template_task_id'),
            ],
            'frequency' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'interval' => ['required', 'integer', 'min:1', 'max:12'],
            'weekdays' => ['array'],
            'weekdays.*' => [Rule::in(['0', '1', '2', '3', '4', '5', '6', 0, 1, 2, 3, 4, 5, 6])],
            'startsOn' => ['required', 'date'],
            'endsOn' => ['nullable', 'date', 'after_or_equal:startsOn'],
        ]);

        auth()->user()->recurrenceRules()->create([
            'template_task_id' => $validated['templateTaskId'],
            'frequency' => $validated['frequency'],
            'interval' => $validated['interval'],
            'weekdays' => array_map('intval', $validated['weekdays']),
            'starts_on' => $validated['startsOn'],
            'ends_on' => filled($validated['endsOn']) ? $validated['endsOn'] : null,
            'next_run_at' => $validated['startsOn'],
            'is_active' => true,
        ]);

        $this->reset(['templateTaskId', 'frequency', 'interval', 'weekdays', 'endsOn']);
        $this->frequency = 'weekly';
        $this->interval = '1';
        $this->startsOn = today()->toDateString();
        unset($this->rules);
        session()->flash('status', 'Aturan tugas berulang disimpan.');
    }

    public function generateNow(): void
    {
        $count = app(RecurringTaskGenerator::class)->generate(userId: (int) auth()->id());
        unset($this->rules);
        session()->flash('status', "Pembuatan selesai: {$count} kejadian baru.");
    }

    public function toggleRule(int $ruleId): void
    {
        $rule = auth()->user()->recurrenceRules()->findOrFail($ruleId);
        $rule->update(['is_active' => ! $rule->is_active]);
        unset($this->rules);
    }

    public function deleteRule(int $ruleId): void
    {
        auth()->user()->recurrenceRules()->findOrFail($ruleId)->delete();
        unset($this->rules);
        session()->flash('status', 'Aturan berulang dihapus. Kejadian yang sudah dibuat tetap tersimpan.');
    }
};
?>

<div class="min-h-screen bg-[#f7f4ec] px-4 py-8 text-[#171717] sm:px-8 lg:px-10"><div class="mx-auto max-w-7xl space-y-7">
    <header class="flex flex-col justify-between gap-5 border-b-2 border-black pb-6 sm:flex-row sm:items-end"><div><p class="text-xs font-black uppercase tracking-[0.24em] text-[#555]">TUGAS!OS / Recurrence</p><h1 class="mt-2 text-4xl font-black sm:text-5xl">Rutinitas, terulang otomatis.</h1><p class="mt-3 max-w-xl text-sm text-[#555]">Aturan menghasilkan kejadian hingga 30 hari ke depan. Checklist baru dimulai dari awal setiap kejadian.</p></div><div class="border-2 border-black bg-[#facc15] px-4 py-3 text-sm font-bold shadow-[4px_4px_0_#171717]">TAHAP 05 — RECURRENCE</div></header>
    @if(session('status'))<div role="status" class="border-2 border-black bg-[#b8edce] px-4 py-3 font-bold">{{ session('status') }}</div>@endif
    <div class="grid gap-5 lg:grid-cols-[0.8fr_1.2fr]">
        <section class="border-2 border-black bg-white p-5 shadow-[5px_5px_0_#171717]"><h2 class="text-2xl font-black">Aturan baru</h2><form wire:submit="saveRule" class="mt-4 space-y-4">
            <label class="grid gap-1 text-sm font-bold">Tugas acuan<select wire:model="templateTaskId" required class="border-2 border-black px-3 py-2 font-normal"><option value="">Pilih tugas utama</option>@foreach($this->templates as $task)<option value="{{ $task->id }}">{{ $task->title }}</option>@endforeach</select>@error('templateTaskId')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label>
            <div class="grid gap-3 sm:grid-cols-2"><label class="grid gap-1 text-sm font-bold">Frekuensi<select wire:model.live="frequency" class="border-2 border-black px-3 py-2 font-normal"><option value="daily">Harian</option><option value="weekly">Mingguan</option><option value="monthly">Bulanan</option></select></label><label class="grid gap-1 text-sm font-bold">Setiap<input wire:model="interval" type="number" min="1" max="12" class="border-2 border-black px-3 py-2 font-normal"><span class="text-xs font-normal">{{ $frequency === 'daily' ? 'hari' : ($frequency === 'weekly' ? 'minggu' : 'bulan') }}</span></label></div>
            @if($frequency === 'weekly')<fieldset><legend class="mb-2 text-sm font-bold">Ulangi pada (opsional)</legend><div class="flex flex-wrap gap-2">@foreach(['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'] as $weekday => $label)<label class="flex items-center gap-1 border border-black px-2 py-1 text-xs"><input type="checkbox" value="{{ $weekday }}" wire:model="weekdays" class="accent-black">{{ $label }}</label>@endforeach</div></fieldset>@endif
            <div class="grid gap-3 sm:grid-cols-2"><label class="grid gap-1 text-sm font-bold">Mulai<input wire:model="startsOn" type="date" required class="border-2 border-black px-3 py-2 font-normal"></label><label class="grid gap-1 text-sm font-bold">Berakhir (opsional)<input wire:model="endsOn" type="date" class="border-2 border-black px-3 py-2 font-normal">@error('endsOn')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label></div>
            <button class="w-full border-2 border-black bg-[#facc15] px-4 py-3 font-black shadow-[3px_3px_0_#171717]">Simpan aturan</button>
        </form></section>
        <section class="space-y-4"><div class="flex flex-wrap items-end justify-between gap-3"><div><p class="text-xs font-black uppercase tracking-widest text-[#666]">Daftar pengulangan</p><h2 class="text-2xl font-black">Aturan aktif & kejadian</h2></div><button type="button" wire:click="generateNow" wire:loading.attr="disabled" class="border-2 border-black bg-[#b8d8ff] px-4 py-2 text-sm font-black">Buat kejadian 30 hari</button></div>
            @forelse($this->rules as $rule)<article wire:key="recurrence-{{ $rule->id }}" class="border-2 border-black bg-white p-4 shadow-[4px_4px_0_#171717]"><div class="flex flex-wrap items-start justify-between gap-3"><div><h3 class="font-black">{{ $rule->templateTask->title }}</h3><p class="mt-1 text-sm text-[#555]">{{ ucfirst($rule->frequency) }} · setiap {{ $rule->interval }} {{ $rule->frequency === 'daily' ? 'hari' : ($rule->frequency === 'weekly' ? 'minggu' : 'bulan') }} · berikutnya {{ $rule->next_run_at->format('d M Y') }}</p></div><div class="flex gap-2"><button type="button" wire:click="toggleRule({{ $rule->id }})" class="border-2 border-black px-3 py-1 text-xs font-bold">{{ $rule->is_active ? 'Jeda' : 'Aktifkan' }}</button><button type="button" wire:click="deleteRule({{ $rule->id }})" wire:confirm="Hapus aturan ini? Kejadian yang sudah dibuat akan tetap ada." class="border-2 border-black px-3 py-1 text-xs font-bold hover:bg-[#ff8c78]">Hapus</button></div></div><p class="mt-2 text-xs">Sudah dibuat: {{ $rule->tasks()->count() }} kejadian · {{ $rule->is_active ? 'Aktif' : 'Dijeda' }}</p></article>@empty<div class="border-2 border-dashed border-black bg-white p-8 text-center"><p class="font-black">Belum ada aturan berulang.</p><p class="mt-1 text-sm text-[#555]">Pilih tugas acuan untuk mulai membuat rutinitas.</p></div>@endforelse
        </section>
    </div>
</div></div>

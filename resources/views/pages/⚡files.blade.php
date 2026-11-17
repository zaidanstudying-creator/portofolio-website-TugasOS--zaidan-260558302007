<?php

use App\Services\TaskFilePolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public string $taskId = '';

    public string $kind = 'reference';

    public $upload = null;

    /** @return array{max_size_mb: int, max_size_kb: int, server_max_size_mb: int, server_max_size_kb: int, extensions: list<string>} */
    #[Computed]
    public function uploadPolicy(): array
    {
        return app(TaskFilePolicy::class)->forUser(auth()->user());
    }

    public string $linkTaskId = '';

    public string $linkLabel = '';

    public string $linkUrl = '';

    public string $linkKind = 'reference';

    public string $noteTaskId = '';

    public string $noteBody = '';

    #[Computed]
    public function tasks(): Collection
    {
        return auth()->user()->tasks()->orderBy('title')->get();
    }

    #[Computed]
    public function files(): Collection
    {
        return auth()->user()->taskFiles()->with('task')->latest()->get();
    }

    #[Computed]
    public function links(): SupportCollection
    {
        return auth()->user()->tasks()->with('links.task')->get()->flatMap->links;
    }

    #[Computed]
    public function notes(): SupportCollection
    {
        return auth()->user()->tasks()->with('taskNotes.task')->get()->flatMap->taskNotes;
    }

    public function saveFile(): void
    {
        $uploadPolicy = $this->uploadPolicy;
        $allowedExtensions = implode(',', $uploadPolicy['extensions']);
        $mimeExtensions = implode(',', app(TaskFilePolicy::class)->mimeValidationExtensions($uploadPolicy['extensions']));

        $validated = $this->validate([
            'taskId' => ['required', 'integer', Rule::exists('tasks', 'id')->where('user_id', auth()->id())],
            'kind' => ['required', Rule::in(['instruction', 'reference', 'work', 'revision', 'final', 'proof'])],
            'upload' => ['required', 'file', 'mimes:'.$mimeExtensions, 'extensions:'.$allowedExtensions, 'max:'.$uploadPolicy['max_size_kb']],
        ]);

        $task = auth()->user()->tasks()->findOrFail($validated['taskId']);
        $path = $this->upload->store('task-files/'.$task->user_id.'/'.$task->id, 'local');

        if (! is_string($path) || $path === '' || ! Storage::disk('local')->exists($path)) {
            $this->addError('upload', 'File gagal disimpan. Periksa penyimpanan lalu coba lagi.');

            return;
        }

        try {
            DB::transaction(function () use ($validated, $path): void {
                $task = auth()->user()->tasks()->lockForUpdate()->whereKey($validated['taskId'])->firstOrFail();
                $version = (int) $task->files()->where('kind', $validated['kind'])->max('version') + 1;

                if ($validated['kind'] === 'final') {
                    $task->files()->where('kind', 'final')->update(['is_final' => false]);
                }

                $task->files()->create([
                    'user_id' => auth()->id(),
                    'kind' => $validated['kind'],
                    'original_name' => $this->upload->getClientOriginalName(),
                    'disk' => 'local',
                    'path' => $path,
                    'mime_type' => $this->upload->getMimeType(),
                    'byte_size' => $this->upload->getSize(),
                    'version' => $version,
                    'is_final' => $validated['kind'] === 'final',
                    'sha256' => hash_file('sha256', $this->upload->getRealPath()),
                ]);
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }

        $this->reset(['taskId', 'kind', 'upload']);
        $this->kind = 'reference';
        unset($this->files);
        session()->flash('status', 'File tersimpan secara privat.');
    }

    public function deleteFile(int $fileId): void
    {
        $fileLocation = DB::transaction(function () use ($fileId): ?array {
            $file = auth()->user()->taskFiles()->lockForUpdate()->whereKey($fileId)->firstOrFail();

            if (DB::table('task_submissions')->where('proof_file_id', $file->id)->exists()) {
                $this->addError('file', 'File ini dipakai sebagai bukti pengumpulan dan tidak dapat dihapus.');

                return null;
            }

            $location = ['disk' => $file->disk, 'path' => $file->path];
            $file->delete();

            return $location;
        });

        if ($fileLocation === null) {
            return;
        }

        Storage::disk($fileLocation['disk'])->delete($fileLocation['path']);
        unset($this->files);
        session()->flash('status', 'File dihapus dari vault.');
    }

    public function saveLink(): void
    {
        $validated = $this->validate([
            'linkTaskId' => ['required', 'integer', Rule::exists('tasks', 'id')->where('user_id', auth()->id())],
            'linkLabel' => ['required', 'string', 'max:120'],
            'linkUrl' => ['required', 'url', 'max:2048'],
            'linkKind' => ['required', Rule::in(['reference', 'repository', 'commit', 'pull_request'])],
        ]);

        if (! in_array(parse_url($validated['linkUrl'], PHP_URL_SCHEME), ['http', 'https'], true)) {
            $this->addError('linkUrl', 'Link harus memakai HTTPS atau HTTP.');

            return;
        }

        if (in_array($validated['linkKind'], ['repository', 'commit', 'pull_request'], true)
            && ! $this->isValidGitHubEvidenceUrl($validated['linkUrl'], $validated['linkKind'])) {
            $this->addError('linkUrl', match ($validated['linkKind']) {
                'repository' => 'Link repository harus menuju repository GitHub yang valid.',
                'commit' => 'Link commit harus menuju halaman commit GitHub dengan hash yang valid.',
                'pull_request' => 'Link pull request harus menuju halaman pull request GitHub yang valid.',
            });

            return;
        }

        auth()->user()->tasks()->findOrFail($validated['linkTaskId'])->links()->create([
            'user_id' => auth()->id(),
            'label' => $validated['linkLabel'],
            'url' => $validated['linkUrl'],
            'kind' => $validated['linkKind'],
        ]);

        $this->reset(['linkTaskId', 'linkLabel', 'linkUrl', 'linkKind']);
        $this->linkKind = 'reference';
        unset($this->links);
        session()->flash('status', 'Link ditautkan ke tugas.');
    }

    private function isValidGitHubEvidenceUrl(string $url, string $kind): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || $host !== 'github.com') {
            return false;
        }

        return match ($kind) {
            'repository' => preg_match('~^/[^/]+/[^/]+/?$~', $path) === 1,
            'commit' => preg_match('~^/[^/]+/[^/]+/commit/[a-f0-9]{7,64}/?$~i', $path) === 1,
            'pull_request' => preg_match('~^/[^/]+/[^/]+/pull/[1-9][0-9]*/?$~', $path) === 1,
            default => false,
        };
    }

    public function saveNote(): void
    {
        $validated = $this->validate([
            'noteTaskId' => ['required', 'integer', Rule::exists('tasks', 'id')->where('user_id', auth()->id())],
            'noteBody' => ['required', 'string', 'max:5000'],
        ]);

        auth()->user()->tasks()->findOrFail($validated['noteTaskId'])->taskNotes()->create([
            'user_id' => auth()->id(),
            'body' => $validated['noteBody'],
        ]);

        $this->reset(['noteTaskId', 'noteBody']);
        unset($this->notes);
        session()->flash('status', 'Catatan pribadi disimpan.');
    }
};
?>

<div class="min-h-screen bg-[#f7f4ec] px-4 py-8 text-[#171717] sm:px-8 lg:px-10">
    <div class="mx-auto max-w-7xl space-y-7">
        <header class="flex flex-col justify-between gap-5 border-b-2 border-black pb-6 sm:flex-row sm:items-end"><div><p class="text-xs font-black uppercase tracking-[0.24em] text-[#555]">TUGAS!OS / File Vault</p><h1 class="mt-2 text-4xl font-black tracking-tight sm:text-5xl">Berkas tetap tertata.</h1><p class="mt-3 max-w-xl text-sm text-[#555]">File tugas disimpan privat dan hanya bisa diakses dari akunmu.</p></div><div class="border-2 border-black bg-[#b8edce] px-4 py-3 text-sm font-bold shadow-[4px_4px_0_#171717]">TAHAP 04 — FILE VAULT</div></header>
        @if(session('status'))<div role="status" aria-live="polite" class="border-2 border-black bg-[#b8edce] px-4 py-3 font-bold shadow-[4px_4px_0_#171717]">{{ session('status') }}</div>@endif
        <div class="grid gap-5 xl:grid-cols-3">
            <section class="border-2 border-black bg-white p-5 shadow-[4px_4px_0_#171717]"><h2 class="text-xl font-black">Simpan file</h2><p class="mt-1 text-xs text-[#555]">Maksimum {{ $this->uploadPolicy['max_size_kb'] >= 1024 ? number_format($this->uploadPolicy['max_size_kb'] / 1024, 2).' MB' : $this->uploadPolicy['max_size_kb'].' KB' }} per file · {{ implode(', ', $this->uploadPolicy['extensions']) }}</p><form wire:submit="saveFile" class="mt-4 space-y-3"><label class="grid gap-1 text-sm font-bold">Tugas<select wire:model="taskId" required class="border-2 border-black px-3 py-2 font-normal"><option value="">Pilih tugas</option>@foreach($this->tasks as $task)<option value="{{ $task->id }}">{{ $task->title }}</option>@endforeach</select>@error('taskId')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label><label class="grid gap-1 text-sm font-bold">Jenis file<select wire:model="kind" class="border-2 border-black px-3 py-2 font-normal"><option value="instruction">Instruksi</option><option value="reference">Referensi</option><option value="work">Hasil kerja</option><option value="revision">Revisi</option><option value="final">Final</option><option value="proof">Bukti pengumpulan</option></select></label><label class="grid gap-1 text-sm font-bold">File<input type="file" wire:model="upload" accept="{{ '.'.implode(',.', $this->uploadPolicy['extensions']) }}" required class="border-2 border-black bg-white px-3 py-2 text-sm font-normal">@error('upload')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label><div wire:loading wire:target="upload" class="text-xs font-bold">Mengunggah file sementara…</div><button class="w-full border-2 border-black bg-[#facc15] px-3 py-2 font-black">Simpan privat</button></form></section>
            <section class="border-2 border-black bg-white p-5 shadow-[4px_4px_0_#171717]"><h2 class="text-xl font-black">Tautkan bukti atau referensi</h2><form wire:submit="saveLink" class="mt-4 space-y-3"><label class="grid gap-1 text-sm font-bold">Tugas<select wire:model="linkTaskId" required class="border-2 border-black px-3 py-2 font-normal"><option value="">Pilih tugas</option>@foreach($this->tasks as $task)<option value="{{ $task->id }}">{{ $task->title }}</option>@endforeach</select>@error('linkTaskId')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label><label class="grid gap-1 text-sm font-bold">Nama link<input wire:model="linkLabel" required maxlength="120" class="border-2 border-black px-3 py-2 font-normal"></label><label class="grid gap-1 text-sm font-bold">URL<input wire:model="linkUrl" required type="url" placeholder="https://…" class="border-2 border-black px-3 py-2 font-normal">@error('linkUrl')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label><fieldset class="space-y-2"><legend class="text-sm font-bold">Jenis tautan</legend><div class="grid grid-cols-2 gap-2">@foreach(['reference' => 'Referensi', 'repository' => 'Repository', 'commit' => 'Commit', 'pull_request' => 'Pull request'] as $kindOption => $kindLabel)<label class="flex min-h-11 items-center gap-2 border-2 border-black bg-white px-3 py-2 text-sm font-semibold"><input type="radio" name="linkKind" value="{{ $kindOption }}" wire:model="linkKind" class="size-4 accent-black">{{ $kindLabel }}</label>@endforeach</div>@error('linkKind')<span role="alert" class="text-xs text-red-700">{{ $message }}</span>@enderror</fieldset><button class="w-full border-2 border-black bg-[#b8d8ff] px-3 py-2 font-black">Simpan link</button></form></section>
            <section class="border-2 border-black bg-white p-5 shadow-[4px_4px_0_#171717]"><h2 class="text-xl font-black">Catatan privat</h2><form wire:submit="saveNote" class="mt-4 space-y-3"><label class="grid gap-1 text-sm font-bold">Tugas<select wire:model="noteTaskId" required class="border-2 border-black px-3 py-2 font-normal"><option value="">Pilih tugas</option>@foreach($this->tasks as $task)<option value="{{ $task->id }}">{{ $task->title }}</option>@endforeach</select></label><label class="grid gap-1 text-sm font-bold">Catatan<textarea wire:model="noteBody" rows="4" required maxlength="5000" class="border-2 border-black px-3 py-2 font-normal"></textarea>@error('noteBody')<span class="text-xs text-red-700">{{ $message }}</span>@enderror</label><button class="w-full border-2 border-black bg-[#ffd9d1] px-3 py-2 font-black">Simpan catatan</button></form></section>
        </div>

        <section class="grid gap-5 lg:grid-cols-2">
            <article class="border-2 border-black bg-white p-5 shadow-[4px_4px_0_#171717]"><h2 class="text-2xl font-black">File tersimpan <span class="text-base">({{ $this->files->count() }})</span></h2>@error('file')<p role="alert" class="mt-3 border-2 border-black bg-[#ffd9d1] px-3 py-2 text-sm font-bold">{{ $message }}</p>@enderror<ul class="mt-3 divide-y-2 divide-black border-t-2 border-black">@forelse($this->files as $file)<li wire:key="file-{{ $file->id }}" class="flex items-center justify-between gap-3 py-3"><div class="min-w-0"><p class="truncate font-bold">{{ $file->original_name }} @if($file->is_final)<span class="bg-[#b8edce] px-1 text-xs">FINAL</span>@endif</p><p class="text-xs text-[#555]">{{ $file->task->title }} · {{ strtoupper($file->kind) }} · v{{ $file->version }} · {{ number_format($file->byte_size / 1048576, 2) }} MB</p></div><div class="flex shrink-0 gap-2"><a href="{{ route('files.download', $file) }}" class="border-2 border-black px-2 py-1 text-xs font-black">Unduh</a><button type="button" wire:click="deleteFile({{ $file->id }})" wire:confirm="Hapus file ini?" class="border-2 border-black px-2 py-1 text-xs font-black hover:bg-[#ff8c78]">Hapus</button></div></li>@empty<li class="py-5 text-sm text-[#555]">Belum ada file di vault.</li>@endforelse</ul></article>
            <div class="space-y-5"><article class="border-2 border-black bg-white p-5 shadow-[4px_4px_0_#171717]"><h2 class="text-2xl font-black">Link tugas</h2><ul class="mt-3 divide-y-2 divide-black border-t-2 border-black">@forelse($this->links as $link)<li wire:key="link-{{ $link->id }}" class="py-3"><div class="flex flex-wrap items-center gap-2"><p class="font-bold">{{ $link->label }}</p><span class="border border-black bg-[#fff1b8] px-2 py-0.5 text-xs font-bold">{{ match ($link->kind) { 'commit' => 'Commit', 'pull_request' => 'Pull request', 'repository' => 'Repository', default => 'Referensi' } }}</span></div><a href="{{ $link->url }}" target="_blank" rel="noopener noreferrer" class="break-all text-sm underline">{{ $link->url }}</a><p class="text-xs text-[#555]">{{ $link->task->title }}</p></li>@empty<li class="py-4 text-sm text-[#555]">Belum ada link.</li>@endforelse</ul></article><article class="border-2 border-black bg-white p-5 shadow-[4px_4px_0_#171717]"><h2 class="text-2xl font-black">Catatan terbaru</h2><ul class="mt-3 divide-y-2 divide-black border-t-2 border-black">@forelse($this->notes as $note)<li wire:key="note-{{ $note->id }}" class="py-3"><p class="whitespace-pre-line text-sm">{{ $note->body }}</p><p class="mt-1 text-xs text-[#555]">{{ $note->task->title }} · {{ $note->created_at->diffForHumans() }}</p></li>@empty<li class="py-4 text-sm text-[#555]">Belum ada catatan.</li>@endforelse</ul></article></div>
        </section>
    </div>
</div>

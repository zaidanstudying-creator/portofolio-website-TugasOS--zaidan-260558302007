<?php

use App\Jobs\UploadGoogleDriveTaskFile;
use App\Models\SyncEvent;
use App\Services\GoogleCalendarSyncService;
use App\Services\GoogleDriveSyncService;
use App\Services\GitHubRepositoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    #[Computed]
    public function googleConnection(): ?\App\Models\IntegrationConnection
    {
        return auth()->user()->integrations()->where('provider', 'google')->first();
    }

    #[Computed]
    public function syncEvents(): Collection
    {
        return auth()->user()->integrations()
            ->where('provider', 'google')
            ->first()?->syncEvents()
            ->whereIn('provider', ['google_calendar', 'google_drive', 'github'])
            ->latest()
            ->limit(8)
            ->get() ?? new Collection;
    }

    #[Computed]
    public function driveFiles(): Collection
    {
        return auth()->user()->taskFiles()->with('task')->latest()->get();
    }

    #[Computed]
    public function driveTasks(): Collection
    {
        return auth()->user()->tasks()->orderBy('title')->get();
    }

    public string $driveFolderId = '';

    public string $calendarId = 'primary';

    public array $googleCalendars = [];

    public array $googleImportEvents = [];

    public string $googleEventId = '';

    public string $driveFileId = '';

    public string $driveLinkFileId = '';

    public string $driveLinkFileName = '';

    public string $driveLinkTaskId = '';

    public array $githubRepositories = [];

    public string $githubProjectId = '';

    public string $githubRepositoryId = '';

    public function mount(): void
    {
        $settings = auth()->user()->integrations()->where('provider', 'google')->first()?->settings ?? [];
        $this->driveFolderId = (string) ($settings['drive_folder_id'] ?? '');
        $this->calendarId = (string) ($settings['calendar_id'] ?? 'primary');
    }

    public function loadGoogleCalendars(): void
    {
        try {
            $this->googleCalendars = app(GoogleCalendarSyncService::class)->calendars(auth()->user());
            $primaryCalendar = collect($this->googleCalendars)->firstWhere('primary', true);

            if ($primaryCalendar && $this->calendarId === 'primary') {
                $this->calendarId = $primaryCalendar['id'];
            }

            $this->resetErrorBag('calendar');
        } catch (\Throwable) {
            $this->googleCalendars = [];
            $this->addError('calendar', 'Daftar kalender tidak dapat dimuat. Hubungkan ulang Google dengan izin calendar list, lalu coba lagi.');
        }
    }

    public function saveGoogleCalendar(): void
    {
        $validated = $this->validate([
            'calendarId' => ['required', 'string', 'max:255'],
        ]);
        $connection = $this->googleConnection;

        if (! $connection || $connection->status !== 'connected') {
            $this->addError('calendar', 'Hubungkan akun Google terlebih dahulu.');

            return;
        }

        try {
            $calendars = app(GoogleCalendarSyncService::class)->calendars(auth()->user());
        } catch (\Throwable) {
            $this->addError('calendar', 'Daftar kalender tidak dapat diverifikasi. Hubungkan ulang Google dan coba lagi.');

            return;
        }

        $selectedCalendar = collect($calendars)->firstWhere('id', $validated['calendarId']);

        if (! $selectedCalendar) {
            $this->addError('calendar', 'Pilih kalender yang dapat kamu ubah dari daftar Google.');

            return;
        }

        $settings = $connection->settings ?? [];
        $settings['calendar_id'] = $selectedCalendar['id'];
        $connection->update(['settings' => $settings]);
        $this->googleCalendars = $calendars;
        unset($this->googleConnection);
        session()->flash('status', 'Kalender Google tujuan berhasil disimpan. Sinkronkan kembali untuk menerapkan pilihan.');
    }

    public function loadGoogleImportEvents(): void
    {
        try {
            $this->googleImportEvents = app(GoogleCalendarSyncService::class)->importableEvents(auth()->user());
            $this->googleEventId = '';
            $this->resetErrorBag('calendarImport');
        } catch (\Throwable) {
            $this->googleImportEvents = [];
            $this->addError('calendarImport', 'Agenda Google tidak dapat dimuat. Periksa koneksi dan izin Google Calendar.');
        }
    }

    public function importGoogleEvent(): void
    {
        $validated = $this->validate([
            'googleEventId' => ['required', 'string', 'max:255'],
        ]);

        try {
            $service = app(GoogleCalendarSyncService::class);
            $listedEvent = collect($service->importableEvents(auth()->user()))
                ->firstWhere('id', $validated['googleEventId']);

            if (! $listedEvent) {
                $this->addError('calendarImport', 'Pilih agenda yang masih tersedia dari daftar Google.');

                return;
            }

            $task = $service->importEvent(auth()->user(), $validated['googleEventId']);
            $this->googleEventId = '';
            $this->loadGoogleImportEvents();
            session()->flash('status', 'Agenda “'.$task->title.'” diimpor sebagai tugas dengan jadwal.');
        } catch (\Throwable) {
            $this->addError('calendarImport', 'Agenda tidak dapat diimpor. Data internal tidak berubah; muat ulang daftar dan coba lagi.');
        }
    }

    public function saveDriveFolder(): void
    {
        $validated = $this->validate([
            'driveFolderId' => ['nullable', 'string', 'max:255'],
        ]);
        $connection = $this->googleConnection;

        if (! $connection || $connection->status !== 'connected') {
            $this->addError('drive', 'Hubungkan akun Google terlebih dahulu.');

            return;
        }

        $settings = $connection->settings ?? [];
        $settings['drive_folder_id'] = trim((string) ($validated['driveFolderId'] ?? ''));
        $connection->update(['settings' => $settings]);
        unset($this->googleConnection);
        session()->flash('status', 'Folder tujuan Google Drive disimpan.');
    }

    public function linkDriveFileToTask(): void
    {
        $validated = $this->validate([
            'driveLinkFileId' => ['required', 'string', 'max:255'],
            'driveLinkTaskId' => ['required', 'integer', Rule::exists('tasks', 'id')->where('user_id', auth()->id())],
        ]);
        $connection = $this->googleConnection;

        if (! $connection || $connection->status !== 'connected') {
            $this->addError('drive', 'Hubungkan akun Google terlebih dahulu.');

            return;
        }

        $task = auth()->user()->tasks()->findOrFail($validated['driveLinkTaskId']);

        try {
            $result = app(GoogleDriveSyncService::class)->linkDriveFile(
                auth()->user(),
                $task,
                $validated['driveLinkFileId'],
            );
            $this->reset(['driveLinkFileId', 'driveLinkFileName', 'driveLinkTaskId']);
            unset($this->googleConnection, $this->syncEvents);
            session()->flash('status', $result['action'] === 'unchanged'
                ? 'File Drive tersebut sudah tertaut ke tugas.'
                : $result['name'].' ditautkan ke '.$task->title.'.');
        } catch (\Throwable) {
            $connection->update(['last_error' => 'File Google Drive tidak dapat ditautkan. Periksa izin file dan coba lagi.']);
            $connection->syncEvents()->create([
                'provider' => 'google_drive',
                'direction' => 'inbound',
                'action' => 'link_drive_file',
                'status' => 'failed',
                'message' => 'Metadata file Drive tidak dapat diverifikasi.',
                'context' => ['task_id' => $task->id],
            ]);
            unset($this->syncEvents, $this->googleConnection);
            $this->addError('drive', 'File Drive tidak dapat diverifikasi atau ditautkan. Muat ulang pemilih dan coba lagi.');
        }
    }

    #[Computed]
    public function githubConnection(): ?\App\Models\IntegrationConnection
    {
        return auth()->user()->integrations()->where('provider', 'github')->first();
    }

    #[Computed]
    public function githubProjects(): Collection
    {
        return auth()->user()->tasks()->whereNull('parent_task_id')->orderBy('title')->get();
    }

    #[Computed]
    public function selectedGitHubRepository(): ?array
    {
        return collect($this->githubRepositories)->firstWhere('id', (int) $this->githubRepositoryId);
    }

    public function loadGitHubRepositories(): void
    {
        $connection = $this->githubConnection;

        if (! $connection || $connection->status !== 'connected') {
            $this->addError('github', 'Hubungkan akun GitHub terlebih dahulu.');

            return;
        }

        try {
            $this->githubRepositories = app(GitHubRepositoryService::class)->publicRepositories($connection);
            $connection->update(['last_synced_at' => now(), 'last_error' => null]);
            unset($this->githubConnection);
            $this->resetErrorBag('github');
        } catch (\Throwable) {
            $this->githubRepositories = [];
            if ($connection->fresh()->status === 'connected') {
                $connection->update(['last_error' => 'Daftar repository GitHub tidak dapat dimuat.']);
            }
            $connection->syncEvents()->create([
                'provider' => 'github',
                'direction' => 'inbound',
                'action' => 'list_repositories',
                'status' => 'failed',
                'message' => 'Repository publik GitHub tidak dapat dimuat.',
            ]);
            unset($this->syncEvents);
            unset($this->githubConnection);
            $this->addError('github', 'Repository publik GitHub tidak dapat dimuat. Periksa koneksi dan coba lagi.');
        }
    }

    public function linkGitHubRepository(): void
    {
        $validated = $this->validate([
            'githubProjectId' => ['required', 'integer', Rule::exists('tasks', 'id')->where('user_id', auth()->id())->whereNull('parent_task_id')],
            'githubRepositoryId' => ['required', 'integer'],
        ]);
        $connection = $this->githubConnection;

        if (! $connection || $connection->status !== 'connected') {
            $this->addError('github', 'Hubungkan akun GitHub terlebih dahulu.');

            return;
        }

        $project = auth()->user()->tasks()->whereNull('parent_task_id')->findOrFail($validated['githubProjectId']);

        try {
            $repository = app(GitHubRepositoryService::class)->linkPublicRepository(
                auth()->user(),
                $project,
                (int) $validated['githubRepositoryId'],
            );
            unset($this->syncEvents);
            session()->flash('status', $repository['full_name'].' ditautkan ke '.$project->title.'.');
        } catch (\Throwable) {
            $connection->syncEvents()->create([
                'provider' => 'github',
                'direction' => 'inbound',
                'action' => 'link_repository',
                'status' => 'failed',
                'message' => 'Repository tidak dapat ditautkan ke proyek.',
                'context' => ['task_id' => $project->id],
            ]);
            $this->addError('githubRepositoryId', 'Repository tidak dapat ditautkan. Muat ulang daftar dan pilih repository publik milik akun yang terhubung.');
        }
    }

    public function uploadDriveFile(): void
    {
        $validated = $this->validate([
            'driveFileId' => ['required', 'integer', Rule::exists('task_files', 'id')->where('user_id', auth()->id())],
        ]);
        $connection = $this->googleConnection;

        if (! $connection || $connection->status !== 'connected') {
            $this->addError('drive', 'Hubungkan akun Google terlebih dahulu.');

            return;
        }

        $file = auth()->user()->taskFiles()->findOrFail($validated['driveFileId']);

        try {
            $event = DB::transaction(function () use ($connection, $file): ?SyncEvent {
                $lockedConnection = $connection->newQuery()->lockForUpdate()->findOrFail($connection->getKey());
                $pendingEvent = $lockedConnection->syncEvents()
                    ->where('provider', 'google_drive')
                    ->where('action', 'upload_file')
                    ->whereIn('status', ['queued', 'running'])
                    ->where('context->task_file_id', $file->getKey())
                    ->latest('id')
                    ->first();

                if ($pendingEvent && $pendingEvent->updated_at?->greaterThan(now()->subMinutes(15))) {
                    return null;
                }

                $pendingEvent?->update([
                    'status' => 'failed',
                    'message' => 'Upload sebelumnya berhenti sebelum selesai. Periksa Drive sebelum mencoba lagi.',
                ]);

                $event = $lockedConnection->syncEvents()->create([
                    'provider' => 'google_drive',
                    'direction' => 'outbound',
                    'action' => 'upload_file',
                    'status' => 'queued',
                    'message' => 'Upload file menunggu antrean.',
                    'context' => ['task_id' => $file->task_id, 'task_file_id' => $file->id],
                ]);

                UploadGoogleDriveTaskFile::dispatch($lockedConnection->id, $file->id, $event->id)->afterCommit();

                return $event;
            });

            if (! $event) {
                $this->addError('drive', 'Upload file ini sedang berjalan. Tunggu hasilnya di log sinkronisasi.');

                return;
            }

            $this->driveFileId = '';
            unset($this->googleConnection, $this->syncEvents, $this->driveFiles);
            session()->flash('status', 'Upload masuk antrean. Status berhasil akan muncul setelah Google Drive mengonfirmasi file.');
        } catch (\Throwable) {
            $this->addError('drive', 'Upload belum dapat dimasukkan ke antrean. File lokal tetap aman; coba lagi setelah koneksi pulih.');
        }
    }

    public function syncCalendar(): void
    {
        $connection = $this->googleConnection;

        if (! $connection || $connection->status !== 'connected') {
            $this->addError('sync', 'Hubungkan akun Google terlebih dahulu.');

            return;
        }

        try {
            $event = app(GoogleCalendarSyncService::class)->queueSync(auth()->user());
            unset($this->googleConnection, $this->syncEvents);
            session()->flash('status', $event->status === 'queued'
                ? 'Sinkronisasi Google Calendar masuk antrean. Statusnya akan muncul pada log di bawah.'
                : 'Sinkronisasi Google Calendar sedang diproses. Statusnya terlihat pada log di bawah.');
        } catch (\Throwable) {
            unset($this->googleConnection, $this->syncEvents);
            $this->addError('sync', 'Sinkronisasi tidak dapat dimasukkan ke antrean. Periksa koneksi aplikasi lalu coba lagi.');
        }
    }
};
?>

<div class="min-h-screen bg-[#f7f4ec] px-4 py-8 text-[#171717] sm:px-8 lg:px-10"><div class="mx-auto max-w-7xl space-y-7">
    <header class="flex flex-col justify-between gap-5 border-b-2 border-black pb-6 sm:flex-row sm:items-end"><div><p class="text-xs font-black uppercase tracking-[0.24em] text-[#555]">TUGAS!OS / Integrations</p><h1 class="mt-2 text-4xl font-black sm:text-5xl">Layanan tetap terhubung.</h1><p class="mt-3 max-w-xl text-sm text-[#555]">Koneksi layanan bersifat opsional. Tugas dan kalender internal tetap bisa dipakai saat layanan luar offline.</p></div><div class="border-2 border-black bg-[#b8d8ff] px-4 py-3 text-sm font-bold shadow-[4px_4px_0_#171717]">TAHAP 06 — CONNECTORS</div></header>
    @if(session('status'))<div role="status" aria-live="polite" class="border-2 border-black bg-[#b8edce] px-4 py-3 font-bold">{{ session('status') }}</div>@endif
    @error('google')<div role="alert" class="border-2 border-black bg-[#ffb7a9] px-4 py-3 font-bold">{{ $message }}</div>@enderror
    @error('sync')<div role="alert" class="border-2 border-black bg-[#ffb7a9] px-4 py-3 font-bold">{{ $message }}</div>@enderror

    <section class="grid gap-5 lg:grid-cols-2">
        <article class="border-2 border-black bg-white p-5 shadow-[5px_5px_0_#171717] sm:p-6"><div class="flex items-start justify-between gap-3"><div><p class="text-xs font-black uppercase tracking-widest text-[#666]">Google Workspace</p><h2 class="mt-1 text-2xl font-black">Google Calendar</h2></div><span class="border-2 border-black px-2 py-1 text-xs font-black uppercase {{ $this->googleConnection?->status === 'connected' ? 'bg-[#b8edce]' : ($this->googleConnection?->status === 'reauth_required' ? 'bg-[#ffd9d1]' : 'bg-[#eeeae0]') }}">{{ $this->googleConnection?->status === 'connected' ? 'Terhubung' : ($this->googleConnection?->status === 'reauth_required' ? 'Perlu hubungkan ulang' : 'Belum terhubung') }}</span></div>
            <p class="mt-3 text-sm leading-6 text-[#555]">Jadwal pengerjaan dan deadline resmi disinkronkan sebagai event terpisah ke kalender yang kamu pilih. Perubahan dan penghapusan memakai external event ID agar event tidak terduplikasi.</p>
            <p class="mt-3 text-xs text-[#555]">Callback OAuth: <code class="break-all border border-black bg-[#f7f4ec] px-1">{{ route('integrations.google.callback') }}</code></p>
            @if($this->googleConnection?->last_synced_at)<p class="mt-3 text-xs font-bold">Sync terakhir: {{ $this->googleConnection->last_synced_at->diffForHumans() }}</p>@endif
            @if($this->googleConnection?->last_error)<p class="mt-2 text-sm font-bold text-red-800">{{ $this->googleConnection->last_error }}</p>@endif
            @if($this->googleConnection?->status === 'connected')
                <div class="mt-5 grid gap-3 border-t-2 border-black pt-4">
                    <div class="flex flex-wrap items-end gap-2">
                        <label for="google-calendar-id" class="grid min-w-64 flex-1 gap-1 text-xs font-black uppercase">Kalender tujuan
                            <select id="google-calendar-id" wire:model="calendarId" class="border-2 border-black bg-white px-3 py-2 text-sm font-semibold">
                                @forelse($googleCalendars as $calendar)
                                    <option wire:key="google-calendar-{{ $calendar['id'] }}" value="{{ $calendar['id'] }}">{{ $calendar['summary'] }}{{ $calendar['primary'] ? ' · utama' : '' }}</option>
                                @empty
                                    <option value="{{ $calendarId }}">{{ $calendarId === 'primary' ? 'Kalender utama' : 'Kalender tersimpan' }}</option>
                                @endforelse
                            </select>
                        </label>
                        <button type="button" wire:click="loadGoogleCalendars" wire:loading.attr="disabled" class="border-2 border-black bg-[#dceaff] px-3 py-2 text-sm font-black">Muat kalender</button>
                        @if($googleCalendars !== [])
                            <button type="button" wire:click="saveGoogleCalendar" wire:loading.attr="disabled" class="border-2 border-black bg-[#b8edce] px-3 py-2 text-sm font-black">Simpan pilihan</button>
                        @endif
                    </div>
                    @error('calendar')<p role="alert" class="border-2 border-black bg-[#ffb7a9] px-3 py-2 text-sm font-bold">{{ $message }}</p>@enderror
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="syncCalendar" wire:loading.attr="disabled" class="border-2 border-black bg-[#facc15] px-4 py-2 text-sm font-black">Sinkronkan kalender</button>
                        <form method="POST" action="{{ route('integrations.google.disconnect') }}">@csrf @method('DELETE')<button type="submit" wire:confirm="Putuskan koneksi Google?" class="border-2 border-black bg-white px-4 py-2 text-sm font-bold">Putus koneksi</button></form>
                    </div>
                    <div class="grid gap-2 border-t-2 border-black pt-4">
                        <div><h3 class="font-black">Impor agenda Google</h3><p class="text-xs leading-5 text-[#555]">Pilih event 30 hari ke depan untuk dibuat sebagai tugas dan jadwal internal. Event berulang dan event milik TUGAS!OS tidak ditampilkan.</p></div>
                        <button type="button" wire:click="loadGoogleImportEvents" wire:loading.attr="disabled" class="justify-self-start border-2 border-black bg-[#dceaff] px-3 py-2 text-sm font-black">Muat agenda</button>
                        @if($googleImportEvents !== [])
                            <form wire:submit="importGoogleEvent" class="grid gap-2 sm:grid-cols-[1fr_auto]">
                                <label for="google-event-id" class="grid gap-1 text-xs font-black uppercase">Agenda yang akan diimpor
                                    <select id="google-event-id" wire:model="googleEventId" required class="border-2 border-black bg-white px-3 py-2 text-sm font-semibold">
                                        <option value="">Pilih agenda</option>
                                        @foreach($googleImportEvents as $event)
                                            <option wire:key="google-import-event-{{ $event['id'] }}" value="{{ $event['id'] }}">{{ $event['summary'] }} · {{ $event['start'] }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <button wire:loading.attr="disabled" class="self-end border-2 border-black bg-[#b8edce] px-3 py-2 text-sm font-black">Impor sebagai tugas</button>
                            </form>
                        @elseif($googleCalendars !== [] || $calendarId !== 'primary')
                            <p class="text-xs text-[#666]">Tidak ada agenda baru yang dapat diimpor dalam 30 hari mendatang.</p>
                        @endif
                        @error('calendarImport')<p role="alert" class="border-2 border-black bg-[#ffb7a9] px-3 py-2 text-sm font-bold">{{ $message }}</p>@enderror
                    </div>
                </div>
            @else
                <div class="mt-5"><a href="{{ route('integrations.google.redirect') }}" class="border-2 border-black bg-[#facc15] px-4 py-2 text-sm font-black">Hubungkan Google</a></div>
            @endif
        </article>

        <article class="border-2 border-black bg-white p-5 shadow-[5px_5px_0_#171717] sm:p-6"><p class="text-xs font-black uppercase tracking-widest text-[#666]">Google Workspace</p><h2 class="mt-1 text-2xl font-black">File Vault → Google Drive</h2><p class="mt-3 text-sm leading-6 text-[#555]">Pilih file dari File Vault dan unggah ke folder Google Drive yang dapat diakses aplikasi. Unggah ulang memperbarui file yang sama; kegagalan tidak dianggap berhasil.</p>
            @error('drive')<p role="alert" class="mt-3 border-2 border-black bg-[#ffb7a9] px-3 py-2 text-sm font-bold">{{ $message }}</p>@enderror
            @if($this->googleConnection?->status === 'connected')
                <div class="mt-4 grid gap-2" x-data="{ pickerLoading: false, pickerError: '', async chooseFolder() { this.pickerLoading = true; this.pickerError = ''; try { const response = await fetch(this.$el.dataset.tokenUrl, { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.$el.dataset.csrfToken } }); const payload = await response.json(); if (!response.ok) { throw new Error(payload.message || 'Token Google tidak tersedia.'); } if (!window.google?.picker) { await new Promise((resolve, reject) => { let script = document.querySelector('script[data-google-picker]'); if (!script) { script = document.createElement('script'); script.src = 'https://apis.google.com/js/api.js'; script.async = true; script.dataset.googlePicker = 'true'; script.onerror = () => reject(new Error('Google Picker gagal dimuat.')); document.head.appendChild(script); } if (window.gapi) { resolve(); } else { script.addEventListener('load', resolve, { once: true }); script.addEventListener('error', () => reject(new Error('Google Picker gagal dimuat.')), { once: true }); } }).then(() => new Promise((resolve, reject) => { window.gapi.load('picker', { callback: resolve, onerror: () => reject(new Error('Google Picker gagal dimuat.')) }); })); } const component = this; const folderView = new google.picker.DocsView(google.picker.ViewId.FOLDERS).setIncludeFolders(true).setSelectFolderEnabled(true).setMode(google.picker.DocsViewMode.LIST); new google.picker.PickerBuilder().addView(folderView).setOAuthToken(payload.accessToken).setDeveloperKey(this.$el.dataset.apiKey).setAppId(this.$el.dataset.appId).setOrigin(window.location.origin).setCallback(async (data) => { if (data.action === google.picker.Action.PICKED) { const folder = data.docs?.[0]; if (!folder?.id) { component.pickerError = 'Folder Google Drive tidak memiliki ID yang valid.'; return; } await component.$wire.set('driveFolderId', folder.id); await component.$wire.call('saveDriveFolder'); } }).build().setVisible(true); } catch (error) { this.pickerError = error.message || 'Google Picker tidak tersedia.'; } finally { this.pickerLoading = false; } } }" data-token-url="{{ route('integrations.google.picker-token') }}" data-csrf-token="{{ csrf_token() }}" data-api-key="{{ config('services.google.picker_api_key') }}" data-app-id="{{ config('services.google.app_id') }}">
                    <p class="text-xs font-black uppercase">Folder tujuan Drive</p>
                    @if(config('services.google.picker_api_key') && config('services.google.app_id'))<button type="button" x-on:click="chooseFolder" x-bind:disabled="pickerLoading" class="justify-self-start border-2 border-black bg-[#b8d8ff] px-3 py-2 text-sm font-black disabled:opacity-60"><span x-show="!pickerLoading">{{ $driveFolderId ? 'Ganti folder Drive' : 'Pilih folder Drive' }}</span><span x-show="pickerLoading">Memuat Google Picker…</span></button>@else<p class="border-2 border-black bg-[#fff1b8] px-3 py-2 text-xs font-bold">Google Picker belum dikonfigurasi. Isi GOOGLE_PICKER_API_KEY dan GOOGLE_APP_ID.</p>@endif
                    <input id="drive-folder-id" type="hidden" wire:model="driveFolderId"><p class="text-xs text-[#666]">{{ $driveFolderId ? 'ID folder tersimpan: '.$driveFolderId : 'Kosongkan untuk menyimpan ke Drive Saya.' }} Folder dipilih melalui dialog resmi Google.</p><p x-show="pickerError" x-text="pickerError" role="alert" class="border-2 border-black bg-[#ffb7a9] px-3 py-2 text-xs font-bold"></p>
                </div>
                <div class="mt-4 grid gap-2 border-t-2 border-black pt-4" x-data="{ pickerLoading: false, pickerError: '', async chooseDriveFile() { this.pickerLoading = true; this.pickerError = ''; try { const response = await fetch(this.$el.dataset.tokenUrl, { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.$el.dataset.csrfToken } }); const payload = await response.json(); if (!response.ok) { throw new Error(payload.message || 'Token Google tidak tersedia.'); } if (!window.google?.picker) { await new Promise((resolve, reject) => { let script = document.querySelector('script[data-google-picker]'); if (!script) { script = document.createElement('script'); script.src = 'https://apis.google.com/js/api.js'; script.async = true; script.dataset.googlePicker = 'true'; script.onerror = () => reject(new Error('Google Picker gagal dimuat.')); document.head.appendChild(script); } if (window.gapi) { resolve(); } else { script.addEventListener('load', resolve, { once: true }); script.addEventListener('error', () => reject(new Error('Google Picker gagal dimuat.')), { once: true }); } }).then(() => new Promise((resolve, reject) => { window.gapi.load('picker', { callback: resolve, onerror: () => reject(new Error('Google Picker gagal dimuat.')) }); })); } const component = this; const fileView = new google.picker.DocsView(google.picker.ViewId.DOCS).setIncludeFolders(false).setSelectFolderEnabled(false).setMode(google.picker.DocsViewMode.LIST); new google.picker.PickerBuilder().addView(fileView).setOAuthToken(payload.accessToken).setDeveloperKey(this.$el.dataset.apiKey).setAppId(this.$el.dataset.appId).setOrigin(window.location.origin).setCallback(async (data) => { if (data.action === google.picker.Action.PICKED) { const file = data.docs?.[0]; if (!file?.id || !file?.name) { component.pickerError = 'File Google Drive tidak memiliki identitas yang valid.'; return; } await component.$wire.set('driveLinkFileId', file.id); await component.$wire.set('driveLinkFileName', file.name); } }).build().setVisible(true); } catch (error) { this.pickerError = error.message || 'Google Picker tidak tersedia.'; } finally { this.pickerLoading = false; } } }" data-token-url="{{ route('integrations.google.picker-token') }}" data-csrf-token="{{ csrf_token() }}" data-api-key="{{ config('services.google.picker_api_key') }}" data-app-id="{{ config('services.google.app_id') }}">
                    <p class="text-xs font-black uppercase">Tautkan file Google Drive yang sudah ada</p>
                    @if(config('services.google.picker_api_key') && config('services.google.app_id'))<button type="button" x-on:click="chooseDriveFile" x-bind:disabled="pickerLoading" class="justify-self-start border-2 border-black bg-[#dceaff] px-3 py-2 text-sm font-black disabled:opacity-60"><span x-show="!pickerLoading">Pilih file dari Drive</span><span x-show="pickerLoading">Memuat Google Picker…</span></button>@else<p class="border-2 border-black bg-[#fff1b8] px-3 py-2 text-xs font-bold">Google Picker belum dikonfigurasi. Isi GOOGLE_PICKER_API_KEY dan GOOGLE_APP_ID.</p>@endif
                    <p class="text-xs text-[#555]">{{ $driveLinkFileName ? 'File dipilih: '.$driveLinkFileName : 'File Drive yang dipilih akan diverifikasi sebelum ditautkan.' }}</p>
                    <p x-show="pickerError" x-text="pickerError" role="alert" class="border-2 border-black bg-[#ffb7a9] px-3 py-2 text-xs font-bold"></p>
                </div>
                <form wire:submit="linkDriveFileToTask" class="mt-3 grid gap-2 border-b-2 border-black pb-4">
                    <input type="hidden" wire:model="driveLinkFileId">
                    <label for="drive-link-task" class="text-xs font-black uppercase">Tautkan ke tugas</label>
                    <select id="drive-link-task" wire:model="driveLinkTaskId" required class="border-2 border-black bg-white px-3 py-2 text-sm"><option value="">Pilih tugas</option>@foreach($this->driveTasks as $task)<option wire:key="drive-link-task-{{ $task->id }}" value="{{ $task->id }}">{{ $task->title }}</option>@endforeach</select>
                    @error('driveLinkFileId')<span class="text-xs font-bold text-red-800">{{ $message }}</span>@enderror
                    @error('driveLinkTaskId')<span class="text-xs font-bold text-red-800">{{ $message }}</span>@enderror
                    <button wire:loading.attr="disabled" class="justify-self-start border-2 border-black bg-[#b8edce] px-3 py-2 text-sm font-black">Verifikasi dan tautkan file</button>
                </form>
                <form wire:submit="uploadDriveFile" class="mt-4 grid gap-2"><label for="drive-file-id" class="text-xs font-black uppercase">File dari File Vault</label><select id="drive-file-id" wire:model="driveFileId" required class="border-2 border-black bg-white px-3 py-2 text-sm"><option value="">Pilih file</option>@foreach($this->driveFiles as $file)<option wire:key="drive-file-option-{{ $file->id }}" value="{{ $file->id }}">{{ $file->task->title }} — {{ $file->original_name }} (v{{ $file->version }})</option>@endforeach</select><button wire:loading.attr="disabled" class="border-2 border-black bg-[#facc15] px-3 py-2 text-sm font-black">Unggah / perbarui di Drive</button></form>
            @else<span class="mt-4 inline-block border-2 border-black bg-[#eeeae0] px-3 py-1 text-xs font-black uppercase">Hubungkan Google untuk mengaktifkan upload</span>@endif
        </article>

        <article class="border-2 border-black bg-white p-5 shadow-[5px_5px_0_#171717] sm:p-6"><div class="flex items-start justify-between gap-3"><div><p class="text-xs font-black uppercase tracking-widest text-[#666]">Source control</p><h2 class="mt-1 text-2xl font-black">GitHub</h2></div><span class="border-2 border-black px-2 py-1 text-xs font-black uppercase {{ $this->githubConnection?->status === 'connected' ? 'bg-[#b8edce]' : ($this->githubConnection?->status === 'reauth_required' ? 'bg-[#ffd9d1]' : 'bg-[#eeeae0]') }}">{{ $this->githubConnection?->status === 'connected' ? '@'.$this->githubConnection->account_label : ($this->githubConnection?->status === 'reauth_required' ? 'Perlu hubungkan ulang' : 'Belum terhubung') }}</span></div><p class="mt-3 text-sm leading-6 text-[#555]">TUGAS!OS meminta izin identitas baca saja dan hanya menampilkan repository publik. Repository privat tidak diminta melalui scope luas. Commit dan pull request tetap dapat ditautkan dari File Vault.</p>
            @error('github')<p role="alert" class="mt-3 border-2 border-black bg-[#ffb7a9] px-3 py-2 text-sm font-bold">{{ $message }}</p>@enderror
            @if($this->githubConnection?->status === 'connected')<div class="mt-4 flex flex-wrap gap-2"><button type="button" wire:click="loadGitHubRepositories" wire:loading.attr="disabled" class="border-2 border-black bg-[#b8d8ff] px-3 py-2 text-sm font-black">Muat repository publik</button><form method="POST" action="{{ route('integrations.github.disconnect') }}">@csrf @method('DELETE')<button type="submit" class="border-2 border-black bg-white px-3 py-2 text-sm font-bold">Putuskan GitHub</button></form></div>
                @if($this->githubConnection->last_error)<p class="mt-2 text-xs font-bold text-red-800">{{ $this->githubConnection->last_error }}</p>@endif
                @if(count($githubRepositories))<form wire:submit="linkGitHubRepository" class="mt-4 grid gap-2"><label for="github-project" class="text-xs font-black uppercase">Proyek induk TUGAS!OS</label><select id="github-project" wire:model="githubProjectId" required class="border-2 border-black bg-white px-3 py-2 text-sm"><option value="">Pilih proyek</option>@foreach($this->githubProjects as $project)<option wire:key="github-project-{{ $project->id }}" value="{{ $project->id }}">{{ $project->title }}</option>@endforeach</select><label for="github-repository" class="text-xs font-black uppercase">Repository publik</label><select id="github-repository" wire:model.live="githubRepositoryId" required class="border-2 border-black bg-white px-3 py-2 text-sm"><option value="">Pilih repository</option>@foreach($githubRepositories as $repository)<option wire:key="github-repository-{{ $repository['id'] }}" value="{{ $repository['id'] }}">{{ $repository['full_name'] }}{{ $repository['language'] ? ' · '.$repository['language'] : '' }}</option>@endforeach</select>@if($this->selectedGitHubRepository)<div class="border-2 border-black bg-[#f7f4ec] p-3 text-xs"><p class="font-black">{{ $this->selectedGitHubRepository['full_name'] }}</p><p class="mt-1">{{ $this->selectedGitHubRepository['description'] ?: 'Belum ada deskripsi.' }}</p><p class="mt-2 font-bold">{{ $this->selectedGitHubRepository['language'] ?: 'Bahasa tidak diketahui' }} · ★ {{ $this->selectedGitHubRepository['stargazers_count'] }}</p></div>@endif @error('githubRepositoryId')<span role="alert" class="text-xs font-bold text-red-800">{{ $message }}</span>@enderror<button class="border-2 border-black bg-[#facc15] px-3 py-2 text-sm font-black">Tautkan repository</button></form>@elseif($githubRepositories === [])<p class="mt-3 text-xs text-[#666]">Pilih “Muat repository publik” untuk mengambil daftar dari GitHub.</p>@endif
            @else<a href="{{ route('integrations.github.redirect') }}" class="mt-4 inline-block border-2 border-black bg-[#facc15] px-4 py-2 text-sm font-black">Hubungkan GitHub</a>@endif
        </article>

        <article class="border-2 border-black bg-[#171717] p-5 text-white shadow-[5px_5px_0_#facc15] sm:p-6"><p class="text-xs font-black uppercase tracking-widest text-[#facc15]">Status sistem</p><h2 class="mt-1 text-2xl font-black">Data utama tetap lokal.</h2><p class="mt-3 text-sm leading-6 text-white/75">Koneksi eksternal dapat dicabut. Access token dan refresh token disimpan terenkripsi; error dan hasil sinkronisasi dicatat tanpa menyimpan rahasia token.</p></article>
    </section>

    <section @if($this->syncEvents->contains(fn ($event) => in_array($event->status, ['queued', 'running'], true))) wire:poll.5s @endif class="border-2 border-black bg-white p-5 shadow-[4px_4px_0_#171717]"><h2 class="text-2xl font-black">Log sinkronisasi eksternal</h2><p role="status" aria-live="polite" class="sr-only">{{ $this->syncEvents->firstWhere('action', 'calendar_sync')?->message }}</p><ul class="mt-3 divide-y-2 divide-black border-t-2 border-black">@forelse($this->syncEvents as $event)<li wire:key="sync-event-{{ $event->id }}" class="flex flex-wrap items-center justify-between gap-2 py-3"><div><p class="font-bold">{{ $event->provider }} · {{ str_replace('_', ' ', $event->action) }}</p><p class="text-xs text-[#555]">{{ $event->message ?? 'Selesai' }} · {{ $event->created_at->diffForHumans() }}</p></div><span class="border-2 border-black px-2 py-1 text-xs font-black uppercase {{ $event->status === 'succeeded' ? 'bg-[#b8edce]' : (in_array($event->status, ['queued', 'running'], true) ? 'bg-[#facc15]' : 'bg-[#ffb7a9]') }}">{{ $event->status }}</span></li>@empty<li class="py-4 text-sm text-[#555]">Belum ada aktivitas sinkronisasi.</li>@endforelse</ul></section>
</div></div>

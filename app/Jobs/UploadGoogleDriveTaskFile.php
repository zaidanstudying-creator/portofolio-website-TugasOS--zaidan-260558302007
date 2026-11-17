<?php

namespace App\Jobs;

use App\Models\IntegrationConnection;
use App\Models\SyncEvent;
use App\Services\GoogleDriveSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class UploadGoogleDriveTaskFile implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 150;

    public int $uniqueFor = 720;

    /** @var list<int> */
    public array $backoff = [10, 30];

    public function __construct(public int $connectionId, public int $fileId, public int $eventId) {}

    public function handle(GoogleDriveSyncService $drive): void
    {
        $event = SyncEvent::query()
            ->whereKey($this->eventId)
            ->where('integration_connection_id', $this->connectionId)
            ->where('provider', 'google_drive')
            ->where('action', 'upload_file')
            ->first();

        if (! $event || ! in_array($event->status, ['queued', 'running'], true)) {
            return;
        }

        $connection = IntegrationConnection::query()
            ->whereKey($this->connectionId)
            ->where('provider', 'google')
            ->where('status', 'connected')
            ->first();

        if (! $connection) {
            $this->markFailed('Koneksi Google sudah tidak aktif. Hubungkan kembali lalu coba lagi.');

            return;
        }

        $file = $connection->user->taskFiles()->find($this->fileId);

        if (! $file) {
            $this->markFailed('File lokal tidak ditemukan. Periksa File Vault sebelum mencoba lagi.');

            return;
        }

        $event->update(['status' => 'running', 'message' => 'Upload file ke Google Drive sedang berjalan.']);
        $result = $drive->syncFile($connection->user, $file, $event);
        $task = $file->task;

        if (filled($result['webViewLink']) && $task && ! $task->links()->where('url', $result['webViewLink'])->exists()) {
            $task->links()->create([
                'user_id' => $connection->user_id,
                'label' => 'Google Drive · '.$result['name'],
                'url' => $result['webViewLink'],
            ]);
        }

        $event->update([
            'status' => 'succeeded',
            'message' => $result['action'] === 'unchanged'
                ? 'File Drive sudah sama dan referensinya tersimpan.'
                : 'Google Drive mengonfirmasi file berhasil disimpan.',
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $this->markFailed('Google Drive tidak mengonfirmasi upload setelah beberapa percobaan. Periksa Drive sebelum mencoba kembali.');
    }

    public function uniqueId(): string
    {
        return 'google-drive-upload:'.$this->connectionId.':'.$this->fileId;
    }

    private function markFailed(string $message): void
    {
        SyncEvent::query()
            ->whereKey($this->eventId)
            ->where('integration_connection_id', $this->connectionId)
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status' => 'failed',
                'message' => $message,
                'updated_at' => now(),
            ]);

        IntegrationConnection::query()
            ->whereKey($this->connectionId)
            ->where('provider', 'google')
            ->where('status', 'connected')
            ->update(['last_error' => 'Upload Google Drive gagal. Periksa izin dan koneksi Drive.']);
    }
}

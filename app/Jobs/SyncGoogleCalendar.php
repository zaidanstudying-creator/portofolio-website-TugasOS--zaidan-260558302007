<?php

namespace App\Jobs;

use App\Models\IntegrationConnection;
use App\Models\SyncEvent;
use App\Services\GoogleCalendarSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncGoogleCalendar implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 720;

    /** @var list<int> */
    public array $backoff = [10, 30];

    public function __construct(public int $connectionId, public int $eventId) {}

    public function handle(GoogleCalendarSyncService $calendar): void
    {
        $event = SyncEvent::query()
            ->whereKey($this->eventId)
            ->where('integration_connection_id', $this->connectionId)
            ->where('provider', 'google_calendar')
            ->where('action', 'calendar_sync')
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
            $event->update([
                'status' => 'failed',
                'message' => 'Koneksi Google sudah tidak aktif. Hubungkan kembali lalu coba lagi.',
            ]);

            return;
        }

        $event->update(['status' => 'running', 'message' => 'Sinkronisasi sedang berjalan.']);
        $count = $calendar->sync($connection->user);
        $connection->refresh();
        $event->update([
            'status' => $connection->last_error ? 'failed' : 'succeeded',
            'message' => $connection->last_error
                ? "Sinkronisasi selesai dengan sebagian item gagal. {$count} jadwal berhasil diperbarui; periksa izin dan coba lagi."
                : "Sinkronisasi selesai. {$count} jadwal diperbarui.",
            'context' => ['synchronized_count' => $count],
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        SyncEvent::query()
            ->whereKey($this->eventId)
            ->where('integration_connection_id', $this->connectionId)
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status' => 'failed',
                'message' => 'Sinkronisasi gagal setelah beberapa percobaan. Periksa koneksi dan izin Google, lalu coba lagi.',
                'updated_at' => now(),
            ]);

        IntegrationConnection::query()
            ->whereKey($this->connectionId)
            ->where('provider', 'google')
            ->where('status', 'connected')
            ->update(['last_error' => 'Sinkronisasi gagal. Periksa koneksi dan izin Google.']);
    }

    public function uniqueId(): string
    {
        return 'google-calendar-sync:'.$this->connectionId;
    }
}

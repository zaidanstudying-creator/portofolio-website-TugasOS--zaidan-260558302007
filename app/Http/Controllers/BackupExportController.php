<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use ZipArchive;

class BackupExportController extends Controller
{
    public function __invoke(Request $request): BinaryFileResponse
    {
        $user = $request->user();
        $temporaryPath = tempnam(sys_get_temp_dir(), 'tugasos-backup-');

        if ($temporaryPath === false) {
            abort(500, 'Backup tidak dapat disiapkan.');
        }

        $archive = new ZipArchive;

        if ($archive->open($temporaryPath, ZipArchive::OVERWRITE) !== true) {
            @unlink($temporaryPath);
            abort(500, 'Backup tidak dapat disiapkan.');
        }

        try {
            $fileRecords = [];

            foreach ($user->taskFiles()->get() as $file) {
                $archivePath = null;
                $disk = Storage::disk($file->disk);

                if ($disk->exists($file->path)) {
                    $archivePath = 'files/task-'.$file->task_id.'/file-'.$file->id;

                    if (! $archive->addFromString($archivePath, $disk->get($file->path))) {
                        throw new \RuntimeException('Lampiran tidak dapat ditambahkan ke backup.');
                    }
                }

                $fileRecords[$file->id] = [
                    'id' => $file->id,
                    'task_id' => $file->task_id,
                    'kind' => $file->kind,
                    'original_name' => $file->original_name,
                    'mime_type' => $file->mime_type,
                    'byte_size' => $file->byte_size,
                    'version' => $file->version,
                    'is_final' => $file->is_final,
                    'sha256' => $file->sha256,
                    'archive_path' => $archivePath,
                    'available' => $archivePath !== null,
                    'created_at' => $file->created_at?->toIso8601String(),
                ];
            }

            $tasks = $user->tasks()
                ->with([
                    'items',
                    'category',
                    'course',
                    'semester',
                    'assigners.roles',
                    'schedules',
                    'files',
                    'links',
                    'taskNotes',
                    'statusEvents',
                    'progressEvents',
                    'submissions.proofFile',
                    'submissions.attempts',
                    'submissions.assessmentEvents',
                ])
                ->get()
                ->map(function (Task $task) use ($fileRecords): array {
                    $task->files->each(fn (TaskFile $file) => $file->makeHidden(['disk', 'path']));
                    $task->submissions->each(fn ($submission) => $submission->proofFile?->makeHidden(['disk', 'path']));
                    $data = $task->toArray();
                    $data['files'] = $task->files->map(fn (TaskFile $file): ?array => $fileRecords[$file->id] ?? null)
                        ->filter()
                        ->values()
                        ->all();

                    return $data;
                })
                ->all();

            $integrations = $user->integrations()->with(['mappings', 'syncEvents'])->get()->map(fn ($connection): array => [
                'provider' => $connection->provider,
                'external_user_id' => $connection->external_user_id,
                'account_label' => $connection->account_label,
                'scopes' => $connection->scopes,
                'status' => $connection->status,
                'last_synced_at' => $connection->last_synced_at?->toIso8601String(),
                'settings' => $connection->settings,
                'mappings' => $connection->mappings->toArray(),
                'sync_events' => $connection->syncEvents->toArray(),
            ])->all();

            $backup = [
                'format' => 'tugasos-backup-v1',
                'generated_at' => now()->toIso8601String(),
                'user' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'settings' => $user->settings,
                ],
                'semesters' => $user->semesters()->get()->toArray(),
                'categories' => $user->categories()->get()->toArray(),
                'courses' => $user->courses()->with('semester')->get()->toArray(),
                'assigners' => $user->assigners()->with('roles')->get()->toArray(),
                'tasks' => $tasks,
                'task_files' => array_values($fileRecords),
                'habits' => $user->habits()->with('entries')->get()->toArray(),
                'recurrence_rules' => $user->recurrenceRules()->get()->toArray(),
                'reminders' => $user->reminders()->get()->toArray(),
                'ai_requests' => $user->aiRequests()->get()->toArray(),
                'integrations' => $integrations,
            ];

            if (! $archive->addFromString('backup.json', json_encode($backup, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
                throw new \RuntimeException('Data backup tidak dapat ditulis.');
            }

            $archive->close();
        } catch (Throwable $exception) {
            $archive->close();
            @unlink($temporaryPath);

            throw $exception;
        }

        $response = response()->download(
            $temporaryPath,
            'tugasos-backup-'.$user->getKey().'-'.now()->format('Ymd-His').'.zip',
            [
                'Content-Type' => 'application/zip',
                'X-Content-Type-Options' => 'nosniff',
            ],
        )->deleteFileAfterSend(true);

        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}

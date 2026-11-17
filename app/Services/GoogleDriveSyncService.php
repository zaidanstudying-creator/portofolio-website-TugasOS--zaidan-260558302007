<?php

namespace App\Services;

use App\Models\ExternalResourceMapping;
use App\Models\IntegrationConnection;
use App\Models\SyncEvent;
use App\Models\Task;
use App\Models\TaskFile;
use App\Models\User;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class GoogleDriveSyncService
{
    private const FILE_FIELDS = 'id,name,mimeType,webViewLink,md5Checksum,appProperties,parents,trashed';

    public function __construct(private GoogleAccessTokenService $tokens, private GoogleApiClient $googleApi) {}

    /**
     * @return array{id: string, name: string, webViewLink: ?string, action: string}
     */
    public function syncFile(User $user, TaskFile $file, ?SyncEvent $syncEvent = null): array
    {
        if ($file->user_id !== $user->id || ! $user->taskFiles()->whereKey($file->getKey())->exists()) {
            throw new RuntimeException('File tidak ditemukan di vault akun ini.');
        }

        $disk = Storage::disk($file->disk);

        if (! $disk->exists($file->path)) {
            throw new RuntimeException('Berkas lokal tidak ditemukan. Unggah ulang file tersebut sebelum sinkronisasi.');
        }

        $connection = $user->integrations()->where('provider', 'google')->where('status', 'connected')->firstOrFail();

        if ($syncEvent && $syncEvent->integration_connection_id !== $connection->getKey()) {
            throw new RuntimeException('Event sinkronisasi tidak cocok dengan koneksi Google akun ini.');
        }

        $accessToken = $this->tokens->for($connection);
        $bytes = $disk->get($file->path);
        $hash = hash('sha256', $file->original_name."\0".($file->mime_type ?: 'application/octet-stream')."\0".$bytes);
        $mapping = $connection->mappings()
            ->where('resource_type', 'task_file')
            ->where('internal_id', (string) $file->id)
            ->first();
        $remote = $mapping ? $this->findById($accessToken, $mapping->external_id) : null;

        if ($remote && (($remote['trashed'] ?? false) === true
            || data_get($remote, 'appProperties.tugasosFileId') !== (string) $file->id)) {
            $remote = null;
        }

        if (! $remote) {
            $remote = $this->findByTaskFileId($accessToken, $file->id);
        }

        $remoteChecksum = $remote['md5Checksum'] ?? null;
        $remoteMatchesLocal = is_string($remoteChecksum) && hash_equals(md5($bytes), $remoteChecksum);

        if ($remote && $mapping?->sync_hash === $hash && $mapping->status === 'synced' && $remoteMatchesLocal) {
            $this->recordEvent($connection, $mapping, $file, 'file_unchanged', 'succeeded', $syncEvent);
            $connection->update(['last_synced_at' => now(), 'last_error' => null]);

            return [
                'id' => $remote['id'],
                'name' => $remote['name'] ?? $file->original_name,
                'webViewLink' => $this->safeWebViewLink($remote['webViewLink'] ?? null),
                'action' => 'unchanged',
            ];
        }

        $folderId = $connection->settings['drive_folder_id'] ?? null;
        $metadata = [
            'name' => $file->original_name,
            'mimeType' => $file->mime_type ?: 'application/octet-stream',
            'appProperties' => ['tugasosFileId' => (string) $file->id],
        ];
        $action = $remote ? 'update_file' : 'create_file';

        if ($remote) {
            $metadata['appProperties'] = $remote['appProperties'] ?? $metadata['appProperties'];
            $result = $this->upload($accessToken, $bytes, $file->mime_type ?: 'application/octet-stream', $metadata, $remote['id']);
        } else {
            if (filled($folderId)) {
                $metadata['parents'] = [$folderId];
            }

            $result = $this->upload($accessToken, $bytes, $file->mime_type ?: 'application/octet-stream', $metadata);
        }

        if (! is_string($result['id'] ?? null)) {
            throw new RuntimeException('Google Drive tidak mengonfirmasi ID file hasil unggah.');
        }

        $savedMapping = $connection->mappings()->updateOrCreate(
            ['resource_type' => 'task_file', 'internal_id' => (string) $file->id],
            [
                'external_id' => $result['id'],
                'external_container_id' => $remote['parents'][0] ?? ($folderId ?: 'root'),
                'sync_hash' => $hash,
                'last_synced_at' => now(),
                'status' => 'synced',
            ],
        );

        $this->recordEvent($connection, $savedMapping, $file, $action, 'succeeded', $syncEvent);
        $connection->update(['last_synced_at' => now(), 'last_error' => null]);

        return [
            'id' => $result['id'],
            'name' => $result['name'] ?? $file->original_name,
            'webViewLink' => $this->safeWebViewLink($result['webViewLink'] ?? null),
            'action' => $action,
        ];
    }

    /**
     * @return array{id: string, name: string, webViewLink: string, action: string}
     */
    public function linkDriveFile(User $user, Task $task, string $fileId): array
    {
        if ($task->user_id !== $user->id || ! $user->tasks()->whereKey($task->getKey())->exists()) {
            throw new RuntimeException('Tugas tidak ditemukan di akun ini.');
        }

        $connection = $user->integrations()->where('provider', 'google')->where('status', 'connected')->firstOrFail();

        if (! in_array('https://www.googleapis.com/auth/drive.file', $connection->scopes ?? [], true)) {
            throw new RuntimeException('Hubungkan ulang Google dan izinkan akses Drive terbatas.');
        }

        $accessToken = $this->tokens->for($connection);
        $remoteFile = $this->googleApi->request($accessToken)
            ->get('https://www.googleapis.com/drive/v3/files/'.rawurlencode($fileId), [
                'fields' => 'id,name,mimeType,webViewLink,trashed,parents',
                'supportsAllDrives' => 'true',
            ])
            ->throw()
            ->json();

        if (! is_array($remoteFile) || ($remoteFile['id'] ?? null) !== $fileId) {
            throw new RuntimeException('Google Drive tidak mengonfirmasi identitas file yang dipilih.');
        }

        $webViewLink = $remoteFile['webViewLink'] ?? null;
        $webViewLink = $this->safeWebViewLink($webViewLink);

        if (($remoteFile['trashed'] ?? false) === true
            || ! is_string($remoteFile['name'] ?? null)
            || $webViewLink === null) {
            throw new RuntimeException('Google Drive tidak mengembalikan file aktif dengan tautan yang valid.');
        }

        return DB::transaction(function () use ($connection, $fileId, $remoteFile, $task, $user, $webViewLink): array {
            $taskLink = $task->links()->firstOrCreate(
                ['url' => $webViewLink],
                [
                    'user_id' => $user->id,
                    'label' => 'Google Drive · '.Str::limit($remoteFile['name'], 235, ''),
                    'kind' => 'reference',
                ],
            );

            $mapping = $connection->mappings()->updateOrCreate(
                ['resource_type' => 'drive_file_link', 'internal_id' => (string) $taskLink->id],
                [
                    'external_id' => $fileId,
                    'external_container_id' => $remoteFile['parents'][0] ?? 'root',
                    'sync_hash' => hash('sha256', $fileId."\0".$webViewLink),
                    'last_synced_at' => now(),
                    'status' => 'linked',
                ],
            );

            $connection->syncEvents()->create([
                'provider' => 'google_drive',
                'direction' => 'inbound',
                'action' => 'link_drive_file',
                'status' => 'succeeded',
                'context' => [
                    'task_id' => $task->id,
                    'task_link_id' => $taskLink->id,
                    'drive_file_id' => $fileId,
                    'mapping_id' => $mapping->id,
                ],
            ]);
            $connection->update(['last_synced_at' => now(), 'last_error' => null]);

            return [
                'id' => $fileId,
                'name' => $remoteFile['name'],
                'webViewLink' => $webViewLink,
                'action' => $taskLink->wasRecentlyCreated ? 'linked' : 'unchanged',
            ];
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findById(string $accessToken, string $fileId): ?array
    {
        try {
            return $this->googleApi->request($accessToken)
                ->get('https://www.googleapis.com/drive/v3/files/'.rawurlencode($fileId), ['fields' => self::FILE_FIELDS])
                ->throw()
                ->json();
        } catch (RequestException $exception) {
            if ($exception->response->status() === 404) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByTaskFileId(string $accessToken, int $taskFileId): ?array
    {
        $query = "appProperties has { key='tugasosFileId' and value='".$taskFileId."' } and trashed=false";
        $response = $this->googleApi->request($accessToken)
            ->get('https://www.googleapis.com/drive/v3/files', [
                'q' => $query,
                'spaces' => 'drive',
                'pageSize' => 1,
                'fields' => 'files('.self::FILE_FIELDS.')',
            ])->throw()->json();

        $file = $response['files'][0] ?? null;

        return is_array($file) && is_string($file['id'] ?? null) ? $file : null;
    }

    private function safeWebViewLink(mixed $webViewLink): ?string
    {
        if (! is_string($webViewLink) || parse_url($webViewLink, PHP_URL_SCHEME) !== 'https') {
            return null;
        }

        $host = strtolower((string) parse_url($webViewLink, PHP_URL_HOST));

        return in_array($host, ['drive.google.com', 'docs.google.com'], true) ? $webViewLink : null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function upload(string $accessToken, string $bytes, string $mimeType, array $metadata, ?string $fileId = null): array
    {
        if (strlen($bytes) > 5 * 1024 * 1024) {
            return $this->resumableUpload($accessToken, $bytes, $mimeType, $metadata, $fileId);
        }

        return $this->multipartUpload($accessToken, $bytes, $mimeType, $metadata, $fileId);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function multipartUpload(string $accessToken, string $bytes, string $mimeType, array $metadata, ?string $fileId): array
    {
        $boundary = 'tugasos_'.bin2hex(random_bytes(16));
        $body = '--'.$boundary."\r\n"
            .'Content-Type: application/json; charset=UTF-8'."\r\n\r\n"
            .json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\r\n"
            .'--'.$boundary."\r\n"
            .'Content-Type: '.$mimeType."\r\n"
            .'Content-Transfer-Encoding: binary'."\r\n\r\n"
            .$bytes."\r\n"
            .'--'.$boundary."--\r\n";
        $url = 'https://www.googleapis.com/upload/drive/v3/files'.($fileId ? '/'.rawurlencode($fileId) : '')
            .'?'.http_build_query(['uploadType' => 'multipart', 'fields' => self::FILE_FIELDS]);
        $request = Http::withToken($accessToken)->acceptJson()->timeout(60)
            ->withBody($body, 'multipart/related; boundary='.$boundary);
        $response = $fileId
            ? $request->patch($url)
            : $request->post($url);

        return $response->throw()->json();
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function resumableUpload(string $accessToken, string $bytes, string $mimeType, array $metadata, ?string $fileId): array
    {
        $url = 'https://www.googleapis.com/upload/drive/v3/files'.($fileId ? '/'.rawurlencode($fileId) : '')
            .'?'.http_build_query(['uploadType' => 'resumable', 'fields' => self::FILE_FIELDS]);
        $request = Http::withToken($accessToken)->acceptJson()->timeout(20)
            ->withHeaders([
                'X-Upload-Content-Type' => $mimeType,
                'X-Upload-Content-Length' => (string) strlen($bytes),
            ])
            ->withBody(json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'application/json');
        $response = $fileId
            ? $request->patch($url)
            : $request->post($url);
        $response->throw();
        $sessionUrl = $response->header('Location');

        if ($sessionUrl === '' || ! str_starts_with($sessionUrl, 'https://www.googleapis.com/upload/')) {
            throw new RuntimeException('Google Drive tidak mengembalikan URL sesi upload yang valid.');
        }

        return Http::withToken($accessToken)->acceptJson()->timeout(120)
            ->withHeaders(['Content-Length' => (string) strlen($bytes)])
            ->withBody($bytes, $mimeType)
            ->put($sessionUrl)
            ->throw()
            ->json();
    }

    private function recordEvent(
        IntegrationConnection $connection,
        ?ExternalResourceMapping $mapping,
        TaskFile $file,
        string $action,
        string $status,
        ?SyncEvent $syncEvent = null,
    ): void {
        $attributes = [
            'provider' => 'google_drive',
            'direction' => 'outbound',
            'action' => $action,
            'status' => $status,
            'message' => $status === 'succeeded' ? 'File Google Drive berhasil dikonfirmasi.' : null,
            'context' => ['task_id' => $file->task_id, 'task_file_id' => $file->id, 'mapping_id' => $mapping?->id],
        ];

        if ($syncEvent) {
            $syncEvent->update([
                ...$attributes,
                'status' => 'running',
                'message' => 'File Drive terkonfirmasi. Referensi tugas sedang disimpan.',
            ]);

            return;
        }

        $connection->syncEvents()->create($attributes);
    }
}

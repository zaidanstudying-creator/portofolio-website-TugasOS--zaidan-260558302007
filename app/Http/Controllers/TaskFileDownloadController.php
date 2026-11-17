<?php

namespace App\Http\Controllers;

use App\Models\TaskFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaskFileDownloadController extends Controller
{
    public function __invoke(Request $request, TaskFile $taskFile): StreamedResponse
    {
        $file = $request->user()->taskFiles()->findOrFail($taskFile->id);
        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);

        $filename = Str::ascii(basename(str_replace('\\', '/', $file->original_name)));

        return Storage::disk($file->disk)->download(
            $file->path,
            $filename !== '' ? $filename : 'task-file-'.$file->id,
            [
                'Content-Type' => $file->mime_type ?: 'application/octet-stream',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}

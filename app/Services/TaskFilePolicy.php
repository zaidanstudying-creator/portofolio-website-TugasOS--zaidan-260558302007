<?php

namespace App\Services;

use App\Models\User;

class TaskFilePolicy
{
    public const MAX_FILE_SIZE_MB = 20;

    public const ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'md', 'png', 'jpg', 'jpeg', 'webp', 'zip'];

    /**
     * @return array{max_size_mb: int, max_size_kb: int, server_max_size_mb: int, server_max_size_kb: int, extensions: list<string>}
     */
    public function forUser(User $user): array
    {
        $settings = $user->settings['file_upload'] ?? [];
        $configuredSize = filter_var($settings['max_size_mb'] ?? self::MAX_FILE_SIZE_MB, FILTER_VALIDATE_INT);
        $extensions = $settings['extensions'] ?? self::ALLOWED_EXTENSIONS;
        $extensions = is_array($extensions)
            ? array_values(array_intersect(self::ALLOWED_EXTENSIONS, array_filter($extensions, 'is_string')))
            : self::ALLOWED_EXTENSIONS;

        if ($extensions === []) {
            $extensions = self::ALLOWED_EXTENSIONS;
        }

        $serverMaxSizeMb = $this->serverMaxSizeMb();
        $serverMaxSizeKb = $this->serverMaxSizeKb();
        $configuredMaxSizeMb = max(1, (int) ($configuredSize ?: self::MAX_FILE_SIZE_MB));

        return [
            'max_size_mb' => min($serverMaxSizeMb, $configuredMaxSizeMb),
            'max_size_kb' => min($serverMaxSizeKb, $configuredMaxSizeMb * 1024),
            'server_max_size_mb' => $serverMaxSizeMb,
            'server_max_size_kb' => $serverMaxSizeKb,
            'extensions' => $extensions,
        ];
    }

    public function serverMaxSizeMb(): int
    {
        return max(1, (int) floor($this->serverMaxSizeKb() / 1024));
    }

    public function serverMaxSizeKb(): int
    {
        $uploadMaxBytes = $this->iniBytes('upload_max_filesize');
        $postMaxBytes = $this->iniBytes('post_max_size');
        $postBodySafetyMargin = 128 * 1024;
        $effectiveBytes = min(
            $uploadMaxBytes,
            max(0, $postMaxBytes - $postBodySafetyMargin),
            self::MAX_FILE_SIZE_MB * 1024 * 1024,
        );

        return max(1, (int) floor($effectiveBytes / 1024));
    }

    /**
     * @param  list<string>  $extensions
     * @return list<string>
     */
    public function mimeValidationExtensions(array $extensions): array
    {
        $mimeExtensions = array_intersect($extensions, ['docx', 'xlsx', 'pptx']) === []
            ? $extensions
            : [...$extensions, 'zip'];

        return array_values(array_unique($mimeExtensions));
    }

    private function iniBytes(string $setting): int
    {
        $value = ini_get($setting);

        if ($value === false || $value === '' || $value === '-1') {
            return PHP_INT_MAX;
        }

        $bytes = ini_parse_quantity($value);

        return $bytes > 0 ? $bytes : PHP_INT_MAX;
    }
}

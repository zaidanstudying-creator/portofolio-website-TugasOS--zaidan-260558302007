<?php

use App\Livewire\Actions\Logout;
use App\Models\User;
use App\Services\ExternalOAuthRevocationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

new class extends Component
{
    public string $password = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout, ExternalOAuthRevocationService $revocations): void
    {
        $this->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        $fileLocations = $user->taskFiles()
            ->get(['id', 'disk', 'path'])
            ->map(fn ($file): array => [
                'id' => $file->id,
                'disk' => $file->disk,
                'path' => $file->path,
            ])
            ->all();
        $userId = (int) $user->getAuthIdentifier();

        foreach ($user->integrations()->get() as $connection) {
            if (! $revocations->revoke($connection)) {
                $message = $connection->provider === 'github'
                    && (! filled(config('services.github.client_id')) || ! filled(config('services.github.client_secret')))
                    ? 'Isi GITHUB_CLIENT_ID dan GITHUB_CLIENT_SECRET agar akses GitHub dapat dicabut. Akun dan datanya tetap tersimpan.'
                    : 'Akses '.$connection->provider.' belum berhasil dicabut. Akun dan datanya tetap tersimpan; coba lagi.';

                $this->addError('account', $message);

                return;
            }

            $connection->delete();
        }

        $logout();
        $user->delete();

        foreach ($fileLocations as $fileLocation) {
            try {
                if (! Storage::disk($fileLocation['disk'])->delete($fileLocation['path'])) {
                    Log::warning('Private task file could not be removed after account deletion.', [
                        'user_id' => $userId,
                        'task_file_id' => $fileLocation['id'],
                    ]);
                }
            } catch (Throwable $exception) {
                Log::warning('Private task file could not be removed after account deletion.', [
                    'user_id' => $userId,
                    'task_file_id' => $fileLocation['id'],
                    'exception' => $exception::class,
                ]);
            }
        }

        $this->redirect('/', navigate: true);
    }
}; ?>

<flux:modal name="confirm-user-deletion" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
    <form method="POST" wire:submit="deleteUser" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Are you sure you want to delete your account?') }}</flux:heading>

            <flux:subheading>
                {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}
            </flux:subheading>
            @error('account')<p role="alert" class="mt-3 border-2 border-black bg-[#ffb7a9] p-3 text-sm font-bold">{{ $message }}</p>@enderror
        </div>

        <flux:input wire:model="password" :label="__('Password')" type="password" viewable />

        <div class="flex justify-end space-x-2 rtl:space-x-reverse">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>

            <flux:button variant="danger" type="submit" data-test="confirm-delete-user-button">
                {{ __('Delete account') }}
            </flux:button>
        </div>
    </form>
</flux:modal>

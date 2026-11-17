<?php

use App\Services\TwoFactorAuthenticationService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Security settings')] class extends Component
{
    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $twoFactorPassword = '';

    public string $twoFactorCode = '';

    #[Locked]
    public string $manualSetupKey = '';

    #[Locked]
    public array $newRecoveryCodes = [];

    #[Computed]
    public function twoFactorEnabled(): bool
    {
        return app(TwoFactorAuthenticationService::class)->hasEnabled(auth()->user());
    }

    public function updatePassword(): void
    {
        $validated = $this->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', Password::min(8), 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ]);

        Auth::logoutOtherDevices($validated['current_password']);

        auth()->user()->forceFill([
            'password' => Hash::make($validated['password']),
        ])->save();

        $this->reset(['current_password', 'password', 'password_confirmation']);
        session()->flash('status', 'Kata sandi berhasil diperbarui.');
        Flux::toast(variant: 'success', text: __('Password updated.'));
    }

    public function startTwoFactorSetup(TwoFactorAuthenticationService $twoFactor): void
    {
        $validated = $this->validate([
            'twoFactorPassword' => ['required', 'string'],
        ]);

        $throttleKey = 'two-factor-setup-password:'.auth()->id();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $this->addError('twoFactorPassword', 'Terlalu banyak percobaan. Coba lagi dalam satu menit.');

            return;
        }

        if (! Hash::check($validated['twoFactorPassword'], auth()->user()->getAuthPassword())) {
            RateLimiter::hit($throttleKey, 60);
            $this->addError('twoFactorPassword', 'Kata sandi saat ini tidak cocok.');

            return;
        }

        RateLimiter::clear($throttleKey);

        try {
            $this->manualSetupKey = $twoFactor->beginSetup(auth()->user());
            $this->reset('twoFactorPassword', 'twoFactorCode');
            $this->resetErrorBag();
        } catch (\Throwable) {
            $this->addError('twoFactorPassword', 'Verifikasi dua langkah sudah aktif atau tidak dapat disiapkan.');
        }
    }

    public function confirmTwoFactorSetup(TwoFactorAuthenticationService $twoFactor): void
    {
        $validated = $this->validate([
            'twoFactorCode' => ['required', 'digits:6'],
        ]);
        if (! $this->canAttemptTwoFactorCode()) {
            return;
        }

        $recoveryCodes = $twoFactor->confirmSetup(auth()->user(), $validated['twoFactorCode']);

        if ($recoveryCodes === null) {
            RateLimiter::hit($this->twoFactorThrottleKey(), 60);
            $this->addError('twoFactorCode', 'Kode tidak cocok. Periksa waktu perangkat authenticator dan coba lagi.');

            return;
        }

        RateLimiter::clear($this->twoFactorThrottleKey());
        $this->newRecoveryCodes = $recoveryCodes;
        $this->manualSetupKey = '';
        $this->reset('twoFactorPassword', 'twoFactorCode');
        session()->flash('status', 'Verifikasi dua langkah aktif. Simpan recovery code ini sekarang.');
    }

    public function regenerateTwoFactorRecoveryCodes(TwoFactorAuthenticationService $twoFactor): void
    {
        $validated = $this->validate([
            'twoFactorCode' => ['required', 'digits:6'],
        ]);
        if (! $this->canAttemptTwoFactorCode()) {
            return;
        }

        $recoveryCodes = $twoFactor->regenerateRecoveryCodes(auth()->user(), $validated['twoFactorCode']);

        if ($recoveryCodes === null) {
            RateLimiter::hit($this->twoFactorThrottleKey(), 60);
            $this->addError('twoFactorCode', 'Kode authenticator tidak cocok. Recovery code lama tetap aktif.');

            return;
        }

        RateLimiter::clear($this->twoFactorThrottleKey());
        $this->newRecoveryCodes = $recoveryCodes;
        $this->reset('twoFactorCode');
        session()->flash('status', 'Recovery code baru dibuat. Kode sebelumnya sudah tidak berlaku.');
    }

    public function disableTwoFactor(TwoFactorAuthenticationService $twoFactor): void
    {
        $validated = $this->validate([
            'twoFactorPassword' => ['required', 'string'],
            'twoFactorCode' => ['required', 'string', 'max:64'],
        ]);
        $passwordThrottleKey = 'two-factor-disable-password:'.auth()->id();

        if (RateLimiter::tooManyAttempts($passwordThrottleKey, 5)) {
            $this->addError('twoFactorPassword', 'Terlalu banyak percobaan. Coba lagi dalam satu menit.');

            return;
        }

        if (! Hash::check($validated['twoFactorPassword'], auth()->user()->getAuthPassword())) {
            RateLimiter::hit($passwordThrottleKey, 60);
            $this->addError('twoFactorPassword', 'Kata sandi saat ini tidak cocok.');

            return;
        }

        RateLimiter::clear($passwordThrottleKey);

        if (! $this->canAttemptTwoFactorCode()) {
            return;
        }

        if (! $twoFactor->disable(auth()->user(), $validated['twoFactorCode'])) {
            RateLimiter::hit($this->twoFactorThrottleKey(), 60);
            $this->addError('twoFactorCode', 'Kode authenticator atau recovery code tidak cocok.');

            return;
        }

        RateLimiter::clear($this->twoFactorThrottleKey());
        $this->manualSetupKey = '';
        $this->newRecoveryCodes = [];
        $this->reset('twoFactorPassword', 'twoFactorCode');
        session()->flash('status', 'Verifikasi dua langkah dinonaktifkan.');
    }

    private function canAttemptTwoFactorCode(): bool
    {
        if (! RateLimiter::tooManyAttempts($this->twoFactorThrottleKey(), 5)) {
            return true;
        }

        $this->addError('twoFactorCode', 'Terlalu banyak percobaan. Coba lagi dalam satu menit.');

        return false;
    }

    private function twoFactorThrottleKey(): string
    {
        return 'two-factor-security:'.auth()->id();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Ubah kata sandi')" :subheading="__('Gunakan kata sandi yang kuat untuk menjaga akunmu.')">
        @if (session('status'))
            <div role="status" aria-live="polite" class="mb-5 border-2 border-black bg-[#b8edce] px-4 py-3 text-sm font-semibold">{{ session('status') }}</div>
        @endif

        <form wire:submit="updatePassword" class="mt-6 space-y-5">
            <label class="grid gap-2 text-sm font-bold">Kata sandi saat ini<input wire:model="current_password" type="password" required autocomplete="current-password" class="min-h-12 border-2 border-black bg-white px-3 py-3 text-base font-normal">@error('current_password')<span class="font-semibold text-red-800">{{ $message }}</span>@enderror</label>
            <label class="grid gap-2 text-sm font-bold">Kata sandi baru<input wire:model="password" type="password" required minlength="8" autocomplete="new-password" class="min-h-12 border-2 border-black bg-white px-3 py-3 text-base font-normal">@error('password')<span class="font-semibold text-red-800">{{ $message }}</span>@enderror</label>
            <label class="grid gap-2 text-sm font-bold">Ulangi kata sandi<input wire:model="password_confirmation" type="password" required minlength="8" autocomplete="new-password" class="min-h-12 border-2 border-black bg-white px-3 py-3 text-base font-normal"></label>
            <button type="submit" data-test="update-password-button" class="min-h-12 border-2 border-black bg-[#facc15] px-5 py-3 font-black shadow-[4px_4px_0_#171717] transition hover:translate-x-0.5 hover:translate-y-0.5 hover:shadow-none">Simpan kata sandi</button>
        </form>

        <section class="mt-10 border-t-2 border-black pt-6" aria-labelledby="two-factor-heading">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div><h2 id="two-factor-heading" class="text-2xl font-black">Verifikasi dua langkah</h2><p class="mt-1 max-w-xl text-sm leading-6 text-[#555]">Gunakan aplikasi authenticator yang mendukung TOTP. Secret dan recovery code disimpan terenkripsi.</p></div>
                <span class="border-2 border-black px-3 py-1 text-xs font-black uppercase {{ $this->twoFactorEnabled ? 'bg-[#b8edce]' : 'bg-[#eeeae0]' }}">{{ $this->twoFactorEnabled ? 'Aktif' : 'Nonaktif' }}</span>
            </div>

            @if(! $this->twoFactorEnabled)
                @if($manualSetupKey !== '')
                    <div class="mt-5 grid gap-4 border-2 border-black bg-[#fff1b8] p-4">
                        <p class="text-sm font-semibold">Tambahkan akun ini secara manual ke authenticator. Masukkan secret di bawah, lalu ketik kode enam digit untuk mengaktifkannya.</p>
                        <label class="grid gap-1 text-sm font-bold">Secret setup<input readonly value="{{ $manualSetupKey }}" class="min-h-12 select-all border-2 border-black bg-white px-3 py-2 font-mono text-base tracking-widest"></label>
                        <form wire:submit="confirmTwoFactorSetup" class="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
                            <label class="grid gap-1 text-sm font-bold">Kode dari authenticator<input wire:model="twoFactorCode" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required class="min-h-12 border-2 border-black bg-white px-3 py-2 font-mono text-lg tracking-[0.3em]">@error('twoFactorCode')<span role="alert" class="text-sm text-red-800">{{ $message }}</span>@enderror</label>
                            <button class="min-h-12 border-2 border-black bg-[#b8edce] px-4 py-2 font-black">Aktifkan verifikasi</button>
                        </form>
                    </div>
                @else
                    <form wire:submit="startTwoFactorSetup" class="mt-5 grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
                        <label class="grid gap-1 text-sm font-bold">Konfirmasi kata sandi<input wire:model="twoFactorPassword" type="password" autocomplete="current-password" required class="min-h-12 border-2 border-black bg-white px-3 py-2">@error('twoFactorPassword')<span role="alert" class="text-sm text-red-800">{{ $message }}</span>@enderror</label>
                        <button class="min-h-12 border-2 border-black bg-[#facc15] px-4 py-2 font-black shadow-[3px_3px_0_#171717]">Siapkan verifikasi dua langkah</button>
                    </form>
                @endif
            @else
                <div class="mt-5 grid gap-4">
                    <form wire:submit="disableTwoFactor" class="grid gap-3 border-2 border-black bg-white p-4 sm:grid-cols-2">
                        <label class="grid gap-1 text-sm font-bold">Kata sandi saat ini<input wire:model="twoFactorPassword" type="password" autocomplete="current-password" required class="min-h-12 border-2 border-black bg-white px-3 py-2">@error('twoFactorPassword')<span role="alert" class="text-sm text-red-800">{{ $message }}</span>@enderror</label>
                        <label class="grid gap-1 text-sm font-bold">Kode authenticator atau recovery code<input wire:model="twoFactorCode" autocomplete="one-time-code" required class="min-h-12 border-2 border-black bg-white px-3 py-2 font-mono">@error('twoFactorCode')<span role="alert" class="text-sm text-red-800">{{ $message }}</span>@enderror</label>
                        <button class="min-h-12 border-2 border-black bg-[#ffb7a9] px-4 py-2 font-black sm:col-span-2">Nonaktifkan verifikasi dua langkah</button>
                    </form>
                    <form wire:submit="regenerateTwoFactorRecoveryCodes" class="grid gap-3 border-2 border-black bg-[#f7f4ec] p-4 sm:grid-cols-[1fr_auto] sm:items-end">
                        <label class="grid gap-1 text-sm font-bold">Kode authenticator untuk mengganti semua recovery code<input wire:model="twoFactorCode" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required class="min-h-12 border-2 border-black bg-white px-3 py-2 font-mono">@error('twoFactorCode')<span role="alert" class="text-sm text-red-800">{{ $message }}</span>@enderror</label>
                        <button class="min-h-12 border-2 border-black bg-[#dceaff] px-4 py-2 font-black">Buat recovery code baru</button>
                    </form>
                </div>
            @endif

            @if($newRecoveryCodes !== [])
                <div role="status" class="mt-5 border-2 border-black bg-[#b8edce] p-4">
                    <h3 class="font-black">Simpan recovery code ini sekarang</h3>
                    <p class="mt-1 text-xs">Setiap kode hanya dapat dipakai sekali. Kode tidak ditampilkan lagi setelah halaman dimuat ulang.</p>
                    <ul class="mt-3 grid gap-2 font-mono text-sm sm:grid-cols-2">@foreach($newRecoveryCodes as $recoveryCode)<li wire:key="recovery-code-{{ $loop->index }}" class="border border-black bg-white px-3 py-2">{{ $recoveryCode }}</li>@endforeach</ul>
                </div>
            @endif
        </section>
    </x-pages::settings.layout>
</section>

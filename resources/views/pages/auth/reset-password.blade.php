<x-layouts::auth :title="__('Kata sandi baru')">
    <div class="space-y-8">
        <div>
            <h2 class="text-4xl font-black tracking-tight [text-wrap:balance]">Buat kata sandi baru</h2>
            <p class="mt-3 text-base leading-7 text-[#555]">Gunakan sedikitnya 8 karakter untuk melindungi akunmu.</p>
        </div>

        <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <div class="space-y-2">
                <label for="email" class="block text-sm font-bold">Alamat email</label>
                <input id="email" name="email" type="email" value="{{ old('email', $email) }}" required autocomplete="email" inputmode="email" class="min-h-12 w-full border-2 border-black bg-white px-3 py-3 text-base outline-offset-2 focus-visible:outline-2 focus-visible:outline-black">
                @error('email')<p role="alert" class="text-sm font-semibold text-red-800">{{ $message }}</p>@enderror
            </div>
            <div class="space-y-2">
                <label for="password" class="block text-sm font-bold">Kata sandi baru</label>
                <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password" class="min-h-12 w-full border-2 border-black bg-white px-3 py-3 text-base outline-offset-2 focus-visible:outline-2 focus-visible:outline-black">
                @error('password')<p role="alert" class="text-sm font-semibold text-red-800">{{ $message }}</p>@enderror
            </div>
            <div class="space-y-2">
                <label for="password_confirmation" class="block text-sm font-bold">Ulangi kata sandi</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required minlength="8" autocomplete="new-password" class="min-h-12 w-full border-2 border-black bg-white px-3 py-3 text-base outline-offset-2 focus-visible:outline-2 focus-visible:outline-black">
            </div>
            <button type="submit" data-test="reset-password-button" class="min-h-12 w-full border-2 border-black bg-[#facc15] px-5 py-3 font-black shadow-[4px_4px_0_#171717] transition hover:translate-x-0.5 hover:translate-y-0.5 hover:shadow-none focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-black">Simpan kata sandi</button>
        </form>
    </div>
</x-layouts::auth>

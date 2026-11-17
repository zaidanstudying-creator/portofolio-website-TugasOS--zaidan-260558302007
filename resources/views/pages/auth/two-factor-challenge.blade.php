<x-layouts::auth :title="__('Verifikasi akun')">
    <div class="space-y-8">
        <div>
            <h1 class="text-4xl font-black tracking-tight [text-wrap:balance]">Verifikasi akun</h1>
            <p class="mt-3 text-base leading-7 text-[#555]">Masukkan kode dari aplikasi authenticator atau salah satu recovery code.</p>
        </div>

        <form method="POST" action="{{ route('two-factor.login.store') }}" class="space-y-5">
            @csrf

            <div class="space-y-2">
                <label for="code" class="block text-sm font-bold">Kode autentikasi atau recovery code</label>
                <input id="code" name="code" type="text" value="{{ old('code') }}" required autofocus autocomplete="one-time-code" inputmode="text" aria-invalid="{{ $errors->has('code') ? 'true' : 'false' }}" @if ($errors->has('code')) aria-describedby="code-error" @endif class="min-h-12 w-full border-2 border-black bg-white px-3 py-3 font-mono text-base uppercase tracking-wider outline-offset-2 focus-visible:outline-2 focus-visible:outline-black">
                @error('code')
                    <p id="code-error" role="alert" class="text-sm font-semibold text-red-800">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="min-h-12 w-full border-2 border-black bg-[#facc15] px-5 py-3 font-black shadow-[4px_4px_0_#171717] transition hover:translate-x-0.5 hover:translate-y-0.5 hover:shadow-none focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-black">Verifikasi dan masuk</button>
        </form>

        <p class="border-t-2 border-black pt-6 text-sm font-semibold text-[#555]">Tidak dapat melanjutkan? <a href="{{ route('login') }}" class="font-black text-black underline decoration-2 underline-offset-4">Kembali ke halaman masuk</a></p>
    </div>
</x-layouts::auth>

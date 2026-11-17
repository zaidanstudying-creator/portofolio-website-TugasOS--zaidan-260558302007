<x-layouts::auth :title="__('Atur ulang kata sandi')">
    <div class="space-y-8">
        <div>
            <h2 class="text-4xl font-black tracking-tight [text-wrap:balance]">Lupa kata sandi?</h2>
            <p class="mt-3 text-base leading-7 text-[#555]">Masukkan email akunmu. Jika terdaftar, kami akan mengirim tautan untuk membuat kata sandi baru.</p>
        </div>

        @if (session('status'))
            <div role="status" aria-live="polite" class="border-2 border-black bg-[#b8edce] px-4 py-3 text-sm font-semibold">{{ session('status') }}</div>
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
            @csrf
            <div class="space-y-2">
                <label for="email" class="block text-sm font-bold">Alamat email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email" inputmode="email" aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}" @if ($errors->has('email')) aria-describedby="email-error" @endif class="min-h-12 w-full border-2 border-black bg-white px-3 py-3 text-base outline-offset-2 focus-visible:outline-2 focus-visible:outline-black">
                @error('email')
                    <p id="email-error" role="alert" class="text-sm font-semibold text-red-800">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit" class="min-h-12 w-full border-2 border-black bg-[#facc15] px-5 py-3 font-black shadow-[4px_4px_0_#171717] transition hover:translate-x-0.5 hover:translate-y-0.5 hover:shadow-none focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-black">Kirim tautan reset</button>
        </form>

        <p class="border-t-2 border-black pt-6 text-sm font-semibold text-[#555]"><a href="{{ route('login') }}" class="font-black text-black underline decoration-2 underline-offset-4">Kembali masuk</a></p>
    </div>
</x-layouts::auth>

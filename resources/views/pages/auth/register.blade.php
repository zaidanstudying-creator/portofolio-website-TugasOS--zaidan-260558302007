<x-layouts::auth :title="__('Buat akun')">
    <div class="space-y-8">
        <div>
            <h2 class="text-4xl font-black tracking-tight [text-wrap:balance]">Buat ruang kerjamu</h2>
            <p class="mt-3 text-base leading-7 text-[#555]">Satu akun pribadi untuk mengatur kehidupan akademikmu.</p>
        </div>

        <form method="POST" action="{{ route('register.store') }}" class="space-y-5">
            @csrf

            <div class="space-y-2">
                <label for="name" class="block text-sm font-bold">Nama lengkap</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" required autofocus autocomplete="name" aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}" @if ($errors->has('name')) aria-describedby="name-error" @endif class="min-h-12 w-full border-2 border-black bg-white px-3 py-3 text-base outline-offset-2 focus-visible:outline-2 focus-visible:outline-black">
                @error('name')
                    <p id="name-error" role="alert" class="text-sm font-semibold text-red-800">{{ $message }}</p>
                @enderror
            </div>

            <div class="space-y-2">
                <label for="email" class="block text-sm font-bold">Alamat email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email" inputmode="email" aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}" @if ($errors->has('email')) aria-describedby="email-error" @endif class="min-h-12 w-full border-2 border-black bg-white px-3 py-3 text-base outline-offset-2 focus-visible:outline-2 focus-visible:outline-black">
                @error('email')
                    <p id="email-error" role="alert" class="text-sm font-semibold text-red-800">{{ $message }}</p>
                @enderror
            </div>

            <div class="space-y-2">
                <label for="password" class="block text-sm font-bold">Kata sandi <span class="font-normal text-[#666]">(minimal 8 karakter)</span></label>
                <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password" aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}" @if ($errors->has('password')) aria-describedby="password-error" @endif class="min-h-12 w-full border-2 border-black bg-white px-3 py-3 text-base outline-offset-2 focus-visible:outline-2 focus-visible:outline-black">
                @error('password')
                    <p id="password-error" role="alert" class="text-sm font-semibold text-red-800">{{ $message }}</p>
                @enderror
            </div>

            <div class="space-y-2">
                <label for="password_confirmation" class="block text-sm font-bold">Ulangi kata sandi</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required minlength="8" autocomplete="new-password" class="min-h-12 w-full border-2 border-black bg-white px-3 py-3 text-base outline-offset-2 focus-visible:outline-2 focus-visible:outline-black">
            </div>

            <button type="submit" data-test="register-user-button" class="min-h-12 w-full border-2 border-black bg-[#facc15] px-5 py-3 font-black shadow-[4px_4px_0_#171717] transition hover:translate-x-0.5 hover:translate-y-0.5 hover:shadow-none focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-black">Buat akun</button>
        </form>

        <p class="border-t-2 border-black pt-6 text-sm font-semibold text-[#555]">Sudah punya akun? <a href="{{ route('login') }}" class="ml-1 font-black text-black underline decoration-2 underline-offset-4">Masuk</a></p>
    </div>
</x-layouts::auth>

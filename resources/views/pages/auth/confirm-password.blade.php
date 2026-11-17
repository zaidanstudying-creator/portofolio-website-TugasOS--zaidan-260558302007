<x-layouts::auth :title="__('Konfirmasi kata sandi')">
    <div class="space-y-7">
        <div><p class="text-xs font-black uppercase tracking-[0.24em] text-[#555]">TUGAS!OS / Security</p><h2 class="mt-2 text-3xl font-black">Konfirmasi identitas</h2><p class="mt-3 text-sm leading-6 text-[#555]">Masukkan kata sandi akunmu sebelum melanjutkan.</p></div>
        <form method="POST" action="{{ route('password.confirm.store') }}" class="space-y-5">
            @csrf
            <label for="password" class="grid gap-2 text-sm font-bold">Kata sandi<input id="password" name="password" type="password" required autocomplete="current-password" class="min-h-12 w-full border-2 border-black bg-white px-3 py-3 text-base outline-offset-2 focus-visible:outline-2 focus-visible:outline-black">@error('password')<span role="alert" class="text-sm text-red-800">{{ $message }}</span>@enderror</label>
            <button type="submit" data-test="confirm-password-button" class="min-h-12 w-full border-2 border-black bg-[#facc15] px-5 py-3 font-black shadow-[4px_4px_0_#171717]">Konfirmasi</button>
        </form>
    </div>
</x-layouts::auth>

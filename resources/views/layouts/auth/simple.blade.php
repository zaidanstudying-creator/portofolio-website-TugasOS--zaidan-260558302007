@props(['title' => null])

<!DOCTYPE html>
<html lang="id">
    <head>
        @include('partials.head', ['title' => $title ? $title.' · TUGAS!OS' : 'TUGAS!OS'])
    </head>
    <body class="min-h-screen bg-[#f8f7f2] text-[#171717] antialiased">
        <main class="grid min-h-svh lg:grid-cols-[minmax(0,1fr)_minmax(28rem,0.85fr)]">
            <aside class="relative hidden overflow-hidden border-r-2 border-black bg-[#facc15] p-10 lg:flex lg:flex-col lg:justify-between xl:p-14">
                <a href="{{ route('home') }}" class="relative z-10 flex w-fit items-center gap-3 text-2xl font-black tracking-tight">
                    <span aria-hidden="true" class="grid size-10 place-items-center border-2 border-black bg-white">
                        <svg viewBox="0 0 24 24" class="size-7" fill="none" stroke="currentColor" stroke-linecap="square" stroke-width="3"><path d="m5 12 4 4L19 6" /></svg>
                    </span>
                    TUGAS!OS
                </a>

                <div class="relative z-10 max-w-xl py-12">
                    <h1 class="text-6xl font-black leading-[0.95] tracking-[-0.06em] [text-wrap:balance]">Semua tugas, satu kendali.</h1>
                    <p class="mt-6 max-w-lg text-xl leading-8">Satu ruang untuk deadline, checklist, dan progres kuliahmu.</p>
                </div>

                <div aria-hidden="true" class="absolute -bottom-24 -right-24 grid size-[27rem] place-items-center border-2 border-black bg-[#b8edce]">
                    <div class="grid size-[17rem] place-items-center border-2 border-black bg-white">
                        <svg viewBox="0 0 100 100" class="size-[12rem]" fill="none" stroke="currentColor" stroke-linecap="square" stroke-width="9"><path d="m15 52 23 23 48-51" /></svg>
                    </div>
                </div>

                <p class="relative z-10 text-sm font-semibold">Ruang kerja akademik pribadi.</p>
            </aside>

            <section class="flex min-h-svh flex-col justify-center px-5 py-8 sm:px-10 lg:px-12 xl:px-20">
                <a href="{{ route('home') }}" class="mb-10 flex w-fit items-center gap-3 text-xl font-black tracking-tight lg:hidden">
                    <span aria-hidden="true" class="grid size-9 place-items-center border-2 border-black bg-[#facc15]">
                        <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-linecap="square" stroke-width="3"><path d="m5 12 4 4L19 6" /></svg>
                    </span>
                    TUGAS!OS
                </a>

                <div class="mx-auto w-full max-w-md">
                    {{ $slot }}
                </div>

                <p class="mx-auto mt-10 w-full max-w-md text-xs font-semibold text-[#666]">Dengan melanjutkan, kamu menggunakan ruang kerja pribadi TUGAS!OS.</p>
            </section>
        </main>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>

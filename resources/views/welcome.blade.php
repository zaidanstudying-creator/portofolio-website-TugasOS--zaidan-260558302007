<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#f8f7f2">
        <meta name="description" content="TUGAS!OS adalah ruang kerja untuk mengatur tugas akademik, deadline, dan progres belajar.">
        <title>TUGAS!OS — Kendali tugas kuliahmu</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-[#f8f7f2] text-[#171717] antialiased">
        <header class="border-b-2 border-black bg-white">
            <div class="mx-auto flex max-w-[1440px] items-center justify-between gap-6 px-5 py-4 sm:px-8 lg:px-12">
                <a href="{{ route('home') }}" aria-label="TUGAS!OS beranda" class="flex items-center gap-3 font-black tracking-tight">
                    <span aria-hidden="true" class="grid size-9 place-items-center border-2 border-black bg-[#facc15] shadow-[3px_3px_0_#171717]"><svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-linecap="square" stroke-width="3"><path d="m5 12 4 4L19 6" /></svg></span>
                    <span class="text-xl sm:text-2xl">TUGAS!OS</span>
                </a>
                <nav aria-label="Navigasi utama" class="flex items-center gap-4 text-sm font-bold sm:gap-7">
                    <a href="#fitur" class="hidden underline-offset-4 hover:underline sm:inline">Fitur</a>
                    <a href="#cara-kerja" class="hidden underline-offset-4 hover:underline sm:inline">Cara kerja</a>
                    @if (Route::has('login'))
                        <a href="{{ route('login') }}" class="px-2 py-2 underline-offset-4 hover:underline">Masuk</a>
                    @endif
                    @if (Route::has('register'))
                        <a href="{{ route('register') }}" class="border-2 border-black bg-[#facc15] px-4 py-2.5 shadow-[3px_3px_0_#171717] transition hover:translate-x-0.5 hover:translate-y-0.5 hover:shadow-none">Buat akun</a>
                    @endif
                </nav>
            </div>
        </header>

        <main>
            <section class="mx-auto grid max-w-[1440px] items-center gap-12 px-5 py-14 sm:px-8 sm:py-20 lg:min-h-[680px] lg:grid-cols-[0.95fr_1.05fr] lg:gap-10 lg:px-12 lg:py-20">
                <div data-reveal class="relative z-10 max-w-2xl">
                    <h1 class="max-w-[12ch] text-[clamp(3.25rem,7vw,6.75rem)] font-black leading-[0.94] tracking-[-0.065em] [text-wrap:balance]">Semua tugas, satu kendali.</h1>
                    <p class="mt-6 max-w-xl text-lg leading-8 text-[#444] sm:text-xl">Atur deadline, pecah pekerjaan jadi checklist, lalu lihat progres kuliahmu dengan jelas.</p>
                    <div class="mt-8 flex flex-wrap items-center gap-x-6 gap-y-4">
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="inline-flex min-h-12 items-center border-2 border-black bg-[#facc15] px-5 py-3 font-black shadow-[4px_4px_0_#171717] transition hover:translate-x-0.5 hover:translate-y-0.5 hover:shadow-none">Mulai atur tugas <span aria-hidden="true" class="ml-3">↗</span></a>
                        @endif
                        @if (Route::has('login'))
                            <a href="{{ route('login') }}" class="min-h-12 py-3 font-bold underline decoration-2 underline-offset-4">Saya sudah punya akun</a>
                        @endif
                    </div>
                    <p class="mt-8 text-sm font-semibold text-[#666]">Dibuat untuk satu ruang kerja yang tetap rapi, dari tugas harian sampai proyek semester.</p>
                </div>

                <div data-reveal="late" class="relative mx-auto w-full max-w-[650px] lg:justify-self-end">
                    <div aria-hidden="true" class="absolute -right-2 -top-5 size-28 border-2 border-black bg-[#facc15] sm:-right-4 sm:-top-8 sm:size-40"></div>
                    <div aria-hidden="true" class="absolute -bottom-5 -left-3 size-24 border-2 border-black bg-[#b8edce] sm:-bottom-7 sm:-left-5 sm:size-32"></div>
                    <article data-ui="task-preview" class="relative border-2 border-black bg-white p-5 shadow-[8px_8px_0_#171717] sm:p-7">
                        <div class="flex flex-wrap items-start justify-between gap-4 border-b-2 border-black pb-4">
                            <div>
                                <h2 class="text-2xl font-black sm:text-3xl">Contoh daftar tugas</h2>
                            </div>
                                <span class="border-2 border-black bg-[#ffd9d1] px-3 py-1.5 text-sm font-black">2 tenggat dekat</span>
                        </div>

                        <div class="mt-5 grid gap-4">
                            <section class="border-2 border-black bg-[#fff1b8] p-4 sm:p-5" aria-labelledby="preview-task-title">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <p class="text-sm font-bold">Basis Data <span class="mx-1 text-[#777]">/</span> Prioritas tinggi</p>
                                    <span class="border-2 border-black bg-white px-2.5 py-1 text-sm font-black">BESOK · 23.59</span>
                                </div>
                                <h3 id="preview-task-title" class="mt-3 text-xl font-black sm:text-2xl">Rancang skema basis data</h3>
                                <fieldset data-preview-checklist class="mt-4 grid gap-2.5">
                                    <legend class="mb-2 text-sm font-bold">Coba centang checklist</legend>
                                    <label class="flex min-h-11 cursor-pointer items-center gap-3 border-2 border-black bg-white px-3 py-2 text-sm font-semibold"><input type="checkbox" checked class="size-5 accent-black"> Identifikasi entitas dan relasi</label>
                                    <label class="flex min-h-11 cursor-pointer items-center gap-3 border-2 border-black bg-white px-3 py-2 text-sm font-semibold"><input type="checkbox" checked class="size-5 accent-black"> Buat diagram ERD</label>
                                    <label class="flex min-h-11 cursor-pointer items-center gap-3 border-2 border-black bg-white px-3 py-2 text-sm font-semibold"><input type="checkbox" class="size-5 accent-black"> Tulis migration tabel</label>
                                </fieldset>
                                <div class="mt-4 flex items-center gap-3">
                                    <progress data-preview-progress value="2" max="3" aria-label="Progres contoh tugas" class="h-3 w-full accent-black"></progress>
                                    <output data-preview-output aria-live="polite" class="min-w-12 text-right text-sm font-black tabular-nums">67%</output>
                                </div>
                            </section>

                            <section class="flex flex-wrap items-center justify-between gap-3 border-2 border-black bg-[#dceaff] p-4" aria-label="Contoh tugas lain">
                                <div><p class="text-sm font-bold">Pemrograman Web</p><p class="mt-1 font-black">Halaman profil responsif</p></div>
                                <span class="border-2 border-black bg-white px-3 py-1.5 text-sm font-black">SEDANG DIKERJAKAN</span>
                            </section>
                        </div>
                        <p class="mt-4 text-sm font-semibold text-[#666]">Data pada pratinjau ini hanya contoh.</p>
                    </article>
                </div>
            </section>

            <section data-reveal id="fitur" class="border-y-2 border-black bg-[#facc15]">
                <div class="mx-auto grid max-w-[1440px] gap-8 px-5 py-16 sm:px-8 lg:grid-cols-[0.8fr_1.2fr] lg:gap-16 lg:px-12 lg:py-20">
                    <div>
                        <h2 class="max-w-[11ch] text-4xl font-black leading-tight tracking-tight sm:text-5xl [text-wrap:balance]">Pekerjaan jelas. Progres terukur.</h2>
                        <p class="mt-5 max-w-md text-lg leading-7">Setiap tugas punya tempat, konteks, dan langkah berikutnya.</p>
                    </div>
                    <ul class="grid gap-0 border-y-2 border-black">
                        <li class="grid gap-2 border-b-2 border-black py-5 sm:grid-cols-[minmax(9rem,0.55fr)_1fr] sm:gap-6"><h3 class="text-xl font-black">Deadline dan prioritas</h3><p class="leading-7">Lihat pekerjaan yang perlu perhatian lebih dulu dan kapan harus selesai.</p></li>
                        <li class="grid gap-2 border-b-2 border-black py-5 sm:grid-cols-[minmax(9rem,0.55fr)_1fr] sm:gap-6"><h3 class="text-xl font-black">Checklist yang dihitung</h3><p class="leading-7">Tandai langkah yang selesai. Progres tugas mengikuti checklist dan pekerjaan anaknya.</p></li>
                        <li class="grid gap-2 py-5 sm:grid-cols-[minmax(9rem,0.55fr)_1fr] sm:gap-6"><h3 class="text-xl font-black">Konteks akademik</h3><p class="leading-7">Hubungkan tugas dengan mata kuliah, semester, kategori, dan pemberi tugas.</p></li>
                    </ul>
                </div>
            </section>

            <section data-reveal id="cara-kerja" class="mx-auto grid max-w-[1440px] gap-10 px-5 py-16 sm:px-8 lg:grid-cols-[0.75fr_1.25fr] lg:gap-16 lg:px-12 lg:py-20">
                <div>
                    <h2 class="max-w-[14ch] text-4xl font-black leading-tight tracking-tight sm:text-5xl [text-wrap:balance]">Siapkan ruang kerja.</h2>
                    <p class="mt-5 max-w-md text-lg leading-7 text-[#444]">Siapkan konteks akademik sekali, lalu gunakan kembali saat mencatat tugas baru.</p>
                </div>
                <ol class="grid gap-4 sm:grid-cols-2">
                    <li class="border-2 border-black bg-white p-5 shadow-[4px_4px_0_#171717]"><span class="grid size-10 place-items-center border-2 border-black bg-[#b8edce] text-xl font-black">1</span><h3 class="mt-4 text-xl font-black">Isi fondasi</h3><p class="mt-2 leading-6 text-[#555]">Tambahkan semester, mata kuliah, kategori, dan pemberi tugas.</p></li>
                    <li class="border-2 border-black bg-[#dceaff] p-5 shadow-[4px_4px_0_#171717]"><span class="grid size-10 place-items-center border-2 border-black bg-white text-xl font-black">2</span><h3 class="mt-4 text-xl font-black">Catat tugas</h3><p class="mt-2 leading-6 text-[#555]">Tulis deadline, tentukan prioritas, lalu pecah jadi checklist.</p></li>
                    <li class="border-2 border-black bg-[#ffd9d1] p-5 shadow-[4px_4px_0_#171717] sm:col-span-2"><span class="grid size-10 place-items-center border-2 border-black bg-white text-xl font-black">3</span><h3 class="mt-4 text-xl font-black">Lanjutkan satu langkah</h3><p class="mt-2 max-w-xl leading-6 text-[#555]">Perbarui checklist dan status. Histori menjaga perubahan progres tetap terlihat.</p></li>
                </ol>
            </section>

            <section data-reveal class="border-y-2 border-black bg-[#171717] text-white">
                <div class="mx-auto flex max-w-[1440px] flex-col items-start justify-between gap-8 px-5 py-14 sm:px-8 md:flex-row md:items-center lg:px-12 lg:py-16">
                    <div><h2 class="max-w-[13ch] text-4xl font-black leading-tight tracking-tight sm:text-5xl [text-wrap:balance]">Bikin tugas pertamamu.</h2><p class="mt-4 max-w-xl text-lg leading-7 text-white/75">Mulai dengan ruang kerja pribadi dan susun satu tugas berikutnya.</p></div>
                    @if (Route::has('register'))
                        <a href="{{ route('register') }}" class="inline-flex min-h-12 shrink-0 items-center border-2 border-white bg-[#facc15] px-5 py-3 font-black text-black shadow-[4px_4px_0_#fff] transition hover:translate-x-0.5 hover:translate-y-0.5 hover:shadow-none">Buat akun <span aria-hidden="true" class="ml-3">↗</span></a>
                    @endif
                </div>
            </section>
        </main>

        <footer class="mx-auto flex max-w-[1440px] flex-col gap-3 px-5 py-8 sm:flex-row sm:items-center sm:justify-between sm:px-8 lg:px-12">
            <a href="{{ route('home') }}" class="text-2xl font-black tracking-tight">TUGAS!OS</a>
            <p class="text-sm font-semibold text-[#555]">Ruang kerja untuk belajar yang lebih terarah.</p>
        </footer>

        <script>
            document.querySelectorAll('[data-preview-checklist]').forEach((checklist) => {
                const checkboxes = Array.from(checklist.querySelectorAll('input[type="checkbox"]'));
                const progress = checklist.closest('section').querySelector('[data-preview-progress]');
                const output = checklist.closest('section').querySelector('[data-preview-output]');

                const updateProgress = () => {
                    const completed = checkboxes.filter((checkbox) => checkbox.checked).length;
                    const percentage = Math.round((completed / checkboxes.length) * 100);

                    progress.value = completed;
                    output.value = `${percentage}%`;
                };

                checkboxes.forEach((checkbox) => checkbox.addEventListener('change', updateProgress));
            });
        </script>
    </body>
</html>

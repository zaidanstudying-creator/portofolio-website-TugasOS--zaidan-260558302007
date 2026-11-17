# TUGAS!OS

TUGAS!OS adalah ruang kerja akademik pribadi untuk tugas, proyek, kalender, lampiran privat, pengumpulan, kebiasaan, analitik, dan saran AI. Antarmuka menggunakan Bahasa Indonesia dan gaya neo-brutalism.

Autentikasi login, pendaftaran, pemulihan password, dan autentikasi dua faktor dibuat di aplikasi ini. Aplikasi tidak menggunakan Laravel Fortify dan tidak meminta verifikasi email.

## Persyaratan

- PHP 8.3 atau lebih baru dengan ekstensi yang dibutuhkan Laravel, termasuk `pdo_mysql` dan `zip`.
- Composer.
- MySQL.
- Node.js dan npm.
- Laravel Herd untuk domain lokal `mytask.test`, atau lingkungan web Laravel lain.

## Menyiapkan aplikasi

1. Pasang dependency PHP dan salin konfigurasi contoh:

   ```bash
   composer install
   cp .env.example .env
   ```

2. Buat database MySQL kosong, lalu sesuaikan `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, dan `DB_PASSWORD` di `.env`. User database perlu izin membuat dan mengubah tabel.

3. Buat kunci aplikasi dan jalankan migrasi:

   ```bash
   php artisan key:generate
   php artisan config:clear
   php artisan migrate
   ```

   Perintah `migrate` menerapkan migrasi yang belum dijalankan dan mempertahankan data yang sudah ada. Jangan gunakan `migrate:fresh` pada database berisi data karena perintah itu menghapus semua tabel.

4. Pasang dan build aset frontend:

   ```bash
   npm install
   npm run build
   ```

5. Atur `APP_URL` sesuai domain lokal. Dengan Herd, nilai contoh adalah `http://mytask.test`. Buka domain tersebut lalu buat akun dari halaman pendaftaran.

Untuk menerapkan migrasi setelah pembaruan kode, periksa status dan jalankan migrasi biasa:

```bash
php artisan migrate:status
php artisan migrate
```

Jika MySQL menolak koneksi, periksa nama database, host, username, password, dan izin user MySQL; lalu jalankan `php artisan config:clear` sebelum mencoba kembali.

## Menjalankan proses latar belakang

Queue database diperlukan untuk pemrosesan dokumen AI, pengiriman LMS, dan sinkronisasi eksternal. Di Herd, jalankan proses ini pada terminal terpisah:

```bash
npm run dev
php artisan queue:work --tries=3 --timeout=70
php artisan schedule:work
```

`schedule:work` membuat tugas berulang untuk 30 hari ke depan setiap hari pukul 00:20. Di production, jalankan worker queue sebagai proses yang selalu aktif dan daftarkan Laravel Scheduler sekali per menit:

```cron
* * * * * cd /path/ke/mytask && php artisan schedule:run >> /dev/null 2>&1
```

Konfigurasi queue menggunakan `retry_after` 180 detik. Pertahankan nilai ini lebih besar dari timeout worker.

## Integrasi

Integrasi tidak diperlukan agar fitur tugas inti tetap berfungsi. Konfigurasikan rahasia melalui environment aplikasi atau pengaturan akun; jangan commit nilai rahasia ke repository.

### Google Calendar dan Drive

Atur `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, dan `GOOGLE_REDIRECT_URI`. Redirect lokal Herd:

```text
http://mytask.test/integrations/google/callback
```

Google Calendar memakai akses event dan daftar kalender baca-saja. Google Drive memakai cakupan `drive.file`. Google Picker juga memerlukan `GOOGLE_PICKER_API_KEY` dan `GOOGLE_APP_ID`. Gunakan HTTPS dan URL callback production saat memasang aplikasi pada domain production.

### GitHub

Atur `GITHUB_CLIENT_ID`, `GITHUB_CLIENT_SECRET`, dan `GITHUB_REDIRECT_URI`. Redirect lokal Herd:

```text
http://mytask.test/integrations/github/callback
```

Koneksi saat ini meminta identitas `read:user` dan hanya menampilkan repository publik yang dapat diakses dengan cakupan tersebut.

### AI Assistant

Provider aplikasi saat ini adalah OpenAI. Atur `AI_PROVIDER=openai`, `OPENAI_API_KEY`, dan `OPENAI_MODEL`, atau simpan provider, model, dan API key pribadi di Ruang kerja. Simpan key hanya melalui konfigurasi rahasia yang sesuai untuk environment masing-masing.

AI hanya menerima instruksi, data, atau dokumen setelah pengguna menyetujui tindakan terkait. Hasil AI berupa usulan yang perlu ditinjau; AI tidak mengubah deadline atau mengirim tugas ke LMS.

### LMS

Pengiriman otomatis memerlukan adapter untuk API resmi LMS. Belum ada adapter LMS kampus yang terdaftar di aplikasi ini. Jangan menganggap pengiriman otomatis aktif sebelum API, metode autentikasi, dan kebijakan submission platform ditentukan serta diuji. Gunakan pencatatan pengumpulan manual sampai adapter kompatibel tersedia.

## Penyimpanan file dan backup

File tugas disimpan pada private storage (`FILESYSTEM_DISK=local` secara default) dan diunduh melalui route yang memeriksa kepemilikan pengguna. Jangan membuat file tugas dapat diakses publik melalui web server.

Menu **Ruang kerja → Backup dan ekspor** mengunduh ZIP yang berisi data akun, histori, dan file yang tersedia. Password, API key AI, serta token OAuth tidak disertakan. Simpan hasil backup di lokasi privat karena arsip tersebut memuat dokumen tugas pribadi.

## Pemeriksaan kualitas

```bash
php artisan test --compact
composer run lint:check
vendor/bin/phpstan analyse --no-progress --memory-limit=512M
npm run build
composer audit --locked
```

Jalankan audit dependency saat jaringan ke Packagist tersedia. Untuk environment production, gunakan `APP_ENV=production`, `APP_DEBUG=false`, HTTPS, konfigurasi email yang benar untuk pemulihan password, serta backup database dan private storage secara berkala.

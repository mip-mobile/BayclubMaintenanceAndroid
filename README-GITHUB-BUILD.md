# Bayclub Mobile — Android APK Build

Project Android WebView untuk membuka `https://bayclub.infinityfree.io/maintenance/`. Source ini tidak menyimpan password login website. APK memerlukan internet karena aplikasi memuat sistem Maintenance Bayclub dari hosting.

## Apa yang dihasilkan workflow

Workflow `.github/workflows/build-signed-apk.yml` berjalan saat ada push ke repository atau dijalankan manual dari tab **Actions**. Workflow selalu menghasilkan `Bayclub-Maintenance-debug.apk` yang sudah ditandatangani dengan debug key CI agar bisa diuji. Jika empat GitHub Actions secrets signing tersedia, workflow juga menghasilkan `Bayclub-Maintenance.apk`, yakni APK release yang ditandatangani dengan keystore Bayclub milikmu.

Debug APK memakai application ID `id.gclub.bayclubmaintenance.debug` dan label `Bayclub Mobile (Testing)`. Release APK memakai application ID `id.gclub.bayclubmaintenance` dan label `Bayclub Mobile`.

## Upload project ke GitHub

Upload isi ZIP ini ke root sebuah repository khusus Bayclub Maintenance. Sertakan `.github/workflows/build-signed-apk.yml`. **Jangan mengunggah** file `.jks`, file password, atau `signing/keystore.properties` yang berisi kredensial sungguhan. File `.gitignore` mengecualikan file-file ini.

## Secrets untuk APK release

Di repository GitHub buka **Settings → Secrets and variables → Actions → New repository secret**, lalu buat empat secrets berikut:

- `BAYCLUB_KEYSTORE_BASE64`: isi Base64 dari `bayclub-release.jks`.
- `BAYCLUB_STORE_PASSWORD`: store password.
- `BAYCLUB_KEY_ALIAS`: `bayclub-release`.
- `BAYCLUB_KEY_PASSWORD`: key password.

### Membuat nilai Base64 di Windows PowerShell

Dengan file keystore berada di folder Downloads, jalankan:

```powershell
[Convert]::ToBase64String([IO.File]::ReadAllBytes("$env:USERPROFILE\Downloads\bayclub-release.jks")) | Set-Clipboard
```

Paste nilai clipboard ke secret `BAYCLUB_KEYSTORE_BASE64`. Jangan tempel nilai Base64, password, atau file keystore ke chat, issue, README, maupun file biasa di repo.

## Mengunduh APK

1. Buka tab **Actions** dan pilih **Build Bayclub Maintenance APK**.
2. Jalankan **Run workflow** atau tunggu workflow otomatis sesudah push.
3. Buka workflow run yang sukses lalu unduh artifact `Bayclub-Maintenance-APK`.
4. Ekstrak artifact. Jika secrets sudah lengkap, isinya mencakup `Bayclub-Maintenance.apk` (release signed) dan `Bayclub-Maintenance-debug.apk` (untuk testing). Jika belum, artifact hanya berisi debug APK.
5. Salin APK ke Android dan instal. Android mungkin meminta izin untuk memasang aplikasi dari sumber tersebut.

## Build lokal Windows (opsional)

1. Instal Android Studio, JDK 17, dan Android SDK Platform 35 serta Build Tools 35.0.0.
2. Letakkan `bayclub-release.jks` pada `signing/bayclub-release.jks`.
3. Salin `signing/keystore.properties.example` menjadi `signing/keystore.properties` lalu isi kredensial asli. **Jangan commit file ini.**
4. Jalankan `Build-APK.bat`.

## Signing key

Semua update release mendatang harus memakai keystore yang sama. Simpan file `.jks` dan password secara aman serta terpisah. Jika keystore hilang atau diganti, Android tidak akan menerima APK baru sebagai update dari aplikasi yang terinstal.

## Aplikasi

- Nama: Bayclub Mobile
- URL awal: `https://bayclub.infinityfree.io/maintenance/`
- Package ID release: `id.gclub.bayclubmaintenance`
- Versi: `1.1.6` / versionCode `8`
- Ikon: logo Bayclub yang diberikan pengguna
- Fitur WebView: JavaScript, DOM storage, cookies, file chooser untuk upload bukti, izin lokasi saat situs meminta, navigasi Back, fullscreen immersive yang menyembunyikan status/notifikasi dan navigation bar serta memulihkannya lebih cepat setelah gestur Back, tombol/gestur Back untuk navigasi halaman sebelumnya, serta tekan Back dua kali di halaman awal untuk keluar.

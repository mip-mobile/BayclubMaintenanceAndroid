BAYCLUB MAINTENANCE ANDROID WEBVIEW APP
=======================================
Nama aplikasi: Bayclub Maintenance
Alamat situs: https://bayclub.infinityfree.io/maintenance/
Package ID release: id.gclub.bayclubmaintenance
Ikon: Logo Bayclub dari pengguna.

STATUS BUILD
------------
APK tidak dibangun dari lingkungan penyiapan ini karena Android SDK dan Gradle tidak tersedia secara lokal serta server unduhan tidak bisa diakses. Source siap untuk build via GitHub Actions.

CARA MENDAPATKAN APK
--------------------
1. Upload isi project ini ke root repository GitHub khusus Bayclub Maintenance.
2. Push ke branch repository atau jalankan workflow secara manual melalui tab Actions.
3. Workflow akan membangun Bayclub-Maintenance-debug.apk untuk pengujian.
4. Untuk APK release yang bisa digunakan sebagai update berkelanjutan, tambahkan empat signing secrets sesuai README-GITHUB-BUILD.md. Jalankan ulang workflow dan unduh Bayclub-Maintenance.apk dari artifact.

KEYSTORE
--------
Keystore tidak disertakan di dalam project source ZIP. Simpan file bayclub-release.jks dan password secara terpisah, jangan upload ke repository. Semua update release harus memakai keystore yang sama.

FITUR WEBVIEW
-------------
- HTTPS WebView untuk Maintenance Bayclub.
- JavaScript, DOM storage, dan cookies untuk sesi login.
- Pemilihan file untuk upload bukti.
- Izin lokasi hanya ketika situs Bayclub memintanya.
- Tombol Back kembali ke halaman sebelumnya.
- Memerlukan internet; tidak ada mode offline.

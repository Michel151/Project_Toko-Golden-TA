# Pembaruan password akun

## Pemasangan di Hostinger

Ekstrak `hostinger-password-update.zip` ke folder proyek Laravel yang berisi file `artisan`. Pertahankan struktur folder `app`, `routes`, `database`, dan `resources` dari paket. Paket tidak berisi `.env` atau database operasional.

Setelah file selesai diunggah, jalankan dari folder proyek:

```bash
php artisan migrate --force
php artisan optimize:clear
```

Migrasi menambahkan `must_change_password` dan `password_version` pada tabel `users`. Password dan akses akun yang sudah ada tetap berlaku sampai passwordnya diubah atau direset. Setelah pemasangan, refresh browser dengan Ctrl+F5.

## Ubah password sendiri

Semua pengguna (owner, kasir, staf gudang) dapat membuka menu akun kanan atas → **Ubah Password**. Isi password saat ini, password baru, dan konfirmasinya. Password baru minimal delapan karakter dan mengandung huruf besar, huruf kecil, angka, serta simbol; password baru harus berbeda dari password saat ini.

Form memiliki tombol mata dan indikator persyaratan langsung. Setelah berhasil, sesi saat ini tetap masuk, sedangkan sesi lain dan cookie Ingat Saya lama tidak dapat dipakai lagi.

## Reset oleh owner

Buka **Master Data → Pengguna**, lalu klik ikon kunci pada akun pengguna lain dan konfirmasikan reset. Sistem membuat password sementara acak dan menampilkannya sekali pada halaman hasil. Salin dan sampaikan secara pribadi kepada pengguna; aplikasi tidak mengirim pesan atau email otomatis.

Password sementara disimpan sebagai hash pada akun. Data sementara untuk halaman hasil dienkripsi di sesi dan tidak dimasukkan ke URL atau log aktivitas. Halaman hasil tidak disimpan pada cache browser. Jika password tidak sempat disalin, lakukan reset lagi.

Pengguna yang masuk memakai password sementara wajib menggantinya sebelum membaca atau menyimpan data pada menu lain, termasuk permintaan JSON. Logout tetap tersedia. Sesi lama ditolak pada permintaan berikutnya. Setelah penggantian berhasil, password sementara tidak berlaku lagi.

Mengganti password pengguna lain melalui form **Ubah Pengguna** juga mengaktifkan kewajiban ganti password dan mencabut sesi lama. Untuk akun sendiri, gunakan menu **Ubah Password**, bukan tombol reset.

## Validasi

Pemeriksaan mencakup seluruh peran, verifikasi password saat ini, kekuatan dan konfirmasi password baru, pembatasan percobaan, reset khusus owner, larangan reset akun sendiri, penolakan akses sebelum ganti password, pembatalan sesi/cookie lama, kerahasiaan log, dan tampilan desktop/mobile. Pengujian memakai database SQLite sementara; data operasional tidak diubah.

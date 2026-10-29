# Navigasi Kembali dan Batal

Tautan navigasi memakai komponen `components.back-link`: abu-abu outline dengan
ikon panah kiri. Halaman detail menggunakan label **Kembali** dan ukuran kecil.
Formulir menggunakan **Batal**, dengan ukuran yang mengikuti tombol simpan.
Tombol Kembali detail beban, pajak, dan retur/klaim ditempatkan sebelum data.
Penggantian password opsional mempunyai Batal; penggantian wajib tidak.

Layout menyisipkan `public/js/return-navigation.js` dari berkas lokal. Tautan
ke detail, tambah, ubah, dan halaman impor membawa parameter `return_to`, berisi
path, query filter/pagination, dan fragment halaman asal. Tautan sudah diperbarui
saat dirender di browser sehingga salin tautan/buka tab baru tetap membawa konteks.
Tautan baru dari AJAX dan tautan yang digunakan setelah URL daftar berubah juga
memakai konteks terbaru. Pergantian tab pada detail tetap memakai halaman asal,
bukan menjadikan tab sebelumnya sebagai tujuan kembali.

Contoh: laporan penjualan dengan filter dan halaman 3 → detail invoice → Kembali
ke laporan dengan parameter yang sama. Daftar barang → detail → ubah → Batal
kembali ke detail; Kembali pada detail tetap menuju daftar dengan filter semula.

Formulir GET mempertahankan konteks melalui field tersembunyi. Formulir POST/PUT
membawanya pada URL action. Middleware `PreserveReturnNavigation` mempertahankan
konteks melalui redirect validasi dan halaman detail setelah penyimpanan. Redirect
ke daftar asal mengembalikan filter/pagination; penyimpanan ubah yang dibuka dari
detail kembali ke detail tersebut beserta konteks daftar asalnya. Respons JSON,
tautan ekspor, serta alur login/logout tidak diarahkan ulang oleh mekanisme ini.

`ReturnNavigation` hanya menerima halaman GET internal aplikasi. URL eksternal,
URL protocol-relative, karakter kontrol/backslash, endpoint JSON, dan rute yang
bukan halaman ditolak. Validasi tujuan tidak menjalankan controller atau mengubah
model binding request aktif. Jika halaman dibuka langsung atau konteks tidak
valid, tombol memakai fallback daftar induk. Hak akses tetap diperiksa oleh
middleware asli saat halaman tujuan dibuka.

Tidak ada migrasi atau perubahan pada data transaksi, stok, HPP, dan aturan
penyimpanan. Setelah deployment, bersihkan view/cache Laravel sesuai prosedur
deployment yang berlaku dan buka ulang halaman untuk memperoleh JavaScript baru.

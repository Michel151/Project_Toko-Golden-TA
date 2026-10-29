# Reset harian draf keranjang

Draf yang dibuat sebelum pukul 18.00 WIB kedaluwarsa pukul 18.00 WIB pada
hari yang sama. Draf baru pada atau setelah pukul 18.00 WIB kedaluwarsa pukul
18.00 WIB keesokan hari. Autosave tidak menggeser batas tersebut.

Batas dihitung dari `sale_drafts.created_at`, bukan tanggal transaksi atau
`updated_at`. Draf lama mengikuti aturan yang sama tanpa migrasi atau
perubahan transaksi historis. Status `expired`, payload kosong, dan kenaikan
versi mempertahankan identitas penutupan agar autosave terlambat tidak
menghidupkan kembali draf lama. Draf consumed/discarded tetap dipertahankan.

Halaman terbuka memeriksa batas tiap detik dan saat tab kembali aktif.
Keranjang aktif yang kedaluwarsa dikosongkan beserta pelanggan dan pembayaran;
keranjang baru memakai ID baru. Penyimpanan browser juga menyaring dan
membersihkan draf kedaluwarsa, termasuk ketika server tidak tersedia.
Batas dari server dan zona WIB digunakan terlepas dari zona waktu browser.
Respons autosave untuk ID keranjang sebelumnya tidak diterapkan pada ID baru.

Server memeriksa batas saat daftar draf diakses, autosave diterima, dan
transaksi hendak menggunakan draf. Pemeriksaan terakhir tetap menolak draf
kedaluwarsa sekalipun scheduler belum berjalan. Browser lama yang belum
memuat versi terbaru perlu dibuka ulang untuk memperoleh perilaku UI baru.

Perintah terjadwal `php artisan sales:expire-drafts` dijalankan setiap menit
melalui Laravel scheduler. Pastikan cron hosting menjalankan `php artisan
schedule:run` setiap menit; gunakan jalur PHP dan proyek aktual hosting.
Cron yang sama juga menjalankan pembatalan transaksi Menunggu Pembayaran.
Tidak memerlukan migrasi database. Setelah unggah perubahan, jalankan
`php artisan optimize:clear` bila view/opcode/cache deployment masih lama.

Reset draf tidak menjalankan mutasi stok atau perubahan HPP. Pembatalan
transaksi Menunggu Pembayaran tetap memakai mekanisme pengembalian stok yang
terpisah dan tidak diubah oleh fitur ini.

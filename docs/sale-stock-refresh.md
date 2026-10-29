# Pembaruan stok halaman kasir

Form transaksi baru dan ubah transaksi menyediakan tombol **Perbarui Stok**.
Stok juga diambil otomatis setiap 30 detik saat tab terlihat, ketika kembali
ke tab/jendela kasir, dan ketika koneksi kembali tersedia. Permintaan otomatis
dibatasi minimal 5 detik antarpercobaan dan tidak ditumpuk saat masih berjalan.

Endpoint `GET /sale/stock-snapshot` mengikuti hak akses membuat atau mengubah
transaksi penjualan. Respons tidak disimpan dalam cache dan hanya memuat ID
barang serta jumlah stok per lokasi dalam satuan dasar. Barang berkedaluwarsa
menggunakan jumlah batch layak jual, sesuai tampilan kasir awal. Respons tidak
menambahkan kembali stok transaksi menunggu pembayaran; form ubah tetap
memperhitungkan alokasi aslinya melalui validasi keranjang yang sudah ada.

Pembaruan hanya mengganti data jumlah stok di memori halaman dan label stok,
lalu memeriksa ulang kecukupan seluruh keranjang. Tidak membangun ulang baris,
mengubah barang, satuan, harga, jumlah, sumber lokasi, pelanggan, atau pembayaran.
Jika koneksi gagal, data stok terakhir dan keranjang tetap dipertahankan dengan
pesan kegagalan. Pengguna dapat mencoba lagi. Setelah kesalahan sesi/hak akses,
percobaan otomatis dihentikan; tombol manual dapat digunakan setelah akses pulih.

Penyimpanan transaksi tetap memvalidasi stok terkini di server. Pembaruan ini
tidak mengubah perhitungan HPP, FEFO, atau data transaksi lama. Tidak memerlukan
migrasi database. JavaScript disisipkan dari file lokal saat Blade dirender,
sesuai pola aset formulir PO agar tidak bergantung pada URL aset di hosting.

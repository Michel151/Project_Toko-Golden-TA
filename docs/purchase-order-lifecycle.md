# Draf, konfirmasi, dan pembatalan Purchase Order

## Alur penggunaan

- Buat PO: pilih **Simpan Draf** atau **Konfirmasi PO**. Konfirmasi langsung tetap memeriksa supplier, barang aktif, satuan, jumlah, dan harga. Jika tidak ada pilihan tindakan pada request, sistem menyimpan sebagai draf.
- Draf: dapat diedit, disimpan kembali, dikonfirmasi, atau dibatalkan sesuai hak akses. Draf belum tersedia pada pilihan penerimaan barang. Draf tetap membutuhkan supplier dan minimal satu baris barang yang valid.
- Konfirmasi: menyimpan waktu dan pengguna yang mengonfirmasi; status menjadi **Menunggu**. Rincian barang, supplier, satuan, jumlah, tanggal, dan harga tidak dapat diedit lagi.
- PO terkonfirmasi tanpa penerimaan: hanya owner yang dapat membatalkan, dengan alasan wajib.
- PO diterima sebagian: owner dapat **Tutup Sisa Pesanan** jika tidak ada reservasi masalah atau PO pengganti yang masih terbuka. Barang yang sudah diterima dan seluruh dokumen penerimaan tetap tersimpan. Jumlah sisa pada tabel menunjukkan barang yang tidak dilanjutkan.
- PO selesai atau PO pengganti retur tidak dapat dibatalkan lewat alur ini. Retur/klaim tetap memakai alur yang sudah ada.
- Pembatalan dan penutupan menyimpan alasan, pengguna, dan waktu. Dokumen tidak dihapus; pengiriman ulang tindakan yang sama tidak menimpa riwayat alasan pertama.

Kedua tindakan awal belum menambah stok, memperbarui harga master, atau mencatat HPP. Perhitungan moving average, FEFO, dan pembaruan harga beli master tetap terjadi melalui penerimaan yang sudah ada.

## Hak akses dan laporan

- Izin **Mengonfirmasi PO** tersedia pada pengaturan hak akses. Izin baru ini tidak otomatis diberikan kepada staf atau kasir; owner memiliki akses penuh.
- Membuat PO langsung terkonfirmasi memerlukan izin menambah dan mengonfirmasi. Mengedit draf sekaligus mengonfirmasi memerlukan izin mengubah dan mengonfirmasi.
- Pembatalan draf memakai izin Menghapus/Membatalkan. Pembatalan PO terkonfirmasi dan penutupan sisa tetap khusus owner.
- Daftar PO tetap menampilkan draf, PO batal, dan PO ditutup, dengan filter status. Total nilai PO terkonfirmasi dan Laporan Pembelian tidak memasukkan draf atau PO batal.
- PO ditutup tetap masuk laporan dengan nilai pesanan asli. Penutupan tidak mengubah jumlah dan harga PO; laporan ini menunjukkan nilai dokumen PO, bukan nilai barang yang sudah diterima.
- Ekspor dokumen PO draf, batal, dan ditutup diberi keterangan status; alasan pembatalan/penutupan turut ditampilkan. Ekspor PO resmi lama mempertahankan formatnya.

## Data lama

Migrasi hanya menambahkan pilihan status dan kolom metadata yang boleh kosong. Tidak ada pembaruan status, pengisian pengguna konfirmasi fiktif, penghapusan transaksi, atau perhitungan ulang persediaan/HPP.

PO lama berstatus Menunggu/Diterima Sebagian/Selesai tetap mempertahankan status dan dapat dilanjutkan penerimaannya sesuai aturan lama. PO lama yang Menunggu diperlakukan sebagai dokumen resmi sehingga rincian tidak dapat diedit; owner dapat membatalkannya jika belum ada penerimaan. Metadata konfirmasi dokumen lama dibiarkan kosong.

## Pemasangan di Hostinger

1. Buat backup database hosting dan kode aplikasi sebelum pemasangan.
2. Unggah seluruh file perubahan, termasuk model, controller, service, route, pengaturan hak akses, view, ekspor, dan migrasi baru. Aset pencarian PO dari pembaruan sebelumnya tetap diperlukan.
3. Dari folder Laravel yang berisi `artisan`, jalankan migrasi tambahan ini:

```bash
php artisan migrate --path=database/migrations/2026_10_28_000002_add_purchase_order_lifecycle.php --force
php artisan optimize:clear
```

4. Muat ulang browser. Uji Simpan Draf → Ubah Draf → Konfirmasi PO → Penerimaan. Uji pembatalan pada dokumen lain yang belum diterima.

Jangan menjalankan `migrate:fresh`, reset, atau mengimpor ulang data untuk memasang fitur ini. Rollback migrasi ditolak jika sudah ada status draf/batal/ditutup; pemulihan harus mempertahankan riwayat tersebut.

## Validasi

Pengujian otomatis memakai SQLite `:memory:` terpisah, bukan database operasional. Cakupan meliputi hak akses, konfirmasi langsung dan dari draf, pembatalan, penutupan sisa, penolakan penerimaan pada status yang tidak valid, ekspor, pemulihan isian setelah validasi gagal, serta preservasi data lama dan ledger biaya. Uji migrasi dengan riwayat yang sudah terisi juga memakai SQLite; migrasi di MySQL Hostinger tetap harus dijalankan saat pemasangan.

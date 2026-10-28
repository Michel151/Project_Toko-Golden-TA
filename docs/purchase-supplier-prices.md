# Harga supplier dan harga pembelian terakhir

## Perilaku

- PO baru mengambil harga hubungan barang-supplier yang dipilih. Harga tersebut menggunakan satuan pembelian default barang. Harga satuan PO lain dihitung langsung melalui konversi ke satuan dasar, dengan pembulatan dua desimal tanpa pembulatan berantai.
- Harga supplier nol tetap nol. Jika harga supplier tidak tersedia, harga Master Barang digunakan sebagai acuan dan ditandai pada form.
- Harga PO dapat diedit manual. Membuat, mengedit, atau menghapus PO belum diproses tidak memperbarui harga master atau harga supplier.
- Membuka PO lama mempertahankan harga tersimpan. Penggantian satuan/barang secara eksplisit mengisi harga supplier saat ini. Mengganti supplier meminta konfirmasi sebelum mengosongkan rincian PO.
- Penerimaan barang baik yang berhasil memperbarui harga beli seluruh satuan Master Barang berdasarkan harga pada baris PO yang diterima. `products.buy_price` mengikuti satuan pembelian default. Harga jual dan hubungan supplier tidak diubah.
- Penerimaan gagal, pemeriksaan tanpa barang baik, dan PO pengganti retur tidak memperbarui harga master. Penerimaan bertanggal lebih lama daripada penerimaan pembelian terakhir tidak menimpa harga terbaru. Untuk tanggal sama, penerimaan yang dicatat terakhir didahulukan.
- HPP moving average tetap dihitung oleh alur penerimaan yang sudah ada berdasarkan jumlah dan nilai PO aktual. Tidak ada perhitungan ulang transaksi lama, perubahan snapshot HPP penjualan, atau perubahan ledger lama.

## Pemasangan

Unggah pembaruan kode aplikasi ke Hostinger, lalu jalankan dari folder proyek Laravel:

```bash
php artisan optimize:clear
```

Pembaruan harga ini tidak membutuhkan migrasi database atau pengisian ulang data. Jika pembaruan Import Supplier sebelumnya belum dipasang, ikuti juga `docs/supplier-import.md` untuk migrasi tabel konfirmasi import yang terpisah.

## Uji singkat

1. Tetapkan barang 1 dus = 24 botol, Master Barang Rp66.000/dus, Supplier 1 Rp66.000/dus, dan Supplier 2 Rp65.000/dus.
2. Pilih Supplier 2 pada PO baru: harga awal Rp65.000/dus atau Rp2.708,33/botol. Ubah harga PO menjadi Rp64.000/dus lalu simpan; Master Barang masih Rp66.000/dus.
3. Terima barang baik dari PO tersebut: Master Barang menjadi Rp64.000/dus dan Rp2.666,67/botol. Harga Supplier 1/2 tetap seperti sebelumnya. Nilai penerimaan menggunakan harga PO, bukan harga acuan master.
4. Buka kembali PO dan penjualan lama: harga transaksi dan HPP lama tidak berubah.

Pengujian otomatis memakai SQLite sementara. Tidak ada transaksi uji yang dikirim ke database Hostinger.

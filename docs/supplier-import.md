# Import supplier

## Pemasangan di Hostinger

Cadangkan database sebelum memasang pembaruan. Unggah perubahan aplikasi ke folder proyek Laravel, lalu jalankan dari folder yang berisi `artisan`:

```bash
php artisan migrate --path=database/migrations/2026_10_28_000001_create_supplier_import_runs_table.php --force
php artisan optimize:clear
```

Migrasi ini hanya membuat tabel `supplier_import_runs` untuk token konfirmasi, payload terenkripsi, dan hasil import. Tidak mengubah tabel stok, biaya persediaan, transaksi, atau HPP lama. Pastikan `storage/framework/cache` dapat ditulis; import menggunakan file lock yang sama untuk seluruh upload supplier pada instalasi ini. Untuk beberapa server dengan filesystem terpisah, gunakan satu lock store bersama sebelum mengaktifkan import pada semua server.

## Alur

1. Buka Master Supplier > Import Supplier, unggah Excel, lalu periksa pratinjau.
2. Perbaiki semua kesalahan. Import dengan kesalahan tidak dapat dikonfirmasi.
3. Centang persetujuan, lalu klik Konfirmasi & Simpan. Pratinjau berlaku satu jam dan hanya dapat dikonfirmasi oleh pengguna yang membuatnya.

Seluruh baris diproses, meskipun pratinjau hanya menampilkan 200 supplier/hubungan pertama. Konfirmasi memeriksa kembali referensi, satuan, dan hak akses terbaru. Perubahan identitas barang atau konversi setelah pratinjau mengharuskan unggah ulang. Semua penyimpanan berada dalam satu transaksi database.

## Data lama dan import ulang

- Supplier dicocokkan melalui ID eksplisit atau nama yang sama, tanpa membedakan huruf besar/kecil dan spasi berulang. Nama dengan tanda baca berbeda tidak digabung secara perkiraan. Jika ada beberapa supplier bernama sama, isi ID supplier yang tepat.
- Supplier terdaftar digunakan kembali; nama, alamat, dan telepon lama tidak ditimpa.
- Hubungan barang-supplier yang sudah ada dilewati tanpa menimpa harga lama.
- Harga hubungan baru disetarakan dari satuan pada Excel ke satuan pembelian default barang. Master Barang dan konversi satuan tidak diubah.
- Token konfirmasi yang dikirim ulang mengembalikan hasil sebelumnya. File yang diunggah ulang menggunakan supplier dan hubungan yang sudah ada, sehingga tidak menggandakan data.
- Stok, batch, transaksi, HPP historis, dan ledger moving average tidak diubah.

Fitur mengikuti hak akses Import Supplier. Hubungan ke barang juga memerlukan hak akses melihat Master Barang. Supplier saja dapat diimport tanpa akses ke Master Barang.

## Verifikasi di hosting

Uji pratinjau, lalu konfirmasi satu file. Unggah ulang file yang sama dan pastikan jumlah supplier/hubungan tidak bertambah. Periksa bahwa nilai stok dan HPP transaksi lama tetap sama. Pengujian otomatis lokal menggunakan SQLite sementara, bukan database Hostinger.

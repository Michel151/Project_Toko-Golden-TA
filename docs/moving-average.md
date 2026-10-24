# HPP Moving Average

## Otomatis Dari Stok Nol

1. Buat barang tanpa stok awal, lalu catat PO dengan harga perolehan yang benar.
2. Catat penerimaan. Saat stok milik toko sebelumnya nol, sistem membentuk saldo jumlah/nilai dari penerimaan tersebut secara otomatis.
3. Penerimaan berikutnya memperbarui rata-rata; penjualan lunas menyimpan HPP saat pembayaran selesai.
4. Keuangan > Penilaian Persediaan hanya menampilkan status dan riwayat. Tidak ada input saldo awal atau tombol aktivasi manual.

Barang yang masih memiliki stok lama tanpa saldo biaya aktif tetap memakai metode sebelumnya sampai seluruh stok milik toko habis. Jumlah ini mencakup semua lokasi dan reservasi penjualan, bukan hanya stok tersedia. Penerimaan saat masih ada stok lama tidak langsung dinilai sebagai saldo nol; stok campuran tetap memakai metode sebelumnya sampai habis. Penerimaan berikutnya dari posisi nol baru membentuk moving average.

Saldo moving average yang sudah aktif sebelumnya tetap dipakai. Verifikasi manual lama yang belum diaktifkan tidak dijadikan nilai stok baru; riwayatnya tetap disimpan. Tidak ada stok, nota, kedaluwarsa, atau HPP lama yang dihapus/dihitung ulang.

## Aturan Perhitungan

- Satu saldo biaya per barang untuk seluruh lokasi, dalam satuan dasar.
- Penerimaan: nilai sebelumnya + jumlah diterima x harga satuan PO. Konversi satuan tidak mengalikan nilai pembelian dua kali.
- Rata-rata = nilai persediaan / stok milik toko. Penjualan menggunakan proporsi nilai saat pembayaran diselesaikan.
- FEFO tetap menentukan kedaluwarsa yang dikeluarkan, bukan harga HPP.
- Nota menunggu pembayaran mengurangi stok tersedia, tetapi belum membentuk HPP atau mengurangi nilai persediaan. Pembatalannya hanya melepaskan reservasi.
- HPP nota lunas tidak mengikuti perubahan harga master berikutnya.
- PO Retur menggunakan biaya dari PO sumber. Tagihan tambahan nol bukan berarti barang pengganti tidak memiliki biaya.
- Barang rusak/tidak sesuai/kurang yang belum diterima sebagai stok layak tidak dikapitalisasi. Pengganti dikapitalisasi saat diterima, bukan saat retur dibuat.
- Transfer tidak mengubah total jumlah/nilai milik toko.
- Pengurangan opname menggunakan rata-rata. Penambahan memerlukan nilai perolehan per satuan dasar dan pemrosesan owner. Selisih nilainya masuk penyesuaian persediaan, terpisah dari HPP penjualan.
- Stok kedaluwarsa tidak otomatis bernilai nol; pengurangan dicatat melalui opname sesuai kondisi fisik.
- Stok awal impor menggunakan nilai perolehan data impor, bukan pendapatan opname.

Nominal disimpan sampai dua desimal dan rata-rata sepuluh desimal. Pengeluaran terakhir menyerap sisa pembulatan agar jumlah nol tidak meninggalkan nilai persediaan.

## Perlindungan Data

- Mutasi stok dan biaya memakai transaksi database dan urutan penguncian yang sama. Kunci unik penerimaan/HPP mencegah posting ganda.
- Tanggal biaya sebelum mutasi biaya terakhir atau setelah hari ini ditolak; tidak menghitung ulang nota lunas.
- Riwayat nilai sebelum/sesudah disimpan. Saldo nol dibentuk dalam transaksi yang sama dengan mutasi, bukan saat membuka halaman.
- Halaman status dan riwayat biaya hanya dapat diakses owner. Endpoint input/aktivasi manual sudah dihapus.
- Migrasi menambah tabel/kolom; tidak menghapus stok, kedaluwarsa, dokumen, atau HPP lama.
- Pajak dan perluasan sampai laba bersih tidak termasuk perubahan ini.

## Verifikasi

`php artisan test --compact --filter=MovingAverageCostTest` memakai SQLite in-memory, bukan database toko. Cakupan: penerimaan, PO Retur, FEFO, reservasi/pembayaran/pembatalan, impor, satuan, opname, pembulatan, tanggal, otorisasi, dan export. SQLite tidak mensimulasikan penguncian paralel MySQL.

Fixture tampilan dibuat dengan `CAPTURE_COST_UI=1` saat menjalankan pengujian, lalu diperiksa dengan `node tools/costing-ui-smoke.cjs`. Permintaan tulis browser diblokir dalam pemeriksaan ini.

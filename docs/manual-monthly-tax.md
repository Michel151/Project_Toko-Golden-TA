# Pencatatan Pajak Manual

- Owner mencatat beban pajak penghasilan per bulan, bukan pembayaran/angsuran pajak.
- Satu bulan hanya memiliki satu catatan. Periode catatan tidak dapat dipindah.
- Nominal Rp0 harus disimpan dan dikonfirmasi secara eksplisit; tidak ada catatan bukan berarti nol.
- Draf belum masuk laporan. Konfirmasi memerlukan persetujuan owner.
- Koreksi catatan terkonfirmasi wajib beralasan dan mengembalikannya ke draf sampai dikonfirmasi ulang.
- Snapshot setiap penyimpanan/konfirmasi dan dokumen lama tetap tersedia dalam riwayat.
- Penguncian baris dan nomor versi menolak penyimpanan dari halaman yang sudah kedaluwarsa.
- Filter kuartalan/tahunan menjumlahkan catatan bulanan; tidak ada input tambahan kuartalan/tahunan.
- Laba bersih belum ditentukan jika ada bulan tanpa pajak terkonfirmasi atau HPP belum lengkap.
- HPP dan nilai selisih opname tidak dihitung ulang oleh modul pajak.
- Jangan catat beban pajak yang sama lagi sebagai beban operasional.
- Modul tidak menghitung tarif, rekonsiliasi fiskal, pajak tangguhan, atau kewajiban pajak secara otomatis.
- Instalasi memerlukan migrasi `2026_10_21_000005_create_monthly_taxes.php`; tabel transaksi lama tidak diubah.

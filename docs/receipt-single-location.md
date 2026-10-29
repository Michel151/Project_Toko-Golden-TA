# Lokasi penerimaan bersama

Formulir penerimaan memakai satu Lokasi Penerimaan untuk semua barang baik pada satu proses. Pengguna wajib memilih lokasi; tidak ada pemilihan otomatis berdasarkan urutan nama.

- Barang berkedaluwarsa tetap dicatat per jumlah dan tanggal batch. Lokasinya mengikuti pilihan di awal.
- Barang tanpa kedaluwarsa langsung mengikuti lokasi yang sama.
- Jika terdapat barang rusak/tidak sesuai, satu Lokasi Penahanan Barang Retur wajib dipilih untuk seluruh barang tersebut. Barang kurang tidak membutuhkan lokasi penahanan.
- Keterangan masalah tetap dicatat per barang.
- Satu PO dapat diterima sebagian melalui beberapa proses dengan lokasi berbeda. Pemindahan setelah penerimaan memakai Transfer Stok.
- Pilihan lokasi dan rincian tetap dipertahankan saat validasi gagal.

Form mengirim location_mode=single, receipt_location_id dan quarantine_location_id bersama. Controller memvalidasi lokasi dan membentuk alokasi/baris batch untuk layanan penerimaan yang sudah ada. Lokasi bersarang tidak dapat mengganti pilihan lokasi bersama dalam mode ini. Payload penerimaan lama tetap didukung agar tidak memutus kompatibilitas.

Tidak ada migrasi, perubahan penerimaan lama, atau perubahan rumus moving average/FEFO. Riwayat tetap menyimpan lokasi pada setiap baris penerimaan dan batch sebagaimana sebelumnya.

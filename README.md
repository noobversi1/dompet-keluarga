# Dompet Keluarga

**Dompet Keluarga** adalah aplikasi web pengelolaan keuangan rumah tangga berbasis **PHP 8+ (PDO)** dan **MySQL/MariaDB** dengan arsitektur **Pengguna Tunggal (*Single-User*)**(start_span)(end_span). Aplikasi ini dirancang untuk membantu keluarga memantau arus kas harian, mengatur batas anggaran bulanan (*budgeting*), mengelola target tabungan, mencatat portofolio investasi, serta mengekspor laporan keuangan dengan standar keamanan berlapis(start_span)(end_span)(start_span)(end_span).

---

## Fitur Utama

### 1. Dashboard & Ringkasan Kekayaan Bersih (*Net Worth*)
* **Total Kekayaan Bersih:** Menggabungkan saldo likuid seluruh dompet, dana tabungan terkumpul, dan nilai pasar portofolio investasi dalam satu ringkasan terpadu[span_5](start_span)[span_5](end_span).
* **Top 3 Dompet Terbesar:** Menampilkan 3 akun kas/rekening dengan saldo tertinggi secara ringkas di halaman utama[span_6](start_span)[span_6](end_span).
* **Arus Kas & Grafik:** Visualisasi proporsi pengeluaran per kategori menggunakan **Chart.js** serta indikator progres *budgeting* bulanan[span_7](start_span)[span_7](end_span).

### 2. Pencatatan Transaksi & Transfer Internal
* **Sinkronisasi Saldo Atomik:** Setiap pencatatan, perubahan, maupun penghapusan pemasukan/pengeluaran otomatis memperbarui saldo dompet terkait menggunakan transaksi database ACID[span_8](start_span)[span_8](end_span).
* **Transfer Antar Dompet:** Mendukung perpindahan dana internal (misalnya dari Rekening Bank ke E-Wallet atau Kas Tunai) lengkap dengan pencatatan biaya admin tanpa memengaruhi laporan pengeluaran bulanan[span_9](start_span)[span_9](end_span).
* **Filter Multi-Kriteria & Pagination:** Pencarian riwayat transaksi berdasarkan bulan, kategori, dan sumber dana dengan pembagian halaman (*pagination*) yang responsif[span_10](start_span)[span_10](end_span).

### 3. Manajemen Kategori, Dompet & Budgeting
* **Multi-Dompet & Kategori:** Pengelolaan penuh (tambah, edit, hapus) untuk akun kas/rekening serta kategori pemasukan dan pengeluaran dengan proteksi integritas relasi data[span_11](start_span)[span_11](end_span).
* **Anggaran Bulanan:** Penetapan batas anggaran per kategori setiap bulan dengan indikator peringatan visual saat mendekati atau melebihi batas[span_12](start_span)[span_12](end_span).

### 4. Target Tabungan & Portofolio Investasi
* **Target Tabungan Keluarga:** Perencanaan dana darurat, pendidikan anak, atau tujuan keluarga lainnya lengkap dengan riwayat alokasi dana dari dompet[span_13](start_span)[span_13](end_span).
* **Portofolio Investasi:** Pencatatan aset investasi (Emas, Reksa Dana, Saham, Obligasi/SBN, Deposito) meliputi jumlah unit, modal pokok, pembaruan nilai pasar (*unrealized gain/loss* & ROI), pencatatan dividen/kupon, hingga realisasi untung/rugi saat pencairan aset[span_14](start_span)[span_14](end_span).

### 5. Ekspor Laporan Keuangan
* **Export Excel (`.xls`):** Menghasilkan file Microsoft Excel XML (*SpreadsheetML*) standar tanpa *dependency* eksternal dengan format angka numerik asli[span_15](start_span)[span_15](end_span).
* **Laporan PDF:** Tampilan laporan siap cetak ukuran A4 yang otomatis mengunduh file PDF biner apabila `dompdf/dompdf` terinstal melalui Composer[span_16](start_span)[span_16](end_span).

### 6. Manajemen Profil & Keamanan Akun
* **Pengaturan Identitas & Kredensial:** Pembaruan nama pengguna, alamat email, dan kata sandi dengan verifikasi password saat ini[span_17](start_span)[span_17](end_span).
* **Kontrol Google reCAPTCHA v2:** Aktivasi dan konfigurasi *Site Key* serta *Secret Key* langsung dari halaman profil lengkap dengan pratinjau langsung (*Live Preview*)[span_18](start_span)[span_18](end_span).

---

## Standar Keamanan

Aplikasi ini menerapkan pendekatan *Defense in Depth* untuk melindungi data keuangan keluarga[span_19](start_span)[span_19](end_span):
* **Anti SQL Injection:** Menggunakan PDO dengan `PDO::ATTR_EMULATE_PREPARES => false` dan *prepared statements* pada seluruh kueri[span_20](start_span)[span_20](end_span).
* **Anti CSRF (*Cross-Site Request Forgery*):** Seluruh formulir `POST` (termasuk aksi hapus dan logout) dilindungi token kriptografis (`random_bytes(32)`) yang divalidasi dengan `hash_equals()`[span_21](start_span)[span_21](end_span).
* **Anti XSS (*Cross-Site Scripting*):** Seluruh keluaran data disanitasi menggunakan `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`[span_22](start_span)[span_22](end_span).
* **Anti IDOR (*Insecure Direct Object Reference*):** Validasi kepemilikan `user_id` sesi aktif pada seluruh operasi baca, ubah, dan hapus data[span_23](start_span)[span_23](end_span).
* **Proteksi Brute-Force & Bot:** Pembatasan percobaan login (*rate limiting*) berbasis kombinasi alamat IP dan email (maksimal 5 kegagalan per 15 menit) serta dukungan **Google reCAPTCHA v2**[span_24](start_span)[span_24](end_span).
* **Keamanan Sesi & Password:** Enkripsi password menggunakan **Bcrypt** (`password_hash`), regenerasi ID sesi saat login/perubahan kredensial (`session_regenerate_id(true)`), cookie sesi `HttpOnly` & `SameSite=Strict`, serta header keamanan HTTP (`X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`)[span_25](start_span)[span_25](end_span)[span_26](start_span)[span_26](end_span).

---

## Teknologi yang Digunakan

* **Backend:** PHP 8.0+ (ekstensi `pdo_mysql`, `json`, `curl`/`openssl`)[span_27](start_span)[span_27](end_span)[span_28](start_span)[span_28](end_span)
* **Database:** MySQL 5.7+ / MariaDB 10.3+ (`utf8mb4`)[span_29](start_span)[span_29](end_span)
* **Frontend:** HTML5, Bootstrap 5.3, Bootstrap Icons, Chart.js 4.4[span_30](start_span)[span_30](end_span)[span_31](start_span)[span_31](end_span)
* **Library Opsional:** `dompdf/dompdf` (via Composer untuk unduhan PDF langsung)[span_32](start_span)[span_32](end_span)

---

## Struktur Direktori

```text
dompet-keluarga/
├── install.php         # Web installer otomatis (database, tabel, & akun pemilik tunggal)
├── config.php          # Koneksi PDO, konfigurasi sesi aman, & HTTP security headers
├── security.php        # Fungsi sanitasi XSS, CSRF, Auth Guard, Rate Limiting, & reCAPTCHA
├── layout.php          # Template antarmuka responsif (Navbar, Alert, Footer)
├── login.php           # Halaman autentikasi pengguna
├── logout.php          # Penghancuran sesi secara aman
├── index.php           # Dashboard utama, ringkasan Net Worth, grafik, & status budget
├── transaksi.php       # Pencatatan pemasukan/pengeluaran, filter, & pagination
├── transfer.php        # Perpindahan saldo antar dompet/rekening
├── budgeting.php       # Manajemen dompet, kategori, & batas anggaran bulanan
├── tabungan.php        # Pengelolaan target tabungan & dana darurat
├── investasi.php       # Pengelolaan portofolio investasi & imbal hasil
├── profil.php          # Pengaturan akun, ganti password, & konfigurasi reCAPTCHA
├── export_excel.php    # Ekspor laporan bulanan ke format Excel (.xls)
└── export_pdf.php      # Ekspor laporan bulanan ke format PDF / cetak A4
```

---

## Panduan Instalasi

1. **Clone Repositori**
   ```bash
   git clone [https://github.com/noobversi1/dompet-keluarga.git](https://github.com/noobversi1/dompet-keluarga.git)
   cd dompet-keluarga
   ```

2. **Pindahkan ke Web Server**
   Letakkan folder proyek ke dalam direktori root web server Anda (misalnya `htdocs` pada XAMPP, `www` pada Laragon, atau `/var/www/html` pada server Linux)[span_33](start_span)[span_33](end_span).

3. **(Opsional) Instal Dompdf untuk Unduhan PDF Langsung**
   ```bash
   composer require dompdf/dompdf
   ```
   *Catatan: Jika langkah ini dilewati, fitur Laporan PDF tetap berfungsi menggunakan dialog cetak browser (Save as PDF).*[span_34](start_span)[span_34](end_span)

4. **Jalankan Web Installer**
   * Buka browser dan akses `http://localhost/dompet-keluarga/install.php`[span_35](start_span)[span_35](end_span).
   * Masukkan kredensial server MySQL Anda beserta data **Akun Pemilik Tunggal**[span_36](start_span)[span_36](end_span).
   * Klik **Mulai Instalasi**[span_37](start_span)[span_37](end_span). Sistem akan membuat database, seluruh tabel relasional, dompet serta kategori bawaan, memperbarui `config.php`, dan mengunci installer melalui file `installed.lock`[span_38](start_span)[span_38](end_span).

5. **Hapus File Installer (Wajib untuk Keamanan)**
   Setelah instalasi selesai dan Anda berhasil login, **segera hapus file `install.php`** dari direktori server[span_39](start_span)[span_39](end_span).

---

## Rekomendasi Keamanan Server Produksi

* Gunakan protokol **HTTPS (SSL/TLS)** agar cookie sesi berstatus `Secure` tidak dapat disadap melalui jaringan publik[span_40](start_span)[span_40](end_span).
* Pastikan `display_errors = Off` pada konfigurasi `php.ini` di server produksi agar informasi struktur direktori tidak tampil jika terjadi pengecualian[span_41](start_span)[span_41](end_span).
* Lakukan pencadangan (*backup*) database MySQL secara berkala.

---

## Lisensi

Proyek ini dirilis di bawah lisensi [MIT License](LICENSE). Dikembangkan oleh **Tarigan Hosting**[span_42](start_span)[span_42](end_span).

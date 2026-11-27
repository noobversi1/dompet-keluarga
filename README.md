# Dompet Keluarga

**Dompet Keluarga** adalah aplikasi web pengelolaan keuangan rumah tangga berbasis **PHP 8+ (PDO)** dan **MySQL/MariaDB** dengan arsitektur **Pengguna Tunggal (*Single-User*)**. Aplikasi ini dirancang untuk membantu keluarga memantau arus kas harian, mengatur batas anggaran bulanan (*budgeting*), mengelola target tabungan, mencatat portofolio investasi, serta mengekspor laporan keuangan dengan standar keamanan berlapis.

---

## Fitur Utama

### 1. Dashboard & Ringkasan Kekayaan Bersih (*Net Worth*)
* **Total Kekayaan Bersih:** Menggabungkan saldo likuid seluruh dompet, dana tabungan terkumpul, dan nilai pasar portofolio investasi dalam satu ringkasan terpadu.
* **Top 3 Dompet Terbesar:** Menampilkan 3 akun kas/rekening dengan saldo tertinggi secara ringkas di halaman utama.
* **Arus Kas & Grafik:** Visualisasi proporsi pengeluaran per kategori menggunakan **Chart.js** serta indikator progres *budgeting* bulanan.

### 2. Pencatatan Transaksi & Transfer Internal
* **Sinkronisasi Saldo Atomik:** Setiap pencatatan, perubahan, maupun penghapusan pemasukan/pengeluaran otomatis memperbarui saldo dompet terkait menggunakan transaksi database ACID.
* **Transfer Antar Dompet:** Mendukung perpindahan dana internal (misalnya dari Rekening Bank ke E-Wallet atau Kas Tunai) lengkap dengan pencatatan biaya admin tanpa memengaruhi laporan pengeluaran bulanan.
* **Filter Multi-Kriteria & Pagination:** Pencarian riwayat transaksi berdasarkan bulan, kategori, dan sumber dana dengan pembagian halaman (*pagination*) yang responsif.

### 3. Manajemen Kategori, Dompet & Budgeting
* **Multi-Dompet & Kategori:** Pengelolaan penuh (tambah, edit, hapus) untuk akun kas/rekening serta kategori pemasukan dan pengeluaran dengan proteksi integritas relasi data.
* **Anggaran Bulanan:** Penetapan batas anggaran per kategori setiap bulan dengan indikator peringatan visual saat mendekati atau melebihi batas.

### 4. Target Tabungan & Portofolio Investasi
* **Target Tabungan Keluarga:** Perencanaan dana darurat, pendidikan anak, atau tujuan keluarga lainnya lengkap dengan riwayat alokasi dana dari dompet.
* **Portofolio Investasi:** Pencatatan aset investasi (Emas, Reksa Dana, Saham, Obligasi/SBN, Deposito) meliputi jumlah unit, modal pokok, pembaruan nilai pasar (*unrealized gain/loss* & ROI), pencatatan dividen/kupon, hingga realisasi untung/rugi saat pencairan aset.

### 5. Ekspor Laporan Keuangan
* **Export Excel (`.xls`):** Menghasilkan file Microsoft Excel XML (*SpreadsheetML*) standar tanpa *dependency* eksternal dengan format angka numerik asli.
* **Laporan PDF:** Tampilan laporan siap cetak ukuran A4 yang otomatis mengunduh file PDF biner apabila `dompdf/dompdf` terinstal melalui Composer.

### 6. Manajemen Profil & Keamanan Akun
* **Pengaturan Identitas & Kredensial:** Pembaruan nama pengguna, alamat email, dan kata sandi dengan verifikasi password saat ini.
* **Kontrol Google reCAPTCHA v2:** Aktivasi dan konfigurasi *Site Key* serta *Secret Key* langsung dari halaman profil lengkap dengan pratinjau langsung (*Live Preview*).

---

## Standar Keamanan

Aplikasi ini menerapkan pendekatan *Defense in Depth* untuk melindungi data keuangan keluarga:
* **Anti SQL Injection:** Menggunakan PDO dengan `PDO::ATTR_EMULATE_PREPARES => false` dan *prepared statements* pada seluruh kueri.
* **Anti CSRF (*Cross-Site Request Forgery*):** Seluruh formulir `POST` (termasuk aksi hapus dan logout) dilindungi token kriptografis (`random_bytes(32)`) yang divalidasi dengan `hash_equals()`.
* **Anti XSS (*Cross-Site Scripting*):** Seluruh keluaran data disanitasi menggunakan `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.
* **Anti IDOR (*Insecure Direct Object Reference*):** Validasi kepemilikan `user_id` sesi aktif pada seluruh operasi baca, ubah, dan hapus data.
* **Proteksi Brute-Force & Bot:** Pembatasan percobaan login (*rate limiting*) berbasis kombinasi alamat IP dan email (maksimal 5 kegagalan per 15 menit) serta dukungan **Google reCAPTCHA v2**.
* **Keamanan Sesi & Password:** Enkripsi password menggunakan **Bcrypt** (`password_hash`), regenerasi ID sesi saat login/perubahan kredensial (`session_regenerate_id(true)`), cookie sesi `HttpOnly` & `SameSite=Strict`, serta header keamanan HTTP (`X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`).

---

## Teknologi yang Digunakan

* **Backend:** PHP 8.0+ (ekstensi `pdo_mysql`, `json`, `curl`/`openssl`)
* **Database:** MySQL 5.7+ / MariaDB 10.3+ (`utf8mb4`)
* **Frontend:** HTML5, Bootstrap 5.3, Bootstrap Icons, Chart.js 4.4
* **Library Opsional:** `dompdf/dompdf` (via Composer untuk unduhan PDF langsung)

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
   git clone https://github.com/noobversi1/dompet-keluarga.git
   cd Dompet-Keluarga
   ```

2. **Pindahkan ke Web Server**
   Letakkan folder proyek ke dalam direktori root web server Anda (misalnya `htdocs` pada XAMPP, `www` pada Laragon, atau `/var/www/html` pada server Linux).

3. **(Opsional) Instal Dompdf untuk Unduhan PDF Langsung**
   ```bash
   composer require dompdf/dompdf
   ```
   *Catatan: Jika langkah ini dilewati, fitur Laporan PDF tetap berfungsi menggunakan dialog cetak browser (Save as PDF).*

4. **Jalankan Web Installer**
   * Buka browser dan akses `http://localhost/dompet-keluarga/install.php`.
   * Masukkan kredensial server MySQL Anda beserta data **Akun Pemilik Tunggal**.
   * Klik **Mulai Instalasi**. Sistem akan membuat database, seluruh tabel relasional, dompet serta kategori bawaan, memperbarui `config.php`, dan mengunci installer melalui file `installed.lock`.

5. **Hapus File Installer (Wajib untuk Keamanan)**
   Setelah instalasi selesai dan Anda berhasil login, **segera hapus file `install.php`** dari direktori server.

---

## Rekomendasi Keamanan Server Produksi

* Gunakan protokol **HTTPS (SSL/TLS)** agar cookie sesi berstatus `Secure` tidak dapat disadap melalui jaringan publik.
* Pastikan `display_errors = Off` pada konfigurasi `php.ini` di server produksi agar informasi struktur direktori tidak tampil jika terjadi pengecualian.
* Lakukan pencadangan (*backup*) database MySQL secara berkala.

---

## Lisensi

Proyek ini dirilis di bawah lisensi [MIT License](LICENSE). Dikembangkan oleh **Tarigan Hosting**.

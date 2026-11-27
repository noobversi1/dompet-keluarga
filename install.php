<?php
declare(strict_types=1);

session_start();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

$lockFile = __DIR__ . '/installed.lock';
$isLocked = file_exists($lockFile);

function e(?string $str): string
{
    return htmlspecialchars((string)$str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (empty($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}

$errors = [];
$logs   = [];
$sukses = false;

$dbHost = $_POST['db_host'] ?? '127.0.0.1';
$dbName = $_POST['db_name'] ?? 'dompet_keluarga';
$dbUser = $_POST['db_user'] ?? 'root';
$dbPass = $_POST['db_pass'] ?? '';

$ownerNama  = $_POST['owner_nama'] ?? '';
$ownerEmail = $_POST['owner_email'] ?? '';
$ownerPass  = $_POST['owner_pass'] ?? '';
$updateConfig = isset($_POST['update_config']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isLocked) {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['install_csrf'], $token)) {
        $errors[] = 'Token keamanan CSRF tidak valid. Muat ulang halaman dan coba lagi.';
    }

    $dbHost     = trim($dbHost);
    $dbName     = trim($dbName);
    $dbUser     = trim($dbUser);
    $ownerNama  = trim($ownerNama);
    $ownerEmail = strtolower(trim($ownerEmail));

    if ($dbHost === '' || $dbUser === '') {
        $errors[] = 'Host dan Username database wajib diisi.';
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbName)) {
        $errors[] = 'Nama database hanya boleh berisi huruf, angka, dan garis bawah (_).';
    }

    if (empty($errors)) {
        try {
            $dsn = "mysql:host={$dbHost};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $logs[] = 'Berhasil terhubung ke server MySQL (' . e($dbHost) . ').';

            // Coba buat database jika belum ada (aman juga untuk shared hosting yang DB-nya dibuat lewat cPanel)
            try {
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (PDOException $ignored) {
                // Abaikan jika user MySQL terbatas hanya pada database yang sudah dibuat di panel hosting
            }

            $pdo->exec("USE `{$dbName}`");
            $logs[] = 'Database <strong>' . e($dbName) . '</strong> aktif dan siap digunakan.';

            // Seluruh skema tabel aplikasi (diurutkan sesuai ketergantungan Foreign Key)
            $tables = [
                'users' => "
                    CREATE TABLE IF NOT EXISTS users (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        nama VARCHAR(100) NOT NULL,
                        email VARCHAR(150) NOT NULL UNIQUE,
                        password_hash VARCHAR(255) NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ",
                'login_attempts' => "
                    CREATE TABLE IF NOT EXISTS login_attempts (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        ip_address VARCHAR(45) NOT NULL,
                        email VARCHAR(150) NOT NULL DEFAULT '',
                        attempted_at INT UNSIGNED NOT NULL,
                        INDEX idx_ip_time (ip_address, attempted_at),
                        INDEX idx_email_time (email, attempted_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ",
                'dompet' => "
                    CREATE TABLE IF NOT EXISTS dompet (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        nama_dompet VARCHAR(100) NOT NULL,
                        saldo DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ",
                'kategori' => "
                    CREATE TABLE IF NOT EXISTS kategori (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        nama_kategori VARCHAR(100) NOT NULL,
                        tipe ENUM('pemasukan', 'pengeluaran') NOT NULL,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ",
                'budgeting' => "
                    CREATE TABLE IF NOT EXISTS budgeting (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        kategori_id INT NOT NULL,
                        bulan CHAR(7) NOT NULL,
                        batas_anggaran DECIMAL(15,2) NOT NULL,
                        UNIQUE KEY unik_budget (user_id, kategori_id, bulan),
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                        FOREIGN KEY (kategori_id) REFERENCES kategori(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ",
                'transaksi' => "
                    CREATE TABLE IF NOT EXISTS transaksi (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        dompet_id INT NOT NULL,
                        kategori_id INT NOT NULL,
                        tipe ENUM('pemasukan', 'pengeluaran') NOT NULL,
                        jumlah DECIMAL(15,2) NOT NULL,
                        keterangan VARCHAR(255) DEFAULT NULL,
                        tanggal DATE NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_user_tanggal (user_id, tanggal),
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                        FOREIGN KEY (dompet_id) REFERENCES dompet(id) ON DELETE RESTRICT,
                        FOREIGN KEY (kategori_id) REFERENCES kategori(id) ON DELETE RESTRICT
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ",
                'target_tabungan' => "
                    CREATE TABLE IF NOT EXISTS target_tabungan (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        nama_target VARCHAR(120) NOT NULL,
                        target_dana DECIMAL(15,2) NOT NULL,
                        dana_terkumpul DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                        tenggat_waktu DATE DEFAULT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ",
                'riwayat_tabungan' => "
                    CREATE TABLE IF NOT EXISTS riwayat_tabungan (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        target_id INT NOT NULL,
                        dompet_id INT NULL,
                        nominal DECIMAL(15,2) NOT NULL,
                        tanggal DATE NOT NULL,
                        keterangan VARCHAR(255) DEFAULT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_user_target (user_id, target_id),
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                        FOREIGN KEY (target_id) REFERENCES target_tabungan(id) ON DELETE CASCADE,
                        FOREIGN KEY (dompet_id) REFERENCES dompet(id) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ",
                'riwayat_transfer' => "
                    CREATE TABLE IF NOT EXISTS riwayat_transfer (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        dompet_asal_id INT NULL,
                        dompet_tujuan_id INT NULL,
                        nominal DECIMAL(15,2) NOT NULL,
                        biaya_admin DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                        tanggal DATE NOT NULL,
                        keterangan VARCHAR(255) DEFAULT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_user_tanggal (user_id, tanggal),
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                        FOREIGN KEY (dompet_asal_id) REFERENCES dompet(id) ON DELETE SET NULL,
                        FOREIGN KEY (dompet_tujuan_id) REFERENCES dompet(id) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ",
                'investasi' => "
                    CREATE TABLE IF NOT EXISTS investasi (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        nama_aset VARCHAR(120) NOT NULL,
                        jenis_aset VARCHAR(50) NOT NULL,
                        satuan VARCHAR(30) NOT NULL DEFAULT 'Unit',
                        jumlah_unit DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
                        modal_pokok DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                        nilai_pasar DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                        catatan VARCHAR(255) DEFAULT NULL,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_user_inv (user_id),
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ",
                'riwayat_investasi' => "
                    CREATE TABLE IF NOT EXISTS riwayat_investasi (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        investasi_id INT NOT NULL,
                        dompet_id INT NULL,
                        jenis_aktivitas ENUM('beli', 'jual', 'dividen', 'valuasi') NOT NULL,
                        jumlah_unit DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
                        nominal_modal DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                        nominal_kas DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                        keuntungan_rugi DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                        tanggal DATE NOT NULL,
                        keterangan VARCHAR(255) DEFAULT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_user_riwayat_inv (user_id, tanggal),
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                        FOREIGN KEY (investasi_id) REFERENCES investasi(id) ON DELETE CASCADE,
                        FOREIGN KEY (dompet_id) REFERENCES dompet(id) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ",
                'pengaturan' => "
                    CREATE TABLE IF NOT EXISTS pengaturan (
                        kunci VARCHAR(100) PRIMARY KEY,
                        nilai TEXT NULL,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ",
            ];

            foreach ($tables as $tableName => $sql) {
                $pdo->exec($sql);
                $logs[] = 'Tabel <code>' . e($tableName) . '</code> siap.';
            }

            // Cek apakah akun pemilik tunggal sudah ada (berguna saat migrasi database lama)
            $jumlahUser = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

            if ($jumlahUser > 0) {
                $logs[] = 'Akun pemilik sudah tersedia di database (mode migrasi server: data lama dipertahankan).';
            } else {
                $validEmail = filter_var($ownerEmail, FILTER_VALIDATE_EMAIL);
                if ($ownerNama === '' || !$validEmail || strlen($ownerPass) < 8) {
                    throw new RuntimeException('Untuk instalasi baru, Nama Pemilik, Email valid, dan Password minimal 8 karakter wajib diisi.');
                }

                $pdo->beginTransaction();
                try {
                    $hash = password_hash($ownerPass, PASSWORD_BCRYPT);
                    $stmtUser = $pdo->prepare('INSERT INTO users (nama, email, password_hash) VALUES (?, ?, ?)');
                    $stmtUser->execute([$ownerNama, $validEmail, $hash]);
                    $userId = (int)$pdo->lastInsertId();

                    $stmtDompet = $pdo->prepare('INSERT INTO dompet (user_id, nama_dompet, saldo) VALUES (?, ?, 0)');
                    foreach (['Kas Tunai Rumah', 'Rekening Bank Utama', 'E-Wallet'] as $d) {
                        $stmtDompet->execute([$userId, $d]);
                    }

                    $stmtKat = $pdo->prepare('INSERT INTO kategori (user_id, nama_kategori, tipe) VALUES (?, ?, ?)');
                    $defaultKategori = [
                        ['Gaji Bulanan', 'pemasukan'],
                        ['Bonus / Usaha Sampingan', 'pemasukan'],
                        ['Belanja Dapur & Sembako', 'pengeluaran'],
                        ['Tagihan Listrik, Air & Internet', 'pengeluaran'],
                        ['Pendidikan & Kebutuhan Anak', 'pengeluaran'],
                        ['Kesehatan & Obat', 'pengeluaran'],
                        ['Transportasi & BBM', 'pengeluaran'],
                        ['Cicilan / Sewa Rumah', 'pengeluaran'],
                    ];
                    foreach ($defaultKategori as $kat) {
                        $stmtKat->execute([$userId, $kat[0], $kat[1]]);
                    }

                    $pdo->commit();
                    $logs[] = 'Akun pemilik tunggal (<strong>' . e($validEmail) . '</strong>), dompet, dan kategori bawaan berhasil dibuat.';
                } catch (Throwable $txErr) {
                    $pdo->rollBack();
                    throw $txErr;
                }
            }

            // Tulis ulang config.php secara aman
            if ($updateConfig) {
                $hostExport = var_export($dbHost, true);
                $nameExport = var_export($dbName, true);
                $userExport = var_export($dbUser, true);
                $passExport = var_export($dbPass, true);

                $configContent = <<<PHP
<?php
declare(strict_types=1);

// Pengaturan Session yang Aman
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => isset(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] === 'on',
    'httponly' => true,
    'samesite' => 'Strict'
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Header Keamanan HTTP Dasar
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Konfigurasi Database MySQL (Dihasilkan oleh install.php)
\$dbHost = {$hostExport};
\$dbName = {$nameExport};
\$dbUser = {$userExport};
\$dbPass = {$passExport};

try {
    \$pdo = new PDO(
        "mysql:host={\$dbHost};dbname={\$dbName};charset=utf8mb4",
        \$dbUser,
        \$dbPass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException \$e) {
    error_log('Database Connection Error: ' . \$e->getMessage());
    http_response_code(500);
    die('Terjadi gangguan koneksi pada server database.');
}
PHP;
                if (file_put_contents(__DIR__ . '/config.php', $configContent) !== false) {
                    $logs[] = 'File <code>config.php</code> berhasil diperbarui.';
                } else {
                    $logs[] = '<span class="text-warning">Peringatan: Gagal menulis ke <code>config.php</code>. Periksa hak akses folder atau sesuaikan manual.</span>';
                }
            }

            file_put_contents($lockFile, 'Installed on ' . date('Y-m-d H:i:s'));
            $logs[] = 'File pengunci <code>installed.lock</code> dibuat.';
            $sukses = true;
            $isLocked = true;
        } catch (Throwable $e) {
            $errors[] = 'Gagal menjalankan instalasi: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalasi & Migrasi - Dompet Keluarga</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center min-vh-100 py-4">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-primary text-white py-3">
                    <h5 class="mb-0 fw-bold"><i class="bi bi-database-gear me-2"></i>Installer & Migrasi Dompet Keluarga</h5>
                </div>
                <div class="card-body p-4">
                    <?php if ($sukses): ?>
                        <div class="alert alert-success">
                            <h6 class="fw-bold mb-1"><i class="bi bi-check-circle-fill me-1"></i>Instalasi Selesai!</h6>
                            <p class="small mb-0">Seluruh 11 tabel database telah terverifikasi dan siap digunakan.</p>
                        </div>

                        <ul class="list-group list-group-flush small mb-3 border rounded">
                            <?php foreach ($logs as $log): ?>
                                <li class="list-group-item"><i class="bi bi-check2 text-success me-2"></i><?= $log ?></li>
                            <?php endforeach; ?>
                        </ul>

                        <div class="alert alert-warning small">
                            <strong>Tips Keamanan:</strong> Simpan salinan <code>install.php</code> di komputer lokal Anda untuk kebutuhan pindah server nanti, lalu hapus atau biarkan file <code>installed.lock</code> tetap aktif di server produksi.
                        </div>

                        <a href="login.php" class="btn btn-primary w-100 fw-semibold">
                            Masuk ke Aplikasi <i class="bi bi-arrow-right ms-1"></i>
                        </a>

                    <?php elseif ($isLocked): ?>
                        <div class="alert alert-warning mb-3">
                            <h6 class="fw-bold"><i class="bi bi-shield-lock-fill me-1"></i>Installer Terkunci</h6>
                            <p class="small mb-0">
                                Ditemukan file <code>installed.lock</code>. Jika Anda baru saja memindahkan aplikasi ke server baru, hapus file <code>installed.lock</code> terlebih dahulu untuk menjalankan ulang konfigurasi database.
                            </p>
                        </div>
                        <a href="login.php" class="btn btn-primary w-100">Ke Halaman Login</a>

                    <?php else: ?>
                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger small">
                                <ul class="mb-0 ps-3">
                                    <?php foreach ($errors as $err): ?>
                                        <li><?= e($err) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['install_csrf']) ?>">

                            <h6 class="fw-bold text-primary mb-2">1. Konfigurasi Database MySQL</h6>
                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Database Host</label>
                                <input type="text" name="db_host" value="<?= e($dbHost) ?>" class="form-control" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Nama Database</label>
                                <input type="text" name="db_name" value="<?= e($dbName) ?>" class="form-control" pattern="[a-zA-Z0-9_]+" required>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Username MySQL</label>
                                    <input type="text" name="db_user" value="<?= e($dbUser) ?>" class="form-control" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Password MySQL</label>
                                    <input type="password" name="db_pass" value="<?= e($dbPass) ?>" class="form-control">
                                </div>
                            </div>

                            <div class="form-check mb-4">
                                <input class="form-check-input" type="checkbox" name="update_config" id="updateConfig" checked>
                                <label class="form-check-label small" for="updateConfig">
                                    Simpan kredensial otomatis ke <code>config.php</code>
                                </label>
                            </div>

                            <hr>
                            <h6 class="fw-bold text-primary mb-1">2. Akun Pemilik Tunggal</h6>
                            <p class="text-muted small mb-3">Kosongkan bagian ini jika Anda sedang memindahkan database lama yang sudah memiliki akun.</p>

                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Nama Pemilik / Keluarga</label>
                                <input type="text" name="owner_nama" value="<?= e($ownerNama) ?>" class="form-control">
                            </div>

                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Email Login</label>
                                    <input type="email" name="owner_email" value="<?= e($ownerEmail) ?>" class="form-control">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Password (Min. 8 Karakter)</label>
                                    <input type="password" name="owner_pass" minlength="8" class="form-control">
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary w-100 fw-semibold">
                                Jalankan Instalasi / Verifikasi Tabel
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>

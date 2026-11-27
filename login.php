<?php
declare(strict_types=1);

require_once 'config.php';
require_once 'security.php';

// Arahkan ke installer jika aplikasi belum terinstal
if (!file_exists(__DIR__ . '/installed.lock') && file_exists(__DIR__ . '/install.php')) {
    header('Location: install.php');
    exit;
}

if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Inisialisasi tabel rate limiting & pengaturan aplikasi
$recaptcha = getRecaptchaConfig($pdo);

$maxAttempts   = 5;   // Maksimal percobaan gagal
$windowSeconds = 900; // Durasi blokir sementara (15 menit)
$clientIp      = getClientIp();

$error    = '';
$isLocked = false;
$flash    = getFlash();

// Cek status blokir IP saat halaman dimuat
$initialStatus = checkLoginRateLimit($pdo, $clientIp, '', $maxAttempts, $windowSeconds);
if (!$initialStatus['allowed']) {
    $isLocked = true;
    $error = 'Terlalu banyak percobaan login gagal. Silakan coba lagi dalam ' . formatWaitTime($initialStatus['retry_after']) . '.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $rawEmail = trim($_POST['email'] ?? '');
    $email    = filter_var($rawEmail, FILTER_VALIDATE_EMAIL);
    $pass     = $_POST['password'] ?? '';

    // 1. Periksa batas percobaan login (rate limiting)
    $rateStatus = checkLoginRateLimit($pdo, $clientIp, $rawEmail, $maxAttempts, $windowSeconds);

    if (!$rateStatus['allowed']) {
        $isLocked = true;
        $error = 'Terlalu banyak percobaan login gagal. Silakan coba lagi dalam ' . formatWaitTime($rateStatus['retry_after']) . '.';
    } else {
        // 2. Verifikasi Google reCAPTCHA jika diaktifkan di pengaturan profil
        $captchaValid = true;
        if ($recaptcha['enabled']) {
            $captchaToken = $_POST['g-recaptcha-response'] ?? '';
            $verif = verifyRecaptcha($recaptcha['secret_key'], $captchaToken, $clientIp);

            if (!$verif['success']) {
                $captchaValid = false;
                recordFailedLogin($pdo, $clientIp, $rawEmail);
                $error = $verif['error'];
            }
        }

        // 3. Verifikasi kredensial email & password
        if ($captchaValid) {
            $loginBerhasil = false;

            if ($email && $pass !== '') {
                $stmt = $pdo->prepare('SELECT id, nama, password_hash FROM users WHERE email = ? LIMIT 1');
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user && password_verify($pass, $user['password_hash'])) {
                    $loginBerhasil = true;
                    clearLoginAttempts($pdo, $clientIp, $email);

                    session_regenerate_id(true);
                    $_SESSION['user_id']   = (int)$user['id'];
                    $_SESSION['user_nama'] = $user['nama'];

                    header('Location: index.php');
                    exit;
                }
            }

            if (!$loginBerhasil) {
                recordFailedLogin($pdo, $clientIp, $rawEmail);
                $afterFailStatus = checkLoginRateLimit($pdo, $clientIp, $rawEmail, $maxAttempts, $windowSeconds);

                if (!$afterFailStatus['allowed']) {
                    $isLocked = true;
                    $error = 'Terlalu banyak percobaan login gagal. Akun/IP dikunci sementara selama ' . formatWaitTime($afterFailStatus['retry_after']) . '.';
                } else {
                    $sisa = $afterFailStatus['remaining_attempts'];
                    $error = 'Email atau password salah.';
                    if ($sisa <= 2) {
                        $error .= " Peringatan: Tersisa {$sisa} kali percobaan sebelum dikunci sementara.";
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Dompet Keluarga</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <?php if ($recaptcha['enabled'] && !$isLocked): ?>
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <?php endif; ?>
</head>
<body class="bg-light d-flex align-items-center min-vh-100">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <h4 class="fw-bold text-primary mb-1">Dompet Keluarga</h4>
                    <p class="text-muted small mb-4">Masuk untuk mengelola anggaran rumah tangga</p>

                    <?php if ($flash): ?>
                        <div class="alert alert-<?= e($flash['type']) ?> py-2 small"><?= e($flash['message']) ?></div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="alert alert-danger py-2 small"><?= e($error) ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <?= csrfField() ?>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" class="form-control" <?= $isLocked ? 'disabled' : 'required' ?>>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" <?= $isLocked ? 'disabled' : 'required' ?>>
                        </div>

                        <?php if ($recaptcha['enabled'] && !$isLocked): ?>
                            <div class="mb-3">
                                <div class="g-recaptcha" data-sitekey="<?= e($recaptcha['site_key']) ?>"></div>
                            </div>
                        <?php endif; ?>

                        <button type="submit" class="btn btn-primary w-100" <?= $isLocked ? 'disabled' : '' ?>>
                            <?= $isLocked ? 'Akses Dikunci Sementara' : 'Masuk' ?>
                        </button>
                    </form>
                </div>
            </div>
            <div class="text-end mt-2">
                <small class="text-muted" style="font-size: 0.78rem;">Developed by: Tarigan Hosting</small>
            </div>
        </div>
    </div>
</div>
</body>
</html>

<?php
declare(strict_types=1);

// Sanitasi output untuk mencegah XSS
function e(?string $string): string
{
    return htmlspecialchars((string)$string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Format mata uang Rupiah
function rupiah(float|string $angka): string
{
    return 'Rp ' . number_format((float)$angka, 0, ',', '.');
}

// Generator & Verifikasi Token CSRF
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

function verifyCsrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('Permintaan ditolak: Token keamanan CSRF tidak valid.');
    }
}

// Proteksi Halaman Khusus Pengguna Login
function requireLogin(): int
{
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
    return (int)$_SESSION['user_id'];
}

// Flash Message Helper
function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array
{
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// =========================================================================
// PROTEKSI BRUTE-FORCE & LOGIN RATE LIMITING
// =========================================================================

function getClientIp(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function checkLoginRateLimit(
    PDO $pdo,
    string $ip,
    string $email = '',
    int $maxAttempts = 5,
    int $windowSeconds = 900
): array {
    $now = time();
    $windowStart = $now - $windowSeconds;
    $normalizedEmail = strtolower(trim($email));

    $stmtIp = $pdo->prepare('
        SELECT COUNT(*) AS total, MIN(attempted_at) AS oldest
        FROM login_attempts
        WHERE ip_address = ? AND attempted_at >= ?
    ');
    $stmtIp->execute([$ip, $windowStart]);
    $ipStats = $stmtIp->fetch();
    $ipCount = (int)($ipStats['total'] ?? 0);

    $emailCount = 0;
    $emailOldest = null;
    if ($normalizedEmail !== '') {
        $stmtEmail = $pdo->prepare('
            SELECT COUNT(*) AS total, MIN(attempted_at) AS oldest
            FROM login_attempts
            WHERE email = ? AND attempted_at >= ?
        ');
        $stmtEmail->execute([$normalizedEmail, $windowStart]);
        $emailStats = $stmtEmail->fetch();
        $emailCount = (int)($emailStats['total'] ?? 0);
        $emailOldest = $emailStats['oldest'] !== null ? (int)$emailStats['oldest'] : null;
    }

    $blockedByIp = $ipCount >= $maxAttempts;
    $blockedByEmail = $normalizedEmail !== '' && $emailCount >= $maxAttempts;

    if ($blockedByIp || $blockedByEmail) {
        $oldestTimestamps = [];
        if ($blockedByIp && $ipStats['oldest'] !== null) {
            $oldestTimestamps[] = (int)$ipStats['oldest'];
        }
        if ($blockedByEmail && $emailOldest !== null) {
            $oldestTimestamps[] = $emailOldest;
        }

        $referenceTime = !empty($oldestTimestamps) ? min($oldestTimestamps) : $now;
        $retryAfter = max(1, ($referenceTime + $windowSeconds) - $now);

        return [
            'allowed'            => false,
            'retry_after'        => $retryAfter,
            'remaining_attempts' => 0,
        ];
    }

    $highestCount = max($ipCount, $emailCount);
    return [
        'allowed'            => true,
        'retry_after'        => 0,
        'remaining_attempts' => max(0, $maxAttempts - $highestCount),
    ];
}

function recordFailedLogin(PDO $pdo, string $ip, string $email = ''): void
{
    $now = time();
    $normalizedEmail = substr(strtolower(trim($email)), 0, 150);

    $stmt = $pdo->prepare('
        INSERT INTO login_attempts (ip_address, email, attempted_at)
        VALUES (?, ?, ?)
    ');
    $stmt->execute([$ip, $normalizedEmail, $now]);

    $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')
        ->execute([$now - 86400]);
}

function clearLoginAttempts(PDO $pdo, string $ip, string $email): void
{
    $normalizedEmail = strtolower(trim($email));
    $stmt = $pdo->prepare('
        DELETE FROM login_attempts
        WHERE ip_address = ? OR email = ?
    ');
    $stmt->execute([$ip, $normalizedEmail]);
}

function formatWaitTime(int $seconds): string
{
    $minutes = intdiv($seconds, 60);
    $remSeconds = $seconds % 60;

    if ($minutes > 0 && $remSeconds > 0) {
        return "{$minutes} menit {$remSeconds} detik";
    }
    if ($minutes > 0) {
        return "{$minutes} menit";
    }
    return "{$remSeconds} detik";
}

// =========================================================================
// PENGATURAN APLIKASI & GOOGLE RECAPTCHA V2
// =========================================================================

function setAppSetting(PDO $pdo, string $key, ?string $value): void
{
    $stmt = $pdo->prepare('
        INSERT INTO pengaturan (kunci, nilai)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE nilai = VALUES(nilai)
    ');
    $stmt->execute([$key, $value]);
}

function getRecaptchaConfig(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT kunci, nilai
        FROM pengaturan
        WHERE kunci IN ('recaptcha_enabled', 'recaptcha_site_key', 'recaptcha_secret_key')
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $flag      = ($rows['recaptcha_enabled'] ?? '0') === '1';
    $siteKey   = trim((string)($rows['recaptcha_site_key'] ?? ''));
    $secretKey = trim((string)($rows['recaptcha_secret_key'] ?? ''));

    return [
        'flag'       => $flag,
        'enabled'    => $flag && $siteKey !== '' && $secretKey !== '',
        'site_key'   => $siteKey,
        'secret_key' => $secretKey,
    ];
}

function verifyRecaptcha(string $secretKey, string $responseToken, string $remoteIp = ''): array
{
    if ($responseToken === '') {
        return [
            'success' => false,
            'error'   => 'Silakan centang kotak verifikasi "Saya bukan robot" terlebih dahulu.',
        ];
    }

    $verifyUrl = 'https://www.google.com/recaptcha/api/siteverify';
    $postFields = http_build_query([
        'secret'   => $secretKey,
        'response' => $responseToken,
        'remoteip' => $remoteIp,
    ]);

    $rawResponse = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($verifyUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $rawResponse = curl_exec($ch);
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $postFields,
                'timeout' => 8,
            ],
        ]);
        $rawResponse = @file_get_contents($verifyUrl, false, $context);
    }

    if ($rawResponse === false) {
        return [
            'success' => false,
            'error'   => 'Gagal menghubungi server verifikasi Google reCAPTCHA. Pastikan server memiliki akses internet.',
        ];
    }

    $result = json_decode($rawResponse, true);
    if (!is_array($result) || empty($result['success'])) {
        return [
            'success' => false,
            'error'   => 'Verifikasi Google reCAPTCHA gagal atau kedaluwarsa. Silakan centang ulang kotak verifikasi.',
        ];
    }

    return [
        'success' => true,
        'error'   => '',
    ];
}

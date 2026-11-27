<?php
declare(strict_types=1);

require_once 'config.php';
require_once 'security.php';
require_once 'layout.php';

$userId = requireLogin();

// Ambil data pengguna aktif dari database
$stmtUser = $pdo->prepare('SELECT id, nama, email, password_hash, created_at FROM users WHERE id = ? LIMIT 1');
$stmtUser->execute([$userId]);
$user = $stmtUser->fetch();

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$recaptchaConfig = getRecaptchaConfig($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $aksi = $_POST['aksi'] ?? '';

    // =====================================================================
    // 1. PERBARUI NAMA PENGGUNA & EMAIL
    // =====================================================================
    if ($aksi === 'update_profil') {
        $namaBaru     = trim($_POST['nama'] ?? '');
        $emailInput   = strtolower(trim($_POST['email'] ?? ''));
        $emailBaru    = filter_var($emailInput, FILTER_VALIDATE_EMAIL);
        $passKonfirm  = $_POST['password_konfirmasi'] ?? '';

        $emailBerubah = ($emailBaru !== false && $emailBaru !== strtolower($user['email']));

        if ($namaBaru === '' || mb_strlen($namaBaru) > 100) {
            setFlash('danger', 'Nama pengguna wajib diisi (maksimal 100 karakter).');
        } elseif (!$emailBaru || mb_strlen($emailBaru) > 150) {
            setFlash('danger', 'Format alamat email tidak valid.');
        } elseif ($emailBerubah && !password_verify($passKonfirm, $user['password_hash'])) {
            setFlash('danger', 'Perubahan alamat email ditolak: Password saat ini tidak sesuai.');
        } else {
            $cekEmail = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
            $cekEmail->execute([$emailBaru, $userId]);

            if ($cekEmail->fetch()) {
                setFlash('danger', 'Alamat email tersebut sudah digunakan oleh akun lain.');
            } else {
                $stmtUpdate = $pdo->prepare('UPDATE users SET nama = ?, email = ? WHERE id = ?');
                $stmtUpdate->execute([$namaBaru, $emailBaru, $userId]);

                $_SESSION['user_nama'] = $namaBaru;

                if ($emailBerubah) {
                    session_regenerate_id(true);
                    setFlash('success', 'Nama pengguna dan alamat email berhasil diperbarui.');
                } else {
                    setFlash('success', 'Nama pengguna berhasil diperbarui.');
                }
            }
        }

        header('Location: profil.php');
        exit;
    }

    // =====================================================================
    // 2. GANTI PASSWORD
    // =====================================================================
    if ($aksi === 'update_password') {
        $passLama       = $_POST['password_lama'] ?? '';
        $passBaru       = $_POST['password_baru'] ?? '';
        $passKonfirmasi = $_POST['konfirmasi_password'] ?? '';

        if ($passLama === '' || $passBaru === '' || $passKonfirmasi === '') {
            setFlash('danger', 'Seluruh kolom password wajib diisi.');
        } elseif (!password_verify($passLama, $user['password_hash'])) {
            setFlash('danger', 'Password saat ini yang Anda masukkan salah.');
        } elseif (strlen($passBaru) < 8) {
            setFlash('danger', 'Password baru harus terdiri dari minimal 8 karakter.');
        } elseif ($passBaru !== $passKonfirmasi) {
            setFlash('danger', 'Konfirmasi password baru tidak cocok.');
        } elseif (password_verify($passBaru, $user['password_hash'])) {
            setFlash('danger', 'Password baru tidak boleh sama dengan password lama.');
        } else {
            $hashBaru = password_hash($passBaru, PASSWORD_BCRYPT);
            $stmtPass = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmtPass->execute([$hashBaru, $userId]);

            session_regenerate_id(true);
            setFlash('success', 'Password berhasil diperbarui. Gunakan password baru pada login berikutnya.');
        }

        header('Location: profil.php');
        exit;
    }

    // =====================================================================
    // 3. PENGATURAN GOOGLE RECAPTCHA V2
    // =====================================================================
    if ($aksi === 'update_recaptcha') {
        $aktifkan       = isset($_POST['recaptcha_enabled']) ? '1' : '0';
        $siteKeyBaru    = trim($_POST['recaptcha_site_key'] ?? '');
        $secretKeyInput = trim($_POST['recaptcha_secret_key'] ?? '');
        $passKonfirm    = $_POST['password_recaptcha'] ?? '';

        // Gunakan Secret Key lama jika kolom dikosongkan saat sudah pernah disimpan
        $secretKeyFinal = $secretKeyInput !== '' ? $secretKeyInput : $recaptchaConfig['secret_key'];

        if (!password_verify($passKonfirm, $user['password_hash'])) {
            setFlash('danger', 'Perubahan pengaturan reCAPTCHA ditolak: Password saat ini tidak sesuai.');
        } elseif ($aktifkan === '1' && ($siteKeyBaru === '' || $secretKeyFinal === '')) {
            setFlash('danger', 'Site Key dan Secret Key wajib diisi untuk mengaktifkan Google reCAPTCHA.');
        } else {
            setAppSetting($pdo, 'recaptcha_enabled', $aktifkan);
            setAppSetting($pdo, 'recaptcha_site_key', $siteKeyBaru);
            setAppSetting($pdo, 'recaptcha_secret_key', $secretKeyFinal);

            if ($aktifkan === '1') {
                setFlash('success', 'Google reCAPTCHA berhasil diaktifkan. Pastikan widget di bawah tampil normal sebelum logout.');
            } else {
                setFlash('success', 'Google reCAPTCHA berhasil dinonaktifkan.');
            }
        }

        header('Location: profil.php');
        exit;
    }
}

renderHeader('Pengaturan Akun', 'profil');
?>
<div class="row g-4">
    <!-- KOLOM KIRI: UBAH NAMA & EMAIL -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-bold">
                <i class="bi bi-person-gear me-2 text-primary"></i>Informasi Profil & Email
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="aksi" value="update_profil">

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Nama Pengguna / Keluarga</label>
                        <input type="text" name="nama" value="<?= e($user['nama']) ?>" maxlength="100" class="form-control" required>
                        <div class="form-text">Nama ini tampil di bilah navigasi dan kepala laporan PDF.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Alamat Email Login</label>
                        <input type="email" name="email" id="inputEmail" value="<?= e($user['email']) ?>" maxlength="150" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Password Saat Ini <span class="text-muted fw-normal">(Wajib diisi jika mengganti email)</span></label>
                        <input type="password" name="password_konfirmasi" id="inputPassKonfirm" class="form-control" placeholder="Masukkan password saat ini untuk verifikasi email baru">
                    </div>

                    <div class="small text-muted mb-3">
                        <i class="bi bi-calendar-check me-1"></i>Akun dibuat pada: <?= e(date('d M Y, H:i', strtotime($user['created_at']))) ?>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-save me-1"></i>Simpan Perubahan Profil
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- KOLOM KANAN: GANTI PASSWORD -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-bold">
                <i class="bi bi-shield-lock me-2 text-primary"></i>Ubah Password
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="aksi" value="update_password">

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Password Saat Ini</label>
                        <input type="password" name="password_lama" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Password Baru (Minimal 8 Karakter)</label>
                        <input type="password" name="password_baru" minlength="8" class="form-control" required>
                    </div>

                    <div class="mb-4">
                        <label class="form-label small fw-semibold">Konfirmasi Password Baru</label>
                        <input type="password" name="konfirmasi_password" minlength="8" class="form-control" required>
                    </div>

                    <button type="submit" class="btn btn-outline-primary w-100">
                        <i class="bi bi-key me-1"></i>Perbarui Password
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- BARIS BAWAH: PENGATURAN GOOGLE RECAPTCHA V2 -->
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-robot me-2 text-primary"></i>Keamanan Login: Google reCAPTCHA v2</span>
                <?php if ($recaptchaConfig['enabled']): ?>
                    <span class="badge bg-success">Aktif</span>
                <?php else: ?>
                    <span class="badge bg-secondary">Nonaktif</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-lg-7">
                        <form method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="aksi" value="update_recaptcha">

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" role="switch" name="recaptcha_enabled" id="switchRecaptcha" value="1" <?= $recaptchaConfig['flag'] ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="switchRecaptcha">
                                    Aktifkan Google reCAPTCHA ("Saya bukan robot") pada halaman Login
                                </label>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Site Key (Kunci Situs)</label>
                                <input type="text" name="recaptcha_site_key" id="inputSiteKey" value="<?= e($recaptchaConfig['site_key']) ?>" class="form-control" placeholder="Contoh: 6Lcxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-semibold">
                                    Secret Key (Kunci Rahasia)
                                    <?php if ($recaptchaConfig['secret_key'] !== ''): ?>
                                        <span class="badge bg-info-subtle text-info border border-info-subtle ms-1">Sudah tersimpan</span>
                                    <?php endif; ?>
                                </label>
                                <input type="password" name="recaptcha_secret_key" id="inputSecretKey" class="form-control" placeholder="<?= $recaptchaConfig['secret_key'] !== '' ? 'Biarkan kosong jika tidak ingin mengganti Secret Key' : 'Masukkan Secret Key dari Google reCAPTCHA' ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Konfirmasi Password Saat Ini <span class="text-danger">*</span></label>
                                <input type="password" name="password_recaptcha" class="form-control" placeholder="Masukkan password akun untuk menyimpan pengaturan keamanan" required>
                            </div>

                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-shield-check me-1"></i>Simpan Pengaturan reCAPTCHA
                            </button>
                        </form>
                    </div>

                    <div class="col-lg-5">
                        <div class="border rounded p-3 bg-light h-100 d-flex flex-column justify-content-between">
                            <div>
                                <h6 class="fw-bold mb-2"><i class="bi bi-info-circle me-1 text-primary"></i>Panduan Konfigurasi</h6>
                                <ol class="small text-muted ps-3 mb-3">
                                    <li>Daftarkan domain Anda di <strong>Google reCAPTCHA Admin Console</strong> dengan memilih tipe <strong>reCAPTCHA v2 &rarr; "I'm not a robot" Checkbox</strong>.</li>
                                    <li>Jika diuji secara lokal, tambahkan <code>localhost</code> dan <code>127.0.0.1</code> pada daftar domain di konsol Google.</li>
                                    <li>Salin <strong>Site Key</strong> dan <strong>Secret Key</strong> ke formulir di samping.</li>
                                </ol>

                                <?php if ($recaptchaConfig['enabled']): ?>
                                    <div class="border-top pt-3">
                                        <div class="small fw-semibold text-success mb-2">
                                            <i class="bi bi-eye me-1"></i>Pratinjau Widget Aktif:
                                        </div>
                                        <div class="g-recaptcha" data-sitekey="<?= e($recaptchaConfig['site_key']) ?>"></div>
                                        <div class="form-text mt-1">
                                            Jika kotak centang di atas muncul normal tanpa pesan error domain, reCAPTCHA siap digunakan saat login.
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="alert alert-warning small mb-0 mt-3 py-2">
                                <strong>Tips Pemulihan:</strong> Jika salah memasukkan kunci sehingga tidak bisa login, ubah nilai <code>recaptcha_enabled</code> menjadi <code>0</code> pada tabel <code>pengaturan</code> di phpMyAdmin.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($recaptchaConfig['enabled']): ?>
<script src="https://www.google.com/recaptcha/api.js" async defer></script>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const emailAwal   = <?= json_encode(strtolower($user['email'])) ?>;
    const inputEmail  = document.getElementById('inputEmail');
    const passKonfirm = document.getElementById('inputPassKonfirm');

    function cekPerubahanEmail() {
        const berubah = inputEmail.value.trim().toLowerCase() !== emailAwal;
        passKonfirm.required = berubah;
    }

    inputEmail.addEventListener('input', cekPerubahanEmail);
    cekPerubahanEmail();

    const switchRecaptcha = document.getElementById('switchRecaptcha');
    const inputSiteKey    = document.getElementById('inputSiteKey');
    const inputSecretKey  = document.getElementById('inputSecretKey');
    const hasSecretKey    = <?= json_encode($recaptchaConfig['secret_key'] !== '') ?>;

    function cekWajibRecaptcha() {
        const aktif = switchRecaptcha.checked;
        inputSiteKey.required   = aktif;
        inputSecretKey.required = aktif && !hasSecretKey;
    }

    switchRecaptcha.addEventListener('change', cekWajibRecaptcha);
    cekWajibRecaptcha();
});
</script>
<?php renderFooter(); ?>

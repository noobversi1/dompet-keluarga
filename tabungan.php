<?php
declare(strict_types=1);

require_once 'config.php';
require_once 'security.php';
require_once 'layout.php';

$userId = requireLogin();

// Otomatis buat tabel riwayat_tabungan jika belum ada di database
$pdo->exec("
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
");

// Parameter Filter & Pagination Target Tabungan
$statusTarget = in_array($_GET['status_target'] ?? '', ['berjalan', 'tercapai'], true) ? $_GET['status_target'] : '';
$qTarget      = trim($_GET['q_target'] ?? '');
$pageTarget   = max(1, (int)($_GET['page_target'] ?? 1));
$perPageTarget = 4; // 4 kartu (2x2 grid) per halaman

// Parameter Filter & Pagination Riwayat Alokasi
$filterTarget  = max(0, (int)($_GET['target_id'] ?? 0));
$filterDompet  = max(0, (int)($_GET['dompet_id'] ?? 0));
$bulanRiwayat  = preg_match('/^\d{4}-\d{2}$/', $_GET['bulan_riwayat'] ?? '') ? $_GET['bulan_riwayat'] : '';
$pageRiwayat   = max(1, (int)($_GET['page_riwayat'] ?? 1));
$perPageRiwayat = 10;

$baseParams = [
    'status_target' => $statusTarget,
    'q_target'      => $qTarget,
    'page_target'   => $pageTarget,
    'target_id'     => $filterTarget,
    'dompet_id'     => $filterDompet,
    'bulan_riwayat' => $bulanRiwayat,
    'page_riwayat'  => $pageRiwayat,
];

function buildTabunganUrl(array $base, array $overrides = []): string
{
    $merged = array_merge($base, $overrides);
    $clean  = [];

    if (($merged['status_target'] ?? '') !== '') $clean['status_target'] = $merged['status_target'];
    if (($merged['q_target'] ?? '') !== '') $clean['q_target'] = $merged['q_target'];
    if (($merged['page_target'] ?? 1) > 1) $clean['page_target'] = (int)$merged['page_target'];

    if (($merged['target_id'] ?? 0) > 0) $clean['target_id'] = (int)$merged['target_id'];
    if (($merged['dompet_id'] ?? 0) > 0) $clean['dompet_id'] = (int)$merged['dompet_id'];
    if (($merged['bulan_riwayat'] ?? '') !== '') $clean['bulan_riwayat'] = $merged['bulan_riwayat'];
    if (($merged['page_riwayat'] ?? 1) > 1) $clean['page_riwayat'] = (int)$merged['page_riwayat'];

    $qs = http_build_query($clean);
    return 'tabungan.php' . ($qs !== '' ? '?' . $qs : '');
}

function renderTabunganPagination(int $currentPage, int $totalPages, string $pageKey, array $base): void
{
    if ($totalPages <= 1) {
        return;
    }
    $startPage = max(1, $currentPage - 2);
    $endPage   = min($totalPages, $currentPage + 2);
    ?>
    <nav aria-label="Navigasi halaman">
        <ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $currentPage > 1 ? e(buildTabunganUrl($base, [$pageKey => $currentPage - 1])) : '#' ?>">&laquo;</a>
            </li>
            <?php if ($startPage > 1): ?>
                <li class="page-item"><a class="page-link" href="<?= e(buildTabunganUrl($base, [$pageKey => 1])) ?>">1</a></li>
                <?php if ($startPage > 2): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
            <?php endif; ?>
            <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                <li class="page-item <?= $p === $currentPage ? 'active' : '' ?>">
                    <a class="page-link" href="<?= e(buildTabunganUrl($base, [$pageKey => $p])) ?>"><?= $p ?></a>
                </li>
            <?php endfor; ?>
            <?php if ($endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                <li class="page-item"><a class="page-link" href="<?= e(buildTabunganUrl($base, [$pageKey => $totalPages])) ?>"><?= $totalPages ?></a></li>
            <?php endif; ?>
            <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $currentPage < $totalPages ? e(buildTabunganUrl($base, [$pageKey => $currentPage + 1])) : '#' ?>">&raquo;</a>
            </li>
        </ul>
    </nav>
    <?php
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $aksi        = $_POST['aksi'] ?? '';
    $redirectUrl = buildTabunganUrl($baseParams);

    // 1. Buat Target Tabungan Baru
    if ($aksi === 'buat_target') {
        $namaTarget = trim($_POST['nama_target'] ?? '');
        $targetDana = (float)($_POST['target_dana'] ?? 0);
        $tenggat    = $_POST['tenggat_waktu'] ?: null;

        if ($tenggat !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tenggat)) {
            $tenggat = null;
        }

        if ($namaTarget !== '' && $targetDana > 0) {
            $stmt = $pdo->prepare('INSERT INTO target_tabungan (user_id, nama_target, target_dana, tenggat_waktu) VALUES (?, ?, ?, ?)');
            $stmt->execute([$userId, $namaTarget, $targetDana, $tenggat]);
            setFlash('success', 'Target tabungan keluarga berhasil dibuat.');
            header('Location: ' . buildTabunganUrl($baseParams, ['page_target' => 1]));
            exit;
        } else {
            setFlash('danger', 'Nama tujuan dan target dana wajib diisi dengan benar.');
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    // 2. Edit Target Tabungan
    if ($aksi === 'edit_target') {
        $targetId   = (int)($_POST['target_id'] ?? 0);
        $namaTarget = trim($_POST['nama_target'] ?? '');
        $targetDana = (float)($_POST['target_dana'] ?? 0);
        $tenggat    = $_POST['tenggat_waktu'] ?: null;

        if ($tenggat !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tenggat)) {
            $tenggat = null;
        }

        if ($targetId > 0 && $namaTarget !== '' && $targetDana > 0) {
            $stmt = $pdo->prepare('
                UPDATE target_tabungan
                SET nama_target = ?, target_dana = ?, tenggat_waktu = ?
                WHERE id = ? AND user_id = ?
            ');
            $stmt->execute([$namaTarget, $targetDana, $tenggat, $targetId, $userId]);
            setFlash('success', 'Target tabungan berhasil diperbarui.');
        } else {
            setFlash('danger', 'Data pembaruan target tabungan tidak valid.');
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    // 3. Hapus Target Tabungan
    if ($aksi === 'hapus_target') {
        $targetId        = (int)($_POST['target_id'] ?? 0);
        $dompetKembaliId = (int)($_POST['dompet_kembali_id'] ?? 0);

        $stmtGet = $pdo->prepare('SELECT id, dana_terkumpul FROM target_tabungan WHERE id = ? AND user_id = ?');
        $stmtGet->execute([$targetId, $userId]);
        $target = $stmtGet->fetch();

        if ($target) {
            $danaTerkumpul = (float)$target['dana_terkumpul'];
            $pdo->beginTransaction();
            try {
                if ($danaTerkumpul > 0 && $dompetKembaliId > 0) {
                    $cekDompet = $pdo->prepare('SELECT id FROM dompet WHERE id = ? AND user_id = ?');
                    $cekDompet->execute([$dompetKembaliId, $userId]);
                    if (!$cekDompet->fetch()) {
                        throw new Exception('Dompet tujuan pengembalian tidak valid.');
                    }
                    $pdo->prepare('UPDATE dompet SET saldo = saldo + ? WHERE id = ? AND user_id = ?')
                        ->execute([$danaTerkumpul, $dompetKembaliId, $userId]);
                }

                $pdo->prepare('DELETE FROM target_tabungan WHERE id = ? AND user_id = ?')
                    ->execute([$targetId, $userId]);

                $pdo->commit();
                $pesan = ($danaTerkumpul > 0 && $dompetKembaliId > 0)
                    ? 'Target tabungan dihapus dan dana terkumpul (' . rupiah($danaTerkumpul) . ') dikembalikan ke dompet.'
                    : 'Target tabungan berhasil dihapus.';
                setFlash('success', $pesan);
            } catch (Exception $e) {
                $pdo->rollBack();
                setFlash('danger', 'Gagal menghapus target tabungan.');
            }
        } else {
            setFlash('danger', 'Target tabungan tidak ditemukan.');
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    // 4. Alokasikan Dana (Setor ke Target Tabungan)
    if ($aksi === 'setor_dana') {
        $targetId   = (int)($_POST['target_id'] ?? 0);
        $dompetId   = (int)($_POST['dompet_id'] ?? 0);
        $nominal    = (float)($_POST['nominal'] ?? 0);
        $tanggal    = $_POST['tanggal'] ?? date('Y-m-d');
        $keterangan = trim($_POST['keterangan'] ?? '');

        $cekTarget = $pdo->prepare('SELECT id FROM target_tabungan WHERE id = ? AND user_id = ?');
        $cekTarget->execute([$targetId, $userId]);

        $cekDompet = $pdo->prepare('SELECT id, saldo FROM dompet WHERE id = ? AND user_id = ?');
        $cekDompet->execute([$dompetId, $userId]);
        $dompet = $cekDompet->fetch();

        if ($cekTarget->fetch() && $dompet && $nominal > 0 && (float)$dompet['saldo'] >= $nominal && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE dompet SET saldo = saldo - ? WHERE id = ? AND user_id = ?')
                    ->execute([$nominal, $dompetId, $userId]);

                $pdo->prepare('UPDATE target_tabungan SET dana_terkumpul = dana_terkumpul + ? WHERE id = ? AND user_id = ?')
                    ->execute([$nominal, $targetId, $userId]);

                $pdo->prepare('INSERT INTO riwayat_tabungan (user_id, target_id, dompet_id, nominal, tanggal, keterangan) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$userId, $targetId, $dompetId, $nominal, $tanggal, $keterangan]);

                $pdo->commit();
                setFlash('success', 'Dana berhasil dialokasikan ke target tabungan.');
                header('Location: ' . buildTabunganUrl($baseParams, ['page_riwayat' => 1]));
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                setFlash('danger', 'Gagal mengalokasikan dana tabungan.');
            }
        } else {
            setFlash('danger', 'Saldo dompet tidak mencukupi atau input alokasi tidak valid.');
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    // 5. Edit Alokasi Tabungan
    if ($aksi === 'edit_setoran') {
        $setoranId  = (int)($_POST['setoran_id'] ?? 0);
        $dompetId   = (int)($_POST['dompet_id'] ?? 0);
        $nominal    = (float)($_POST['nominal'] ?? 0);
        $tanggal    = $_POST['tanggal'] ?? '';
        $keterangan = trim($_POST['keterangan'] ?? '');

        $stmtOld = $pdo->prepare('SELECT id, target_id, dompet_id, nominal FROM riwayat_tabungan WHERE id = ? AND user_id = ?');
        $stmtOld->execute([$setoranId, $userId]);
        $oldSetoran = $stmtOld->fetch();

        $cekDompet = $pdo->prepare('SELECT id FROM dompet WHERE id = ? AND user_id = ?');
        $cekDompet->execute([$dompetId, $userId]);

        if ($oldSetoran && $cekDompet->fetch() && $nominal > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            $pdo->beginTransaction();
            try {
                if (!empty($oldSetoran['dompet_id'])) {
                    $pdo->prepare('UPDATE dompet SET saldo = saldo + ? WHERE id = ? AND user_id = ?')
                        ->execute([$oldSetoran['nominal'], $oldSetoran['dompet_id'], $userId]);
                }
                $pdo->prepare('UPDATE target_tabungan SET dana_terkumpul = GREATEST(0, dana_terkumpul - ?) WHERE id = ? AND user_id = ?')
                    ->execute([$oldSetoran['nominal'], $oldSetoran['target_id'], $userId]);

                $stmtSaldoBaru = $pdo->prepare('SELECT saldo FROM dompet WHERE id = ? AND user_id = ? FOR UPDATE');
                $stmtSaldoBaru->execute([$dompetId, $userId]);
                $saldoBaru = (float)$stmtSaldoBaru->fetchColumn();

                if ($saldoBaru < $nominal) {
                    $pdo->rollBack();
                    setFlash('danger', 'Saldo dompet tujuan tidak mencukupi untuk nominal baru.');
                    header('Location: ' . $redirectUrl);
                    exit;
                }

                $pdo->prepare('UPDATE dompet SET saldo = saldo - ? WHERE id = ? AND user_id = ?')
                    ->execute([$nominal, $dompetId, $userId]);

                $pdo->prepare('UPDATE target_tabungan SET dana_terkumpul = dana_terkumpul + ? WHERE id = ? AND user_id = ?')
                    ->execute([$nominal, $oldSetoran['target_id'], $userId]);

                $pdo->prepare('
                    UPDATE riwayat_tabungan
                    SET dompet_id = ?, nominal = ?, tanggal = ?, keterangan = ?
                    WHERE id = ? AND user_id = ?
                ')->execute([$dompetId, $nominal, $tanggal, $keterangan, $setoranId, $userId]);

                $pdo->commit();
                setFlash('success', 'Riwayat alokasi tabungan berhasil diperbarui dan saldo disesuaikan.');
            } catch (Exception $e) {
                $pdo->rollBack();
                setFlash('danger', 'Gagal memperbarui alokasi tabungan.');
            }
        } else {
            setFlash('danger', 'Data pembaruan alokasi tidak valid.');
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    // 6. Hapus Alokasi Tabungan
    if ($aksi === 'hapus_setoran') {
        $setoranId = (int)($_POST['setoran_id'] ?? 0);

        $stmtGet = $pdo->prepare('SELECT id, target_id, dompet_id, nominal FROM riwayat_tabungan WHERE id = ? AND user_id = ?');
        $stmtGet->execute([$setoranId, $userId]);
        $setoran = $stmtGet->fetch();

        if ($setoran) {
            $pdo->beginTransaction();
            try {
                if (!empty($setoran['dompet_id'])) {
                    $pdo->prepare('UPDATE dompet SET saldo = saldo + ? WHERE id = ? AND user_id = ?')
                        ->execute([$setoran['nominal'], $setoran['dompet_id'], $userId]);
                }

                $pdo->prepare('UPDATE target_tabungan SET dana_terkumpul = GREATEST(0, dana_terkumpul - ?) WHERE id = ? AND user_id = ?')
                    ->execute([$setoran['nominal'], $setoran['target_id'], $userId]);

                $pdo->prepare('DELETE FROM riwayat_tabungan WHERE id = ? AND user_id = ?')
                    ->execute([$setoranId, $userId]);

                $pdo->commit();
                setFlash('success', 'Alokasi tabungan berhasil dibatalkan dan dana dikembalikan ke saldo dompet.');
            } catch (Exception $e) {
                $pdo->rollBack();
                setFlash('danger', 'Gagal menghapus alokasi tabungan.');
            }
        } else {
            setFlash('danger', 'Data alokasi tabungan tidak ditemukan.');
        }
        header('Location: ' . $redirectUrl);
        exit;
    }
}

// Ringkasan Global Seluruh Tabungan (Card Kiri) & Dropdown
$stmtGlobal = $pdo->prepare('
    SELECT COALESCE(SUM(target_dana), 0) AS total_target,
           COALESCE(SUM(dana_terkumpul), 0) AS total_terkumpul
    FROM target_tabungan
    WHERE user_id = ?
');
$stmtGlobal->execute([$userId]);
$globalTabungan     = $stmtGlobal->fetch();
$totalTargetDana    = (float)($globalTabungan['total_target'] ?? 0);
$totalDanaTerkumpul = (float)($globalTabungan['total_terkumpul'] ?? 0);

$allTargetsDropdown = $pdo->prepare('SELECT id, nama_target FROM target_tabungan WHERE user_id = ? ORDER BY nama_target ASC');
$allTargetsDropdown->execute([$userId]);
$allTargetsDropdown = $allTargetsDropdown->fetchAll();

$dompets = $pdo->prepare('SELECT id, nama_dompet, saldo FROM dompet WHERE user_id = ? ORDER BY id ASC');
$dompets->execute([$userId]);
$dompets = $dompets->fetchAll();

// =========================================================================
// QUERY 1: KARTU TARGET TABUNGAN (FILTER + PAGINATION)
// =========================================================================
$whereTarget  = ['user_id = ?'];
$paramsTarget = [$userId];

if ($statusTarget === 'berjalan') {
    $whereTarget[] = 'dana_terkumpul < target_dana';
} elseif ($statusTarget === 'tercapai') {
    $whereTarget[] = 'dana_terkumpul >= target_dana';
}
if ($qTarget !== '') {
    $whereTarget[]  = 'nama_target LIKE ?';
    $paramsTarget[] = '%' . $qTarget . '%';
}
$sqlWhereTarget = implode(' AND ', $whereTarget);

$stmtCountTarget = $pdo->prepare("SELECT COUNT(*) FROM target_tabungan WHERE {$sqlWhereTarget}");
$stmtCountTarget->execute($paramsTarget);
$totalRowsTarget  = (int)$stmtCountTarget->fetchColumn();
$totalPagesTarget = max(1, (int)ceil($totalRowsTarget / $perPageTarget));
if ($pageTarget > $totalPagesTarget) $pageTarget = $totalPagesTarget;
$offsetTarget = ($pageTarget - 1) * $perPageTarget;

$stmtTargets = $pdo->prepare("
    SELECT *
    FROM target_tabungan
    WHERE {$sqlWhereTarget}
    ORDER BY id DESC
    LIMIT ? OFFSET ?
");
$idx = 1;
foreach ($paramsTarget as $pVal) {
    $stmtTargets->bindValue($idx++, $pVal, is_int($pVal) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmtTargets->bindValue($idx++, $perPageTarget, PDO::PARAM_INT);
$stmtTargets->bindValue($idx++, $offsetTarget, PDO::PARAM_INT);
$stmtTargets->execute();
$targets = $stmtTargets->fetchAll();

// =========================================================================
// QUERY 2: RIWAYAT ALOKASI TABUNGAN (FILTER + PAGINATION)
// =========================================================================
$whereRiwayat  = ['r.user_id = ?'];
$paramsRiwayat = [$userId];

if ($filterTarget > 0) {
    $whereRiwayat[]  = 'r.target_id = ?';
    $paramsRiwayat[] = $filterTarget;
}
if ($filterDompet > 0) {
    $whereRiwayat[]  = 'r.dompet_id = ?';
    $paramsRiwayat[] = $filterDompet;
}
if ($bulanRiwayat !== '') {
    $whereRiwayat[]  = "DATE_FORMAT(r.tanggal, '%Y-%m') = ?";
    $paramsRiwayat[] = $bulanRiwayat;
}
$sqlWhereRiwayat = implode(' AND ', $whereRiwayat);

$stmtStatsRiwayat = $pdo->prepare("
    SELECT COUNT(*) AS cnt, COALESCE(SUM(r.nominal), 0) AS total_setor
    FROM riwayat_tabungan r
    WHERE {$sqlWhereRiwayat}
");
$stmtStatsRiwayat->execute($paramsRiwayat);
$statsRiwayat      = $stmtStatsRiwayat->fetch();
$totalRowsRiwayat  = (int)($statsRiwayat['cnt'] ?? 0);
$totalSetorRiwayat = (float)($statsRiwayat['total_setor'] ?? 0);
$totalPagesRiwayat = max(1, (int)ceil($totalRowsRiwayat / $perPageRiwayat));
if ($pageRiwayat > $totalPagesRiwayat) $pageRiwayat = $totalPagesRiwayat;
$offsetRiwayat = ($pageRiwayat - 1) * $perPageRiwayat;

$stmtRiwayat = $pdo->prepare("
    SELECT r.*, t.nama_target, d.nama_dompet
    FROM riwayat_tabungan r
    JOIN target_tabungan t ON r.target_id = t.id
    LEFT JOIN dompet d ON r.dompet_id = d.id
    WHERE {$sqlWhereRiwayat}
    ORDER BY r.tanggal DESC, r.id DESC
    LIMIT ? OFFSET ?
");
$idx = 1;
foreach ($paramsRiwayat as $pVal) {
    $stmtRiwayat->bindValue($idx++, $pVal, is_int($pVal) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmtRiwayat->bindValue($idx++, $perPageRiwayat, PDO::PARAM_INT);
$stmtRiwayat->bindValue($idx++, $offsetRiwayat, PDO::PARAM_INT);
$stmtRiwayat->execute();
$riwayatSetoran = $stmtRiwayat->fetchAll();

$isTargetFiltered  = ($statusTarget !== '' || $qTarget !== '');
$isRiwayatFiltered = ($filterTarget > 0 || $filterDompet > 0 || $bulanRiwayat !== '');

renderHeader('Target Tabungan Keluarga', 'tabungan');
?>
<div class="row g-4">
    <!-- KOLOM KIRI: FORM BUAT TARGET & RINGKASAN -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-bold">Buat Target Tabungan Baru</div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="aksi" value="buat_target">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Tujuan Tabungan</label>
                        <input type="text" name="nama_target" class="form-control" placeholder="Contoh: Dana Darurat / Pendidikan Anak" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Target Dana (Rp)</label>
                        <input type="number" name="target_dana" min="10000" step="1000" class="form-control" placeholder="Contoh: 15000000" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Target Waktu (Opsional)</label>
                        <input type="date" name="tenggat_waktu" class="form-control">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Simpan Target</button>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-bold">Ringkasan Tabungan Keluarga</div>
            <div class="card-body">
                <div class="d-flex justify-content-between small mb-2">
                    <span class="text-muted">Total Dana Terkumpul</span>
                    <span class="fw-bold text-success"><?= rupiah($totalDanaTerkumpul) ?></span>
                </div>
                <div class="d-flex justify-content-between small mb-2">
                    <span class="text-muted">Total Seluruh Target</span>
                    <span class="fw-bold text-dark"><?= rupiah($totalTargetDana) ?></span>
                </div>
                <div class="d-flex justify-content-between small pt-2 border-top">
                    <span class="text-muted">Kekurangan Dana</span>
                    <span class="fw-semibold text-primary"><?= rupiah(max(0, $totalTargetDana - $totalDanaTerkumpul)) ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- KOLOM KANAN: KARTU TARGET & RIWAYAT ALOKASI -->
    <div class="col-lg-8">
        <!-- Bilah Filter Target Tabungan -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-2 px-3">
                <form method="GET" class="row g-2 align-items-center">
                    <input type="hidden" name="target_id" value="<?= $filterTarget ?>">
                    <input type="hidden" name="dompet_id" value="<?= $filterDompet ?>">
                    <input type="hidden" name="bulan_riwayat" value="<?= e($bulanRiwayat) ?>">
                    <input type="hidden" name="page_riwayat" value="<?= $pageRiwayat ?>">

                    <div class="col-md-4">
                        <select name="status_target" class="form-select form-select-sm">
                            <option value="">Semua Status Target</option>
                            <option value="berjalan" <?= $statusTarget === 'berjalan' ? 'selected' : '' ?>>Sedang Berjalan</option>
                            <option value="tercapai" <?= $statusTarget === 'tercapai' ? 'selected' : '' ?>>Sudah Tercapai (100%)</option>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <input type="text" name="q_target" value="<?= e($qTarget) ?>" class="form-control form-control-sm" placeholder="Cari nama tujuan tabungan...">
                    </div>
                    <div class="col-md-3 d-flex gap-1">
                        <button type="submit" class="btn btn-primary btn-sm flex-fill">Filter Target</button>
                        <?php if ($isTargetFiltered): ?>
                            <a href="<?= e(buildTabunganUrl($baseParams, ['status_target' => '', 'q_target' => '', 'page_target' => 1])) ?>" class="btn btn-outline-secondary btn-sm" title="Reset"><i class="bi bi-x"></i></a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Daftar Kartu Target Tabungan -->
        <div class="row g-3 mb-3">
            <?php if (empty($targets)): ?>
                <div class="col-12">
                    <div class="alert alert-info mb-0">
                        <?= $isTargetFiltered ? 'Tidak ada target tabungan yang sesuai dengan filter.' : 'Belum ada target tabungan keluarga. Buat tujuan menabung pertama Anda di samping.' ?>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($targets as $tg):
                    $persen = $tg['target_dana'] > 0 ? round(($tg['dana_terkumpul'] / $tg['target_dana']) * 100, 1) : 0;
                ?>
                    <div class="col-md-6">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body d-flex flex-column">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                                    <div>
                                        <h5 class="fw-bold mb-0"><?= e($tg['nama_target']) ?></h5>
                                        <span class="small text-muted">Tenggat: <?= e($tg['tenggat_waktu'] ?: 'Fleksibel') ?></span>
                                    </div>
                                    <div class="d-flex align-items-center gap-1">
                                        <span class="badge <?= $persen >= 100 ? 'bg-success' : 'bg-primary' ?> me-1"><?= $persen ?>%</span>
                                        <button type="button"
                                                class="btn btn-outline-primary btn-sm py-0 px-2"
                                                title="Edit Target Tabungan"
                                                data-bs-toggle="modal"
                                                data-bs-target="#modalEditTarget"
                                                data-id="<?= (int)$tg['id'] ?>"
                                                data-nama="<?= e($tg['nama_target']) ?>"
                                                data-target="<?= (float)$tg['target_dana'] ?>"
                                                data-tenggat="<?= e($tg['tenggat_waktu'] ?? '') ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button type="button"
                                                class="btn btn-outline-danger btn-sm py-0 px-2"
                                                title="Hapus Target Tabungan"
                                                data-bs-toggle="modal"
                                                data-bs-target="#modalHapusTarget"
                                                data-id="<?= (int)$tg['id'] ?>"
                                                data-nama="<?= e($tg['nama_target']) ?>"
                                                data-terkumpul="<?= (float)$tg['dana_terkumpul'] ?>"
                                                data-terkumpul-rp="<?= e(rupiah($tg['dana_terkumpul'])) ?>">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="progress my-2" style="height: 12px;">
                                    <div class="progress-bar bg-success" style="width: <?= min(100, $persen) ?>%"></div>
                                </div>

                                <div class="d-flex justify-content-between small mb-3">
                                    <span>Terkumpul: <strong class="text-success"><?= rupiah($tg['dana_terkumpul']) ?></strong></span>
                                    <span>Target: <strong><?= rupiah($tg['target_dana']) ?></strong></span>
                                </div>

                                <form method="POST" class="border-top pt-3 mt-auto">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="aksi" value="setor_dana">
                                    <input type="hidden" name="target_id" value="<?= (int)$tg['id'] ?>">
                                    <div class="row g-2 mb-2">
                                        <div class="col-7">
                                            <select name="dompet_id" class="form-select form-select-sm" required>
                                                <?php foreach ($dompets as $d): ?>
                                                    <option value="<?= (int)$d['id'] ?>"><?= e($d['nama_dompet']) ?> (<?= rupiah($d['saldo']) ?>)</option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-5">
                                            <input type="number" name="nominal" min="1000" step="1" placeholder="Nominal" class="form-control form-control-sm" required>
                                        </div>
                                        <div class="col-5">
                                            <input type="date" name="tanggal" value="<?= date('Y-m-d') ?>" class="form-control form-control-sm" required>
                                        </div>
                                        <div class="col-7">
                                            <input type="text" name="keterangan" placeholder="Catatan (opsional)" class="form-control form-control-sm">
                                        </div>
                                    </div>
                                    <button type="submit" class="btn btn-success btn-sm w-100">Alokasikan Dana</button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Pagination Kartu Target Tabungan -->
        <?php if ($totalPagesTarget > 1): ?>
            <div class="d-flex justify-content-between align-items-center bg-white rounded shadow-sm px-3 py-2 mb-4">
                <small class="text-muted">Menampilkan halaman <?= $pageTarget ?> dari <?= $totalPagesTarget ?> (<?= $totalRowsTarget ?> target)</small>
                <?php renderTabunganPagination($pageTarget, $totalPagesTarget, 'page_target', $baseParams); ?>
            </div>
        <?php endif; ?>

        <!-- Tabel Riwayat Tabungan yang Sudah Dialokasikan -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-bold">Riwayat Alokasi Tabungan</span>
                    <?php if ($isRiwayatFiltered): ?>
                        <a href="<?= e(buildTabunganUrl($baseParams, ['target_id' => 0, 'dompet_id' => 0, 'bulan_riwayat' => '', 'page_riwayat' => 1])) ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Reset Filter
                        </a>
                    <?php endif; ?>
                </div>
                <form method="GET" class="row g-2">
                    <input type="hidden" name="status_target" value="<?= e($statusTarget) ?>">
                    <input type="hidden" name="q_target" value="<?= e($qTarget) ?>">
                    <input type="hidden" name="page_target" value="<?= $pageTarget ?>">

                    <div class="col-md-4">
                        <select name="target_id" class="form-select form-select-sm">
                            <option value="0">Semua Target Tabungan</option>
                            <?php foreach ($allTargetsDropdown as $tgOpt): ?>
                                <option value="<?= (int)$tgOpt['id'] ?>" <?= $filterTarget === (int)$tgOpt['id'] ? 'selected' : '' ?>>
                                    <?= e($tgOpt['nama_target']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="dompet_id" class="form-select form-select-sm">
                            <option value="0">Semua Dompet</option>
                            <?php foreach ($dompets as $d): ?>
                                <option value="<?= (int)$d['id'] ?>" <?= $filterDompet === (int)$d['id'] ? 'selected' : '' ?>>
                                    <?= e($d['nama_dompet']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <input type="month" name="bulan_riwayat" value="<?= e($bulanRiwayat) ?>" class="form-control form-control-sm" title="Filter Bulan">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100">Filter</button>
                    </div>
                </form>
            </div>

            <div class="bg-light border-bottom px-3 py-2 d-flex justify-content-between align-items-center small">
                <span class="text-muted">Ditemukan <strong><?= $totalRowsRiwayat ?></strong> catatan alokasi</span>
                <span>Total Setoran: <strong class="text-success"><?= rupiah($totalSetorRiwayat) ?></strong></span>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Tanggal</th>
                            <th>Target & Catatan</th>
                            <th>Sumber Dompet</th>
                            <th class="text-end">Nominal</th>
                            <th class="text-center" style="width: 110px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($riwayatSetoran)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    <?= $isRiwayatFiltered ? 'Tidak ada riwayat alokasi yang cocok dengan filter.' : 'Belum ada riwayat alokasi tabungan yang tercatat.' ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($riwayatSetoran as $rs): ?>
                                <tr>
                                    <td class="small"><?= e($rs['tanggal']) ?></td>
                                    <td>
                                        <div class="fw-semibold"><?= e($rs['nama_target']) ?></div>
                                        <div class="small text-muted"><?= e($rs['keterangan'] ?: '-') ?></div>
                                    </td>
                                    <td class="small"><?= e($rs['nama_dompet'] ?? 'Dompet Dihapus') ?></td>
                                    <td class="text-end fw-bold text-success"><?= rupiah($rs['nominal']) ?></td>
                                    <td class="text-center">
                                        <div class="d-inline-flex gap-1">
                                            <button type="button"
                                                    class="btn btn-outline-primary btn-sm"
                                                    title="Edit Alokasi Tabungan"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalEditSetoran"
                                                    data-id="<?= (int)$rs['id'] ?>"
                                                    data-target="<?= e($rs['nama_target']) ?>"
                                                    data-dompet="<?= (int)($rs['dompet_id'] ?? 0) ?>"
                                                    data-nominal="<?= (float)$rs['nominal'] ?>"
                                                    data-tanggal="<?= e($rs['tanggal']) ?>"
                                                    data-keterangan="<?= e($rs['keterangan'] ?? '') ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Hapus alokasi tabungan sebesar <?= e(rupiah($rs['nominal'])) ?>? Dana akan dikembalikan ke saldo dompet asal.');">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="aksi" value="hapus_setoran">
                                                <input type="hidden" name="setoran_id" value="<?= (int)$rs['id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm" title="Hapus Alokasi Tabungan">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPagesRiwayat > 1): ?>
                <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
                    <small class="text-muted">
                        Menampilkan <?= $offsetRiwayat + 1 ?>–<?= min($offsetRiwayat + $perPageRiwayat, $totalRowsRiwayat) ?> dari <?= $totalRowsRiwayat ?> catatan
                    </small>
                    <?php renderTabunganPagination($pageRiwayat, $totalPagesRiwayat, 'page_riwayat', $baseParams); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Edit Target Tabungan -->
<div class="modal fade" id="modalEditTarget" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="aksi" value="edit_target">
                <input type="hidden" name="target_id" id="editTargetId">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Edit Target Tabungan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Tujuan Tabungan</label>
                        <input type="text" name="nama_target" id="editTargetNama" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Target Dana (Rp)</label>
                        <input type="number" name="target_dana" id="editTargetDana" min="10000" step="1000" class="form-control" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Target Waktu (Opsional)</label>
                        <input type="date" name="tenggat_waktu" id="editTargetTenggat" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Hapus Target Tabungan -->
<div class="modal fade" id="modalHapusTarget" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="aksi" value="hapus_target">
                <input type="hidden" name="target_id" id="hapusTargetId">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-danger">Hapus Target Tabungan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">Apakah Anda yakin ingin menghapus target tabungan <strong id="hapusTargetNama"></strong>?</p>
                    <div id="boxPengembalianDana" class="alert alert-warning small mb-0 d-none">
                        <p class="mb-2">
                            Target ini memiliki dana terkumpul sebesar <strong id="hapusTargetTerkumpul"></strong>. Pilih dompet tujuan pengembalian saldo:
                        </p>
                        <select name="dompet_kembali_id" id="hapusTargetDompet" class="form-select form-select-sm">
                            <?php foreach ($dompets as $d): ?>
                                <option value="<?= (int)$d['id'] ?>">Kembalikan ke <?= e($d['nama_dompet']) ?> (<?= rupiah($d['saldo']) ?>)</option>
                            <?php endforeach; ?>
                            <option value="0">Hapus tanpa mengembalikan saldo ke dompet</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger">Ya, Hapus Target</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit Alokasi Tabungan -->
<div class="modal fade" id="modalEditSetoran" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="aksi" value="edit_setoran">
                <input type="hidden" name="setoran_id" id="editSetoranId">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Edit Alokasi Tabungan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Target Tabungan</label>
                        <input type="text" id="editSetoranTarget" class="form-control bg-light" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Tanggal Alokasi</label>
                        <input type="date" name="tanggal" id="editSetoranTanggal" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Sumber Dompet</label>
                        <select name="dompet_id" id="editSetoranDompet" class="form-select" required>
                            <?php foreach ($dompets as $d): ?>
                                <option value="<?= (int)$d['id'] ?>"><?= e($d['nama_dompet']) ?> (<?= rupiah($d['saldo']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Nominal (Rp)</label>
                        <input type="number" name="nominal" id="editSetoranNominal" min="1000" step="1" class="form-control" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Catatan (Opsional)</label>
                        <input type="text" name="keterangan" id="editSetoranKeterangan" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modalEditTarget = document.getElementById('modalEditTarget');
    if (modalEditTarget) {
        modalEditTarget.addEventListener('show.bs.modal', function (event) {
            const btn = event.relatedTarget;
            document.getElementById('editTargetId').value      = btn.getAttribute('data-id');
            document.getElementById('editTargetNama').value    = btn.getAttribute('data-nama');
            document.getElementById('editTargetDana').value    = btn.getAttribute('data-target');
            document.getElementById('editTargetTenggat').value = btn.getAttribute('data-tenggat');
        });
    }

    const modalHapusTarget = document.getElementById('modalHapusTarget');
    if (modalHapusTarget) {
        modalHapusTarget.addEventListener('show.bs.modal', function (event) {
            const btn = event.relatedTarget;
            const terkumpul = parseFloat(btn.getAttribute('data-terkumpul') || '0');
            const boxDana   = document.getElementById('boxPengembalianDana');

            document.getElementById('hapusTargetId').value            = btn.getAttribute('data-id');
            document.getElementById('hapusTargetNama').textContent      = btn.getAttribute('data-nama');
            document.getElementById('hapusTargetTerkumpul').textContent = btn.getAttribute('data-terkumpul-rp');

            boxDana.classList.toggle('d-none', terkumpul <= 0);
        });
    }

    const modalEditSetoran = document.getElementById('modalEditSetoran');
    if (modalEditSetoran) {
        modalEditSetoran.addEventListener('show.bs.modal', function (event) {
            const btn = event.relatedTarget;
            document.getElementById('editSetoranId').value         = btn.getAttribute('data-id');
            document.getElementById('editSetoranTarget').value     = btn.getAttribute('data-target');
            document.getElementById('editSetoranTanggal').value    = btn.getAttribute('data-tanggal');
            document.getElementById('editSetoranNominal').value    = btn.getAttribute('data-nominal');
            document.getElementById('editSetoranKeterangan').value = btn.getAttribute('data-keterangan');

            const dompetId = btn.getAttribute('data-dompet');
            const selectDompet = document.getElementById('editSetoranDompet');
            if (dompetId && dompetId !== '0') {
                selectDompet.value = dompetId;
            }
        });
    }
});
</script>
<?php renderFooter(); ?>

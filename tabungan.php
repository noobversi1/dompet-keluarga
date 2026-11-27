<?php
declare(strict_types=1);

require_once 'config.php';
require_once 'security.php';
require_once 'layout.php';

$userId = requireLogin();

// Parameter Filter & Pagination Target Tabungan
$statusTarget  = in_array($_GET['status_target'] ?? '', ['berjalan', 'tercapai'], true) ? $_GET['status_target'] : '';
$qTarget       = trim($_GET['q_target'] ?? '');
$pageTarget    = max(1, (int)($_GET['page_target'] ?? 1));
$perPageTarget = 4; // 4 kartu (2x2 grid) per halaman

// Parameter Filter & Pagination Riwayat Alokasi & Penarikan
$filterTarget   = max(0, (int)($_GET['target_id'] ?? 0));
$filterDompet   = max(0, (int)($_GET['dompet_id'] ?? 0));
$filterJenis    = in_array($_GET['jenis_riwayat'] ?? '', ['setor', 'tarik'], true) ? $_GET['jenis_riwayat'] : '';
$bulanRiwayat   = preg_match('/^\d{4}-\d{2}$/', $_GET['bulan_riwayat'] ?? '') ? $_GET['bulan_riwayat'] : '';
$pageRiwayat    = max(1, (int)($_GET['page_riwayat'] ?? 1));
$perPageRiwayat = 10;

$baseParams = [
    'status_target' => $statusTarget,
    'q_target'      => $qTarget,
    'page_target'   => $pageTarget,
    'target_id'     => $filterTarget,
    'dompet_id'     => $filterDompet,
    'jenis_riwayat' => $filterJenis,
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
    if (($merged['jenis_riwayat'] ?? '') !== '') $clean['jenis_riwayat'] = $merged['jenis_riwayat'];
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

    // =====================================================================
    // 1. BUAT TARGET TABUNGAN BARU
    // =====================================================================
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

    // =====================================================================
    // 2. EDIT TARGET TABUNGAN
    // =====================================================================
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

    // =====================================================================
    // 3. HAPUS TARGET TABUNGAN
    // =====================================================================
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

    // =====================================================================
    // 4. ALOKASIKAN DANA (SETOR KE TARGET TABUNGAN)
    // =====================================================================
    if ($aksi === 'setor_dana') {
        $targetId   = (int)($_POST['target_id'] ?? 0);
        $dompetId   = (int)($_POST['dompet_id'] ?? 0);
        $nominal    = (float)($_POST['nominal'] ?? 0);
        $tanggal    = $_POST['tanggal'] ?? date('Y-m-d');
        $keterangan = trim($_POST['keterangan'] ?? '');

        if ($targetId <= 0 || $dompetId <= 0 || $nominal <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            setFlash('danger', 'Input alokasi tabungan tidak valid.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $cekTarget = $pdo->prepare('SELECT id FROM target_tabungan WHERE id = ? AND user_id = ? FOR UPDATE');
            $cekTarget->execute([$targetId, $userId]);

            $cekDompet = $pdo->prepare('SELECT id, saldo FROM dompet WHERE id = ? AND user_id = ? FOR UPDATE');
            $cekDompet->execute([$dompetId, $userId]);
            $dompet = $cekDompet->fetch();

            if (!$cekTarget->fetch() || !$dompet) {
                $pdo->rollBack();
                setFlash('danger', 'Target tabungan atau dompet tidak ditemukan.');
            } elseif ((float)$dompet['saldo'] < $nominal) {
                $pdo->rollBack();
                setFlash('danger', 'Saldo dompet tidak mencukupi untuk alokasi tabungan.');
            } else {
                $pdo->prepare('UPDATE dompet SET saldo = saldo - ? WHERE id = ? AND user_id = ?')
                    ->execute([$nominal, $dompetId, $userId]);

                $pdo->prepare('UPDATE target_tabungan SET dana_terkumpul = dana_terkumpul + ? WHERE id = ? AND user_id = ?')
                    ->execute([$nominal, $targetId, $userId]);

                $pdo->prepare('INSERT INTO riwayat_tabungan (user_id, target_id, dompet_id, jenis, nominal, tanggal, keterangan) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$userId, $targetId, $dompetId, 'setor', $nominal, $tanggal, $keterangan]);

                $pdo->commit();
                setFlash('success', 'Dana sebesar ' . rupiah($nominal) . ' berhasil dialokasikan ke target tabungan.');
                header('Location: ' . buildTabunganUrl($baseParams, ['page_riwayat' => 1]));
                exit;
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', 'Gagal mengalokasikan dana tabungan.');
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    // =====================================================================
    // 5. TARIK DANA DARI TARGET TABUNGAN KE DOMPET
    // =====================================================================
    if ($aksi === 'tarik_dana') {
        $targetId   = (int)($_POST['target_id'] ?? 0);
        $dompetId   = (int)($_POST['dompet_id'] ?? 0);
        $nominal    = (float)($_POST['nominal'] ?? 0);
        $tanggal    = $_POST['tanggal'] ?? date('Y-m-d');
        $keterangan = trim($_POST['keterangan'] ?? '');

        if ($targetId <= 0 || $dompetId <= 0 || $nominal <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            setFlash('danger', 'Input penarikan dana tabungan tidak valid.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $stmtTarget = $pdo->prepare('SELECT id, nama_target, dana_terkumpul FROM target_tabungan WHERE id = ? AND user_id = ? FOR UPDATE');
            $stmtTarget->execute([$targetId, $userId]);
            $target = $stmtTarget->fetch();

            $stmtDompet = $pdo->prepare('SELECT id, nama_dompet FROM dompet WHERE id = ? AND user_id = ? FOR UPDATE');
            $stmtDompet->execute([$dompetId, $userId]);
            $dompet = $stmtDompet->fetch();

            if (!$target || !$dompet) {
                $pdo->rollBack();
                setFlash('danger', 'Target tabungan atau dompet tujuan tidak ditemukan.');
            } elseif ((float)$target['dana_terkumpul'] < $nominal) {
                $pdo->rollBack();
                setFlash('danger', 'Dana terkumpul pada tabungan tidak mencukupi (tersedia: ' . rupiah($target['dana_terkumpul']) . ').');
            } else {
                $pdo->prepare('UPDATE target_tabungan SET dana_terkumpul = dana_terkumpul - ? WHERE id = ? AND user_id = ?')
                    ->execute([$nominal, $targetId, $userId]);

                $pdo->prepare('UPDATE dompet SET saldo = saldo + ? WHERE id = ? AND user_id = ?')
                    ->execute([$nominal, $dompetId, $userId]);

                $pdo->prepare('INSERT INTO riwayat_tabungan (user_id, target_id, dompet_id, jenis, nominal, tanggal, keterangan) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$userId, $targetId, $dompetId, 'tarik', $nominal, $tanggal, $keterangan ?: 'Penarikan dana tabungan']);

                $pdo->commit();
                setFlash('success', 'Penarikan dana sebesar ' . rupiah($nominal) . ' berhasil ditransfer ke ' . $dompet['nama_dompet'] . '.');
                header('Location: ' . buildTabunganUrl($baseParams, ['page_riwayat' => 1]));
                exit;
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', 'Gagal memproses penarikan dana tabungan.');
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    // =====================================================================
    // 6. EDIT RIWAYAT ALOKASI / PENARIKAN TABUNGAN
    // =====================================================================
    if ($aksi === 'edit_setoran') {
        $setoranId  = (int)($_POST['setoran_id'] ?? 0);
        $dompetId   = (int)($_POST['dompet_id'] ?? 0);
        $nominal    = (float)($_POST['nominal'] ?? 0);
        $tanggal    = $_POST['tanggal'] ?? '';
        $keterangan = trim($_POST['keterangan'] ?? '');

        if ($setoranId <= 0 || $dompetId <= 0 || $nominal <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            setFlash('danger', 'Data pembaruan riwayat tabungan tidak valid.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $stmtOld = $pdo->prepare('SELECT id, target_id, dompet_id, jenis, nominal FROM riwayat_tabungan WHERE id = ? AND user_id = ? FOR UPDATE');
            $stmtOld->execute([$setoranId, $userId]);
            $oldItem = $stmtOld->fetch();

            $cekDompet = $pdo->prepare('SELECT id FROM dompet WHERE id = ? AND user_id = ? FOR UPDATE');
            $cekDompet->execute([$dompetId, $userId]);

            if (!$oldItem || !$cekDompet->fetch()) {
                $pdo->rollBack();
                setFlash('danger', 'Data riwayat atau dompet tidak ditemukan.');
                header('Location: ' . $redirectUrl);
                exit;
            }

            $jenisItem = $oldItem['jenis'] ?? 'setor';

            if ($jenisItem === 'tarik') {
                // 1. Batalkan penarikan lama: tarik kembali dari dompet lama, kembalikan ke target tabungan
                if (!empty($oldItem['dompet_id'])) {
                    $stmtCekDompetLama = $pdo->prepare('SELECT saldo FROM dompet WHERE id = ? AND user_id = ? FOR UPDATE');
                    $stmtCekDompetLama->execute([$oldItem['dompet_id'], $userId]);
                    $saldoDompetLama = $stmtCekDompetLama->fetchColumn();

                    if ($saldoDompetLama !== false && (float)$saldoDompetLama < (float)$oldItem['nominal']) {
                        $pdo->rollBack();
                        setFlash('danger', 'Riwayat penarikan tidak dapat diubah karena saldo di dompet tujuan lama sudah terpakai.');
                        header('Location: ' . $redirectUrl);
                        exit;
                    }

                    $pdo->prepare('UPDATE dompet SET saldo = saldo - ? WHERE id = ? AND user_id = ?')
                        ->execute([$oldItem['nominal'], $oldItem['dompet_id'], $userId]);
                }

                $pdo->prepare('UPDATE target_tabungan SET dana_terkumpul = dana_terkumpul + ? WHERE id = ? AND user_id = ?')
                    ->execute([$oldItem['nominal'], $oldItem['target_id'], $userId]);

                // 2. Terapkan penarikan baru
                $stmtTargetNow = $pdo->prepare('SELECT dana_terkumpul FROM target_tabungan WHERE id = ? AND user_id = ? FOR UPDATE');
                $stmtTargetNow->execute([$oldItem['target_id'], $userId]);
                $danaTersedia = (float)$stmtTargetNow->fetchColumn();

                if ($danaTersedia < $nominal) {
                    $pdo->rollBack();
                    setFlash('danger', 'Dana terkumpul pada tabungan tidak mencukupi untuk nominal penarikan baru.');
                    header('Location: ' . $redirectUrl);
                    exit;
                }

                $pdo->prepare('UPDATE target_tabungan SET dana_terkumpul = dana_terkumpul - ? WHERE id = ? AND user_id = ?')
                    ->execute([$nominal, $oldItem['target_id'], $userId]);

                $pdo->prepare('UPDATE dompet SET saldo = saldo + ? WHERE id = ? AND user_id = ?')
                    ->execute([$nominal, $dompetId, $userId]);
            } else {
                // 1. Batalkan setoran lama: kembalikan ke dompet lama, kurangi target tabungan
                if (!empty($oldItem['dompet_id'])) {
                    $pdo->prepare('UPDATE dompet SET saldo = saldo + ? WHERE id = ? AND user_id = ?')
                        ->execute([$oldItem['nominal'], $oldItem['dompet_id'], $userId]);
                }
                $pdo->prepare('UPDATE target_tabungan SET dana_terkumpul = GREATEST(0, dana_terkumpul - ?) WHERE id = ? AND user_id = ?')
                    ->execute([$oldItem['nominal'], $oldItem['target_id'], $userId]);

                // 2. Terapkan setoran baru
                $stmtSaldoBaru = $pdo->prepare('SELECT saldo FROM dompet WHERE id = ? AND user_id = ? FOR UPDATE');
                $stmtSaldoBaru->execute([$dompetId, $userId]);
                $saldoBaru = (float)$stmtSaldoBaru->fetchColumn();

                if ($saldoBaru < $nominal) {
                    $pdo->rollBack();
                    setFlash('danger', 'Saldo dompet asal tidak mencukupi untuk nominal alokasi baru.');
                    header('Location: ' . $redirectUrl);
                    exit;
                }

                $pdo->prepare('UPDATE dompet SET saldo = saldo - ? WHERE id = ? AND user_id = ?')
                    ->execute([$nominal, $dompetId, $userId]);

                $pdo->prepare('UPDATE target_tabungan SET dana_terkumpul = dana_terkumpul + ? WHERE id = ? AND user_id = ?')
                    ->execute([$nominal, $oldItem['target_id'], $userId]);
            }

            $pdo->prepare('
                UPDATE riwayat_tabungan
                SET dompet_id = ?, nominal = ?, tanggal = ?, keterangan = ?
                WHERE id = ? AND user_id = ?
            ')->execute([$dompetId, $nominal, $tanggal, $keterangan, $setoranId, $userId]);

            $pdo->commit();
            setFlash('success', 'Riwayat transaksi tabungan berhasil diperbarui dan saldo telah disesuaikan.');
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', 'Gagal memperbarui riwayat tabungan.');
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    // =====================================================================
    // 7. HAPUS / BATALKAN RIWAYAT ALOKASI ATAU PENARIKAN TABUNGAN
    // =====================================================================
    if ($aksi === 'hapus_setoran') {
        $setoranId = (int)($_POST['setoran_id'] ?? 0);

        $pdo->beginTransaction();
        try {
            $stmtGet = $pdo->prepare('SELECT id, target_id, dompet_id, jenis, nominal FROM riwayat_tabungan WHERE id = ? AND user_id = ? FOR UPDATE');
            $stmtGet->execute([$setoranId, $userId]);
            $item = $stmtGet->fetch();

            if (!$item) {
                $pdo->rollBack();
                setFlash('danger', 'Data riwayat tabungan tidak ditemukan.');
                header('Location: ' . $redirectUrl);
                exit;
            }

            $jenisItem = $item['jenis'] ?? 'setor';

            if ($jenisItem === 'tarik') {
                // Batalkan penarikan: kurangi dompet tujuan, kembalikan ke target tabungan
                if (!empty($item['dompet_id'])) {
                    $stmtCekDompet = $pdo->prepare('SELECT saldo FROM dompet WHERE id = ? AND user_id = ? FOR UPDATE');
                    $stmtCekDompet->execute([$item['dompet_id'], $userId]);
                    $saldoDompet = $stmtCekDompet->fetchColumn();

                    if ($saldoDompet !== false && (float)$saldoDompet < (float)$item['nominal']) {
                        $pdo->rollBack();
                        setFlash('danger', 'Riwayat penarikan tidak dapat dihapus karena saldo di dompet penerima sudah terpakai.');
                        header('Location: ' . $redirectUrl);
                        exit;
                    }

                    $pdo->prepare('UPDATE dompet SET saldo = saldo - ? WHERE id = ? AND user_id = ?')
                        ->execute([$item['nominal'], $item['dompet_id'], $userId]);
                }

                $pdo->prepare('UPDATE target_tabungan SET dana_terkumpul = dana_terkumpul + ? WHERE id = ? AND user_id = ?')
                    ->execute([$item['nominal'], $item['target_id'], $userId]);

                $pesanSukses = 'Penarikan tabungan dibatalkan dan dana dikembalikan ke target tabungan.';
            } else {
                // Batalkan setoran: kembalikan ke dompet asal, kurangi target tabungan
                if (!empty($item['dompet_id'])) {
                    $pdo->prepare('UPDATE dompet SET saldo = saldo + ? WHERE id = ? AND user_id = ?')
                        ->execute([$item['nominal'], $item['dompet_id'], $userId]);
                }

                $pdo->prepare('UPDATE target_tabungan SET dana_terkumpul = GREATEST(0, dana_terkumpul - ?) WHERE id = ? AND user_id = ?')
                    ->execute([$item['nominal'], $item['target_id'], $userId]);

                $pesanSukses = 'Alokasi tabungan berhasil dibatalkan dan dana dikembalikan ke saldo dompet.';
            }

            $pdo->prepare('DELETE FROM riwayat_tabungan WHERE id = ? AND user_id = ?')
                ->execute([$setoranId, $userId]);

            $pdo->commit();
            setFlash('success', $pesanSukses);
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', 'Gagal menghapus riwayat tabungan.');
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
// QUERY 2: RIWAYAT ALOKASI & PENARIKAN TABUNGAN (FILTER + PAGINATION)
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
if ($filterJenis !== '') {
    $whereRiwayat[]  = 'r.jenis = ?';
    $paramsRiwayat[] = $filterJenis;
}
if ($bulanRiwayat !== '') {
    $whereRiwayat[]  = "DATE_FORMAT(r.tanggal, '%Y-%m') = ?";
    $paramsRiwayat[] = $bulanRiwayat;
}
$sqlWhereRiwayat = implode(' AND ', $whereRiwayat);

$stmtStatsRiwayat = $pdo->prepare("
    SELECT
        COUNT(*) AS cnt,
        COALESCE(SUM(CASE WHEN r.jenis = 'setor' THEN r.nominal ELSE 0 END), 0) AS total_setor,
        COALESCE(SUM(CASE WHEN r.jenis = 'tarik' THEN r.nominal ELSE 0 END), 0) AS total_tarik
    FROM riwayat_tabungan r
    WHERE {$sqlWhereRiwayat}
");
$stmtStatsRiwayat->execute($paramsRiwayat);
$statsRiwayat      = $stmtStatsRiwayat->fetch();
$totalRowsRiwayat  = (int)($statsRiwayat['cnt'] ?? 0);
$totalSetorRiwayat = (float)($statsRiwayat['total_setor'] ?? 0);
$totalTarikRiwayat = (float)($statsRiwayat['total_tarik'] ?? 0);
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
$isRiwayatFiltered = ($filterTarget > 0 || $filterDompet > 0 || $filterJenis !== '' || $bulanRiwayat !== '');

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

    <!-- KOLOM KANAN: KARTU TARGET & RIWAYAT ALOKASI / PENARIKAN -->
    <div class="col-lg-8">
        <!-- Bilah Filter Target Tabungan -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-2 px-3">
                <form method="GET" class="row g-2 align-items-center">
                    <input type="hidden" name="target_id" value="<?= $filterTarget ?>">
                    <input type="hidden" name="dompet_id" value="<?= $filterDompet ?>">
                    <input type="hidden" name="jenis_riwayat" value="<?= e($filterJenis) ?>">
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
                    $persen    = $tg['target_dana'] > 0 ? round(($tg['dana_terkumpul'] / $tg['target_dana']) * 100, 1) : 0;
                    $terkumpul = (float)$tg['dana_terkumpul'];
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
                                                data-terkumpul="<?= $terkumpul ?>"
                                                data-terkumpul-rp="<?= e(rupiah($terkumpul)) ?>">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="progress my-2" style="height: 12px;">
                                    <div class="progress-bar bg-success" style="width: <?= min(100, $persen) ?>%"></div>
                                </div>

                                <div class="d-flex justify-content-between small mb-3">
                                    <span>Terkumpul: <strong class="text-success"><?= rupiah($terkumpul) ?></strong></span>
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
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-success btn-sm flex-fill">
                                            <i class="bi bi-plus-circle me-1"></i>Alokasikan
                                        </button>
                                        <button type="button"
                                                class="btn btn-outline-warning text-dark btn-sm flex-fill"
                                                data-bs-toggle="modal"
                                                data-bs-target="#modalTarikDana"
                                                data-id="<?= (int)$tg['id'] ?>"
                                                data-nama="<?= e($tg['nama_target']) ?>"
                                                data-terkumpul="<?= $terkumpul ?>"
                                                data-terkumpul-rp="<?= e(rupiah($terkumpul)) ?>"
                                                <?= $terkumpul <= 0 ? 'disabled' : '' ?>>
                                            <i class="bi bi-box-arrow-up-right me-1"></i>Tarik Dana
                                        </button>
                                    </div>
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

        <!-- Tabel Riwayat Alokasi & Penarikan Tabungan -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-bold">Riwayat Alokasi & Penarikan Tabungan</span>
                    <?php if ($isRiwayatFiltered): ?>
                        <a href="<?= e(buildTabunganUrl($baseParams, ['target_id' => 0, 'dompet_id' => 0, 'jenis_riwayat' => '', 'bulan_riwayat' => '', 'page_riwayat' => 1])) ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Reset Filter
                        </a>
                    <?php endif; ?>
                </div>
                <form method="GET" class="row g-2">
                    <input type="hidden" name="status_target" value="<?= e($statusTarget) ?>">
                    <input type="hidden" name="q_target" value="<?= e($qTarget) ?>">
                    <input type="hidden" name="page_target" value="<?= $pageTarget ?>">

                    <div class="col-md-3">
                        <select name="target_id" class="form-select form-select-sm">
                            <option value="0">Semua Target</option>
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
                    <div class="col-md-2">
                        <select name="jenis_riwayat" class="form-select form-select-sm">
                            <option value="">Semua Jenis</option>
                            <option value="setor" <?= $filterJenis === 'setor' ? 'selected' : '' ?>>Setor</option>
                            <option value="tarik" <?= $filterJenis === 'tarik' ? 'selected' : '' ?>>Tarik</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <input type="month" name="bulan_riwayat" value="<?= e($bulanRiwayat) ?>" class="form-control form-control-sm" title="Filter Bulan">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100">Filter</button>
                    </div>
                </form>
            </div>

            <div class="bg-light border-bottom px-3 py-2 d-flex flex-wrap justify-content-between align-items-center gap-2 small">
                <span class="text-muted">Ditemukan <strong><?= $totalRowsRiwayat ?></strong> catatan</span>
                <div class="d-flex flex-wrap gap-3">
                    <span>Total Setor: <strong class="text-success">+ <?= rupiah($totalSetorRiwayat) ?></strong></span>
                    <span>Total Tarik: <strong class="text-danger">- <?= rupiah($totalTarikRiwayat) ?></strong></span>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Tanggal</th>
                            <th>Target & Catatan</th>
                            <th>Dompet</th>
                            <th class="text-end">Nominal</th>
                            <th class="text-center" style="width: 110px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($riwayatSetoran)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    <?= $isRiwayatFiltered ? 'Tidak ada riwayat tabungan yang cocok dengan filter.' : 'Belum ada riwayat alokasi atau penarikan tabungan yang tercatat.' ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($riwayatSetoran as $rs):
                                $isTarik = ($rs['jenis'] ?? 'setor') === 'tarik';
                            ?>
                                <tr>
                                    <td class="small"><?= e($rs['tanggal']) ?></td>
                                    <td>
                                        <div>
                                            <?php if ($isTarik): ?>
                                                <span class="badge bg-warning-subtle text-dark border border-warning-subtle me-1">Tarik</span>
                                            <?php else: ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle me-1">Setor</span>
                                            <?php endif; ?>
                                            <span class="fw-semibold"><?= e($rs['nama_target']) ?></span>
                                        </div>
                                        <div class="small text-muted"><?= e($rs['keterangan'] ?: '-') ?></div>
                                    </td>
                                    <td class="small">
                                        <span class="text-muted"><?= $isTarik ? 'Ke: ' : 'Dari: ' ?></span>
                                        <?= e($rs['nama_dompet'] ?? 'Dompet Dihapus') ?>
                                    </td>
                                    <td class="text-end fw-bold <?= $isTarik ? 'text-danger' : 'text-success' ?>">
                                        <?= $isTarik ? '- ' : '+ ' ?><?= rupiah($rs['nominal']) ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="d-inline-flex gap-1">
                                            <button type="button"
                                                    class="btn btn-outline-primary btn-sm"
                                                    title="Edit Riwayat Tabungan"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalEditSetoran"
                                                    data-id="<?= (int)$rs['id'] ?>"
                                                    data-jenis="<?= e($rs['jenis'] ?? 'setor') ?>"
                                                    data-target="<?= e($rs['nama_target']) ?>"
                                                    data-dompet="<?= (int)($rs['dompet_id'] ?? 0) ?>"
                                                    data-nominal="<?= (float)$rs['nominal'] ?>"
                                                    data-tanggal="<?= e($rs['tanggal']) ?>"
                                                    data-keterangan="<?= e($rs['keterangan'] ?? '') ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Batalkan catatan <?= $isTarik ? 'penarikan' : 'alokasi' ?> sebesar <?= e(rupiah($rs['nominal'])) ?>? Saldo tabungan dan dompet akan disesuaikan kembali.');">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="aksi" value="hapus_setoran">
                                                <input type="hidden" name="setoran_id" value="<?= (int)$rs['id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm" title="Batalkan / Hapus Riwayat">
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

<!-- MODAL 1: TARIK DANA DARI TABUNGAN -->
<div class="modal fade" id="modalTarikDana" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="aksi" value="tarik_dana">
                <input type="hidden" name="target_id" id="tarikTargetId">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Tarik Dana Tabungan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Pos Tabungan Asal</label>
                        <input type="text" id="tarikTargetNama" class="form-control bg-light" readonly>
                        <div class="form-text">Dana tersedia saat ini: <strong class="text-success" id="tarikSaldoTersediaRp"></strong></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Dompet Tujuan Penerima Dana</label>
                        <select name="dompet_id" class="form-select" required>
                            <?php foreach ($dompets as $d): ?>
                                <option value="<?= (int)$d['id'] ?>"><?= e($d['nama_dompet']) ?> (<?= rupiah($d['saldo']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label small fw-semibold mb-0">Nominal Penarikan (Rp)</label>
                            <button type="button" id="btnTarikSemua" class="btn btn-link btn-sm p-0 text-decoration-none">Tarik Semua</button>
                        </div>
                        <input type="number" name="nominal" id="tarikNominal" min="1" step="1" class="form-control" placeholder="Masukkan jumlah yang ingin ditarik" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Tanggal Penarikan</label>
                        <input type="date" name="tanggal" value="<?= date('Y-m-d') ?>" class="form-control" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Keperluan / Keterangan (Opsional)</label>
                        <input type="text" name="keterangan" class="form-control" placeholder="Contoh: Keperluan mendadak / Pembayaran sekolah">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-warning">Proses Penarikan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL 2: EDIT TARGET TABUNGAN -->
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

<!-- MODAL 3: HAPUS TARGET TABUNGAN -->
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

<!-- MODAL 4: EDIT RIWAYAT ALOKASI / PENARIKAN TABUNGAN -->
<div class="modal fade" id="modalEditSetoran" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="aksi" value="edit_setoran">
                <input type="hidden" name="setoran_id" id="editSetoranId">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="editSetoranModalTitle">Edit Alokasi Tabungan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Target Tabungan</label>
                        <input type="text" id="editSetoranTarget" class="form-control bg-light" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Tanggal</label>
                        <input type="date" name="tanggal" id="editSetoranTanggal" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold" id="editSetoranDompetLabel">Sumber Dompet</label>
                        <select name="dompet_id" id="editSetoranDompet" class="form-select" required>
                            <?php foreach ($dompets as $d): ?>
                                <option value="<?= (int)$d['id'] ?>"><?= e($d['nama_dompet']) ?> (<?= rupiah($d['saldo']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Nominal (Rp)</label>
                        <input type="number" name="nominal" id="editSetoranNominal" min="1" step="1" class="form-control" required>
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
    // Modal Tarik Dana
    let maxTarikTersedia = 0;
    const modalTarikDana = document.getElementById('modalTarikDana');
    const inputTarikNominal = document.getElementById('tarikNominal');
    const btnTarikSemua = document.getElementById('btnTarikSemua');

    if (modalTarikDana) {
        modalTarikDana.addEventListener('show.bs.modal', function (event) {
            const btn = event.relatedTarget;
            maxTarikTersedia = parseFloat(btn.getAttribute('data-terkumpul') || '0');

            document.getElementById('tarikTargetId').value = btn.getAttribute('data-id');
            document.getElementById('tarikTargetNama').value = btn.getAttribute('data-nama');
            document.getElementById('tarikSaldoTersediaRp').textContent = btn.getAttribute('data-terkumpul-rp');

            inputTarikNominal.value = '';
            inputTarikNominal.max = maxTarikTersedia;
        });
    }

    if (btnTarikSemua && inputTarikNominal) {
        btnTarikSemua.addEventListener('click', function () {
            if (maxTarikTersedia > 0) {
                inputTarikNominal.value = Math.floor(maxTarikTersedia);
            }
        });
    }

    // Modal Edit Target
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

    // Modal Hapus Target
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

    // Modal Edit Riwayat Alokasi / Penarikan
    const modalEditSetoran = document.getElementById('modalEditSetoran');
    if (modalEditSetoran) {
        modalEditSetoran.addEventListener('show.bs.modal', function (event) {
            const btn = event.relatedTarget;
            const jenis = btn.getAttribute('data-jenis') || 'setor';
            const isTarik = (jenis === 'tarik');

            document.getElementById('editSetoranModalTitle').textContent = isTarik ? 'Edit Penarikan Tabungan' : 'Edit Alokasi Tabungan';
            document.getElementById('editSetoranDompetLabel').textContent = isTarik ? 'Dompet Tujuan Penerima' : 'Sumber Dompet';
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

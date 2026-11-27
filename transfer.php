<?php
declare(strict_types=1);

require_once 'config.php';
require_once 'security.php';
require_once 'layout.php';

$userId = requireLogin();

// Parameter Filter & Pagination
$bulan = $_GET['bulan'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $bulan)) {
    $bulan = date('Y-m');
}
$filterAsal   = max(0, (int)($_GET['asal_id'] ?? 0));
$filterTujuan = max(0, (int)($_GET['tujuan_id'] ?? 0));
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 15;

function buildTransferUrl(string $bulan, int $asalId = 0, int $tujuanId = 0, int $page = 1): string
{
    $params = ['bulan' => $bulan];
    if ($asalId > 0) {
        $params['asal_id'] = $asalId;
    }
    if ($tujuanId > 0) {
        $params['tujuan_id'] = $tujuanId;
    }
    if ($page > 1) {
        $params['page'] = $page;
    }
    return 'transfer.php?' . http_build_query($params);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $aksi = $_POST['aksi'] ?? '';

    $retBulan  = preg_match('/^\d{4}-\d{2}$/', $_POST['ret_bulan'] ?? '') ? $_POST['ret_bulan'] : $bulan;
    $retAsal   = max(0, (int)($_POST['ret_asal'] ?? $filterAsal));
    $retTujuan = max(0, (int)($_POST['ret_tujuan'] ?? $filterTujuan));
    $retPage   = max(1, (int)($_POST['ret_page'] ?? $page));

    // 1. Catat Transfer Baru
    if ($aksi === 'tambah') {
        $asalId     = (int)($_POST['dompet_asal_id'] ?? 0);
        $tujuanId   = (int)($_POST['dompet_tujuan_id'] ?? 0);
        $nominal    = (float)($_POST['nominal'] ?? 0);
        $biayaAdmin = (float)($_POST['biaya_admin'] ?? 0);
        $tanggal    = $_POST['tanggal'] ?? date('Y-m-d');
        $keterangan = trim($_POST['keterangan'] ?? '');

        if ($asalId === $tujuanId) {
            setFlash('danger', 'Dompet asal dan dompet tujuan tidak boleh sama.');
            header('Location: ' . buildTransferUrl($retBulan, $retAsal, $retTujuan, $retPage));
            exit;
        }

        if ($nominal <= 0 || $biayaAdmin < 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            setFlash('danger', 'Nominal transfer atau tanggal tidak valid.');
            header('Location: ' . buildTransferUrl($retBulan, $retAsal, $retTujuan, $retPage));
            exit;
        }

        $pdo->beginTransaction();
        try {
            $stmtAsal = $pdo->prepare('SELECT id, saldo FROM dompet WHERE id = ? AND user_id = ? FOR UPDATE');
            $stmtAsal->execute([$asalId, $userId]);
            $dompetAsal = $stmtAsal->fetch();

            $stmtTujuan = $pdo->prepare('SELECT id FROM dompet WHERE id = ? AND user_id = ? FOR UPDATE');
            $stmtTujuan->execute([$tujuanId, $userId]);
            $dompetTujuan = $stmtTujuan->fetch();

            $totalPotong = $nominal + $biayaAdmin;

            if (!$dompetAsal || !$dompetTujuan) {
                $pdo->rollBack();
                setFlash('danger', 'Dompet asal atau tujuan tidak ditemukan.');
            } elseif ((float)$dompetAsal['saldo'] < $totalPotong) {
                $pdo->rollBack();
                setFlash('danger', 'Saldo dompet asal tidak mencukupi (dibutuhkan ' . rupiah($totalPotong) . ' termasuk biaya admin).');
            } else {
                $pdo->prepare('UPDATE dompet SET saldo = saldo - ? WHERE id = ? AND user_id = ?')
                    ->execute([$totalPotong, $asalId, $userId]);

                $pdo->prepare('UPDATE dompet SET saldo = saldo + ? WHERE id = ? AND user_id = ?')
                    ->execute([$nominal, $tujuanId, $userId]);

                $pdo->prepare('
                    INSERT INTO riwayat_transfer (user_id, dompet_asal_id, dompet_tujuan_id, nominal, biaya_admin, tanggal, keterangan)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ')->execute([$userId, $asalId, $tujuanId, $nominal, $biayaAdmin, $tanggal, $keterangan]);

                $pdo->commit();
                setFlash('success', 'Transfer saldo berhasil dicatat.');
                header('Location: ' . buildTransferUrl(substr($tanggal, 0, 7), $retAsal, $retTujuan, 1));
                exit;
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', 'Terjadi kesalahan saat memproses transfer.');
        }
        header('Location: ' . buildTransferUrl($retBulan, $retAsal, $retTujuan, $retPage));
        exit;
    }

    // 2. Edit Riwayat Transfer
    if ($aksi === 'edit') {
        $transferId = (int)($_POST['transfer_id'] ?? 0);
        $asalId     = (int)($_POST['dompet_asal_id'] ?? 0);
        $tujuanId   = (int)($_POST['dompet_tujuan_id'] ?? 0);
        $nominal    = (float)($_POST['nominal'] ?? 0);
        $biayaAdmin = (float)($_POST['biaya_admin'] ?? 0);
        $tanggal    = $_POST['tanggal'] ?? '';
        $keterangan = trim($_POST['keterangan'] ?? '');

        if ($asalId === $tujuanId) {
            setFlash('danger', 'Dompet asal dan dompet tujuan tidak boleh sama.');
            header('Location: ' . buildTransferUrl($retBulan, $retAsal, $retTujuan, $retPage));
            exit;
        }

        if ($transferId <= 0 || $nominal <= 0 || $biayaAdmin < 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            setFlash('danger', 'Data pembaruan transfer tidak valid.');
            header('Location: ' . buildTransferUrl($retBulan, $retAsal, $retTujuan, $retPage));
            exit;
        }

        $pdo->beginTransaction();
        try {
            $stmtOld = $pdo->prepare('SELECT * FROM riwayat_transfer WHERE id = ? AND user_id = ? FOR UPDATE');
            $stmtOld->execute([$transferId, $userId]);
            $old = $stmtOld->fetch();

            if (!$old) {
                $pdo->rollBack();
                setFlash('danger', 'Riwayat transfer tidak ditemukan.');
                header('Location: ' . buildTransferUrl($retBulan, $retAsal, $retTujuan, $retPage));
                exit;
            }

            if (!empty($old['dompet_tujuan_id'])) {
                $stmtCekTujuanLama = $pdo->prepare('SELECT saldo FROM dompet WHERE id = ? AND user_id = ? FOR UPDATE');
                $stmtCekTujuanLama->execute([$old['dompet_tujuan_id'], $userId]);
                $saldoTujuanLama = $stmtCekTujuanLama->fetchColumn();

                if ($saldoTujuanLama !== false && (float)$saldoTujuanLama < (float)$old['nominal']) {
                    $pdo->rollBack();
                    setFlash('danger', 'Transfer tidak dapat diubah karena dana di dompet tujuan lama sudah terpakai untuk transaksi lain.');
                    header('Location: ' . buildTransferUrl($retBulan, $retAsal, $retTujuan, $retPage));
                    exit;
                }

                $pdo->prepare('UPDATE dompet SET saldo = saldo - ? WHERE id = ? AND user_id = ?')
                    ->execute([$old['nominal'], $old['dompet_tujuan_id'], $userId]);
            }

            if (!empty($old['dompet_asal_id'])) {
                $totalLama = (float)$old['nominal'] + (float)$old['biaya_admin'];
                $pdo->prepare('UPDATE dompet SET saldo = saldo + ? WHERE id = ? AND user_id = ?')
                    ->execute([$totalLama, $old['dompet_asal_id'], $userId]);
            }

            $stmtAsalBaru = $pdo->prepare('SELECT id, saldo FROM dompet WHERE id = ? AND user_id = ? FOR UPDATE');
            $stmtAsalBaru->execute([$asalId, $userId]);
            $dompetAsalBaru = $stmtAsalBaru->fetch();

            $stmtTujuanBaru = $pdo->prepare('SELECT id FROM dompet WHERE id = ? AND user_id = ? FOR UPDATE');
            $stmtTujuanBaru->execute([$tujuanId, $userId]);
            $dompetTujuanBaru = $stmtTujuanBaru->fetch();

            $totalBaru = $nominal + $biayaAdmin;

            if (!$dompetAsalBaru || !$dompetTujuanBaru || (float)$dompetAsalBaru['saldo'] < $totalBaru) {
                $pdo->rollBack();
                setFlash('danger', 'Saldo dompet asal baru tidak mencukupi untuk nominal perubahan ini.');
                header('Location: ' . buildTransferUrl($retBulan, $retAsal, $retTujuan, $retPage));
                exit;
            }

            $pdo->prepare('UPDATE dompet SET saldo = saldo - ? WHERE id = ? AND user_id = ?')
                ->execute([$totalBaru, $asalId, $userId]);

            $pdo->prepare('UPDATE dompet SET saldo = saldo + ? WHERE id = ? AND user_id = ?')
                ->execute([$nominal, $tujuanId, $userId]);

            $pdo->prepare('
                UPDATE riwayat_transfer
                SET dompet_asal_id = ?, dompet_tujuan_id = ?, nominal = ?, biaya_admin = ?, tanggal = ?, keterangan = ?
                WHERE id = ? AND user_id = ?
            ')->execute([$asalId, $tujuanId, $nominal, $biayaAdmin, $tanggal, $keterangan, $transferId, $userId]);

            $pdo->commit();
            setFlash('success', 'Riwayat transfer berhasil diperbarui dan saldo kedua dompet telah disesuaikan.');
            header('Location: ' . buildTransferUrl(substr($tanggal, 0, 7), $retAsal, $retTujuan, $retPage));
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', 'Gagal memperbarui data transfer.');
        }
        header('Location: ' . buildTransferUrl($retBulan, $retAsal, $retTujuan, $retPage));
        exit;
    }

    // 3. Hapus / Batalkan Transfer
    if ($aksi === 'hapus') {
        $transferId = (int)($_POST['transfer_id'] ?? 0);

        $pdo->beginTransaction();
        try {
            $stmtGet = $pdo->prepare('SELECT * FROM riwayat_transfer WHERE id = ? AND user_id = ? FOR UPDATE');
            $stmtGet->execute([$transferId, $userId]);
            $trf = $stmtGet->fetch();

            if (!$trf) {
                $pdo->rollBack();
                setFlash('danger', 'Data transfer tidak ditemukan.');
                header('Location: ' . buildTransferUrl($retBulan, $retAsal, $retTujuan, $retPage));
                exit;
            }

            if (!empty($trf['dompet_tujuan_id'])) {
                $stmtCekTujuan = $pdo->prepare('SELECT saldo FROM dompet WHERE id = ? AND user_id = ? FOR UPDATE');
                $stmtCekTujuan->execute([$trf['dompet_tujuan_id'], $userId]);
                $saldoTujuan = $stmtCekTujuan->fetchColumn();

                if ($saldoTujuan !== false && (float)$saldoTujuan < (float)$trf['nominal']) {
                    $pdo->rollBack();
                    setFlash('danger', 'Transfer tidak dapat dihapus karena saldo di dompet tujuan sudah terpakai.');
                    header('Location: ' . buildTransferUrl($retBulan, $retAsal, $retTujuan, $retPage));
                    exit;
                }

                $pdo->prepare('UPDATE dompet SET saldo = saldo - ? WHERE id = ? AND user_id = ?')
                    ->execute([$trf['nominal'], $trf['dompet_tujuan_id'], $userId]);
            }

            if (!empty($trf['dompet_asal_id'])) {
                $totalKembali = (float)$trf['nominal'] + (float)$trf['biaya_admin'];
                $pdo->prepare('UPDATE dompet SET saldo = saldo + ? WHERE id = ? AND user_id = ?')
                    ->execute([$totalKembali, $trf['dompet_asal_id'], $userId]);
            }

            $pdo->prepare('DELETE FROM riwayat_transfer WHERE id = ? AND user_id = ?')
                ->execute([$transferId, $userId]);

            $pdo->commit();
            setFlash('success', 'Transfer dibatalkan dan saldo dompet telah dikembalikan.');
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', 'Gagal membatalkan transfer.');
        }
        header('Location: ' . buildTransferUrl($retBulan, $retAsal, $retTujuan, $retPage));
        exit;
    }
}

// Ambil Daftar Dompet Aktif
$stmtDompet = $pdo->prepare('SELECT id, nama_dompet, saldo FROM dompet WHERE user_id = ? ORDER BY id ASC');
$stmtDompet->execute([$userId]);
$dompetList = $stmtDompet->fetchAll();

// Susun Kueri Dinamis Berdasarkan Filter
$whereClauses = ["r.user_id = ?", "DATE_FORMAT(r.tanggal, '%Y-%m') = ?"];
$whereParams  = [$userId, $bulan];

if ($filterAsal > 0) {
    $whereClauses[] = "r.dompet_asal_id = ?";
    $whereParams[]  = $filterAsal;
}
if ($filterTujuan > 0) {
    $whereClauses[] = "r.dompet_tujuan_id = ?";
    $whereParams[]  = $filterTujuan;
}
$whereSql = implode(' AND ', $whereClauses);

// Hitung Total Data & Statistik Sesuai Filter
$stmtStats = $pdo->prepare("
    SELECT
        COUNT(*) AS total_rows,
        COALESCE(SUM(r.nominal), 0) AS total_nominal,
        COALESCE(SUM(r.biaya_admin), 0) AS total_admin
    FROM riwayat_transfer r
    WHERE {$whereSql}
");
$stmtStats->execute($whereParams);
$stats               = $stmtStats->fetch();
$totalRows           = (int)($stats['total_rows'] ?? 0);
$totalVolumeTransfer = (float)($stats['total_nominal'] ?? 0);
$totalBiayaAdmin     = (float)($stats['total_admin'] ?? 0);

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

// Ambil Data Riwayat Transfer Sesuai Halaman
$sqlTrf = "
    SELECT r.*, d1.nama_dompet AS nama_asal, d2.nama_dompet AS nama_tujuan
    FROM riwayat_transfer r
    LEFT JOIN dompet d1 ON r.dompet_asal_id = d1.id
    LEFT JOIN dompet d2 ON r.dompet_tujuan_id = d2.id
    WHERE {$whereSql}
    ORDER BY r.tanggal DESC, r.id DESC
    LIMIT ? OFFSET ?
";
$stmtTransfer = $pdo->prepare($sqlTrf);
$bindIdx = 1;
foreach ($whereParams as $paramVal) {
    $stmtTransfer->bindValue($bindIdx++, $paramVal, is_int($paramVal) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmtTransfer->bindValue($bindIdx++, $perPage, PDO::PARAM_INT);
$stmtTransfer->bindValue($bindIdx++, $offset, PDO::PARAM_INT);
$stmtTransfer->execute();
$transferList = $stmtTransfer->fetchAll();

$isFiltered = ($filterAsal > 0 || $filterTujuan > 0);

renderHeader('Transfer Antar Dompet', 'transfer');
?>
<div class="row g-4">
    <!-- KOLOM KIRI: FORMULIR TRANSFER & SALDO DOMPET -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-bold">
                <i class="bi bi-arrow-left-right me-1 text-primary"></i>Transfer Saldo Antar Dompet
            </div>
            <div class="card-body">
                <?php if (count($dompetList) < 2): ?>
                    <div class="alert alert-warning small mb-0">
                        Anda memerlukan minimal 2 dompet atau rekening untuk melakukan transfer. Tambahkan dompet di menu <a href="budgeting.php">Kategori & Budgeting</a>.
                    </div>
                <?php else: ?>
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="aksi" value="tambah">
                        <input type="hidden" name="ret_bulan" value="<?= e($bulan) ?>">
                        <input type="hidden" name="ret_asal" value="<?= $filterAsal ?>">
                        <input type="hidden" name="ret_tujuan" value="<?= $filterTujuan ?>">

                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Tanggal Transfer</label>
                            <input type="date" name="tanggal" value="<?= date('Y-m-d') ?>" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Dari Dompet / Rekening (Asal)</label>
                            <select name="dompet_asal_id" class="form-select" required>
                                <?php foreach ($dompetList as $d): ?>
                                    <option value="<?= (int)$d['id'] ?>" <?= $filterAsal === (int)$d['id'] ? 'selected' : '' ?>>
                                        <?= e($d['nama_dompet']) ?> (<?= rupiah($d['saldo']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Ke Dompet / Rekening (Tujuan)</label>
                            <select name="dompet_tujuan_id" class="form-select" required>
                                <?php foreach ($dompetList as $idx => $d): ?>
                                    <option value="<?= (int)$d['id'] ?>" <?= ($filterTujuan > 0 ? $filterTujuan === (int)$d['id'] : $idx === 1) ? 'selected' : '' ?>>
                                        <?= e($d['nama_dompet']) ?> (<?= rupiah($d['saldo']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-7">
                                <label class="form-label small fw-semibold">Nominal Transfer (Rp)</label>
                                <input type="number" name="nominal" min="1" step="1" class="form-control" placeholder="Contoh: 500000" required>
                            </div>
                            <div class="col-5">
                                <label class="form-label small fw-semibold">Biaya Admin</label>
                                <input type="number" name="biaya_admin" min="0" step="1" value="0" class="form-control" placeholder="0">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Keterangan (Opsional)</label>
                            <input type="text" name="keterangan" class="form-control" placeholder="Top up GoPay / Tarik tunai ATM">
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Proses Transfer</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Ringkasan Saldo Dompet Terkini -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-bold">Posisi Saldo Dompet Saat Ini</div>
            <div class="card-body">
                <?php foreach ($dompetList as $d): ?>
                    <div class="d-flex justify-content-between small py-1 border-bottom">
                        <span class="text-muted"><?= e($d['nama_dompet']) ?></span>
                        <span class="fw-bold text-dark"><?= rupiah($d['saldo']) ?></span>
                    </div>
                <?php endforeach; ?>
                <div class="d-flex justify-content-between small pt-2 mt-1">
                    <span class="fw-semibold text-primary">Total Gabungan</span>
                    <span class="fw-bold text-primary"><?= rupiah(array_sum(array_column($dompetList, 'saldo'))) ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- KOLOM KANAN: FILTER, RINGKASAN, RIWAYAT TRANSFER & PAGINATION -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <!-- Header & Bilah Filter -->
            <div class="card-header bg-white py-3">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="fw-bold">Riwayat Transfer (<?= e($bulan) ?>)</span>
                    <?php if ($isFiltered): ?>
                        <a href="<?= e(buildTransferUrl($bulan, 0, 0, 1)) ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Reset Filter
                        </a>
                    <?php endif; ?>
                </div>

                <form method="GET" class="row g-2">
                    <div class="col-md-3">
                        <label class="form-label small text-muted mb-1">Bulan</label>
                        <input type="month" name="bulan" value="<?= e($bulan) ?>" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted mb-1">Dompet Asal</label>
                        <select name="asal_id" class="form-select form-select-sm">
                            <option value="0">Semua Asal</option>
                            <?php foreach ($dompetList as $d): ?>
                                <option value="<?= (int)$d['id'] ?>" <?= $filterAsal === (int)$d['id'] ? 'selected' : '' ?>>
                                    <?= e($d['nama_dompet']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small text-muted mb-1">Dompet Tujuan</label>
                        <select name="tujuan_id" class="form-select form-select-sm">
                            <option value="0">Semua Tujuan</option>
                            <?php foreach ($dompetList as $d): ?>
                                <option value="<?= (int)$d['id'] ?>" <?= $filterTujuan === (int)$d['id'] ? 'selected' : '' ?>>
                                    <?= e($d['nama_dompet']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="bi bi-funnel me-1"></i>Filter
                        </button>
                    </div>
                </form>
            </div>

            <!-- Bar Ringkasan Hasil Filter -->
            <div class="bg-light border-bottom px-3 py-2 d-flex flex-wrap justify-content-between align-items-center gap-2 small">
                <span class="text-muted">
                    Ditemukan <strong><?= $totalRows ?></strong> transfer
                    <?= $isFiltered ? '<span class="badge bg-primary-subtle text-primary ms-1">Filter Aktif</span>' : '' ?>
                </span>
                <div class="d-flex flex-wrap gap-3">
                    <span>Total Dipindahkan: <strong class="text-primary"><?= rupiah($totalVolumeTransfer) ?></strong></span>
                    <?php if ($totalBiayaAdmin > 0): ?>
                        <span>Biaya Admin: <strong class="text-danger"><?= rupiah($totalBiayaAdmin) ?></strong></span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tabel Riwayat Transfer -->
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Tanggal</th>
                            <th>Alur Transfer & Catatan</th>
                            <th class="text-end">Nominal</th>
                            <th class="text-center" style="width: 110px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transferList)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">
                                    <?= $isFiltered ? 'Tidak ada catatan transfer yang cocok dengan filter yang dipilih.' : 'Belum ada catatan transfer antar dompet pada bulan ini.' ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transferList as $tr): ?>
                                <tr>
                                    <td class="small"><?= e($tr['tanggal']) ?></td>
                                    <td>
                                        <div class="fw-semibold">
                                            <?= e($tr['nama_asal'] ?? 'Dompet Dihapus') ?>
                                            <i class="bi bi-arrow-right mx-1 text-primary"></i>
                                            <?= e($tr['nama_tujuan'] ?? 'Dompet Dihapus') ?>
                                        </div>
                                        <div class="small text-muted">
                                            <?= e($tr['keterangan'] ?: 'Transfer saldo') ?>
                                            <?php if ((float)$tr['biaya_admin'] > 0): ?>
                                                <span class="badge bg-light text-secondary border ms-1">Admin: <?= rupiah($tr['biaya_admin']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-end fw-bold text-primary"><?= rupiah($tr['nominal']) ?></td>
                                    <td class="text-center">
                                        <div class="d-inline-flex gap-1">
                                            <button type="button"
                                                    class="btn btn-outline-primary btn-sm"
                                                    title="Edit Transfer"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalEditTransfer"
                                                    data-id="<?= (int)$tr['id'] ?>"
                                                    data-tanggal="<?= e($tr['tanggal']) ?>"
                                                    data-asal="<?= (int)($tr['dompet_asal_id'] ?? 0) ?>"
                                                    data-tujuan="<?= (int)($tr['dompet_tujuan_id'] ?? 0) ?>"
                                                    data-nominal="<?= (float)$tr['nominal'] ?>"
                                                    data-admin="<?= (float)$tr['biaya_admin'] ?>"
                                                    data-keterangan="<?= e($tr['keterangan'] ?? '') ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Batalkan transfer sebesar <?= e(rupiah($tr['nominal'])) ?>? Saldo kedua dompet akan dikembalikan seperti semula.');">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="aksi" value="hapus">
                                                <input type="hidden" name="transfer_id" value="<?= (int)$tr['id'] ?>">
                                                <input type="hidden" name="ret_bulan" value="<?= e($bulan) ?>">
                                                <input type="hidden" name="ret_asal" value="<?= $filterAsal ?>">
                                                <input type="hidden" name="ret_tujuan" value="<?= $filterTujuan ?>">
                                                <input type="hidden" name="ret_page" value="<?= $page ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm" title="Batalkan / Hapus Transfer">
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

            <!-- Navigasi Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
                    <small class="text-muted">
                        Menampilkan <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalRows) ?> dari <?= $totalRows ?> transfer
                    </small>
                    <nav aria-label="Navigasi halaman transfer">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= $page > 1 ? e(buildTransferUrl($bulan, $filterAsal, $filterTujuan, $page - 1)) : '#' ?>">&laquo;</a>
                            </li>
                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage   = min($totalPages, $page + 2);
                            if ($startPage > 1): ?>
                                <li class="page-item"><a class="page-link" href="<?= e(buildTransferUrl($bulan, $filterAsal, $filterTujuan, 1)) ?>">1</a></li>
                                <?php if ($startPage > 2): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                            <?php endif; ?>
                            <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= e(buildTransferUrl($bulan, $filterAsal, $filterTujuan, $p)) ?>"><?= $p ?></a>
                                </li>
                            <?php endfor; ?>
                            <?php if ($endPage < $totalPages): ?>
                                <?php if ($endPage < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                                <li class="page-item"><a class="page-link" href="<?= e(buildTransferUrl($bulan, $filterAsal, $filterTujuan, $totalPages)) ?>"><?= $totalPages ?></a></li>
                            <?php endif; ?>
                            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= $page < $totalPages ? e(buildTransferUrl($bulan, $filterAsal, $filterTujuan, $page + 1)) : '#' ?>">&raquo;</a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Edit Transfer -->
<div class="modal fade" id="modalEditTransfer" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="aksi" value="edit">
                <input type="hidden" name="transfer_id" id="editTrfId">
                <input type="hidden" name="ret_bulan" value="<?= e($bulan) ?>">
                <input type="hidden" name="ret_asal" value="<?= $filterAsal ?>">
                <input type="hidden" name="ret_tujuan" value="<?= $filterTujuan ?>">
                <input type="hidden" name="ret_page" value="<?= $page ?>">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Edit Transfer Antar Dompet</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Tanggal Transfer</label>
                        <input type="date" name="tanggal" id="editTrfTanggal" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Dari Dompet / Rekening (Asal)</label>
                        <select name="dompet_asal_id" id="editTrfAsal" class="form-select" required>
                            <?php foreach ($dompetList as $d): ?>
                                <option value="<?= (int)$d['id'] ?>"><?= e($d['nama_dompet']) ?> (<?= rupiah($d['saldo']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Ke Dompet / Rekening (Tujuan)</label>
                        <select name="dompet_tujuan_id" id="editTrfTujuan" class="form-select" required>
                            <?php foreach ($dompetList as $d): ?>
                                <option value="<?= (int)$d['id'] ?>"><?= e($d['nama_dompet']) ?> (<?= rupiah($d['saldo']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-7">
                            <label class="form-label small fw-semibold">Nominal Transfer (Rp)</label>
                            <input type="number" name="nominal" id="editTrfNominal" min="1" step="1" class="form-control" required>
                        </div>
                        <div class="col-5">
                            <label class="form-label small fw-semibold">Biaya Admin</label>
                            <input type="number" name="biaya_admin" id="editTrfAdmin" min="0" step="1" class="form-control" required>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Keterangan (Opsional)</label>
                        <input type="text" name="keterangan" id="editTrfKeterangan" class="form-control">
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
    const modalEdit = document.getElementById('modalEditTransfer');
    if (modalEdit) {
        modalEdit.addEventListener('show.bs.modal', function (event) {
            const btn = event.relatedTarget;
            document.getElementById('editTrfId').value         = btn.getAttribute('data-id');
            document.getElementById('editTrfTanggal').value    = btn.getAttribute('data-tanggal');
            document.getElementById('editTrfNominal').value    = btn.getAttribute('data-nominal');
            document.getElementById('editTrfAdmin').value      = btn.getAttribute('data-admin');
            document.getElementById('editTrfKeterangan').value = btn.getAttribute('data-keterangan');

            const asalId   = btn.getAttribute('data-asal');
            const tujuanId = btn.getAttribute('data-tujuan');
            if (asalId && asalId !== '0') document.getElementById('editTrfAsal').value = asalId;
            if (tujuanId && tujuanId !== '0') document.getElementById('editTrfTujuan').value = tujuanId;
        });
    }
});
</script>
<?php renderFooter(); ?>

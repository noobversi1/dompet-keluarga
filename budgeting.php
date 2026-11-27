<?php
declare(strict_types=1);

require_once 'config.php';
require_once 'security.php';
require_once 'layout.php';

$userId = requireLogin();

// Parameter Filter & Pagination Independen untuk Ketiga Tabel
$bulan = $_GET['bulan'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $bulan)) {
    $bulan = date('Y-m');
}

$qBudget    = trim($_GET['q_budget'] ?? '');
$pageBudget = max(1, (int)($_GET['page_budget'] ?? 1));

$qDompet    = trim($_GET['q_dompet'] ?? '');
$pageDompet = max(1, (int)($_GET['page_dompet'] ?? 1));

$tipeKat    = in_array($_GET['tipe_kat'] ?? '', ['pemasukan', 'pengeluaran'], true) ? $_GET['tipe_kat'] : '';
$qKat       = trim($_GET['q_kat'] ?? '');
$pageKat    = max(1, (int)($_GET['page_kat'] ?? 1));

$perPage    = 8; // 8 baris per bagian agar ketiga panel ringkas dan nyaman discroll

$baseParams = [
    'bulan'       => $bulan,
    'q_budget'    => $qBudget,
    'page_budget' => $pageBudget,
    'q_dompet'    => $qDompet,
    'page_dompet' => $pageDompet,
    'tipe_kat'    => $tipeKat,
    'q_kat'       => $qKat,
    'page_kat'    => $pageKat,
];

function buildBudgetingUrl(array $base, array $overrides = []): string
{
    $merged = array_merge($base, $overrides);
    $clean  = ['bulan' => $merged['bulan']];

    if (($merged['q_budget'] ?? '') !== '') $clean['q_budget'] = $merged['q_budget'];
    if (($merged['page_budget'] ?? 1) > 1) $clean['page_budget'] = (int)$merged['page_budget'];

    if (($merged['q_dompet'] ?? '') !== '') $clean['q_dompet'] = $merged['q_dompet'];
    if (($merged['page_dompet'] ?? 1) > 1) $clean['page_dompet'] = (int)$merged['page_dompet'];

    if (($merged['tipe_kat'] ?? '') !== '') $clean['tipe_kat'] = $merged['tipe_kat'];
    if (($merged['q_kat'] ?? '') !== '') $clean['q_kat'] = $merged['q_kat'];
    if (($merged['page_kat'] ?? 1) > 1) $clean['page_kat'] = (int)$merged['page_kat'];

    return 'budgeting.php?' . http_build_query($clean);
}

function renderBudgetingPagination(int $currentPage, int $totalPages, string $pageKey, array $base): void
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
                <a class="page-link" href="<?= $currentPage > 1 ? e(buildBudgetingUrl($base, [$pageKey => $currentPage - 1])) : '#' ?>">&laquo;</a>
            </li>
            <?php if ($startPage > 1): ?>
                <li class="page-item"><a class="page-link" href="<?= e(buildBudgetingUrl($base, [$pageKey => 1])) ?>">1</a></li>
                <?php if ($startPage > 2): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
            <?php endif; ?>
            <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                <li class="page-item <?= $p === $currentPage ? 'active' : '' ?>">
                    <a class="page-link" href="<?= e(buildBudgetingUrl($base, [$pageKey => $p])) ?>"><?= $p ?></a>
                </li>
            <?php endfor; ?>
            <?php if ($endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                <li class="page-item"><a class="page-link" href="<?= e(buildBudgetingUrl($base, [$pageKey => $totalPages])) ?>"><?= $totalPages ?></a></li>
            <?php endif; ?>
            <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $currentPage < $totalPages ? e(buildBudgetingUrl($base, [$pageKey => $currentPage + 1])) : '#' ?>">&raquo;</a>
            </li>
        </ul>
    </nav>
    <?php
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $aksi = $_POST['aksi'] ?? '';
    $redirectUrl = buildBudgetingUrl($baseParams);

    // 1. Tambah Kategori Baru
    if ($aksi === 'tambah_kategori') {
        $nama = trim($_POST['nama_kategori'] ?? '');
        $tipe = ($_POST['tipe'] ?? '') === 'pemasukan' ? 'pemasukan' : 'pengeluaran';
        if ($nama !== '') {
            $stmt = $pdo->prepare('INSERT INTO kategori (user_id, nama_kategori, tipe) VALUES (?, ?, ?)');
            $stmt->execute([$userId, $nama, $tipe]);
            setFlash('success', 'Kategori baru berhasil ditambahkan.');
        } else {
            setFlash('danger', 'Nama kategori wajib diisi.');
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    // 2. Edit Kategori yang Sudah Ada
    if ($aksi === 'edit_kategori') {
        $kategoriId = (int)($_POST['kategori_id'] ?? 0);
        $namaBaru   = trim($_POST['nama_kategori'] ?? '');
        $tipeInput  = ($_POST['tipe'] ?? '') === 'pemasukan' ? 'pemasukan' : 'pengeluaran';

        $stmtCek = $pdo->prepare('SELECT id, tipe FROM kategori WHERE id = ? AND user_id = ?');
        $stmtCek->execute([$kategoriId, $userId]);
        $katLama = $stmtCek->fetch();

        if ($katLama && $namaBaru !== '') {
            $stmtTrx = $pdo->prepare('SELECT COUNT(*) FROM transaksi WHERE kategori_id = ? AND user_id = ?');
            $stmtTrx->execute([$kategoriId, $userId]);
            $jmlTrx = (int)$stmtTrx->fetchColumn();

            $stmtBdg = $pdo->prepare('SELECT COUNT(*) FROM budgeting WHERE kategori_id = ? AND user_id = ?');
            $stmtBdg->execute([$kategoriId, $userId]);
            $jmlBdg = (int)$stmtBdg->fetchColumn();

            $tipeFinal = ($jmlTrx + $jmlBdg > 0) ? $katLama['tipe'] : $tipeInput;

            $stmtUpd = $pdo->prepare('UPDATE kategori SET nama_kategori = ?, tipe = ? WHERE id = ? AND user_id = ?');
            $stmtUpd->execute([$namaBaru, $tipeFinal, $kategoriId, $userId]);
            setFlash('success', 'Kategori berhasil diperbarui.');
        } else {
            setFlash('danger', 'Data pembaruan kategori tidak valid.');
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    // 3. Hapus Kategori
    if ($aksi === 'hapus_kategori') {
        $kategoriId = (int)($_POST['kategori_id'] ?? 0);

        $stmtTrx = $pdo->prepare('SELECT COUNT(*) FROM transaksi WHERE kategori_id = ? AND user_id = ?');
        $stmtTrx->execute([$kategoriId, $userId]);
        $jmlTrx = (int)$stmtTrx->fetchColumn();

        $stmtBdg = $pdo->prepare('SELECT COUNT(*) FROM budgeting WHERE kategori_id = ? AND user_id = ?');
        $stmtBdg->execute([$kategoriId, $userId]);
        $jmlBdg = (int)$stmtBdg->fetchColumn();

        if ($jmlTrx > 0 || $jmlBdg > 0) {
            setFlash('warning', 'Kategori tidak dapat dihapus karena masih digunakan pada ' . $jmlTrx . ' transaksi dan ' . $jmlBdg . ' anggaran.');
        } else {
            $stmtDel = $pdo->prepare('DELETE FROM kategori WHERE id = ? AND user_id = ?');
            $stmtDel->execute([$kategoriId, $userId]);
            if ($stmtDel->rowCount() > 0) {
                setFlash('success', 'Kategori berhasil dihapus.');
            } else {
                setFlash('danger', 'Kategori tidak ditemukan.');
            }
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    // 4. Tambah Dompet / Rekening
    if ($aksi === 'tambah_dompet') {
        $namaDompet = trim($_POST['nama_dompet'] ?? '');
        $saldoAwal  = (float)($_POST['saldo_awal'] ?? 0);
        if ($namaDompet !== '' && $saldoAwal >= 0) {
            $stmt = $pdo->prepare('INSERT INTO dompet (user_id, nama_dompet, saldo) VALUES (?, ?, ?)');
            $stmt->execute([$userId, $namaDompet, $saldoAwal]);
            setFlash('success', 'Dompet / rekening baru berhasil ditambahkan.');
        } else {
            setFlash('danger', 'Nama dompet wajib diisi dan saldo tidak boleh negatif.');
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    // 5. Edit Dompet / Rekening
    if ($aksi === 'edit_dompet') {
        $dompetId   = (int)($_POST['dompet_id'] ?? 0);
        $namaDompet = trim($_POST['nama_dompet'] ?? '');
        $saldo      = (float)($_POST['saldo'] ?? 0);

        if ($dompetId > 0 && $namaDompet !== '' && $saldo >= 0) {
            $stmt = $pdo->prepare('UPDATE dompet SET nama_dompet = ?, saldo = ? WHERE id = ? AND user_id = ?');
            $stmt->execute([$namaDompet, $saldo, $dompetId, $userId]);
            setFlash('success', 'Data dompet / rekening berhasil diperbarui.');
        } else {
            setFlash('danger', 'Data pembaruan dompet tidak valid.');
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    // 6. Hapus Dompet / Rekening
    if ($aksi === 'hapus_dompet') {
        $dompetId = (int)($_POST['dompet_id'] ?? 0);

        $cekTrx = $pdo->prepare('SELECT COUNT(*) FROM transaksi WHERE dompet_id = ? AND user_id = ?');
        $cekTrx->execute([$dompetId, $userId]);
        $jumlahTrx = (int)$cekTrx->fetchColumn();

        if ($jumlahTrx > 0) {
            setFlash('warning', 'Dompet tidak dapat dihapus karena masih memiliki ' . $jumlahTrx . ' riwayat transaksi.');
        } else {
            $stmt = $pdo->prepare('DELETE FROM dompet WHERE id = ? AND user_id = ?');
            $stmt->execute([$dompetId, $userId]);
            if ($stmt->rowCount() > 0) {
                setFlash('success', 'Dompet / rekening berhasil dihapus.');
            } else {
                setFlash('danger', 'Dompet tidak ditemukan.');
            }
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    // 7. Simpan / Tambah Limit Budgeting
    if ($aksi === 'simpan_budget') {
        $kategoriId    = (int)($_POST['kategori_id'] ?? 0);
        $bulanTarget   = $_POST['bulan_target'] ?? date('Y-m');
        $batasAnggaran = (float)($_POST['batas_anggaran'] ?? 0);

        $cekKat = $pdo->prepare("SELECT id FROM kategori WHERE id = ? AND user_id = ? AND tipe = 'pengeluaran'");
        $cekKat->execute([$kategoriId, $userId]);

        if ($cekKat->fetch() && $batasAnggaran > 0 && preg_match('/^\d{4}-\d{2}$/', $bulanTarget)) {
            $stmt = $pdo->prepare("
                INSERT INTO budgeting (user_id, kategori_id, bulan, batas_anggaran)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE batas_anggaran = VALUES(batas_anggaran)
            ");
            $stmt->execute([$userId, $kategoriId, $bulanTarget, $batasAnggaran]);
            setFlash('success', 'Batas anggaran (budget) berhasil disimpan.');
            header('Location: ' . buildBudgetingUrl($baseParams, ['bulan' => $bulanTarget, 'page_budget' => 1]));
            exit;
        } else {
            setFlash('danger', 'Data budgeting tidak valid.');
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    // 8. Edit Nominal Budgeting
    if ($aksi === 'edit_budget') {
        $budgetId      = (int)($_POST['budget_id'] ?? 0);
        $batasAnggaran = (float)($_POST['batas_anggaran'] ?? 0);

        if ($budgetId > 0 && $batasAnggaran > 0) {
            $stmt = $pdo->prepare('UPDATE budgeting SET batas_anggaran = ? WHERE id = ? AND user_id = ?');
            $stmt->execute([$batasAnggaran, $budgetId, $userId]);
            setFlash('success', 'Batas anggaran berhasil diperbarui.');
        } else {
            setFlash('danger', 'Nominal batas anggaran tidak valid.');
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    // 9. Hapus Budgeting
    if ($aksi === 'hapus_budget') {
        $budgetId = (int)($_POST['budget_id'] ?? 0);
        $stmt = $pdo->prepare('DELETE FROM budgeting WHERE id = ? AND user_id = ?');
        $stmt->execute([$budgetId, $userId]);
        if ($stmt->rowCount() > 0) {
            setFlash('success', 'Anggaran kategori berhasil dihapus.');
        } else {
            setFlash('danger', 'Data anggaran tidak ditemukan.');
        }
        header('Location: ' . $redirectUrl);
        exit;
    }
}

// Dropdown Kategori Pengeluaran (Form Input Kiri)
$kategoriPengeluaran = $pdo->prepare("SELECT id, nama_kategori FROM kategori WHERE user_id = ? AND tipe = 'pengeluaran' ORDER BY nama_kategori ASC");
$kategoriPengeluaran->execute([$userId]);
$kategoriPengeluaran = $kategoriPengeluaran->fetchAll();

// =========================================================================
// QUERY 1: DAFTAR ANGGARAN BULANAN (FILTER + PAGINATION)
// =========================================================================
$whereBudget  = ['b.user_id = ?', 'b.bulan = ?'];
$paramsBudget = [$userId, $bulan];
if ($qBudget !== '') {
    $whereBudget[]  = 'k.nama_kategori LIKE ?';
    $paramsBudget[] = '%' . $qBudget . '%';
}
$sqlWhereBudget = implode(' AND ', $whereBudget);

$stmtCountBudget = $pdo->prepare("
    SELECT COUNT(*) AS cnt, COALESCE(SUM(b.batas_anggaran), 0) AS total_anggaran
    FROM budgeting b
    JOIN kategori k ON b.kategori_id = k.id
    WHERE {$sqlWhereBudget}
");
$stmtCountBudget->execute($paramsBudget);
$statsBudget        = $stmtCountBudget->fetch();
$totalRowsBudget    = (int)($statsBudget['cnt'] ?? 0);
$totalNominalBudget = (float)($statsBudget['total_anggaran'] ?? 0);
$totalPagesBudget   = max(1, (int)ceil($totalRowsBudget / $perPage));
if ($pageBudget > $totalPagesBudget) $pageBudget = $totalPagesBudget;
$offsetBudget = ($pageBudget - 1) * $perPage;

$stmtBudget = $pdo->prepare("
    SELECT b.id, b.batas_anggaran, k.nama_kategori
    FROM budgeting b
    JOIN kategori k ON b.kategori_id = k.id
    WHERE {$sqlWhereBudget}
    ORDER BY k.nama_kategori ASC
    LIMIT ? OFFSET ?
");
$idx = 1;
foreach ($paramsBudget as $pVal) {
    $stmtBudget->bindValue($idx++, $pVal, is_int($pVal) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmtBudget->bindValue($idx++, $perPage, PDO::PARAM_INT);
$stmtBudget->bindValue($idx++, $offsetBudget, PDO::PARAM_INT);
$stmtBudget->execute();
$daftarBudget = $stmtBudget->fetchAll();

// =========================================================================
// QUERY 2: DAFTAR DOMPET / REKENING (FILTER + PAGINATION)
// =========================================================================
$whereDompet  = ['user_id = ?'];
$paramsDompet = [$userId];
if ($qDompet !== '') {
    $whereDompet[]  = 'nama_dompet LIKE ?';
    $paramsDompet[] = '%' . $qDompet . '%';
}
$sqlWhereDompet = implode(' AND ', $whereDompet);

$stmtCountDompet = $pdo->prepare("
    SELECT COUNT(*) AS cnt, COALESCE(SUM(saldo), 0) AS total_saldo
    FROM dompet
    WHERE {$sqlWhereDompet}
");
$stmtCountDompet->execute($paramsDompet);
$statsDompet      = $stmtCountDompet->fetch();
$totalRowsDompet  = (int)($statsDompet['cnt'] ?? 0);
$totalSaldoDompet = (float)($statsDompet['total_saldo'] ?? 0);
$totalPagesDompet = max(1, (int)ceil($totalRowsDompet / $perPage));
if ($pageDompet > $totalPagesDompet) $pageDompet = $totalPagesDompet;
$offsetDompet = ($pageDompet - 1) * $perPage;

$stmtDompet = $pdo->prepare("
    SELECT id, nama_dompet, saldo
    FROM dompet
    WHERE {$sqlWhereDompet}
    ORDER BY id ASC
    LIMIT ? OFFSET ?
");
$idx = 1;
foreach ($paramsDompet as $pVal) {
    $stmtDompet->bindValue($idx++, $pVal, is_int($pVal) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmtDompet->bindValue($idx++, $perPage, PDO::PARAM_INT);
$stmtDompet->bindValue($idx++, $offsetDompet, PDO::PARAM_INT);
$stmtDompet->execute();
$daftarDompet = $stmtDompet->fetchAll();

// =========================================================================
// QUERY 3: DAFTAR SEMUA KATEGORI (FILTER TIPE & NAMA + PAGINATION)
// =========================================================================
$whereKat  = ['k.user_id = ?'];
$paramsKat = [$userId];
if ($tipeKat !== '') {
    $whereKat[]  = 'k.tipe = ?';
    $paramsKat[] = $tipeKat;
}
if ($qKat !== '') {
    $whereKat[]  = 'k.nama_kategori LIKE ?';
    $paramsKat[] = '%' . $qKat . '%';
}
$sqlWhereKat = implode(' AND ', $whereKat);

$stmtCountKat = $pdo->prepare("SELECT COUNT(*) FROM kategori k WHERE {$sqlWhereKat}");
$stmtCountKat->execute($paramsKat);
$totalRowsKat  = (int)$stmtCountKat->fetchColumn();
$totalPagesKat = max(1, (int)ceil($totalRowsKat / $perPage));
if ($pageKat > $totalPagesKat) $pageKat = $totalPagesKat;
$offsetKat = ($pageKat - 1) * $perPage;

$stmtKat = $pdo->prepare("
    SELECT k.id, k.nama_kategori, k.tipe,
           (SELECT COUNT(*) FROM transaksi t WHERE t.kategori_id = k.id AND t.user_id = k.user_id) AS jml_trx,
           (SELECT COUNT(*) FROM budgeting b WHERE b.kategori_id = k.id AND b.user_id = k.user_id) AS jml_budget
    FROM kategori k
    WHERE {$sqlWhereKat}
    ORDER BY k.tipe DESC, k.nama_kategori ASC
    LIMIT ? OFFSET ?
");
$idx = 1;
foreach ($paramsKat as $pVal) {
    $stmtKat->bindValue($idx++, $pVal, is_int($pVal) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmtKat->bindValue($idx++, $perPage, PDO::PARAM_INT);
$stmtKat->bindValue($idx++, $offsetKat, PDO::PARAM_INT);
$stmtKat->execute();
$semuaKategori = $stmtKat->fetchAll();

// Perbarui query $daftarBudget agar menyertakan kategori_id
$daftarBudget = $pdo->prepare("
    SELECT b.id, b.kategori_id, b.batas_anggaran, k.nama_kategori
    FROM budgeting b
    JOIN kategori k ON b.kategori_id = k.id
    WHERE b.user_id = ? AND b.bulan = ?
    ORDER BY k.nama_kategori ASC
");
$daftarBudget->execute([$userId, $bulan]);
$daftarBudget = $daftarBudget->fetchAll();

// Ambil rincian item rencana belanja per kategori pada bulan aktif
$stmtRincian = $pdo->prepare('
    SELECT kategori_id, keterangan, nominal
    FROM rencana_belanja
    WHERE user_id = ? AND bulan = ?
    ORDER BY id ASC
');
$stmtRincian->execute([$userId, $bulan]);
$rincianPerKategori = [];
foreach ($stmtRincian->fetchAll() as $item) {
    $rincianPerKategori[(int)$item['kategori_id']][] = $item;
}


renderHeader('Kategori, Dompet & Budgeting', 'budgeting');
?>
<div class="row g-4">
    <!-- KOLOM KIRI: FORMULIR INPUT -->
    <div class="col-lg-5">
        <!-- Form Atur Limit Budgeting -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-bold">Atur Limit Anggaran (Budgeting)</div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="aksi" value="simpan_budget">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Bulan Anggaran</label>
                        <input type="month" name="bulan_target" value="<?= e($bulan) ?>" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Kategori Pengeluaran</label>
                        <select name="kategori_id" class="form-select" required>
                            <?php foreach ($kategoriPengeluaran as $kp): ?>
                                <option value="<?= (int)$kp['id'] ?>"><?= e($kp['nama_kategori']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Batas Maksimal Pengeluaran (Rp)</label>
                        <input type="number" name="batas_anggaran" min="1000" step="1000" class="form-control" placeholder="Contoh: 2500000" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Simpan Anggaran</button>
                </form>
            </div>
        </div>

        <!-- Form Tambah Dompet / Rekening -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-bold">Tambah Dompet / Rekening</div>
            <div class="card-body">
                <form method="POST" class="row g-2">
                    <?= csrfField() ?>
                    <input type="hidden" name="aksi" value="tambah_dompet">
                    <div class="col-7">
                        <input type="text" name="nama_dompet" class="form-control" placeholder="Contoh: Tabungan BCA" required>
                    </div>
                    <div class="col-5">
                        <input type="number" name="saldo_awal" min="0" step="1" value="0" class="form-control" placeholder="Saldo Awal">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-outline-success btn-sm w-100">Tambah Dompet</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Form Tambah Kategori Baru -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-bold">Tambah Kategori Baru</div>
            <div class="card-body">
                <form method="POST" class="row g-2">
                    <?= csrfField() ?>
                    <input type="hidden" name="aksi" value="tambah_kategori">
                    <div class="col-7">
                        <input type="text" name="nama_kategori" class="form-control" placeholder="Nama kategori..." required>
                    </div>
                    <div class="col-5">
                        <select name="tipe" class="form-select">
                            <option value="pengeluaran">Pengeluaran</option>
                            <option value="pemasukan">Pemasukan</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-outline-primary btn-sm w-100">Tambah Kategori</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- KOLOM KANAN: DAFTAR BUDGET, DOMPET & KATEGORI -->
    <div class="col-lg-7">
        <!-- 1. TABEL DAFTAR ANGGARAN BULANAN -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-bold">Daftar Anggaran Bulan <?= e($bulan) ?></span>
                    <span class="badge bg-primary-subtle text-primary">Total: <?= rupiah($totalNominalBudget) ?></span>
                </div>
                <form method="GET" class="row g-2">
                    <input type="hidden" name="q_dompet" value="<?= e($qDompet) ?>">
                    <input type="hidden" name="page_dompet" value="<?= $pageDompet ?>">
                    <input type="hidden" name="tipe_kat" value="<?= e($tipeKat) ?>">
                    <input type="hidden" name="q_kat" value="<?= e($qKat) ?>">
                    <input type="hidden" name="page_kat" value="<?= $pageKat ?>">

                    <div class="col-sm-4">
                        <input type="month" name="bulan" value="<?= e($bulan) ?>" class="form-control form-control-sm">
                    </div>
                    <div class="col-sm-5">
                        <input type="text" name="q_budget" value="<?= e($qBudget) ?>" class="form-control form-control-sm" placeholder="Cari kategori anggaran...">
                    </div>
                    <div class="col-sm-3 d-flex gap-1">
                        <button type="submit" class="btn btn-primary btn-sm flex-fill">Filter</button>
                        <?php if ($qBudget !== ''): ?>
                            <a href="<?= e(buildBudgetingUrl($baseParams, ['q_budget' => '', 'page_budget' => 1])) ?>" class="btn btn-outline-secondary btn-sm" title="Reset"><i class="bi bi-x"></i></a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Kategori Pengeluaran</th>
                            <th class="text-end">Batas Anggaran</th>
                            <th class="text-center" style="width: 110px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($daftarBudget)): ?>
                            <tr><td colspan="3" class="text-center text-muted py-3">Belum ada anggaran ditetapkan pada bulan ini.</td></tr>
                        <?php else: ?>
                            <?php foreach ($daftarBudget as $b):
                                $items = $rincianPerKategori[(int)$b['kategori_id']] ?? [];
                            ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= e($b['nama_kategori']) ?></div>
                                        <?php if (!empty($items)): ?>
                                            <ul class="list-unstyled mb-1 mt-1 small text-muted">
                                                <?php foreach ($items as $it): ?>
                                                    <li>
                                                        <i class="bi bi-dot"></i><?= e($it['keterangan']) ?>
                                                        <span class="text-dark">(<?= rupiah($it['nominal']) ?>)</span>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                            <a href="rencana_belanja.php?bulan=<?= urlencode($bulan) ?>&kategori_id=<?= (int)$b['kategori_id'] ?>"
                                               class="badge bg-info-subtle text-info-emphasis text-decoration-none">
                                                <i class="bi bi-list-check me-1"></i>Kelola <?= count($items) ?> Rencana Belanja
                                            </a>
                                        <?php else: ?>
                                            <a href="rencana_belanja.php?bulan=<?= urlencode($bulan) ?>&kategori_id=<?= (int)$b['kategori_id'] ?>"
                                               class="small text-decoration-none">
                                                + Buat rincian rencana belanja
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end fw-bold"><?= rupiah($b['batas_anggaran']) ?></td>
                                    <td class="text-center">
                                        <div class="d-inline-flex gap-1">
                                            <button type="button"
                                                    class="btn btn-outline-primary btn-sm"
                                                    title="Edit Anggaran"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalEditBudget"
                                                    data-id="<?= (int)$b['id'] ?>"
                                                    data-kategori="<?= e($b['nama_kategori']) ?>"
                                                    data-batas="<?= (float)$b['batas_anggaran'] ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Hapus anggaran untuk kategori <?= e($b['nama_kategori']) ?>?');">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="aksi" value="hapus_budget">
                                                <input type="hidden" name="budget_id" value="<?= (int)$b['id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm" title="Hapus Anggaran">
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
            <?php if ($totalPagesBudget > 1): ?>
                <div class="card-footer bg-white d-flex justify-content-between align-items-center py-2">
                    <small class="text-muted">Hal. <?= $pageBudget ?> dari <?= $totalPagesBudget ?> (<?= $totalRowsBudget ?> data)</small>
                    <?php renderBudgetingPagination($pageBudget, $totalPagesBudget, 'page_budget', $baseParams); ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- 2. TABEL DAFTAR DOMPET / REKENING -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-bold">Daftar Dompet / Rekening</span>
                    <span class="badge bg-success-subtle text-success">Total Saldo: <?= rupiah($totalSaldoDompet) ?></span>
                </div>
                <form method="GET" class="row g-2">
                    <input type="hidden" name="bulan" value="<?= e($bulan) ?>">
                    <input type="hidden" name="q_budget" value="<?= e($qBudget) ?>">
                    <input type="hidden" name="page_budget" value="<?= $pageBudget ?>">
                    <input type="hidden" name="tipe_kat" value="<?= e($tipeKat) ?>">
                    <input type="hidden" name="q_kat" value="<?= e($qKat) ?>">
                    <input type="hidden" name="page_kat" value="<?= $pageKat ?>">

                    <div class="col-sm-9">
                        <input type="text" name="q_dompet" value="<?= e($qDompet) ?>" class="form-control form-control-sm" placeholder="Cari nama dompet / rekening...">
                    </div>
                    <div class="col-sm-3 d-flex gap-1">
                        <button type="submit" class="btn btn-primary btn-sm flex-fill">Cari</button>
                        <?php if ($qDompet !== ''): ?>
                            <a href="<?= e(buildBudgetingUrl($baseParams, ['q_dompet' => '', 'page_dompet' => 1])) ?>" class="btn btn-outline-secondary btn-sm" title="Reset"><i class="bi bi-x"></i></a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Nama Dompet / Rekening</th>
                            <th class="text-end">Saldo Saat Ini</th>
                            <th class="text-center" style="width: 110px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($daftarDompet)): ?>
                            <tr><td colspan="3" class="text-center text-muted py-3">Tidak ada dompet atau rekening yang sesuai.</td></tr>
                        <?php else: ?>
                            <?php foreach ($daftarDompet as $d): ?>
                                <tr>
                                    <td class="fw-semibold"><?= e($d['nama_dompet']) ?></td>
                                    <td class="text-end fw-bold text-primary"><?= rupiah($d['saldo']) ?></td>
                                    <td class="text-center">
                                        <div class="d-inline-flex gap-1">
                                            <button type="button"
                                                    class="btn btn-outline-primary btn-sm"
                                                    title="Edit Dompet"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalEditDompet"
                                                    data-id="<?= (int)$d['id'] ?>"
                                                    data-nama="<?= e($d['nama_dompet']) ?>"
                                                    data-saldo="<?= (float)$d['saldo'] ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Yakin ingin menghapus dompet <?= e($d['nama_dompet']) ?>?');">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="aksi" value="hapus_dompet">
                                                <input type="hidden" name="dompet_id" value="<?= (int)$d['id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm" title="Hapus Dompet">
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
            <?php if ($totalPagesDompet > 1): ?>
                <div class="card-footer bg-white d-flex justify-content-between align-items-center py-2">
                    <small class="text-muted">Hal. <?= $pageDompet ?> dari <?= $totalPagesDompet ?> (<?= $totalRowsDompet ?> data)</small>
                    <?php renderBudgetingPagination($pageDompet, $totalPagesDompet, 'page_dompet', $baseParams); ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- 3. TABEL DAFTAR SEMUA KATEGORI -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-bold">Semua Kategori Rumah Tangga</span>
                    <span class="badge bg-secondary-subtle text-secondary"><?= $totalRowsKat ?> Kategori</span>
                </div>
                <form method="GET" class="row g-2">
                    <input type="hidden" name="bulan" value="<?= e($bulan) ?>">
                    <input type="hidden" name="q_budget" value="<?= e($qBudget) ?>">
                    <input type="hidden" name="page_budget" value="<?= $pageBudget ?>">
                    <input type="hidden" name="q_dompet" value="<?= e($qDompet) ?>">
                    <input type="hidden" name="page_dompet" value="<?= $pageDompet ?>">

                    <div class="col-sm-4">
                        <select name="tipe_kat" class="form-select form-select-sm">
                            <option value="">Semua Tipe</option>
                            <option value="pengeluaran" <?= $tipeKat === 'pengeluaran' ? 'selected' : '' ?>>Pengeluaran</option>
                            <option value="pemasukan" <?= $tipeKat === 'pemasukan' ? 'selected' : '' ?>>Pemasukan</option>
                        </select>
                    </div>
                    <div class="col-sm-5">
                        <input type="text" name="q_kat" value="<?= e($qKat) ?>" class="form-control form-control-sm" placeholder="Cari nama kategori...">
                    </div>
                    <div class="col-sm-3 d-flex gap-1">
                        <button type="submit" class="btn btn-primary btn-sm flex-fill">Filter</button>
                        <?php if ($tipeKat !== '' || $qKat !== ''): ?>
                            <a href="<?= e(buildBudgetingUrl($baseParams, ['tipe_kat' => '', 'q_kat' => '', 'page_kat' => 1])) ?>" class="btn btn-outline-secondary btn-sm" title="Reset"><i class="bi bi-x"></i></a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Nama Kategori</th>
                            <th>Tipe</th>
                            <th class="text-center" style="width: 110px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($semuaKategori)): ?>
                            <tr><td colspan="3" class="text-center text-muted py-3">Tidak ada kategori yang sesuai.</td></tr>
                        <?php else: ?>
                            <?php foreach ($semuaKategori as $sk):
                                $terpakai = ((int)$sk['jml_trx'] + (int)$sk['jml_budget']) > 0;
                            ?>
                                <tr>
                                    <td class="fw-semibold"><?= e($sk['nama_kategori']) ?></td>
                                    <td>
                                        <span class="badge <?= $sk['tipe'] === 'pemasukan' ? 'bg-success-subtle text-success border border-success' : 'bg-danger-subtle text-danger border border-danger' ?>">
                                            <?= ucfirst(e($sk['tipe'])) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <div class="d-inline-flex gap-1">
                                            <button type="button"
                                                    class="btn btn-outline-primary btn-sm"
                                                    title="Edit Kategori"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalEditKategori"
                                                    data-id="<?= (int)$sk['id'] ?>"
                                                    data-nama="<?= e($sk['nama_kategori']) ?>"
                                                    data-tipe="<?= e($sk['tipe']) ?>"
                                                    data-terpakai="<?= $terpakai ? '1' : '0' ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Yakin ingin menghapus kategori <?= e($sk['nama_kategori']) ?>?');">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="aksi" value="hapus_kategori">
                                                <input type="hidden" name="kategori_id" value="<?= (int)$sk['id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm" title="Hapus Kategori">
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
            <?php if ($totalPagesKat > 1): ?>
                <div class="card-footer bg-white d-flex justify-content-between align-items-center py-2">
                    <small class="text-muted">Hal. <?= $pageKat ?> dari <?= $totalPagesKat ?> (<?= $totalRowsKat ?> data)</small>
                    <?php renderBudgetingPagination($pageKat, $totalPagesKat, 'page_kat', $baseParams); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Edit Anggaran (Budget) -->
<div class="modal fade" id="modalEditBudget" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="aksi" value="edit_budget">
                <input type="hidden" name="budget_id" id="editBudgetId">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Edit Batas Anggaran</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Kategori</label>
                        <input type="text" id="editBudgetKategori" class="form-control bg-light" readonly>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Batas Maksimal Pengeluaran (Rp)</label>
                        <input type="number" name="batas_anggaran" id="editBudgetBatas" min="1000" step="1000" class="form-control" required>
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

<!-- Modal Edit Dompet / Rekening -->
<div class="modal fade" id="modalEditDompet" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="aksi" value="edit_dompet">
                <input type="hidden" name="dompet_id" id="editDompetId">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Edit Dompet / Rekening</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Nama Dompet / Rekening</label>
                        <input type="text" name="nama_dompet" id="editDompetNama" class="form-control" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Saldo Saat Ini (Rp)</label>
                        <input type="number" name="saldo" id="editDompetSaldo" min="0" step="1" class="form-control" required>
                        <div class="form-text">Gunakan kolom ini jika perlu melakukan penyesuaian/koreksi saldo manual.</div>
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

<!-- Modal Edit Kategori -->
<div class="modal fade" id="modalEditKategori" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="aksi" value="edit_kategori">
                <input type="hidden" name="kategori_id" id="editKatId">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Edit Kategori</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Nama Kategori</label>
                        <input type="text" name="nama_kategori" id="editKatNama" class="form-control" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Tipe Kategori</label>
                        <select name="tipe" id="editKatTipe" class="form-select">
                            <option value="pengeluaran">Pengeluaran</option>
                            <option value="pemasukan">Pemasukan</option>
                        </select>
                        <div id="editKatTipeInfo" class="form-text text-warning d-none">
                            Tipe kategori dikunci karena sudah digunakan pada riwayat transaksi atau budgeting. Anda tetap dapat mengubah nama kategori.
                        </div>
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
    const modalBudget = document.getElementById('modalEditBudget');
    if (modalBudget) {
        modalBudget.addEventListener('show.bs.modal', function (event) {
            const btn = event.relatedTarget;
            document.getElementById('editBudgetId').value = btn.getAttribute('data-id');
            document.getElementById('editBudgetKategori').value = btn.getAttribute('data-kategori');
            document.getElementById('editBudgetBatas').value = btn.getAttribute('data-batas');
        });
    }

    const modalDompet = document.getElementById('modalEditDompet');
    if (modalDompet) {
        modalDompet.addEventListener('show.bs.modal', function (event) {
            const btn = event.relatedTarget;
            document.getElementById('editDompetId').value = btn.getAttribute('data-id');
            document.getElementById('editDompetNama').value = btn.getAttribute('data-nama');
            document.getElementById('editDompetSaldo').value = btn.getAttribute('data-saldo');
        });
    }

    const modalKategori = document.getElementById('modalEditKategori');
    if (modalKategori) {
        modalKategori.addEventListener('show.bs.modal', function (event) {
            const btn = event.relatedTarget;
            const terpakai = btn.getAttribute('data-terpakai') === '1';
            const selectTipe = document.getElementById('editKatTipe');
            const infoTipe = document.getElementById('editKatTipeInfo');

            document.getElementById('editKatId').value = btn.getAttribute('data-id');
            document.getElementById('editKatNama').value = btn.getAttribute('data-nama');
            selectTipe.value = btn.getAttribute('data-tipe');

            selectTipe.disabled = terpakai;
            if (terpakai) {
                infoTipe.classList.remove('d-none');
            } else {
                infoTipe.classList.add('d-none');
            }
        });
    }
});
</script>
<?php renderFooter(); ?>

<?php
declare(strict_types=1);

require_once 'config.php';
require_once 'security.php';
require_once 'layout.php';

$userId = requireLogin();

// Ambil & validasi parameter filter serta halaman (GET)
$bulan = $_GET['bulan'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $bulan)) {
    $bulan = date('Y-m');
}
$filterKategori = max(0, (int)($_GET['kategori_id'] ?? 0));
$filterDompet   = max(0, (int)($_GET['dompet_id'] ?? 0));
$page           = max(1, (int)($_GET['page'] ?? 1));
$perPage        = 15; // Jumlah transaksi per halaman

// Helper untuk menyusun URL dengan mempertahankan filter aktif
function buildTransaksiUrl(string $bulan, int $kategoriId = 0, int $dompetId = 0, int $page = 1): string
{
    $params = ['bulan' => $bulan];
    if ($kategoriId > 0) {
        $params['kategori_id'] = $kategoriId;
    }
    if ($dompetId > 0) {
        $params['dompet_id'] = $dompetId;
    }
    if ($page > 1) {
        $params['page'] = $page;
    }
    return 'transaksi.php?' . http_build_query($params);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $aksi = $_POST['aksi'] ?? '';

    // Pertahankan state filter dari formulir POST jika ada
    $retBulan    = preg_match('/^\d{4}-\d{2}$/', $_POST['ret_bulan'] ?? '') ? $_POST['ret_bulan'] : $bulan;
    $retKategori = max(0, (int)($_POST['ret_kategori'] ?? $filterKategori));
    $retDompet   = max(0, (int)($_POST['ret_dompet'] ?? $filterDompet));
    $retPage     = max(1, (int)($_POST['ret_page'] ?? $page));

    // 1. Tambah Transaksi Baru
    if ($aksi === 'tambah') {
        $dompetId   = (int)($_POST['dompet_id'] ?? 0);
        $kategoriId = (int)($_POST['kategori_id'] ?? 0);
        $jumlah     = (float)($_POST['jumlah'] ?? 0);
        $tanggal    = $_POST['tanggal'] ?? date('Y-m-d');
        $keterangan = trim($_POST['keterangan'] ?? '');

        $cekDompet = $pdo->prepare('SELECT id FROM dompet WHERE id = ? AND user_id = ?');
        $cekDompet->execute([$dompetId, $userId]);

        $cekKat = $pdo->prepare('SELECT id, tipe FROM kategori WHERE id = ? AND user_id = ?');
        $cekKat->execute([$kategoriId, $userId]);
        $kat = $cekKat->fetch();

        if ($cekDompet->fetch() && $kat && $jumlah > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            $pdo->beginTransaction();
            try {
                $tipe = $kat['tipe'];
                $stmt = $pdo->prepare('INSERT INTO transaksi (user_id, dompet_id, kategori_id, tipe, jumlah, keterangan, tanggal) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$userId, $dompetId, $kategoriId, $tipe, $jumlah, $keterangan, $tanggal]);

                $operator = ($tipe === 'pemasukan') ? '+' : '-';
                $upd = $pdo->prepare("UPDATE dompet SET saldo = saldo {$operator} ? WHERE id = ? AND user_id = ?");
                $upd->execute([$jumlah, $dompetId, $userId]);

                $pdo->commit();
                setFlash('success', 'Transaksi berhasil dicatat dan saldo dompet diperbarui.');
                header('Location: ' . buildTransaksiUrl(substr($tanggal, 0, 7), $retKategori, $retDompet, 1));
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                setFlash('danger', 'Gagal menyimpan transaksi.');
            }
        } else {
            setFlash('danger', 'Input transaksi tidak valid.');
        }
        header('Location: ' . buildTransaksiUrl($retBulan, $retKategori, $retDompet, $retPage));
        exit;
    }

    // 2. Edit Transaksi (Koreksi Input & Penyesuaian Saldo Otomatis)
    if ($aksi === 'edit') {
        $transaksiId = (int)($_POST['transaksi_id'] ?? 0);
        $dompetId    = (int)($_POST['dompet_id'] ?? 0);
        $kategoriId  = (int)($_POST['kategori_id'] ?? 0);
        $jumlah      = (float)($_POST['jumlah'] ?? 0);
        $tanggal     = $_POST['tanggal'] ?? '';
        $keterangan  = trim($_POST['keterangan'] ?? '');

        $stmtOld = $pdo->prepare('SELECT id, dompet_id, tipe, jumlah FROM transaksi WHERE id = ? AND user_id = ?');
        $stmtOld->execute([$transaksiId, $userId]);
        $oldTrx = $stmtOld->fetch();

        $cekDompet = $pdo->prepare('SELECT id FROM dompet WHERE id = ? AND user_id = ?');
        $cekDompet->execute([$dompetId, $userId]);

        $cekKat = $pdo->prepare('SELECT id, tipe FROM kategori WHERE id = ? AND user_id = ?');
        $cekKat->execute([$kategoriId, $userId]);
        $newKat = $cekKat->fetch();

        if ($oldTrx && $cekDompet->fetch() && $newKat && $jumlah > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            $pdo->beginTransaction();
            try {
                // Kembalikan saldo dompet lama
                $revertOp = ($oldTrx['tipe'] === 'pemasukan') ? '-' : '+';
                $stmtRevert = $pdo->prepare("UPDATE dompet SET saldo = saldo {$revertOp} ? WHERE id = ? AND user_id = ?");
                $stmtRevert->execute([$oldTrx['jumlah'], $oldTrx['dompet_id'], $userId]);

                // Perbarui data transaksi
                $newTipe = $newKat['tipe'];
                $stmtUpd = $pdo->prepare('
                    UPDATE transaksi
                    SET dompet_id = ?, kategori_id = ?, tipe = ?, jumlah = ?, keterangan = ?, tanggal = ?
                    WHERE id = ? AND user_id = ?
                ');
                $stmtUpd->execute([$dompetId, $kategoriId, $newTipe, $jumlah, $keterangan, $tanggal, $transaksiId, $userId]);

                // Terapkan nominal baru ke saldo dompet tujuan
                $applyOp = ($newTipe === 'pemasukan') ? '+' : '-';
                $stmtApply = $pdo->prepare("UPDATE dompet SET saldo = saldo {$applyOp} ? WHERE id = ? AND user_id = ?");
                $stmtApply->execute([$jumlah, $dompetId, $userId]);

                $pdo->commit();
                setFlash('success', 'Transaksi berhasil diperbarui dan saldo dompet telah disesuaikan.');
                header('Location: ' . buildTransaksiUrl(substr($tanggal, 0, 7), $retKategori, $retDompet, $retPage));
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                setFlash('danger', 'Gagal memperbarui transaksi.');
            }
        } else {
            setFlash('danger', 'Data pembaruan transaksi tidak valid.');
        }
        header('Location: ' . buildTransaksiUrl($retBulan, $retKategori, $retDompet, $retPage));
        exit;
    }

    // 3. Hapus Transaksi
    if ($aksi === 'hapus') {
        $transaksiId = (int)($_POST['transaksi_id'] ?? 0);
        $stmtGet = $pdo->prepare('SELECT dompet_id, tipe, jumlah FROM transaksi WHERE id = ? AND user_id = ?');
        $stmtGet->execute([$transaksiId, $userId]);
        $trx = $stmtGet->fetch();

        if ($trx) {
            $pdo->beginTransaction();
            try {
                $operator = ($trx['tipe'] === 'pemasukan') ? '-' : '+';
                $upd = $pdo->prepare("UPDATE dompet SET saldo = saldo {$operator} ? WHERE id = ? AND user_id = ?");
                $upd->execute([$trx['jumlah'], $trx['dompet_id'], $userId]);

                $del = $pdo->prepare('DELETE FROM transaksi WHERE id = ? AND user_id = ?');
                $del->execute([$transaksiId, $userId]);

                $pdo->commit();
                setFlash('success', 'Transaksi dihapus dan saldo dompet telah disesuaikan kembali.');
            } catch (Exception $e) {
                $pdo->rollBack();
                setFlash('danger', 'Gagal menghapus transaksi.');
            }
        }
        header('Location: ' . buildTransaksiUrl($retBulan, $retKategori, $retDompet, $retPage));
        exit;
    }
}

// Ambil Daftar Dompet & Kategori untuk Dropdown Form & Filter
$stmtDompet = $pdo->prepare('SELECT id, nama_dompet, saldo FROM dompet WHERE user_id = ? ORDER BY id ASC');
$stmtDompet->execute([$userId]);
$dompetList = $stmtDompet->fetchAll();

$stmtKategori = $pdo->prepare('SELECT id, nama_kategori, tipe FROM kategori WHERE user_id = ? ORDER BY tipe DESC, nama_kategori ASC');
$stmtKategori->execute([$userId]);
$kategoriList = $stmtKategori->fetchAll();

// Susun Kueri Dinamis Berdasarkan Filter Aktif
$whereClauses = ["t.user_id = ?", "DATE_FORMAT(t.tanggal, '%Y-%m') = ?"];
$whereParams  = [$userId, $bulan];

if ($filterKategori > 0) {
    $whereClauses[] = "t.kategori_id = ?";
    $whereParams[]  = $filterKategori;
}

if ($filterDompet > 0) {
    $whereClauses[] = "t.dompet_id = ?";
    $whereParams[]  = $filterDompet;
}

$whereSql = implode(' AND ', $whereClauses);

// Hitung Total Data & Ringkasan Nominal Sesuai Filter (Untuk Pagination & Statistik)
$stmtStats = $pdo->prepare("
    SELECT
        COUNT(*) AS total_rows,
        COALESCE(SUM(CASE WHEN t.tipe = 'pemasukan' THEN t.jumlah ELSE 0 END), 0) AS total_masuk,
        COALESCE(SUM(CASE WHEN t.tipe = 'pengeluaran' THEN t.jumlah ELSE 0 END), 0) AS total_keluar
    FROM transaksi t
    WHERE {$whereSql}
");
$stmtStats->execute($whereParams);
$stats       = $stmtStats->fetch();
$totalRows   = (int)($stats['total_rows'] ?? 0);
$totalMasuk  = (float)($stats['total_masuk'] ?? 0);
$totalKeluar = (float)($stats['total_keluar'] ?? 0);

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

// Ambil Data Transaksi Sesuai Halaman (LIMIT & OFFSET)
$sqlTrx = "
    SELECT t.*, d.nama_dompet, k.nama_kategori
    FROM transaksi t
    JOIN dompet d ON t.dompet_id = d.id
    JOIN kategori k ON t.kategori_id = k.id
    WHERE {$whereSql}
    ORDER BY t.tanggal DESC, t.id DESC
    LIMIT ? OFFSET ?
";
$stmtTrx = $pdo->prepare($sqlTrx);
$bindIdx = 1;
foreach ($whereParams as $paramVal) {
    $stmtTrx->bindValue($bindIdx++, $paramVal, is_int($paramVal) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmtTrx->bindValue($bindIdx++, $perPage, PDO::PARAM_INT);
$stmtTrx->bindValue($bindIdx++, $offset, PDO::PARAM_INT);
$stmtTrx->execute();
$transaksiList = $stmtTrx->fetchAll();

$isFiltered = ($filterKategori > 0 || $filterDompet > 0);

renderHeader('Transaksi Keuangan', 'transaksi');
?>
<div class="row g-4">
    <!-- KOLOM KIRI: FORM TAMBAH TRANSAKSI -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-bold">Catat Transaksi Baru</div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="aksi" value="tambah">
                    <input type="hidden" name="ret_bulan" value="<?= e($bulan) ?>">
                    <input type="hidden" name="ret_kategori" value="<?= $filterKategori ?>">
                    <input type="hidden" name="ret_dompet" value="<?= $filterDompet ?>">

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Tanggal</label>
                        <input type="date" name="tanggal" value="<?= date('Y-m-d') ?>" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Kategori</label>
                        <select name="kategori_id" class="form-select" required>
                            <optgroup label="Pengeluaran">
                                <?php foreach ($kategoriList as $k): if ($k['tipe'] === 'pengeluaran'): ?>
                                    <option value="<?= (int)$k['id'] ?>" <?= $filterKategori === (int)$k['id'] ? 'selected' : '' ?>>
                                        <?= e($k['nama_kategori']) ?>
                                    </option>
                                <?php endif; endforeach; ?>
                            </optgroup>
                            <optgroup label="Pemasukan">
                                <?php foreach ($kategoriList as $k): if ($k['tipe'] === 'pemasukan'): ?>
                                    <option value="<?= (int)$k['id'] ?>" <?= $filterKategori === (int)$k['id'] ? 'selected' : '' ?>>
                                        <?= e($k['nama_kategori']) ?>
                                    </option>
                                <?php endif; endforeach; ?>
                            </optgroup>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Dompet / Sumber Dana</label>
                        <select name="dompet_id" class="form-select" required>
                            <?php foreach ($dompetList as $d): ?>
                                <option value="<?= (int)$d['id'] ?>" <?= $filterDompet === (int)$d['id'] ? 'selected' : '' ?>>
                                    <?= e($d['nama_dompet']) ?> (<?= rupiah($d['saldo']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Nominal (Rp)</label>
                        <input type="number" name="jumlah" min="1" step="1" class="form-control" placeholder="Contoh: 150000" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Keterangan</label>
                        <input type="text" name="keterangan" class="form-control" placeholder="Belanja mingguan / Tagihan PLN">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Simpan Transaksi</button>
                </form>
            </div>
        </div>
    </div>

    <!-- KOLOM KANAN: FILTER, RINGKASAN, TABEL RIWAYAT & PAGINATION -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <!-- Header & Bilah Filter -->
            <div class="card-header bg-white py-3">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="fw-bold">Riwayat Transaksi (<?= e($bulan) ?>)</span>
                    <?php if ($isFiltered): ?>
                        <a href="<?= e(buildTransaksiUrl($bulan, 0, 0, 1)) ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Reset Filter
                        </a>
                    <?php endif; ?>
                </div>

                <form method="GET" class="row g-2">
                    <div class="col-md-3">
                        <label class="form-label small text-muted mb-1">Bulan</label>
                        <input type="month" name="bulan" value="<?= e($bulan) ?>" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small text-muted mb-1">Filter Kategori</label>
                        <select name="kategori_id" class="form-select form-select-sm">
                            <option value="0">Semua Kategori</option>
                            <optgroup label="Pengeluaran">
                                <?php foreach ($kategoriList as $k): if ($k['tipe'] === 'pengeluaran'): ?>
                                    <option value="<?= (int)$k['id'] ?>" <?= $filterKategori === (int)$k['id'] ? 'selected' : '' ?>>
                                        <?= e($k['nama_kategori']) ?>
                                    </option>
                                <?php endif; endforeach; ?>
                            </optgroup>
                            <optgroup label="Pemasukan">
                                <?php foreach ($kategoriList as $k): if ($k['tipe'] === 'pemasukan'): ?>
                                    <option value="<?= (int)$k['id'] ?>" <?= $filterKategori === (int)$k['id'] ? 'selected' : '' ?>>
                                        <?= e($k['nama_kategori']) ?>
                                    </option>
                                <?php endif; endforeach; ?>
                            </optgroup>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted mb-1">Sumber Dana</label>
                        <select name="dompet_id" class="form-select form-select-sm">
                            <option value="0">Semua Sumber Dana</option>
                            <?php foreach ($dompetList as $d): ?>
                                <option value="<?= (int)$d['id'] ?>" <?= $filterDompet === (int)$d['id'] ? 'selected' : '' ?>>
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
                    Ditemukan <strong><?= $totalRows ?></strong> transaksi
                    <?= $isFiltered ? '<span class="badge bg-primary-subtle text-primary ms-1">Filter Aktif</span>' : '' ?>
                </span>
                <div class="d-flex flex-wrap gap-3">
                    <span>Masuk: <strong class="text-success">+ <?= rupiah($totalMasuk) ?></strong></span>
                    <span>Keluar: <strong class="text-danger">- <?= rupiah($totalKeluar) ?></strong></span>
                </div>
            </div>

            <!-- Tabel Data Transaksi -->
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Tanggal</th>
                            <th>Kategori & Keterangan</th>
                            <th>Sumber Dana</th>
                            <th class="text-end">Nominal</th>
                            <th class="text-center" style="width: 110px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transaksiList)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    <?= $isFiltered ? 'Tidak ada transaksi yang cocok dengan kombinasi filter yang dipilih.' : 'Belum ada transaksi pada bulan ini.' ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transaksiList as $t): ?>
                                <tr>
                                    <td class="small"><?= e($t['tanggal']) ?></td>
                                    <td>
                                        <div class="fw-semibold"><?= e($t['nama_kategori']) ?></div>
                                        <div class="small text-muted"><?= e($t['keterangan'] ?: '-') ?></div>
                                    </td>
                                    <td class="small"><?= e($t['nama_dompet']) ?></td>
                                    <td class="text-end fw-bold <?= $t['tipe'] === 'pemasukan' ? 'text-success' : 'text-danger' ?>">
                                        <?= $t['tipe'] === 'pemasukan' ? '+' : '-' ?> <?= rupiah($t['jumlah']) ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="d-inline-flex gap-1">
                                            <button type="button"
                                                    class="btn btn-outline-primary btn-sm"
                                                    title="Edit Transaksi"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalEditTransaksi"
                                                    data-id="<?= (int)$t['id'] ?>"
                                                    data-tanggal="<?= e($t['tanggal']) ?>"
                                                    data-kategori="<?= (int)$t['kategori_id'] ?>"
                                                    data-dompet="<?= (int)$t['dompet_id'] ?>"
                                                    data-jumlah="<?= (float)$t['jumlah'] ?>"
                                                    data-keterangan="<?= e($t['keterangan'] ?? '') ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Hapus transaksi ini? Saldo dompet akan disesuaikan kembali.');">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="aksi" value="hapus">
                                                <input type="hidden" name="transaksi_id" value="<?= (int)$t['id'] ?>">
                                                <input type="hidden" name="ret_bulan" value="<?= e($bulan) ?>">
                                                <input type="hidden" name="ret_kategori" value="<?= $filterKategori ?>">
                                                <input type="hidden" name="ret_dompet" value="<?= $filterDompet ?>">
                                                <input type="hidden" name="ret_page" value="<?= $page ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm" title="Hapus Transaksi">
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
                        Menampilkan <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalRows) ?> dari <?= $totalRows ?> transaksi
                    </small>
                    <nav aria-label="Navigasi halaman transaksi">
                        <ul class="pagination pagination-sm mb-0">
                            <!-- Tombol Sebelumnya -->
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= $page > 1 ? e(buildTransaksiUrl($bulan, $filterKategori, $filterDompet, $page - 1)) : '#' ?>">
                                    &laquo;
                                </a>
                            </li>

                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage   = min($totalPages, $page + 2);

                            if ($startPage > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?= e(buildTransaksiUrl($bulan, $filterKategori, $filterDompet, 1)) ?>">1</a>
                                </li>
                                <?php if ($startPage > 2): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= e(buildTransaksiUrl($bulan, $filterKategori, $filterDompet, $p)) ?>">
                                        <?= $p ?>
                                    </a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($endPage < $totalPages): ?>
                                <?php if ($endPage < $totalPages - 1): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?= e(buildTransaksiUrl($bulan, $filterKategori, $filterDompet, $totalPages)) ?>">
                                        <?= $totalPages ?>
                                    </a>
                                </li>
                            <?php endif; ?>

                            <!-- Tombol Berikutnya -->
                            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= $page < $totalPages ? e(buildTransaksiUrl($bulan, $filterKategori, $filterDompet, $page + 1)) : '#' ?>">
                                    &raquo;
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Edit Transaksi -->
<div class="modal fade" id="modalEditTransaksi" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="aksi" value="edit">
                <input type="hidden" name="transaksi_id" id="editTrxId">
                <input type="hidden" name="ret_bulan" value="<?= e($bulan) ?>">
                <input type="hidden" name="ret_kategori" value="<?= $filterKategori ?>">
                <input type="hidden" name="ret_dompet" value="<?= $filterDompet ?>">
                <input type="hidden" name="ret_page" value="<?= $page ?>">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Edit Transaksi</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Tanggal</label>
                        <input type="date" name="tanggal" id="editTrxTanggal" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Kategori</label>
                        <select name="kategori_id" id="editTrxKategori" class="form-select" required>
                            <optgroup label="Pengeluaran">
                                <?php foreach ($kategoriList as $k): if ($k['tipe'] === 'pengeluaran'): ?>
                                    <option value="<?= (int)$k['id'] ?>"><?= e($k['nama_kategori']) ?></option>
                                <?php endif; endforeach; ?>
                            </optgroup>
                            <optgroup label="Pemasukan">
                                <?php foreach ($kategoriList as $k): if ($k['tipe'] === 'pemasukan'): ?>
                                    <option value="<?= (int)$k['id'] ?>"><?= e($k['nama_kategori']) ?></option>
                                <?php endif; endforeach; ?>
                            </optgroup>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Dompet / Sumber Dana</label>
                        <select name="dompet_id" id="editTrxDompet" class="form-select" required>
                            <?php foreach ($dompetList as $d): ?>
                                <option value="<?= (int)$d['id'] ?>"><?= e($d['nama_dompet']) ?> (<?= rupiah($d['saldo']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Nominal (Rp)</label>
                        <input type="number" name="jumlah" id="editTrxJumlah" min="1" step="1" class="form-control" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Keterangan</label>
                        <input type="text" name="keterangan" id="editTrxKeterangan" class="form-control" placeholder="Opsional">
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
    const modalEdit = document.getElementById('modalEditTransaksi');
    if (modalEdit) {
        modalEdit.addEventListener('show.bs.modal', function (event) {
            const btn = event.relatedTarget;
            document.getElementById('editTrxId').value         = btn.getAttribute('data-id');
            document.getElementById('editTrxTanggal').value    = btn.getAttribute('data-tanggal');
            document.getElementById('editTrxKategori').value   = btn.getAttribute('data-kategori');
            document.getElementById('editTrxDompet').value     = btn.getAttribute('data-dompet');
            document.getElementById('editTrxJumlah').value     = btn.getAttribute('data-jumlah');
            document.getElementById('editTrxKeterangan').value = btn.getAttribute('data-keterangan');
        });
    }
});
</script>
<?php renderFooter(); ?>

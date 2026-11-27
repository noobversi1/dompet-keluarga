<?php
declare(strict_types=1);

require_once 'config.php';
require_once 'security.php';
require_once 'layout.php';

$userId = requireLogin();

/**
 * Menghitung ulang total rencana belanja per kategori & bulan,
 * lalu menyinkronkannya ke batas_anggaran di tabel budgeting.
 */
function sinkronkanBudgetKategori(PDO $pdo, int $userId, int $kategoriId, string $bulan): void
{
    $stmtSum = $pdo->prepare('
        SELECT COALESCE(SUM(nominal), 0)
        FROM rencana_belanja
        WHERE user_id = ? AND kategori_id = ? AND bulan = ?
    ');
    $stmtSum->execute([$userId, $kategoriId, $bulan]);
    $totalRencana = (float)$stmtSum->fetchColumn();

    if ($totalRencana > 0) {
        $stmtUpsert = $pdo->prepare('
            INSERT INTO budgeting (user_id, kategori_id, bulan, batas_anggaran)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE batas_anggaran = VALUES(batas_anggaran)
        ');
        $stmtUpsert->execute([$userId, $kategoriId, $bulan, $totalRencana]);
    } else {
        $stmtDel = $pdo->prepare('
            DELETE FROM budgeting
            WHERE user_id = ? AND kategori_id = ? AND bulan = ?
        ');
        $stmtDel->execute([$userId, $kategoriId, $bulan]);
    }
}

// Parameter Filter & Pagination
$bulan = $_GET['bulan'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $bulan)) {
    $bulan = date('Y-m');
}
$filterKategori = (int)($_GET['kategori_id'] ?? 0);
$page           = max(1, (int)($_GET['page'] ?? 1));
$perPage        = 15;

$queryRedirect = 'rencana_belanja.php?bulan=' . urlencode($bulan)
    . ($filterKategori > 0 ? '&kategori_id=' . $filterKategori : '')
    . ($page > 1 ? '&page=' . $page : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $aksi = $_POST['aksi'] ?? '';

    // =====================================================================
    // 1. TAMBAH ITEM RENCANA BELANJA
    // =====================================================================
    if ($aksi === 'tambah_rencana') {
        $bulanTarget = $_POST['bulan_target'] ?? $bulan;
        $kategoriId  = (int)($_POST['kategori_id'] ?? 0);
        $nominal     = (float)($_POST['nominal'] ?? 0);
        $keterangan  = trim($_POST['keterangan'] ?? '');

        $cekKat = $pdo->prepare("SELECT id FROM kategori WHERE id = ? AND user_id = ? AND tipe = 'pengeluaran'");
        $cekKat->execute([$kategoriId, $userId]);

        if (!preg_match('/^\d{4}-\d{2}$/', $bulanTarget) || !$cekKat->fetch() || $nominal <= 0 || $keterangan === '') {
            setFlash('danger', 'Pastikan bulan, kategori pengeluaran, nominal, dan keterangan barang diisi dengan benar.');
            header('Location: ' . $queryRedirect);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $stmtIns = $pdo->prepare('
                INSERT INTO rencana_belanja (user_id, kategori_id, bulan, nominal, keterangan)
                VALUES (?, ?, ?, ?, ?)
            ');
            $stmtIns->execute([$userId, $kategoriId, $bulanTarget, $nominal, $keterangan]);

            sinkronkanBudgetKategori($pdo, $userId, $kategoriId, $bulanTarget);

            $pdo->commit();
            setFlash('success', 'Rencana belanja ditambahkan dan nominal budget kategori otomatis diperbarui.');
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', 'Gagal menyimpan rencana belanja.');
        }

        $redirectBaru = 'rencana_belanja.php?bulan=' . urlencode($bulanTarget)
            . ($filterKategori > 0 ? '&kategori_id=' . $filterKategori : '');
        header('Location: ' . $redirectBaru);
        exit;
    }

    // =====================================================================
    // 2. EDIT ITEM RENCANA BELANJA
    // =====================================================================
    if ($aksi === 'edit_rencana') {
        $rencanaId   = (int)($_POST['rencana_id'] ?? 0);
        $kategoriId  = (int)($_POST['kategori_id'] ?? 0);
        $nominal     = (float)($_POST['nominal'] ?? 0);
        $keterangan  = trim($_POST['keterangan'] ?? '');

        $stmtLama = $pdo->prepare('SELECT id, kategori_id, bulan FROM rencana_belanja WHERE id = ? AND user_id = ?');
        $stmtLama->execute([$rencanaId, $userId]);
        $itemLama = $stmtLama->fetch();

        $cekKat = $pdo->prepare("SELECT id FROM kategori WHERE id = ? AND user_id = ? AND tipe = 'pengeluaran'");
        $cekKat->execute([$kategoriId, $userId]);

        if (!$itemLama || !$cekKat->fetch() || $nominal <= 0 || $keterangan === '') {
            setFlash('danger', 'Data pembaruan rencana belanja tidak valid.');
            header('Location: ' . $queryRedirect);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $stmtUpd = $pdo->prepare('
                UPDATE rencana_belanja
                SET kategori_id = ?, nominal = ?, keterangan = ?
                WHERE id = ? AND user_id = ?
            ');
            $stmtUpd->execute([$kategoriId, $nominal, $keterangan, $rencanaId, $userId]);

            // Sinkronkan kategori baru & kategori lama (jika kategori diubah)
            sinkronkanBudgetKategori($pdo, $userId, $kategoriId, $itemLama['bulan']);
            if ((int)$itemLama['kategori_id'] !== $kategoriId) {
                sinkronkanBudgetKategori($pdo, $userId, (int)$itemLama['kategori_id'], $itemLama['bulan']);
            }

            $pdo->commit();
            setFlash('success', 'Item rencana belanja diperbarui dan akumulasi budget disesuaikan.');
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', 'Gagal memperbarui item rencana belanja.');
        }
        header('Location: ' . $queryRedirect);
        exit;
    }

    // =====================================================================
    // 3. HAPUS ITEM RENCANA BELANJA
    // =====================================================================
    if ($aksi === 'hapus_rencana') {
        $rencanaId = (int)($_POST['rencana_id'] ?? 0);

        $stmtLama = $pdo->prepare('SELECT id, kategori_id, bulan FROM rencana_belanja WHERE id = ? AND user_id = ?');
        $stmtLama->execute([$rencanaId, $userId]);
        $itemLama = $stmtLama->fetch();

        if ($itemLama) {
            $pdo->beginTransaction();
            try {
                $stmtDel = $pdo->prepare('DELETE FROM rencana_belanja WHERE id = ? AND user_id = ?');
                $stmtDel->execute([$rencanaId, $userId]);

                sinkronkanBudgetKategori($pdo, $userId, (int)$itemLama['kategori_id'], $itemLama['bulan']);

                $pdo->commit();
                setFlash('success', 'Item rencana belanja dihapus dan nominal budget telah dihitung ulang.');
            } catch (Exception $e) {
                $pdo->rollBack();
                setFlash('danger', 'Gagal menghapus item rencana belanja.');
            }
        }
        header('Location: ' . $queryRedirect);
        exit;
    }
}

// Ambil Daftar Kategori Pengeluaran
$stmtKat = $pdo->prepare("SELECT id, nama_kategori FROM kategori WHERE user_id = ? AND tipe = 'pengeluaran' ORDER BY nama_kategori ASC");
$stmtKat->execute([$userId]);
$kategoriPengeluaran = $stmtKat->fetchAll();

// Ambil Akumulasi Rencana Belanja per Kategori (Otomatis Jadi Budget)
$stmtAkumulasi = $pdo->prepare('
    SELECT rb.kategori_id, k.nama_kategori,
           COUNT(rb.id) AS jumlah_item,
           COALESCE(SUM(rb.nominal), 0) AS total_nominal
    FROM rencana_belanja rb
    JOIN kategori k ON rb.kategori_id = k.id
    WHERE rb.user_id = ? AND rb.bulan = ?
    GROUP BY rb.kategori_id, k.nama_kategori
    ORDER BY total_nominal DESC, k.nama_kategori ASC
');
$stmtAkumulasi->execute([$userId, $bulan]);
$akumulasiKategori = $stmtAkumulasi->fetchAll();

$totalSeluruhRencana = array_sum(array_column($akumulasiKategori, 'total_nominal'));
$totalSeluruhItem    = array_sum(array_column($akumulasiKategori, 'jumlah_item'));

// Query Daftar Rincian Item Rencana Belanja (dengan Filter & Pagination)
$whereSql = 'WHERE rb.user_id = ? AND rb.bulan = ?';
$params   = [$userId, $bulan];

if ($filterKategori > 0) {
    $whereSql .= ' AND rb.kategori_id = ?';
    $params[]  = $filterKategori;
}

$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM rencana_belanja rb $whereSql");
$stmtCount->execute($params);
$totalRows  = (int)$stmtCount->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$sqlItems = "
    SELECT rb.id, rb.kategori_id, rb.bulan, rb.nominal, rb.keterangan, k.nama_kategori
    FROM rencana_belanja rb
    JOIN kategori k ON rb.kategori_id = k.id
    $whereSql
    ORDER BY k.nama_kategori ASC, rb.id DESC
    LIMIT ? OFFSET ?
";
$stmtItems = $pdo->prepare($sqlItems);
foreach ($params as $idx => $val) {
    $stmtItems->bindValue($idx + 1, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmtItems->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
$stmtItems->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
$stmtItems->execute();
$daftarRencana = $stmtItems->fetchAll();

renderHeader('Rencana Belanja & Penyusunan Budget', 'rencana_belanja');
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h3 class="fw-bold mb-0">Rencana Belanja Rumah Tangga</h3>
        <span class="text-muted small">Susun daftar kebutuhan belanja bulanan — total per kategori otomatis menjadi nominal Budget</span>
    </div>
    <a href="budgeting.php?bulan=<?= urlencode($bulan) ?>" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-pie-chart me-1"></i>Lihat Hasil di Budgeting
    </a>
</div>

<!-- RINGKASAN AKUMULASI RENCANA PER KATEGORI -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-bold d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="bi bi-calculator me-2 text-primary"></i>Akumulasi Budget dari Rencana Belanja (<?= e($bulan) ?>)</span>
        <span class="badge bg-primary fs-6">Total Budget: <?= rupiah($totalSeluruhRencana) ?> (<?= (int)$totalSeluruhItem ?> item)</span>
    </div>
    <div class="card-body">
        <?php if (empty($akumulasiKategori)): ?>
            <p class="text-muted small mb-0">Belum ada rencana belanja pada bulan <?= e($bulan) ?>. Tambahkan item kebutuhan di bawah untuk membentuk anggaran secara otomatis.</p>
        <?php else: ?>
            <div class="row g-2">
                <?php foreach ($akumulasiKategori as $ak): ?>
                    <div class="col-md-4 col-sm-6">
                        <a href="rencana_belanja.php?bulan=<?= urlencode($bulan) ?>&kategori_id=<?= (int)$ak['kategori_id'] ?>"
                           class="text-decoration-none">
                            <div class="border rounded p-2 px-3 h-100 d-flex justify-content-between align-items-center <?= $filterKategori === (int)$ak['kategori_id'] ? 'border-primary bg-primary-subtle' : 'bg-light' ?>">
                                <div>
                                    <div class="fw-semibold text-dark small"><?= e($ak['nama_kategori']) ?></div>
                                    <span class="text-muted" style="font-size: 0.75rem;"><?= (int)$ak['jumlah_item'] ?> item rencana</span>
                                </div>
                                <span class="fw-bold text-primary small"><?= rupiah($ak['total_nominal']) ?></span>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <!-- KOLOM KIRI: FORM TAMBAH RENCANA BELANJA -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-bold">
                <i class="bi bi-cart-plus me-1 text-primary"></i>Tambah Rencana Belanja
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="aksi" value="tambah_rencana">

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Periode Bulan Budget</label>
                        <input type="month" name="bulan_target" value="<?= e($bulan) ?>" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Kategori Pengeluaran</label>
                        <select name="kategori_id" class="form-select" required>
                            <?php foreach ($kategoriPengeluaran as $kp): ?>
                                <option value="<?= (int)$kp['id'] ?>" <?= $filterKategori === (int)$kp['id'] ? 'selected' : '' ?>>
                                    <?= e($kp['nama_kategori']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Nominal Estimasi (Rp)</label>
                        <input type="number" name="nominal" min="100" step="100" class="form-control" placeholder="Contoh: 250000" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Keterangan / Barang yang Dibeli</label>
                        <input type="text" name="keterangan" class="form-control" placeholder="Contoh: Beras 10kg & Minyak Goreng" required>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-plus-circle me-1"></i>Simpan ke Rencana & Budget
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- KOLOM KANAN: DAFTAR ITEM RENCANA BELANJA -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <form method="GET" class="row g-2 align-items-center">
                    <div class="col-md-4">
                        <input type="month" name="bulan" value="<?= e($bulan) ?>" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-5">
                        <select name="kategori_id" class="form-select form-select-sm">
                            <option value="0">-- Semua Kategori --</option>
                            <?php foreach ($kategoriPengeluaran as $kp): ?>
                                <option value="<?= (int)$kp['id'] ?>" <?= $filterKategori === (int)$kp['id'] ? 'selected' : '' ?>>
                                    <?= e($kp['nama_kategori']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex gap-1">
                        <button type="submit" class="btn btn-secondary btn-sm flex-fill">Filter</button>
                        <?php if ($filterKategori > 0): ?>
                            <a href="rencana_belanja.php?bulan=<?= urlencode($bulan) ?>" class="btn btn-outline-secondary btn-sm" title="Reset Filter">Reset</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Kategori</th>
                            <th>Keterangan / Rencana Barang</th>
                            <th class="text-end">Estimasi Nominal</th>
                            <th class="text-center" style="width: 110px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($daftarRencana)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">
                                    Belum ada item rencana belanja untuk filter yang dipilih.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($daftarRencana as $r): ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                            <?= e($r['nama_kategori']) ?>
                                        </span>
                                    </td>
                                    <td class="fw-semibold"><?= e($r['keterangan']) ?></td>
                                    <td class="text-end fw-bold text-dark"><?= rupiah($r['nominal']) ?></td>
                                    <td class="text-center">
                                        <div class="d-inline-flex gap-1">
                                            <button type="button"
                                                    class="btn btn-outline-primary btn-sm"
                                                    title="Edit Item"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalEditRencana"
                                                    data-id="<?= (int)$r['id'] ?>"
                                                    data-kategori="<?= (int)$r['kategori_id'] ?>"
                                                    data-nominal="<?= (float)$r['nominal'] ?>"
                                                    data-keterangan="<?= e($r['keterangan']) ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Hapus item rencana \"<?= e($r['keterangan']) ?>\"? Nominal budget kategori akan otomatis dikurangi.');">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="aksi" value="hapus_rencana">
                                                <input type="hidden" name="rencana_id" value="<?= (int)$r['id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm" title="Hapus Item">
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

            <?php if ($totalPages > 1): ?>
                <div class="card-footer bg-white d-flex justify-content-between align-items-center">
                    <small class="text-muted">Halaman <?= $page ?> dari <?= $totalPages ?> (Total <?= $totalRows ?> item)</small>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <?php for ($p = 1; $p <= $totalPages; $p++):
                                $linkPage = 'rencana_belanja.php?bulan=' . urlencode($bulan)
                                    . ($filterKategori > 0 ? '&kategori_id=' . $filterKategori : '')
                                    . '&page=' . $p;
                            ?>
                                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= e($linkPage) ?>"><?= $p ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- MODAL EDIT ITEM RENCANA BELANJA -->
<div class="modal fade" id="modalEditRencana" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="aksi" value="edit_rencana">
                <input type="hidden" name="rencana_id" id="editRencanaId">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Edit Item Rencana Belanja</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Kategori Pengeluaran</label>
                        <select name="kategori_id" id="editRencanaKategori" class="form-select" required>
                            <?php foreach ($kategoriPengeluaran as $kp): ?>
                                <option value="<?= (int)$kp['id'] ?>"><?= e($kp['nama_kategori']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Nominal Estimasi (Rp)</label>
                        <input type="number" name="nominal" id="editRencanaNominal" min="100" step="100" class="form-control" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Keterangan / Barang yang Dibeli</label>
                        <input type="text" name="keterangan" id="editRencanaKeterangan" class="form-control" required>
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
    const modalEdit = document.getElementById('modalEditRencana');
    if (modalEdit) {
        modalEdit.addEventListener('show.bs.modal', function (event) {
            const btn = event.relatedTarget;
            document.getElementById('editRencanaId').value = btn.getAttribute('data-id');
            document.getElementById('editRencanaKategori').value = btn.getAttribute('data-kategori');
            document.getElementById('editRencanaNominal').value = btn.getAttribute('data-nominal');
            document.getElementById('editRencanaKeterangan').value = btn.getAttribute('data-keterangan');
        });
    }
});
</script>
<?php renderFooter(); ?>

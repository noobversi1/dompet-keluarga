<?php
declare(strict_types=1);

require_once 'config.php';
require_once 'security.php';
require_once 'layout.php';

$userId = requireLogin();
$bulan = $_GET['bulan'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $bulan)) {
    $bulan = date('Y-m');
}

// 1. Saldo Semua Dompet (Aset Likuid) - diurutkan dari saldo terbesar
$stmtDompet = $pdo->prepare('SELECT id, nama_dompet, saldo FROM dompet WHERE user_id = ? ORDER BY saldo DESC, id ASC');
$stmtDompet->execute([$userId]);
$daftarDompet = $stmtDompet->fetchAll();
$totalSaldo   = array_sum(array_column($daftarDompet, 'saldo'));
$top3Dompet   = array_slice($daftarDompet, 0, 3);

// 2. Total Dana Tabungan Terkumpul
$stmtTabungan = $pdo->prepare('SELECT COALESCE(SUM(dana_terkumpul), 0) FROM target_tabungan WHERE user_id = ?');
$stmtTabungan->execute([$userId]);
$totalTabungan = (float)$stmtTabungan->fetchColumn();

// 3. Total Nilai Portofolio Investasi
$stmtInv = $pdo->prepare('
    SELECT COALESCE(SUM(modal_pokok), 0) AS total_modal,
           COALESCE(SUM(nilai_pasar), 0) AS total_pasar
    FROM investasi
    WHERE user_id = ?
');
$stmtInv->execute([$userId]);
$invSummary    = $stmtInv->fetch();
$totalModalInv = (float)($invSummary['total_modal'] ?? 0);
$totalPasarInv = (float)($invSummary['total_pasar'] ?? 0);
$gainLossInv   = $totalPasarInv - $totalModalInv;

// Total Kekayaan Bersih Keluarga (Net Worth)
$totalKekayaanBersih = $totalSaldo + $totalTabungan + $totalPasarInv;

// 4. Ringkasan Pemasukan & Pengeluaran Bulan Terpilih
$stmtRingkas = $pdo->prepare("
    SELECT tipe, COALESCE(SUM(jumlah), 0) AS total
    FROM transaksi
    WHERE user_id = ? AND DATE_FORMAT(tanggal, '%Y-%m') = ?
    GROUP BY tipe
");
$stmtRingkas->execute([$userId, $bulan]);
$pemasukan = 0.0;
$pengeluaran = 0.0;
foreach ($stmtRingkas->fetchAll() as $row) {
    if ($row['tipe'] === 'pemasukan') $pemasukan = (float)$row['total'];
    if ($row['tipe'] === 'pengeluaran') $pengeluaran = (float)$row['total'];
}

// 5. Pengeluaran per Kategori (Untuk Grafik Chart.js)
$stmtChart = $pdo->prepare("
    SELECT k.nama_kategori, COALESCE(SUM(t.jumlah), 0) AS total
    FROM transaksi t
    JOIN kategori k ON t.kategori_id = k.id
    WHERE t.user_id = ? AND t.tipe = 'pengeluaran' AND DATE_FORMAT(t.tanggal, '%Y-%m') = ?
    GROUP BY k.id, k.nama_kategori
    ORDER BY total DESC
");
$stmtChart->execute([$userId, $bulan]);
$chartData = $stmtChart->fetchAll();

// 6. Progress Budgeting Bulanan
$stmtBudget = $pdo->prepare("
    SELECT b.batas_anggaran, k.nama_kategori,
           COALESCE(SUM(t.jumlah), 0) AS terpakai
    FROM budgeting b
    JOIN kategori k ON b.kategori_id = k.id
    LEFT JOIN transaksi t ON t.kategori_id = b.kategori_id
         AND t.user_id = b.user_id
         AND t.tipe = 'pengeluaran'
         AND DATE_FORMAT(t.tanggal, '%Y-%m') = b.bulan
    WHERE b.user_id = ? AND b.bulan = ?
    GROUP BY b.id, b.batas_anggaran, k.nama_kategori
    ORDER BY (COALESCE(SUM(t.jumlah), 0) / b.batas_anggaran) DESC
");
$stmtBudget->execute([$userId, $bulan]);
$daftarBudget = $stmtBudget->fetchAll();

renderHeader('Dashboard Keuangan', 'dashboard');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h3 class="fw-bold mb-0">Ringkasan Keuangan Rumah Tangga</h3>
        <span class="text-muted small">Periode Laporan Arus Kas: <?= e($bulan) ?></span>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <form method="GET" class="d-flex gap-2">
            <input type="month" name="bulan" value="<?= e($bulan) ?>" class="form-control form-control-sm">
            <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
        </form>
        <a href="export_excel.php?bulan=<?= urlencode($bulan) ?>" class="btn btn-success btn-sm">
            <i class="bi bi-file-earmark-excel me-1"></i>Export Excel
        </a>
        <a href="export_pdf.php?bulan=<?= urlencode($bulan) ?>" target="_blank" class="btn btn-danger btn-sm">
            <i class="bi bi-file-earmark-pdf me-1"></i>Laporan PDF
        </a>
    </div>
</div>

<!-- RINGKASAN TOTAL KEKAYAAN BERSIH (NET WORTH) -->
<div class="card border-0 shadow-sm mb-4 bg-white">
    <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-safe2 me-2 text-primary"></i>Ringkasan Kekayaan Bersih Keluarga (Net Worth)</span>
        <span class="badge bg-primary fs-6"><?= rupiah($totalKekayaanBersih) ?></span>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <div class="border rounded p-3 h-100 d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="text-muted small">Kas & Rekening Likuid</span>
                        <i class="bi bi-wallet2 text-primary"></i>
                    </div>
                    <h5 class="fw-bold text-primary mb-2"><?= rupiah($totalSaldo) ?></h5>
                    <div class="border-top pt-2 small mb-2">
                        <?php if (empty($top3Dompet)): ?>
                            <span class="text-muted">Belum ada dompet terdaftar.</span>
                        <?php else: ?>
                            <?php foreach ($top3Dompet as $d): ?>
                                <div class="d-flex justify-content-between text-muted">
                                    <span><?= e($d['nama_dompet']) ?></span>
                                    <span class="fw-semibold text-dark"><?= rupiah($d['saldo']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <?php if (count($daftarDompet) > 3): ?>
                        <a href="budgeting.php" class="small text-decoration-none mt-auto">
                            Lihat semua (<?= count($daftarDompet) ?> dompet) &rarr;
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-4">
                <div class="border rounded p-3 h-100 d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="text-muted small">Dana Tabungan Terkumpul</span>
                        <i class="bi bi-piggy-bank text-success"></i>
                    </div>
                    <h5 class="fw-bold text-success mb-2"><?= rupiah($totalTabungan) ?></h5>
                    <p class="small text-muted mb-2">Dana yang dialokasikan pada pos tujuan tabungan & dana darurat keluarga.</p>
                    <a href="tabungan.php" class="small text-decoration-none mt-auto">Kelola Target Tabungan &rarr;</a>
                </div>
            </div>
            <div class="col-md-4">
                <div class="border rounded p-3 h-100 d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="text-muted small">Portofolio Investasi (Nilai Pasar)</span>
                        <i class="bi bi-graph-up-arrow text-info"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-1"><?= rupiah($totalPasarInv) ?></h5>
                    <div class="small mb-2">
                        Modal: <span class="fw-semibold"><?= rupiah($totalModalInv) ?></span> |
                        <span class="fw-bold <?= $gainLossInv >= 0 ? 'text-success' : 'text-danger' ?>">
                            <?= ($gainLossInv >= 0 ? '+' : '-') . rupiah(abs($gainLossInv)) ?>
                        </span>
                    </div>
                    <a href="investasi.php" class="small text-decoration-none mt-auto">Kelola Portofolio Investasi &rarr;</a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- KARTU ARUS KAS BULANAN -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <span class="text-muted small">Pemasukan (<?= e($bulan) ?>)</span>
                <h4 class="fw-bold text-success mt-1">+ <?= rupiah($pemasukan) ?></h4>
                <p class="small text-muted mb-0 mt-2">Gaji, bonus, dividen & profit investasi bulan ini.</p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <span class="text-muted small">Pengeluaran (<?= e($bulan) ?>)</span>
                <h4 class="fw-bold text-danger mt-1">- <?= rupiah($pengeluaran) ?></h4>
                <p class="small text-muted mb-0 mt-2">Total belanja & biaya operasional bulan ini.</p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <span class="text-muted small">Selisih Arus Kas (<?= e($bulan) ?>)</span>
                <?php $selisihBulan = $pemasukan - $pengeluaran; ?>
                <h4 class="fw-bold mt-1 <?= $selisihBulan >= 0 ? 'text-primary' : 'text-danger' ?>">
                    <?= ($selisihBulan >= 0 ? '+' : '-') . ' ' . rupiah(abs($selisihBulan)) ?>
                </h4>
                <p class="small text-muted mb-0 mt-2">Surplus/defisit arus kas bersih periode berjalan.</p>
            </div>
        </div>
    </div>
</div>

<!-- MONITORING BUDGETING & GRAFIK PENGELUARAN -->
<div class="row g-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                <span>Monitoring Anggaran (Budgeting)</span>
                <a href="budgeting.php?bulan=<?= urlencode($bulan) ?>" class="btn btn-outline-primary btn-sm">Atur Budget</a>
            </div>
            <div class="card-body">
                <?php if (empty($daftarBudget)): ?>
                    <p class="text-muted small mb-0">Belum ada anggaran yang diatur untuk bulan <?= e($bulan) ?>.</p>
                <?php else: ?>
                    <?php foreach ($daftarBudget as $b):
                        $persen = $b['batas_anggaran'] > 0 ? round(($b['terpakai'] / $b['batas_anggaran']) * 100, 1) : 0;
                        $warna = $persen >= 100 ? 'bg-danger' : ($persen >= 80 ? 'bg-warning' : 'bg-success');
                    ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between small mb-1">
                                <span class="fw-semibold"><?= e($b['nama_kategori']) ?></span>
                                <span><?= rupiah($b['terpakai']) ?> / <?= rupiah($b['batas_anggaran']) ?> (<?= $persen ?>%)</span>
                            </div>
                            <div class="progress" style="height: 10px;">
                                <div class="progress-bar <?= $warna ?>" style="width: <?= min(100, $persen) ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-bold">Proporsi Pengeluaran per Kategori</div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <?php if (empty($chartData)): ?>
                    <p class="text-muted small mb-0">Belum ada data pengeluaran pada bulan ini.</p>
                <?php else: ?>
                    <canvas id="expenseChart" style="max-height: 260px;"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($chartData)): ?>
<script>
const ctx = document.getElementById('expenseChart');
new Chart(ctx, {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_column($chartData, 'nama_kategori')) ?>,
        datasets: [{
            data: <?= json_encode(array_map('floatval', array_column($chartData, 'total'))) ?>,
            backgroundColor: ['#0d6efd', '#dc3545', '#ffc107', '#198754', '#6f42c1', '#fd7e14', '#20c997', '#6c757d']
        }]
    },
    options: { plugins: { legend: { position: 'bottom' } } }
});
</script>
<?php endif; ?>
<?php renderFooter(); ?>

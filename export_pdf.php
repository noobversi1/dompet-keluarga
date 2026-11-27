<?php
declare(strict_types=1);

require_once 'config.php';
require_once 'security.php';

$userId = requireLogin();
$bulan = $_GET['bulan'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $bulan)) {
    $bulan = date('Y-m');
}

$namaUser = $_SESSION['user_nama'] ?? 'Keluarga';

// 1. Ambil Rincian Seluruh Transaksi Bulan Terpilih
$stmt = $pdo->prepare("
    SELECT t.tanggal, k.nama_kategori, t.tipe, d.nama_dompet, t.jumlah, t.keterangan
    FROM transaksi t
    JOIN kategori k ON t.kategori_id = k.id
    JOIN dompet d ON t.dompet_id = d.id
    WHERE t.user_id = ? AND DATE_FORMAT(t.tanggal, '%Y-%m') = ?
    ORDER BY t.tanggal ASC, t.id ASC
");
$stmt->execute([$userId, $bulan]);
$transaksi = $stmt->fetchAll();

// 2. Ambil Rincian Total per Kategori (Pemasukan & Pengeluaran)
$stmtKategori = $pdo->prepare("
    SELECT k.nama_kategori, t.tipe, COUNT(t.id) AS jumlah_trx, COALESCE(SUM(t.jumlah), 0) AS total
    FROM transaksi t
    JOIN kategori k ON t.kategori_id = k.id
    WHERE t.user_id = ? AND DATE_FORMAT(t.tanggal, '%Y-%m') = ?
    GROUP BY k.id, k.nama_kategori, t.tipe
    ORDER BY total DESC
");
$stmtKategori->execute([$userId, $bulan]);
$ringkasanKategori = $stmtKategori->fetchAll();

$kategoriMasuk  = [];
$kategoriKeluar = [];
foreach ($ringkasanKategori as $rk) {
    if ($rk['tipe'] === 'pemasukan') {
        $kategoriMasuk[] = $rk;
    } else {
        $kategoriKeluar[] = $rk;
    }
}

// 3. Ambil Ringkasan Realisasi Budgeting
$stmtBudget = $pdo->prepare("
    SELECT b.batas_anggaran, k.nama_kategori, COALESCE(SUM(t.jumlah), 0) AS terpakai
    FROM budgeting b
    JOIN kategori k ON b.kategori_id = k.id
    LEFT JOIN transaksi t ON t.kategori_id = b.kategori_id
         AND t.user_id = b.user_id AND t.tipe = 'pengeluaran'
         AND DATE_FORMAT(t.tanggal, '%Y-%m') = b.bulan
    WHERE b.user_id = ? AND b.bulan = ?
    GROUP BY b.id, b.batas_anggaran, k.nama_kategori
    ORDER BY k.nama_kategori ASC
");
$stmtBudget->execute([$userId, $bulan]);
$budgets = $stmtBudget->fetchAll();

// Hitung Total Keseluruhan
$totalMasuk  = 0.0;
$totalKeluar = 0.0;
foreach ($transaksi as $t) {
    if ($t['tipe'] === 'pemasukan') {
        $totalMasuk += (float)$t['jumlah'];
    } else {
        $totalKeluar += (float)$t['jumlah'];
    }
}
$selisihKas = $totalMasuk - $totalKeluar;

ob_start();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Laporan Keuangan <?= e($bulan) ?></title>
<style>
    body { font-family: Arial, sans-serif; font-size: 11.5px; color: #222; margin: 18px; }
    h2 { margin: 0 0 4px 0; color: #0d6efd; font-size: 18px; }
    h4 { margin: 16px 0 6px 0; color: #1e293b; font-size: 13px; border-bottom: 1px solid #cbd5e1; padding-bottom: 3px; }
    .meta { color: #555; margin-bottom: 14px; font-size: 11px; }
    table.data-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    table.data-table th, table.data-table td { border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; }
    table.data-table th { background-color: #f1f5f9; font-weight: bold; color: #334155; }
    table.data-table tfoot td { background-color: #f8fafc; font-weight: bold; }
    table.layout-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
    table.layout-table td.col-half { width: 49%; vertical-align: top; border: none; padding: 0; }
    table.layout-table td.col-spacer { width: 2%; border: none; padding: 0; }
    .right { text-align: right !important; }
    .center { text-align: center !important; }
    .masuk { color: #198754; font-weight: bold; }
    .keluar { color: #dc3545; font-weight: bold; }
    @media print { .no-print { display: none; } }
</style>
</head>
<body>
    <?php if (!file_exists(__DIR__ . '/vendor/autoload.php')): ?>
    <div class="no-print" style="margin-bottom:15px;">
        <button onclick="window.print()" style="padding:8px 14px; background:#0d6efd; color:#fff; border:none; border-radius:4px; cursor:pointer;">Cetak / Simpan sebagai PDF</button>
    </div>
    <?php endif; ?>
    <h2>Laporan Keuangan Rumah Tangga</h2>
    <div class="meta">
        Akun: <strong><?= e($namaUser) ?></strong> |
        Periode: <strong><?= e($bulan) ?></strong> |
        Dicetak: <?= date('d-m-Y H:i') ?>
    </div>

    <!-- Ringkasan Arus Kas Utama -->
    <table class="data-table">
        <thead>
            <tr>
                <th>Total Pemasukan</th>
                <th>Total Pengeluaran</th>
                <th>Selisih Arus Kas</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="masuk"><?= rupiah($totalMasuk) ?></td>
                <td class="keluar"><?= rupiah($totalKeluar) ?></td>
                <td class="<?= $selisihKas >= 0 ? 'masuk' : 'keluar' ?>">
                    <strong><?= rupiah($selisihKas) ?></strong>
                </td>
            </tr>
        </tbody>
    </table>

    <!-- Rincian Total per Kategori (Pemasukan & Pengeluaran) -->
    <table class="layout-table">
        <tr>
            <!-- Kolom Kiri: Kategori Pemasukan -->
            <td class="col-half">
                <h4>Rincian Pemasukan per Kategori</h4>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Kategori</th>
                            <th class="center">Trx</th>
                            <th class="right">Total</th>
                            <th class="right">%</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($kategoriMasuk)): ?>
                            <tr><td colspan="4" class="center" style="color:#64748b;">Tidak ada pemasukan.</td></tr>
                        <?php else: ?>
                            <?php foreach ($kategoriMasuk as $km):
                                $persenMasuk = $totalMasuk > 0 ? round(((float)$km['total'] / $totalMasuk) * 100, 1) : 0;
                            ?>
                            <tr>
                                <td><?= e($km['nama_kategori']) ?></td>
                                <td class="center"><?= (int)$km['jumlah_trx'] ?>x</td>
                                <td class="right masuk"><?= rupiah($km['total']) ?></td>
                                <td class="right"><?= $persenMasuk ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2">Total Pemasukan</td>
                            <td class="right masuk"><?= rupiah($totalMasuk) ?></td>
                            <td class="right"><?= $totalMasuk > 0 ? '100%' : '0%' ?></td>
                        </tr>
                    </tfoot>
                </table>
            </td>

            <td class="col-spacer"></td>

            <!-- Kolom Kanan: Kategori Pengeluaran -->
            <td class="col-half">
                <h4>Rincian Pengeluaran per Kategori</h4>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Kategori</th>
                            <th class="center">Trx</th>
                            <th class="right">Total</th>
                            <th class="right">%</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($kategoriKeluar)): ?>
                            <tr><td colspan="4" class="center" style="color:#64748b;">Tidak ada pengeluaran.</td></tr>
                        <?php else: ?>
                            <?php foreach ($kategoriKeluar as $kk):
                                $persenKeluar = $totalKeluar > 0 ? round(((float)$kk['total'] / $totalKeluar) * 100, 1) : 0;
                            ?>
                            <tr>
                                <td><?= e($kk['nama_kategori']) ?></td>
                                <td class="center"><?= (int)$kk['jumlah_trx'] ?>x</td>
                                <td class="right keluar"><?= rupiah($kk['total']) ?></td>
                                <td class="right"><?= $persenKeluar ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2">Total Pengeluaran</td>
                            <td class="right keluar"><?= rupiah($totalKeluar) ?></td>
                            <td class="right"><?= $totalKeluar > 0 ? '100%' : '0%' ?></td>
                        </tr>
                    </tfoot>
                </table>
            </td>
        </tr>
    </table>

    <!-- Realisasi Anggaran (Budgeting) -->
    <?php if (!empty($budgets)): ?>
    <h4>Realisasi Anggaran (Budgeting)</h4>
    <table class="data-table">
        <thead>
            <tr>
                <th>Kategori Pengeluaran</th>
                <th class="right">Batas Anggaran</th>
                <th class="right">Terpakai</th>
                <th class="right">Sisa Anggaran</th>
                <th class="right">Serapan</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($budgets as $b):
                $sisa = (float)$b['batas_anggaran'] - (float)$b['terpakai'];
                $serapan = (float)$b['batas_anggaran'] > 0 ? round(((float)$b['terpakai'] / (float)$b['batas_anggaran']) * 100, 1) : 0;
            ?>
            <tr>
                <td><?= e($b['nama_kategori']) ?></td>
                <td class="right"><?= rupiah($b['batas_anggaran']) ?></td>
                <td class="right"><?= rupiah($b['terpakai']) ?></td>
                <td class="right <?= $sisa < 0 ? 'keluar' : '' ?>"><?= rupiah($sisa) ?></td>
                <td class="right"><?= $serapan ?>%</td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- Rincian Transaksi Kronologis -->
    <h4>Rincian Transaksi</h4>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 75px;">Tanggal</th>
                <th>Kategori</th>
                <th>Dompet</th>
                <th>Keterangan</th>
                <th class="right" style="width: 115px;">Nominal</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($transaksi)): ?>
                <tr><td colspan="5" class="center" style="color:#64748b;">Tidak ada transaksi pada periode ini.</td></tr>
            <?php else: ?>
                <?php foreach ($transaksi as $t): ?>
                <tr>
                    <td><?= e($t['tanggal']) ?></td>
                    <td><?= e($t['nama_kategori']) ?></td>
                    <td><?= e($t['nama_dompet']) ?></td>
                    <td><?= e($t['keterangan'] ?: '-') ?></td>
                    <td class="right <?= $t['tipe'] === 'pemasukan' ? 'masuk' : 'keluar' ?>">
                        <?= ($t['tipe'] === 'pemasukan' ? '+' : '-') . ' ' . rupiah($t['jumlah']) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if (!file_exists(__DIR__ . '/vendor/autoload.php')): ?>
        <script>window.onload = function() { window.print(); };</script>
    <?php endif; ?>
</body>
</html>
<?php
$html = ob_get_clean();

// Jika Dompdf terinstal melalui Composer, generate PDF biner langsung
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    $dompdf = new \Dompdf\Dompdf([
        'isRemoteEnabled'  => false,
        'defaultMediaType' => 'print',
    ]);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream("Laporan_Keuangan_{$bulan}.pdf", ['Attachment' => true]);
    exit;
}

echo $html;

<?php
require_once 'config.php';
require_once 'security.php';

$userId = requireLogin();
$bulan = $_GET['bulan'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $bulan)) {
    $bulan = date('Y-m');
}

$stmt = $pdo->prepare("
    SELECT t.tanggal, k.nama_kategori, t.tipe, d.nama_dompet, t.jumlah, t.keterangan
    FROM transaksi t
    JOIN kategori k ON t.kategori_id = k.id
    JOIN dompet d ON t.dompet_id = d.id
    WHERE t.user_id = ? AND DATE_FORMAT(t.tanggal, '%Y-%m') = ?
    ORDER BY t.tanggal ASC, t.id ASC
");
$stmt->execute([$userId, $bulan]);
$rows = $stmt->fetchAll();

$filename = "Laporan_Keuangan_Keluarga_{$bulan}.xls";
header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header("Pragma: no-cache");
header("Expires: 0");

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
 <Styles>
  <Style ss:ID="Header">
   <Font ss:Bold="1" ss:Color="#FFFFFF"/>
   <Interior ss:Color="#0D6EFD" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="Currency">
   <NumberFormat ss:Format="#,##0"/>
  </Style>
  <Style ss:ID="BoldCurrency">
   <Font ss:Bold="1"/>
   <NumberFormat ss:Format="#,##0"/>
  </Style>
 </Styles>
 <Worksheet ss:Name="Laporan <?= e($bulan) ?>">
  <Table>
   <Column ss:Width="85"/>
   <Column ss:Width="160"/>
   <Column ss:Width="90"/>
   <Column ss:Width="130"/>
   <Column ss:Width="110"/>
   <Column ss:Width="200"/>
   <Row>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Tanggal</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Kategori</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Tipe</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Dompet</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Nominal (Rp)</Data></Cell>
    <Cell ss:StyleID="Header"><Data ss:Type="String">Keterangan</Data></Cell>
   </Row>
   <?php
   $totalMasuk = 0;
   $totalKeluar = 0;
   foreach ($rows as $r):
       if ($r['tipe'] === 'pemasukan') $totalMasuk += (float)$r['jumlah'];
       else $totalKeluar += (float)$r['jumlah'];
   ?>
   <Row>
    <Cell><Data ss:Type="String"><?= e($r['tanggal']) ?></Data></Cell>
    <Cell><Data ss:Type="String"><?= e($r['nama_kategori']) ?></Data></Cell>
    <Cell><Data ss:Type="String"><?= e(ucfirst($r['tipe'])) ?></Data></Cell>
    <Cell><Data ss:Type="String"><?= e($r['nama_dompet']) ?></Data></Cell>
    <Cell ss:StyleID="Currency"><Data ss:Type="Number"><?= (float)$r['jumlah'] ?></Data></Cell>
    <Cell><Data ss:Type="String"><?= e($r['keterangan'] ?? '-') ?></Data></Cell>
   </Row>
   <?php endforeach; ?>
   <Row/>
   <Row>
    <Cell><Data ss:Type="String">Total Pemasukan</Data></Cell>
    <Cell ss:StyleID="BoldCurrency"><Data ss:Type="Number"><?= $totalMasuk ?></Data></Cell>
   </Row>
   <Row>
    <Cell><Data ss:Type="String">Total Pengeluaran</Data></Cell>
    <Cell ss:StyleID="BoldCurrency"><Data ss:Type="Number"><?= $totalKeluar ?></Data></Cell>
   </Row>
   <Row>
    <Cell><Data ss:Type="String">Selisih Bersih</Data></Cell>
    <Cell ss:StyleID="BoldCurrency"><Data ss:Type="Number"><?= $totalMasuk - $totalKeluar ?></Data></Cell>
   </Row>
  </Table>
 </Worksheet>
</Workbook>

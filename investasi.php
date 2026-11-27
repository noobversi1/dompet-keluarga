<?php
declare(strict_types=1);

require_once 'config.php';
require_once 'security.php';
require_once 'layout.php';

$userId = requireLogin();

function getOrCreateKategoriInvestasi(PDO $pdo, int $userId, string $namaKategori, string $tipe): int
{
    $stmt = $pdo->prepare('SELECT id FROM kategori WHERE user_id = ? AND nama_kategori = ? AND tipe = ? LIMIT 1');
    $stmt->execute([$userId, $namaKategori, $tipe]);
    $row = $stmt->fetch();
    if ($row) {
        return (int)$row['id'];
    }

    $ins = $pdo->prepare('INSERT INTO kategori (user_id, nama_kategori, tipe) VALUES (?, ?, ?)');
    $ins->execute([$userId, $namaKategori, $tipe]);
    return (int)$pdo->lastInsertId();
}

$jenisList = ['Emas', 'Reksa Dana', 'Saham', 'Obligasi / SBN', 'Deposito', 'Lainnya'];

// Parameter Filter & Pagination Daftar Aset
$filterJenisAset = in_array($_GET['jenis_aset'] ?? '', $jenisList, true) ? $_GET['jenis_aset'] : '';
$qAset           = trim($_GET['q_aset'] ?? '');
$pageAset        = max(1, (int)($_GET['page_aset'] ?? 1));
$perPageAset     = 4; // 4 kartu (2x2 grid) per halaman

// Parameter Filter & Pagination Riwayat Aktivitas
$filterInvId     = max(0, (int)($_GET['inv_id'] ?? 0));
$filterAktivitas = in_array($_GET['jenis_aktivitas'] ?? '', ['beli', 'jual', 'dividen', 'valuasi'], true) ? $_GET['jenis_aktivitas'] : '';
$bulanRiwayat    = preg_match('/^\d{4}-\d{2}$/', $_GET['bulan_riwayat'] ?? '') ? $_GET['bulan_riwayat'] : '';
$pageRiwayat     = max(1, (int)($_GET['page_riwayat'] ?? 1));
$perPageRiwayat  = 10;

$baseParams = [
    'jenis_aset'      => $filterJenisAset,
    'q_aset'          => $qAset,
    'page_aset'       => $pageAset,
    'inv_id'          => $filterInvId,
    'jenis_aktivitas' => $filterAktivitas,
    'bulan_riwayat'   => $bulanRiwayat,
    'page_riwayat'    => $pageRiwayat,
];

function buildInvestasiUrl(array $base, array $overrides = []): string
{
    $merged = array_merge($base, $overrides);
    $clean  = [];

    if (($merged['jenis_aset'] ?? '') !== '') $clean['jenis_aset'] = $merged['jenis_aset'];
    if (($merged['q_aset'] ?? '') !== '') $clean['q_aset'] = $merged['q_aset'];
    if (($merged['page_aset'] ?? 1) > 1) $clean['page_aset'] = (int)$merged['page_aset'];

    if (($merged['inv_id'] ?? 0) > 0) $clean['inv_id'] = (int)$merged['inv_id'];
    if (($merged['jenis_aktivitas'] ?? '') !== '') $clean['jenis_aktivitas'] = $merged['jenis_aktivitas'];
    if (($merged['bulan_riwayat'] ?? '') !== '') $clean['bulan_riwayat'] = $merged['bulan_riwayat'];
    if (($merged['page_riwayat'] ?? 1) > 1) $clean['page_riwayat'] = (int)$merged['page_riwayat'];

    $qs = http_build_query($clean);
    return 'investasi.php' . ($qs !== '' ? '?' . $qs : '');
}

function renderInvestasiPagination(int $currentPage, int $totalPages, string $pageKey, array $base): void
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
                <a class="page-link" href="<?= $currentPage > 1 ? e(buildInvestasiUrl($base, [$pageKey => $currentPage - 1])) : '#' ?>">&laquo;</a>
            </li>
            <?php if ($startPage > 1): ?>
                <li class="page-item"><a class="page-link" href="<?= e(buildInvestasiUrl($base, [$pageKey => 1])) ?>">1</a></li>
                <?php if ($startPage > 2): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
            <?php endif; ?>
            <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                <li class="page-item <?= $p === $currentPage ? 'active' : '' ?>">
                    <a class="page-link" href="<?= e(buildInvestasiUrl($base, [$pageKey => $p])) ?>"><?= $p ?></a>
                </li>
            <?php endfor; ?>
            <?php if ($endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                <li class="page-item"><a class="page-link" href="<?= e(buildInvestasiUrl($base, [$pageKey => $totalPages])) ?>"><?= $totalPages ?></a></li>
            <?php endif; ?>
            <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $currentPage < $totalPages ? e(buildInvestasiUrl($base, [$pageKey => $currentPage + 1])) : '#' ?>">&raquo;</a>
            </li>
        </ul>
    </nav>
    <?php
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $aksi        = $_POST['aksi'] ?? '';
    $redirectUrl = buildInvestasiUrl($baseParams);

    // 1. TAMBAH ASET INVESTASI BARU
    if ($aksi === 'tambah_aset') {
        $namaAset   = trim($_POST['nama_aset'] ?? '');
        $jenisAset  = in_array($_POST['jenis_aset'] ?? '', $jenisList, true) ? $_POST['jenis_aset'] : 'Lainnya';
        $satuan     = trim($_POST['satuan'] ?? 'Unit') ?: 'Unit';
        $catatan    = trim($_POST['catatan'] ?? '');
        $dompetId   = (int)($_POST['dompet_id'] ?? 0);
        $modalAwal  = (float)($_POST['modal_awal'] ?? 0);
        $unitAwal   = (float)($_POST['unit_awal'] ?? 0);
        $tanggal    = $_POST['tanggal'] ?? date('Y-m-d');

        if ($namaAset === '' || $modalAwal < 0 || $unitAwal < 0) {
            setFlash('danger', 'Nama aset wajib diisi dan nominal/unit tidak boleh negatif.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        $pdo->beginTransaction();
        try {
            if ($modalAwal > 0) {
                $stmtDompet = $pdo->prepare('SELECT id, saldo FROM dompet WHERE id = ? AND user_id = ? FOR UPDATE');
                $stmtDompet->execute([$dompetId, $userId]);
                $dompet = $stmtDompet->fetch();

                if (!$dompet || (float)$dompet['saldo'] < $modalAwal) {
                    $pdo->rollBack();
                    setFlash('danger', 'Saldo dompet tidak mencukupi untuk modal awal investasi.');
                    header('Location: ' . $redirectUrl);
                    exit;
                }

                $pdo->prepare('UPDATE dompet SET saldo = saldo - ? WHERE id = ? AND user_id = ?')
                    ->execute([$modalAwal, $dompetId, $userId]);
            }

            $stmtIns = $pdo->prepare('
                INSERT INTO investasi (user_id, nama_aset, jenis_aset, satuan, jumlah_unit, modal_pokok, nilai_pasar, catatan)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmtIns->execute([$userId, $namaAset, $jenisAset, $satuan, $unitAwal, $modalAwal, $modalAwal, $catatan]);
            $invId = (int)$pdo->lastInsertId();

            if ($modalAwal > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
                $pdo->prepare('
                    INSERT INTO riwayat_investasi (user_id, investasi_id, dompet_id, jenis_aktivitas, jumlah_unit, nominal_modal, nominal_kas, tanggal, keterangan)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ')->execute([$userId, $invId, $dompetId, 'beli', $unitAwal, $modalAwal, $modalAwal, $tanggal, 'Pembelian awal aset']);
            }

            $pdo->commit();
            setFlash('success', 'Aset investasi baru berhasil ditambahkan ke portofolio.');
            header('Location: ' . buildInvestasiUrl($baseParams, ['page_aset' => 1]));
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', 'Gagal menambahkan aset investasi.');
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    // 2. BELI / TOP-UP MODAL INVESTASI
    if ($aksi === 'beli_aset') {
        $invId      = (int)($_POST['investasi_id'] ?? 0);
        $dompetId   = (int)($_POST['dompet_id'] ?? 0);
        $nominal    = (float)($_POST['nominal'] ?? 0);
        $unit       = (float)($_POST['jumlah_unit'] ?? 0);
        $tanggal    = $_POST['tanggal'] ?? date('Y-m-d');
        $keterangan = trim($_POST['keterangan'] ?? '');

        if ($invId <= 0 || $dompetId <= 0 || $nominal <= 0 || $unit < 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            setFlash('danger', 'Input pembelian / top-up investasi tidak valid.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $stmtInv = $pdo->prepare('SELECT id FROM investasi WHERE id = ? AND user_id = ? FOR UPDATE');
            $stmtInv->execute([$invId, $userId]);

            $stmtDompet = $pdo->prepare('SELECT id, saldo FROM dompet WHERE id = ? AND user_id = ? FOR UPDATE');
            $stmtDompet->execute([$dompetId, $userId]);
            $dompet = $stmtDompet->fetch();

            if (!$stmtInv->fetch() || !$dompet) {
                $pdo->rollBack();
                setFlash('danger', 'Aset investasi atau dompet tidak ditemukan.');
            } elseif ((float)$dompet['saldo'] < $nominal) {
                $pdo->rollBack();
                setFlash('danger', 'Saldo dompet tidak mencukupi untuk top-up investasi.');
            } else {
                $pdo->prepare('UPDATE dompet SET saldo = saldo - ? WHERE id = ? AND user_id = ?')
                    ->execute([$nominal, $dompetId, $userId]);

                $pdo->prepare('
                    UPDATE investasi
                    SET jumlah_unit = jumlah_unit + ?,
                        modal_pokok = modal_pokok + ?,
                        nilai_pasar = nilai_pasar + ?
                    WHERE id = ? AND user_id = ?
                ')->execute([$unit, $nominal, $nominal, $invId, $userId]);

                $pdo->prepare('
                    INSERT INTO riwayat_investasi (user_id, investasi_id, dompet_id, jenis_aktivitas, jumlah_unit, nominal_modal, nominal_kas, tanggal, keterangan)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ')->execute([$userId, $invId, $dompetId, 'beli', $unit, $nominal, $nominal, $tanggal, $keterangan ?: 'Top-up modal investasi']);

                $pdo->commit();
                setFlash('success', 'Pembelian / top-up investasi berhasil dicatat.');
                header('Location: ' . buildInvestasiUrl($baseParams, ['page_riwayat' => 1]));
                exit;
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', 'Gagal memproses pembelian investasi.');
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    // 3. EDIT INFO ASET & UPDATE NILAI PASAR TERKINI
    if ($aksi === 'edit_aset') {
        $invId      = (int)($_POST['investasi_id'] ?? 0);
        $namaAset   = trim($_POST['nama_aset'] ?? '');
        $jenisAset  = in_array($_POST['jenis_aset'] ?? '', $jenisList, true) ? $_POST['jenis_aset'] : 'Lainnya';
        $satuan     = trim($_POST['satuan'] ?? 'Unit') ?: 'Unit';
        $jumlahUnit = (float)($_POST['jumlah_unit'] ?? 0);
        $nilaiPasar = (float)($_POST['nilai_pasar'] ?? 0);
        $catatan    = trim($_POST['catatan'] ?? '');

        if ($invId > 0 && $namaAset !== '' && $jumlahUnit >= 0 && $nilaiPasar >= 0) {
            $stmtOld = $pdo->prepare('SELECT nilai_pasar FROM investasi WHERE id = ? AND user_id = ?');
            $stmtOld->execute([$invId, $userId]);
            $old = $stmtOld->fetch();

            if ($old) {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('
                        UPDATE investasi
                        SET nama_aset = ?, jenis_aset = ?, satuan = ?, jumlah_unit = ?, nilai_pasar = ?, catatan = ?
                        WHERE id = ? AND user_id = ?
                    ')->execute([$namaAset, $jenisAset, $satuan, $jumlahUnit, $nilaiPasar, $catatan, $invId, $userId]);

                    $selisihValuasi = $nilaiPasar - (float)$old['nilai_pasar'];
                    if (abs($selisihValuasi) >= 1) {
                        $pdo->prepare('
                            INSERT INTO riwayat_investasi (user_id, investasi_id, jenis_aktivitas, jumlah_unit, nominal_kas, keuntungan_rugi, tanggal, keterangan)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ')->execute([$userId, $invId, 'valuasi', $jumlahUnit, $nilaiPasar, $selisihValuasi, date('Y-m-d'), 'Pembaruan nilai pasar terkini']);
                    }

                    $pdo->commit();
                    setFlash('success', 'Data aset dan nilai pasar terkini berhasil diperbarui.');
                } catch (Exception $e) {
                    $pdo->rollBack();
                    setFlash('danger', 'Gagal memperbarui aset investasi.');
                }
            }
        } else {
            setFlash('danger', 'Data pembaruan aset tidak valid.');
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    // 4. JUAL / CAIRKAN ASET KE DOMPET
    if ($aksi === 'jual_aset') {
        $invId       = (int)($_POST['investasi_id'] ?? 0);
        $dompetId    = (int)($_POST['dompet_id'] ?? 0);
        $nominalCair = (float)($_POST['nominal_cair'] ?? 0);
        $porsiModal  = (float)($_POST['porsi_modal'] ?? 0);
        $unitJual    = (float)($_POST['unit_jual'] ?? 0);
        $tanggal     = $_POST['tanggal'] ?? date('Y-m-d');
        $keterangan  = trim($_POST['keterangan'] ?? '');

        if ($invId <= 0 || $dompetId <= 0 || $nominalCair <= 0 || $porsiModal < 0 || $unitJual < 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            setFlash('danger', 'Input pencairan investasi tidak valid.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $stmtInv = $pdo->prepare('SELECT * FROM investasi WHERE id = ? AND user_id = ? FOR UPDATE');
            $stmtInv->execute([$invId, $userId]);
            $inv = $stmtInv->fetch();

            $stmtDompet = $pdo->prepare('SELECT id FROM dompet WHERE id = ? AND user_id = ? FOR UPDATE');
            $stmtDompet->execute([$dompetId, $userId]);

            if (!$inv || !$stmtDompet->fetch()) {
                $pdo->rollBack();
                setFlash('danger', 'Aset investasi atau dompet tujuan tidak ditemukan.');
                header('Location: ' . $redirectUrl);
                exit;
            }

            if ($porsiModal > (float)$inv['modal_pokok']) {
                $pdo->rollBack();
                setFlash('danger', 'Porsi modal pokok yang dilepas melebihi modal tersimpan (' . rupiah($inv['modal_pokok']) . ').');
                header('Location: ' . $redirectUrl);
                exit;
            }

            $gainLoss  = $nominalCair - $porsiModal;
            $sisaModal = max(0.0, (float)$inv['modal_pokok'] - $porsiModal);
            $sisaUnit  = max(0.0, (float)$inv['jumlah_unit'] - $unitJual);
            $sisaPasar = ($sisaModal <= 0.01) ? 0.0 : max(0.0, (float)$inv['nilai_pasar'] - $nominalCair);

            $pdo->prepare('UPDATE dompet SET saldo = saldo + ? WHERE id = ? AND user_id = ?')
                ->execute([$nominalCair, $dompetId, $userId]);

            $pdo->prepare('
                UPDATE investasi
                SET modal_pokok = ?, jumlah_unit = ?, nilai_pasar = ?
                WHERE id = ? AND user_id = ?
            ')->execute([$sisaModal, $sisaUnit, $sisaPasar, $invId, $userId]);

            if ($gainLoss > 0) {
                $katId = getOrCreateKategoriInvestasi($pdo, $userId, 'Imbal Hasil Investasi', 'pemasukan');
                $ketTrx = 'Profit jual ' . $inv['nama_aset'] . ($keterangan !== '' ? ' - ' . $keterangan : '');
                $pdo->prepare('INSERT INTO transaksi (user_id, dompet_id, kategori_id, tipe, jumlah, keterangan, tanggal) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$userId, $dompetId, $katId, 'pemasukan', $gainLoss, $ketTrx, $tanggal]);
            } elseif ($gainLoss < 0) {
                $katId = getOrCreateKategoriInvestasi($pdo, $userId, 'Kerugian Investasi', 'pengeluaran');
                $ketTrx = 'Rugi jual ' . $inv['nama_aset'] . ($keterangan !== '' ? ' - ' . $keterangan : '');
                $pdo->prepare('INSERT INTO transaksi (user_id, dompet_id, kategori_id, tipe, jumlah, keterangan, tanggal) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$userId, $dompetId, $katId, 'pengeluaran', abs($gainLoss), $ketTrx, $tanggal]);
            }

            $pdo->prepare('
                INSERT INTO riwayat_investasi (user_id, investasi_id, dompet_id, jenis_aktivitas, jumlah_unit, nominal_modal, nominal_kas, keuntungan_rugi, tanggal, keterangan)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ')->execute([$userId, $invId, $dompetId, 'jual', $unitJual, $porsiModal, $nominalCair, $gainLoss, $tanggal, $keterangan ?: 'Pencairan / penjualan aset']);

            $pdo->commit();
            setFlash('success', 'Pencairan aset berhasil diproses dan saldo dompet telah bertambah ' . rupiah($nominalCair) . '.');
            header('Location: ' . buildInvestasiUrl($baseParams, ['page_riwayat' => 1]));
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', 'Gagal memproses pencairan investasi.');
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    // 5. CATAT DIVIDEN / KUPON / BAGI HASIL
    if ($aksi === 'catat_dividen') {
        $invId      = (int)($_POST['investasi_id'] ?? 0);
        $dompetId   = (int)($_POST['dompet_id'] ?? 0);
        $nominal    = (float)($_POST['nominal'] ?? 0);
        $tanggal    = $_POST['tanggal'] ?? date('Y-m-d');
        $keterangan = trim($_POST['keterangan'] ?? '');

        if ($invId <= 0 || $dompetId <= 0 || $nominal <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            setFlash('danger', 'Input pencatatan dividen / bagi hasil tidak valid.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $stmtInv = $pdo->prepare('SELECT id, nama_aset FROM investasi WHERE id = ? AND user_id = ?');
            $stmtInv->execute([$invId, $userId]);
            $inv = $stmtInv->fetch();

            $stmtDompet = $pdo->prepare('SELECT id FROM dompet WHERE id = ? AND user_id = ? FOR UPDATE');
            $stmtDompet->execute([$dompetId, $userId]);

            if (!$inv || !$stmtDompet->fetch()) {
                $pdo->rollBack();
                setFlash('danger', 'Aset investasi atau dompet penerima tidak ditemukan.');
                header('Location: ' . $redirectUrl);
                exit;
            }

            $pdo->prepare('UPDATE dompet SET saldo = saldo + ? WHERE id = ? AND user_id = ?')
                ->execute([$nominal, $dompetId, $userId]);

            $katId = getOrCreateKategoriInvestasi($pdo, $userId, 'Imbal Hasil Investasi', 'pemasukan');
            $ketTrx = 'Dividen/Bagi hasil ' . $inv['nama_aset'] . ($keterangan !== '' ? ' - ' . $keterangan : '');
            $pdo->prepare('INSERT INTO transaksi (user_id, dompet_id, kategori_id, tipe, jumlah, keterangan, tanggal) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$userId, $dompetId, $katId, 'pemasukan', $nominal, $ketTrx, $tanggal]);

            $pdo->prepare('
                INSERT INTO riwayat_investasi (user_id, investasi_id, dompet_id, jenis_aktivitas, nominal_kas, keuntungan_rugi, tanggal, keterangan)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ')->execute([$userId, $invId, $dompetId, 'dividen', $nominal, $nominal, $tanggal, $keterangan ?: 'Dividen / Kupon / Bagi Hasil']);

            $pdo->commit();
            setFlash('success', 'Imbal hasil investasi sebesar ' . rupiah($nominal) . ' berhasil ditambahkan ke dompet.');
            header('Location: ' . buildInvestasiUrl($baseParams, ['page_riwayat' => 1]));
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', 'Gagal mencatat dividen / bagi hasil.');
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    // 6. HAPUS ASET INVESTASI
    if ($aksi === 'hapus_aset') {
        $invId           = (int)($_POST['investasi_id'] ?? 0);
        $dompetKembaliId = (int)($_POST['dompet_kembali_id'] ?? 0);

        $stmtGet = $pdo->prepare('SELECT id, modal_pokok FROM investasi WHERE id = ? AND user_id = ?');
        $stmtGet->execute([$invId, $userId]);
        $inv = $stmtGet->fetch();

        if ($inv) {
            $modalPokok = (float)$inv['modal_pokok'];
            $pdo->beginTransaction();
            try {
                if ($modalPokok > 0 && $dompetKembaliId > 0) {
                    $cekDompet = $pdo->prepare('SELECT id FROM dompet WHERE id = ? AND user_id = ?');
                    $cekDompet->execute([$dompetKembaliId, $userId]);
                    if (!$cekDompet->fetch()) {
                        throw new Exception('Dompet tujuan tidak valid.');
                    }
                    $pdo->prepare('UPDATE dompet SET saldo = saldo + ? WHERE id = ? AND user_id = ?')
                        ->execute([$modalPokok, $dompetKembaliId, $userId]);
                }

                $pdo->prepare('DELETE FROM investasi WHERE id = ? AND user_id = ?')
                    ->execute([$invId, $userId]);

                $pdo->commit();
                $pesan = ($modalPokok > 0 && $dompetKembaliId > 0)
                    ? 'Aset investasi dihapus dan modal pokok (' . rupiah($modalPokok) . ') dikembalikan ke dompet.'
                    : 'Aset investasi berhasil dihapus.';
                setFlash('success', $pesan);
            } catch (Exception $e) {
                $pdo->rollBack();
                setFlash('danger', 'Gagal menghapus aset investasi.');
            }
        }
        header('Location: ' . $redirectUrl);
        exit;
    }
}

// Ringkasan Global Portofolio & Grafik (Untuk Kartu Atas & Chart)
$stmtGlobalInv = $pdo->prepare('
    SELECT COALESCE(SUM(modal_pokok), 0) AS total_modal,
           COALESCE(SUM(nilai_pasar), 0) AS total_pasar
    FROM investasi
    WHERE user_id = ?
');
$stmtGlobalInv->execute([$userId]);
$globalInv       = $stmtGlobalInv->fetch();
$totalModal      = (float)($globalInv['total_modal'] ?? 0);
$totalNilaiPasar = (float)($globalInv['total_pasar'] ?? 0);
$totalGainLoss   = $totalNilaiPasar - $totalModal;
$persenRoi       = $totalModal > 0 ? round(($totalGainLoss / $totalModal) * 100, 2) : 0.0;

$stmtAlokasi = $pdo->prepare('
    SELECT jenis_aset, COALESCE(SUM(nilai_pasar), 0) AS total_nilai
    FROM investasi
    WHERE user_id = ? AND nilai_pasar > 0
    GROUP BY jenis_aset
    ORDER BY total_nilai DESC
');
$stmtAlokasi->execute([$userId]);
$alokasiJenis = $stmtAlokasi->fetchAll();

// Dropdown Daftar Aset & Dompet
$allAsetDropdown = $pdo->prepare('SELECT id, nama_aset FROM investasi WHERE user_id = ? ORDER BY nama_aset ASC');
$allAsetDropdown->execute([$userId]);
$allAsetDropdown = $allAsetDropdown->fetchAll();

$stmtDompet = $pdo->prepare('SELECT id, nama_dompet, saldo FROM dompet WHERE user_id = ? ORDER BY id ASC');
$stmtDompet->execute([$userId]);
$daftarDompet = $stmtDompet->fetchAll();

// =========================================================================
// QUERY 1: DAFTAR ASET INVESTASI (FILTER + PAGINATION)
// =========================================================================
$whereAset  = ['user_id = ?'];
$paramsAset = [$userId];

if ($filterJenisAset !== '') {
    $whereAset[]  = 'jenis_aset = ?';
    $paramsAset[] = $filterJenisAset;
}
if ($qAset !== '') {
    $whereAset[]  = 'nama_aset LIKE ?';
    $paramsAset[] = '%' . $qAset . '%';
}
$sqlWhereAset = implode(' AND ', $whereAset);

$stmtCountAset = $pdo->prepare("SELECT COUNT(*) FROM investasi WHERE {$sqlWhereAset}");
$stmtCountAset->execute($paramsAset);
$totalRowsAset  = (int)$stmtCountAset->fetchColumn();
$totalPagesAset = max(1, (int)ceil($totalRowsAset / $perPageAset));
if ($pageAset > $totalPagesAset) $pageAset = $totalPagesAset;
$offsetAset = ($pageAset - 1) * $perPageAset;

$stmtInv = $pdo->prepare("
    SELECT *
    FROM investasi
    WHERE {$sqlWhereAset}
    ORDER BY nilai_pasar DESC, id DESC
    LIMIT ? OFFSET ?
");
$idx = 1;
foreach ($paramsAset as $pVal) {
    $stmtInv->bindValue($idx++, $pVal, is_int($pVal) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmtInv->bindValue($idx++, $perPageAset, PDO::PARAM_INT);
$stmtInv->bindValue($idx++, $offsetAset, PDO::PARAM_INT);
$stmtInv->execute();
$daftarInvestasi = $stmtInv->fetchAll();

// =========================================================================
// QUERY 2: RIWAYAT AKTIVITAS PORTOFOLIO (FILTER + PAGINATION)
// =========================================================================
$whereRiwayat  = ['r.user_id = ?'];
$paramsRiwayat = [$userId];

if ($filterInvId > 0) {
    $whereRiwayat[]  = 'r.investasi_id = ?';
    $paramsRiwayat[] = $filterInvId;
}
if ($filterAktivitas !== '') {
    $whereRiwayat[]  = 'r.jenis_aktivitas = ?';
    $paramsRiwayat[] = $filterAktivitas;
}
if ($bulanRiwayat !== '') {
    $whereRiwayat[]  = "DATE_FORMAT(r.tanggal, '%Y-%m') = ?";
    $paramsRiwayat[] = $bulanRiwayat;
}
$sqlWhereRiwayat = implode(' AND ', $whereRiwayat);

$stmtCountRiwayat = $pdo->prepare("SELECT COUNT(*) FROM riwayat_investasi r WHERE {$sqlWhereRiwayat}");
$stmtCountRiwayat->execute($paramsRiwayat);
$totalRowsRiwayat  = (int)$stmtCountRiwayat->fetchColumn();
$totalPagesRiwayat = max(1, (int)ceil($totalRowsRiwayat / $perPageRiwayat));
if ($pageRiwayat > $totalPagesRiwayat) $pageRiwayat = $totalPagesRiwayat;
$offsetRiwayat = ($pageRiwayat - 1) * $perPageRiwayat;

$stmtRiwayat = $pdo->prepare("
    SELECT r.*, i.nama_aset, i.satuan, d.nama_dompet
    FROM riwayat_investasi r
    JOIN investasi i ON r.investasi_id = i.id
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
$riwayatInvestasi = $stmtRiwayat->fetchAll();

$isAsetFiltered    = ($filterJenisAset !== '' || $qAset !== '');
$isRiwayatFiltered = ($filterInvId > 0 || $filterAktivitas !== '' || $bulanRiwayat !== '');

renderHeader('Portofolio Investasi Keluarga', 'investasi');
?>

<!-- KARTU METRIK RINGKASAN PORTOFOLIO -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <span class="text-muted small">Total Nilai Pasar Portofolio</span>
                <h4 class="fw-bold text-primary mt-1 mb-1"><?= rupiah($totalNilaiPasar) ?></h4>
                <span class="small text-muted">Nilai aset aktif berdasarkan harga pasar terkini</span>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <span class="text-muted small">Total Modal Pokok Tertanam</span>
                <h4 class="fw-bold text-dark mt-1 mb-1"><?= rupiah($totalModal) ?></h4>
                <span class="small text-muted">Akumulasi dana yang disisihkan dari dompet</span>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <span class="text-muted small">Potensi Untung / Rugi (Unrealized)</span>
                <h4 class="fw-bold mt-1 mb-1 <?= $totalGainLoss >= 0 ? 'text-success' : 'text-danger' ?>">
                    <?= ($totalGainLoss >= 0 ? '+' : '-') . ' ' . rupiah(abs($totalGainLoss)) ?>
                </h4>
                <span class="badge <?= $totalGainLoss >= 0 ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' ?>">
                    Imbal Hasil: <?= ($persenRoi >= 0 ? '+' : '') . $persenRoi ?>%
                </span>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- KOLOM KIRI: TAMBAH ASET & GRAFIK ALOKASI -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-bold">
                <i class="bi bi-graph-up-arrow me-1 text-primary"></i>Tambah Instrumen Investasi
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="aksi" value="tambah_aset">

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Nama Aset / Produk</label>
                        <input type="text" name="nama_aset" class="form-control" placeholder="Contoh: Emas Antam / Bibit RDPU" required>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-7">
                            <label class="form-label small fw-semibold">Jenis Instrumen</label>
                            <select name="jenis_aset" class="form-select" required>
                                <?php foreach ($jenisList as $j): ?>
                                    <option value="<?= e($j) ?>"><?= e($j) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-5">
                            <label class="form-label small fw-semibold">Satuan</label>
                            <input type="text" name="satuan" value="Unit" class="form-control" placeholder="Gram / Lot / Unit" required>
                        </div>
                    </div>

                    <div class="border rounded p-3 bg-light mb-3">
                        <div class="small fw-semibold text-primary mb-2">Pembelian Awal (Opsional)</div>
                        <div class="mb-2">
                            <label class="form-label small mb-1">Sumber Dompet</label>
                            <select name="dompet_id" class="form-select form-select-sm">
                                <?php foreach ($daftarDompet as $d): ?>
                                    <option value="<?= (int)$d['id'] ?>"><?= e($d['nama_dompet']) ?> (<?= rupiah($d['saldo']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-7">
                                <label class="form-label small mb-1">Modal Awal (Rp)</label>
                                <input type="number" name="modal_awal" min="0" step="1" value="0" class="form-control form-control-sm">
                            </div>
                            <div class="col-5">
                                <label class="form-label small mb-1">Jumlah Unit</label>
                                <input type="number" name="unit_awal" min="0" step="0.0001" value="0" class="form-control form-control-sm">
                            </div>
                        </div>
                        <div>
                            <label class="form-label small mb-1">Tanggal</label>
                            <input type="date" name="tanggal" value="<?= date('Y-m-d') ?>" class="form-control form-control-sm">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Catatan (Opsional)</label>
                        <input type="text" name="catatan" class="form-control" placeholder="Platform / Tujuan jangka panjang">
                    </div>

                    <button type="submit" class="btn btn-primary w-100">Simpan Instrumen</button>
                </form>
            </div>
        </div>

        <!-- Grafik Proporsi Portofolio -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-bold">Alokasi Portofolio per Jenis</div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <?php if (empty($alokasiJenis)): ?>
                    <p class="text-muted small mb-0">Belum ada aset bernilai aktif untuk ditampilkan.</p>
                <?php else: ?>
                    <canvas id="investasiChart" style="max-height: 240px;"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- KOLOM KANAN: DAFTAR ASET & RIWAYAT TRANSAKSI INVESTASI -->
    <div class="col-lg-8">
        <!-- Daftar Kartu Aset Investasi -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-bold">Daftar Aset Investasi Keluarga</span>
                    <span class="badge bg-primary-subtle text-primary"><?= $totalRowsAset ?> Aset</span>
                </div>
                <form method="GET" class="row g-2">
                    <input type="hidden" name="inv_id" value="<?= $filterInvId ?>">
                    <input type="hidden" name="jenis_aktivitas" value="<?= e($filterAktivitas) ?>">
                    <input type="hidden" name="bulan_riwayat" value="<?= e($bulanRiwayat) ?>">
                    <input type="hidden" name="page_riwayat" value="<?= $pageRiwayat ?>">

                    <div class="col-md-4">
                        <select name="jenis_aset" class="form-select form-select-sm">
                            <option value="">Semua Jenis Instrumen</option>
                            <?php foreach ($jenisList as $j): ?>
                                <option value="<?= e($j) ?>" <?= $filterJenisAset === $j ? 'selected' : '' ?>><?= e($j) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <input type="text" name="q_aset" value="<?= e($qAset) ?>" class="form-control form-control-sm" placeholder="Cari nama aset / produk...">
                    </div>
                    <div class="col-md-3 d-flex gap-1">
                        <button type="submit" class="btn btn-primary btn-sm flex-fill">Filter Aset</button>
                        <?php if ($isAsetFiltered): ?>
                            <a href="<?= e(buildInvestasiUrl($baseParams, ['jenis_aset' => '', 'q_aset' => '', 'page_aset' => 1])) ?>" class="btn btn-outline-secondary btn-sm" title="Reset"><i class="bi bi-x"></i></a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            <div class="card-body">
                <?php if (empty($daftarInvestasi)): ?>
                    <div class="alert alert-info mb-0">
                        <?= $isAsetFiltered ? 'Tidak ada aset investasi yang sesuai dengan filter yang dipilih.' : 'Belum ada portofolio investasi yang tercatat. Tambahkan instrumen investasi pertama keluarga Anda melalui formulir di samping.' ?>
                    </div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($daftarInvestasi as $inv):
                            $modal = (float)$inv['modal_pokok'];
                            $pasar = (float)$inv['nilai_pasar'];
                            $selisih = $pasar - $modal;
                            $roi = $modal > 0 ? round(($selisih / $modal) * 100, 2) : 0.0;
                            $unitFmt = rtrim(rtrim(number_format((float)$inv['jumlah_unit'], 4, ',', '.'), '0'), ',');
                        ?>
                            <div class="col-md-6">
                                <div class="border rounded p-3 h-100 d-flex flex-column bg-white">
                                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                        <div>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle mb-1">
                                                <?= e($inv['jenis_aset']) ?>
                                            </span>
                                            <h5 class="fw-bold mb-0"><?= e($inv['nama_aset']) ?></h5>
                                            <div class="small text-muted">
                                                Kepemilikan: <strong><?= $unitFmt ?: '0' ?> <?= e($inv['satuan']) ?></strong>
                                            </div>
                                        </div>
                                        <div class="d-flex gap-1">
                                            <button type="button"
                                                    class="btn btn-outline-primary btn-sm py-0 px-2"
                                                    title="Edit Info & Update Nilai Pasar"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalEditAset"
                                                    data-id="<?= (int)$inv['id'] ?>"
                                                    data-nama="<?= e($inv['nama_aset']) ?>"
                                                    data-jenis="<?= e($inv['jenis_aset']) ?>"
                                                    data-satuan="<?= e($inv['satuan']) ?>"
                                                    data-unit="<?= (float)$inv['jumlah_unit'] ?>"
                                                    data-pasar="<?= $pasar ?>"
                                                    data-catatan="<?= e($inv['catatan'] ?? '') ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button type="button"
                                                    class="btn btn-outline-danger btn-sm py-0 px-2"
                                                    title="Hapus Aset"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalHapusAset"
                                                    data-id="<?= (int)$inv['id'] ?>"
                                                    data-nama="<?= e($inv['nama_aset']) ?>"
                                                    data-modal="<?= $modal ?>"
                                                    data-modal-rp="<?= e(rupiah($modal)) ?>">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="bg-light rounded p-2 mb-3 small">
                                        <div class="d-flex justify-content-between mb-1">
                                            <span class="text-muted">Modal Pokok:</span>
                                            <span class="fw-semibold"><?= rupiah($modal) ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between mb-1">
                                            <span class="text-muted">Nilai Pasar:</span>
                                            <span class="fw-bold text-primary"><?= rupiah($pasar) ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between pt-1 border-top">
                                            <span class="text-muted">Untung / Rugi:</span>
                                            <span class="fw-bold <?= $selisih >= 0 ? 'text-success' : 'text-danger' ?>">
                                                <?= ($selisih >= 0 ? '+' : '-') . rupiah(abs($selisih)) ?> (<?= ($roi >= 0 ? '+' : '') . $roi ?>%)
                                            </span>
                                        </div>
                                    </div>

                                    <?php if (!empty($inv['catatan'])): ?>
                                        <p class="small text-muted mb-3"><i class="bi bi-info-circle me-1"></i><?= e($inv['catatan']) ?></p>
                                    <?php endif; ?>

                                    <div class="d-grid gap-2 d-sm-flex mt-auto">
                                        <button type="button"
                                                class="btn btn-success btn-sm flex-fill"
                                                data-bs-toggle="modal"
                                                data-bs-target="#modalBeliAset"
                                                data-id="<?= (int)$inv['id'] ?>"
                                                data-nama="<?= e($inv['nama_aset']) ?>"
                                                data-satuan="<?= e($inv['satuan']) ?>">
                                            <i class="bi bi-plus-circle me-1"></i>Beli
                                        </button>
                                        <button type="button"
                                                class="btn btn-outline-info btn-sm flex-fill"
                                                data-bs-toggle="modal"
                                                data-bs-target="#modalDividenAset"
                                                data-id="<?= (int)$inv['id'] ?>"
                                                data-nama="<?= e($inv['nama_aset']) ?>">
                                            <i class="bi bi-cash-coin me-1"></i>Dividen
                                        </button>
                                        <button type="button"
                                                class="btn btn-outline-warning text-dark btn-sm flex-fill"
                                                data-bs-toggle="modal"
                                                data-bs-target="#modalJualAset"
                                                data-id="<?= (int)$inv['id'] ?>"
                                                data-nama="<?= e($inv['nama_aset']) ?>"
                                                data-satuan="<?= e($inv['satuan']) ?>"
                                                data-modal="<?= $modal ?>"
                                                data-pasar="<?= $pasar ?>"
                                                data-unit="<?= (float)$inv['jumlah_unit'] ?>"
                                                <?= $modal <= 0 ? 'disabled' : '' ?>>
                                            <i class="bi bi-box-arrow-up-right me-1"></i>Jual
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($totalPagesAset > 1): ?>
                <div class="card-footer bg-white d-flex justify-content-between align-items-center py-2">
                    <small class="text-muted">Hal. <?= $pageAset ?> dari <?= $totalPagesAset ?> (<?= $totalRowsAset ?> aset)</small>
                    <?php renderInvestasiPagination($pageAset, $totalPagesAset, 'page_aset', $baseParams); ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Tabel Riwayat Aktivitas Investasi -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-bold">Riwayat Aktivitas Portofolio</span>
                    <?php if ($isRiwayatFiltered): ?>
                        <a href="<?= e(buildInvestasiUrl($baseParams, ['inv_id' => 0, 'jenis_aktivitas' => '', 'bulan_riwayat' => '', 'page_riwayat' => 1])) ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Reset Filter
                        </a>
                    <?php endif; ?>
                </div>
                <form method="GET" class="row g-2">
                    <input type="hidden" name="jenis_aset" value="<?= e($filterJenisAset) ?>">
                    <input type="hidden" name="q_aset" value="<?= e($qAset) ?>">
                    <input type="hidden" name="page_aset" value="<?= $pageAset ?>">

                    <div class="col-md-4">
                        <select name="inv_id" class="form-select form-select-sm">
                            <option value="0">Semua Instrumen Aset</option>
                            <?php foreach ($allAsetDropdown as $asetOpt): ?>
                                <option value="<?= (int)$asetOpt['id'] ?>" <?= $filterInvId === (int)$asetOpt['id'] ? 'selected' : '' ?>>
                                    <?= e($asetOpt['nama_aset']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="jenis_aktivitas" class="form-select form-select-sm">
                            <option value="">Semua Aktivitas</option>
                            <option value="beli" <?= $filterAktivitas === 'beli' ? 'selected' : '' ?>>Beli / Top-Up</option>
                            <option value="jual" <?= $filterAktivitas === 'jual' ? 'selected' : '' ?>>Jual / Cairkan</option>
                            <option value="dividen" <?= $filterAktivitas === 'dividen' ? 'selected' : '' ?>>Dividen / Kupon</option>
                            <option value="valuasi" <?= $filterAktivitas === 'valuasi' ? 'selected' : '' ?>>Update Harga</option>
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
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Tanggal</th>
                            <th>Aset & Aktivitas</th>
                            <th>Dompet</th>
                            <th class="text-end">Nominal Kas</th>
                            <th class="text-end">Untung / Rugi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($riwayatInvestasi)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    <?= $isRiwayatFiltered ? 'Tidak ada riwayat aktivitas yang sesuai dengan filter.' : 'Belum ada riwayat transaksi investasi.' ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($riwayatInvestasi as $ri):
                                $badgeMap = [
                                    'beli'    => ['bg-primary-subtle text-primary', 'Beli / Top-Up'],
                                    'jual'    => ['bg-warning-subtle text-dark', 'Jual / Cairkan'],
                                    'dividen' => ['bg-success-subtle text-success', 'Dividen / Kupon'],
                                    'valuasi' => ['bg-secondary-subtle text-secondary', 'Update Harga'],
                                ];
                                [$badgeClass, $labelAktivitas] = $badgeMap[$ri['jenis_aktivitas']] ?? ['bg-light text-dark', $ri['jenis_aktivitas']];
                                $gl = (float)$ri['keuntungan_rugi'];
                            ?>
                                <tr>
                                    <td class="small"><?= e($ri['tanggal']) ?></td>
                                    <td>
                                        <span class="badge <?= $badgeClass ?> me-1"><?= e($labelAktivitas) ?></span>
                                        <span class="fw-semibold"><?= e($ri['nama_aset']) ?></span>
                                        <div class="small text-muted"><?= e($ri['keterangan'] ?: '-') ?></div>
                                    </td>
                                    <td class="small"><?= e($ri['nama_dompet'] ?? '-') ?></td>
                                    <td class="text-end fw-semibold"><?= rupiah($ri['nominal_kas']) ?></td>
                                    <td class="text-end fw-bold <?= $gl > 0 ? 'text-success' : ($gl < 0 ? 'text-danger' : 'text-muted') ?>">
                                        <?= $gl == 0 ? '-' : (($gl > 0 ? '+' : '-') . rupiah(abs($gl))) ?>
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
                        Menampilkan <?= $offsetRiwayat + 1 ?>–<?= min($offsetRiwayat + $perPageRiwayat, $totalRowsRiwayat) ?> dari <?= $totalRowsRiwayat ?> aktivitas
                    </small>
                    <?php renderInvestasiPagination($pageRiwayat, $totalPagesRiwayat, 'page_riwayat', $baseParams); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- MODAL 1: BELI / TOP-UP ASET -->
<div class="modal fade" id="modalBeliAset" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="aksi" value="beli_aset">
                <input type="hidden" name="investasi_id" id="beliInvId">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Beli / Top-Up Investasi</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Instrumen Aset</label>
                        <input type="text" id="beliInvNama" class="form-control bg-light" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Sumber Dana Dompet</label>
                        <select name="dompet_id" class="form-select" required>
                            <?php foreach ($daftarDompet as $d): ?>
                                <option value="<?= (int)$d['id'] ?>"><?= e($d['nama_dompet']) ?> (<?= rupiah($d['saldo']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-7">
                            <label class="form-label small fw-semibold">Nominal Beli (Rp)</label>
                            <input type="number" name="nominal" min="1000" step="1" class="form-control" required>
                        </div>
                        <div class="col-5">
                            <label class="form-label small fw-semibold">Unit (<span id="beliInvSatuan">Unit</span>)</label>
                            <input type="number" name="jumlah_unit" min="0" step="0.0001" value="0" class="form-control">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Tanggal Transaksi</label>
                        <input type="date" name="tanggal" value="<?= date('Y-m-d') ?>" class="form-control" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Keterangan (Opsional)</label>
                        <input type="text" name="keterangan" class="form-control" placeholder="Pembelian rutin bulanan">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-success">Proses Pembelian</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL 2: EDIT ASET & UPDATE NILAI PASAR -->
<div class="modal fade" id="modalEditAset" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="aksi" value="edit_aset">
                <input type="hidden" name="investasi_id" id="editInvId">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Edit Aset & Update Nilai Pasar</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Nama Aset</label>
                        <input type="text" name="nama_aset" id="editInvNama" class="form-control" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-7">
                            <label class="form-label small fw-semibold">Jenis Instrumen</label>
                            <select name="jenis_aset" id="editInvJenis" class="form-select" required>
                                <?php foreach ($jenisList as $j): ?>
                                    <option value="<?= e($j) ?>"><?= e($j) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-5">
                            <label class="form-label small fw-semibold">Satuan</label>
                            <input type="text" name="satuan" id="editInvSatuan" class="form-control" required>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Total Unit Dimiliki</label>
                            <input type="number" name="jumlah_unit" id="editInvUnit" min="0" step="0.0001" class="form-control" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold text-primary">Nilai Pasar Terkini (Rp)</label>
                            <input type="number" name="nilai_pasar" id="editInvPasar" min="0" step="1" class="form-control border-primary" required>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Catatan</label>
                        <input type="text" name="catatan" id="editInvCatatan" class="form-control">
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

<!-- MODAL 3: DIVIDEN / KUPON / BAGI HASIL -->
<div class="modal fade" id="modalDividenAset" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="aksi" value="catat_dividen">
                <input type="hidden" name="investasi_id" id="divInvId">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Catat Dividen / Kupon / Bagi Hasil</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Instrumen Aset</label>
                        <input type="text" id="divInvNama" class="form-control bg-light" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Dompet Penerima Dana</label>
                        <select name="dompet_id" class="form-select" required>
                            <?php foreach ($daftarDompet as $d): ?>
                                <option value="<?= (int)$d['id'] ?>"><?= e($d['nama_dompet']) ?> (<?= rupiah($d['saldo']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-7">
                            <label class="form-label small fw-semibold">Nominal Diterima (Rp)</label>
                            <input type="number" name="nominal" min="1" step="1" class="form-control" required>
                        </div>
                        <div class="col-5">
                            <label class="form-label small fw-semibold">Tanggal</label>
                            <input type="date" name="tanggal" value="<?= date('Y-m-d') ?>" class="form-control" required>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Keterangan</label>
                        <input type="text" name="keterangan" class="form-control" placeholder="Kupon bulanan SBN / Dividen tahunan">
                    </div>
                    <div class="form-text">Nominal ini otomatis menambah saldo dompet dan dicatat sebagai Pemasukan di laporan arus kas.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-info text-white">Simpan Imbal Hasil</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL 4: JUAL / CAIRKAN ASET -->
<div class="modal fade" id="modalJualAset" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="aksi" value="jual_aset">
                <input type="hidden" name="investasi_id" id="jualInvId">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Jual / Cairkan Aset Investasi</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Instrumen Aset</label>
                        <input type="text" id="jualInvNama" class="form-control bg-light" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Dompet Tujuan Pencairan</label>
                        <select name="dompet_id" class="form-select" required>
                            <?php foreach ($daftarDompet as $d): ?>
                                <option value="<?= (int)$d['id'] ?>"><?= e($d['nama_dompet']) ?> (<?= rupiah($d['saldo']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Dana Cair Diterima (Rp)</label>
                            <input type="number" name="nominal_cair" id="jualNominalCair" min="1" step="1" class="form-control" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Porsi Modal Dilepas (Rp)</label>
                            <input type="number" name="porsi_modal" id="jualPorsiModal" min="0" step="1" class="form-control" required>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Unit Dijual (<span id="jualInvSatuan">Unit</span>)</label>
                            <input type="number" name="unit_jual" id="jualUnit" min="0" step="0.0001" class="form-control">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Tanggal Pencairan</label>
                            <input type="date" name="tanggal" value="<?= date('Y-m-d') ?>" class="form-control" required>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Keterangan</label>
                        <input type="text" name="keterangan" class="form-control" placeholder="Pencairan sebagian / seluruh aset">
                    </div>
                    <div class="form-text">Selisih antara Dana Cair dan Porsi Modal otomatis dicatat sebagai keuntungan/kerugian investasi pada laporan bulanan.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-warning">Proses Pencairan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL 5: HAPUS ASET INVESTASI -->
<div class="modal fade" id="modalHapusAset" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="aksi" value="hapus_aset">
                <input type="hidden" name="investasi_id" id="hapusInvId">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-danger">Hapus Instrumen Investasi</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">Apakah Anda yakin ingin menghapus instrumen <strong id="hapusInvNama"></strong>?</p>
                    <div id="boxRefundModal" class="alert alert-warning small mb-0 d-none">
                        <p class="mb-2">Instrumen ini masih memiliki modal pokok sebesar <strong id="hapusInvModalRp"></strong>. Pilih dompet tujuan pengembalian dana:</p>
                        <select name="dompet_kembali_id" class="form-select form-select-sm">
                            <?php foreach ($daftarDompet as $d): ?>
                                <option value="<?= (int)$d['id'] ?>">Kembalikan ke <?= e($d['nama_dompet']) ?> (<?= rupiah($d['saldo']) ?>)</option>
                            <?php endforeach; ?>
                            <option value="0">Hapus tanpa mengembalikan saldo ke dompet</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger">Ya, Hapus Aset</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if (!empty($alokasiJenis)): ?>
<script>
const ctxInv = document.getElementById('investasiChart');
new Chart(ctxInv, {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_column($alokasiJenis, 'jenis_aset')) ?>,
        datasets: [{
            data: <?= json_encode(array_map('floatval', array_column($alokasiJenis, 'total_nilai'))) ?>,
            backgroundColor: ['#0d6efd', '#198754', '#ffc107', '#6f42c1', '#fd7e14', '#20c997']
        }]
    },
    options: { plugins: { legend: { position: 'bottom' } } }
});
</script>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const mBeli = document.getElementById('modalBeliAset');
    if (mBeli) {
        mBeli.addEventListener('show.bs.modal', function (e) {
            const b = e.relatedTarget;
            document.getElementById('beliInvId').value = b.getAttribute('data-id');
            document.getElementById('beliInvNama').value = b.getAttribute('data-nama');
            document.getElementById('beliInvSatuan').textContent = b.getAttribute('data-satuan');
        });
    }

    const mEdit = document.getElementById('modalEditAset');
    if (mEdit) {
        mEdit.addEventListener('show.bs.modal', function (e) {
            const b = e.relatedTarget;
            document.getElementById('editInvId').value = b.getAttribute('data-id');
            document.getElementById('editInvNama').value = b.getAttribute('data-nama');
            document.getElementById('editInvJenis').value = b.getAttribute('data-jenis');
            document.getElementById('editInvSatuan').value = b.getAttribute('data-satuan');
            document.getElementById('editInvUnit').value = b.getAttribute('data-unit');
            document.getElementById('editInvPasar').value = b.getAttribute('data-pasar');
            document.getElementById('editInvCatatan').value = b.getAttribute('data-catatan');
        });
    }

    const mDiv = document.getElementById('modalDividenAset');
    if (mDiv) {
        mDiv.addEventListener('show.bs.modal', function (e) {
            const b = e.relatedTarget;
            document.getElementById('divInvId').value = b.getAttribute('data-id');
            document.getElementById('divInvNama').value = b.getAttribute('data-nama');
        });
    }

    const mJual = document.getElementById('modalJualAset');
    if (mJual) {
        mJual.addEventListener('show.bs.modal', function (e) {
            const b = e.relatedTarget;
            document.getElementById('jualInvId').value = b.getAttribute('data-id');
            document.getElementById('jualInvNama').value = b.getAttribute('data-nama');
            document.getElementById('jualInvSatuan').textContent = b.getAttribute('data-satuan');
            document.getElementById('jualNominalCair').value = b.getAttribute('data-pasar');
            document.getElementById('jualPorsiModal').value = b.getAttribute('data-modal');
            document.getElementById('jualUnit').value = b.getAttribute('data-unit');
        });
    }

    const mHapus = document.getElementById('modalHapusAset');
    if (mHapus) {
        mHapus.addEventListener('show.bs.modal', function (e) {
            const b = e.relatedTarget;
            const modal = parseFloat(b.getAttribute('data-modal') || '0');
            document.getElementById('hapusInvId').value = b.getAttribute('data-id');
            document.getElementById('hapusInvNama').textContent = b.getAttribute('data-nama');
            document.getElementById('hapusInvModalRp').textContent = b.getAttribute('data-modal-rp');
            document.getElementById('boxRefundModal').classList.toggle('d-none', modal <= 0);
        });
    }
});
</script>
<?php renderFooter(); ?>

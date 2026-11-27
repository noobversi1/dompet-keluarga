<?php
function renderHeader(string $title, string $active): void
{
    $flash = getFlash();
    $namaUser = $_SESSION['user_nama'] ?? 'Pengguna';
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?> - Dompet Keluarga</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
</head>
<body class="bg-light d-flex flex-column min-vh-100">
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-bold" href="index.php"><i class="bi bi-wallet2 me-2"></i>Dompet Keluarga</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item"><a class="nav-link <?= $active === 'dashboard' ? 'active fw-semibold' : '' ?>" href="index.php">Dashboard</a></li>
                <li class="nav-item"><a class="nav-link <?= $active === 'transaksi' ? 'active fw-semibold' : '' ?>" href="transaksi.php">Transaksi</a></li>
                <li class="nav-item"><a class="nav-link <?= $active === 'transfer' ? 'active fw-semibold' : '' ?>" href="transfer.php">Transfer</a></li>
                <li class="nav-item"><a class="nav-link <?= $active === 'budgeting' ? 'active fw-semibold' : '' ?>" href="budgeting.php">Kategori & Budgeting</a></li>
                <li class="nav-item"><a class="nav-link <?= $active === 'tabungan' ? 'active fw-semibold' : '' ?>" href="tabungan.php">Target Tabungan</a></li>
                <li class="nav-item"><a class="nav-link <?= $active === 'investasi' ? 'active fw-semibold' : '' ?>" href="investasi.php">Investasi</a></li>
            </ul>
            <div class="d-flex align-items-center text-white gap-3">
                <a href="profil.php" class="text-white text-decoration-none small <?= $active === 'profil' ? 'fw-bold text-decoration-underline' : '' ?>" title="Pengaturan Akun">
                    <i class="bi bi-person-circle me-1"></i><?= e($namaUser) ?>
                </a>
                <form method="POST" action="logout.php" class="m-0">
                    <?= csrfField() ?>
                    <button type="submit" class="btn btn-outline-light btn-sm">Keluar</button>
                </form>
            </div>
        </div>
    </div>
</nav>
<div class="container py-4 flex-grow-1">
    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= e($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php
}

function renderFooter(): void
{
    ?>
</div>
<footer class="mt-auto py-3">
    <div class="container text-end">
        <small class="text-muted" style="font-size: 0.78rem;">Developed by: Tarigan Hosting</small>
    </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
    <?php
}

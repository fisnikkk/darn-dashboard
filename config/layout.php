<?php
/**
 * DARN Dashboard - Main Layout
 * Mirrors the Excel workbook tabs as sidebar navigation
 */
require_once __DIR__ . '/auth.php';

function renderLayout($pageTitle, $currentPage, $content) {
    $pages = [
        ['id' => 'distribuimi', 'icon' => 'fa-truck', 'label' => 'Distribuimi'],
        ['id' => 'shpenzimet', 'icon' => 'fa-money-bill-wave', 'label' => 'Shpenzimet'],
        ['id' => 'plini_depo', 'icon' => 'fa-gas-pump', 'label' => 'Plini Depo'],
        ['id' => 'shitje_produkteve', 'icon' => 'fa-shopping-cart', 'label' => 'Shitje Produkteve'],
        ['id' => 'kontrata', 'icon' => 'fa-file-contract', 'label' => 'Kontrata'],
        ['id' => 'gjendja_bankare', 'icon' => 'fa-university', 'label' => 'Gjendja Bankare'],
        ['id' => 'nxemese', 'icon' => 'fa-fire', 'label' => 'Nxemëse'],
        ['id' => 'borxhet', 'icon' => 'fa-balance-scale', 'label' => 'Borxhet'],
        ['id' => 'fatura', 'icon' => 'fa-file-invoice', 'label' => 'Fatura'],
        ['id' => 'kartela', 'icon' => 'fa-id-card', 'label' => 'Kartela'],
        ['id' => 'log', 'icon' => 'fa-history', 'label' => 'Log'],
        ['id' => 'snapshot', 'icon' => 'fa-database', 'label' => 'Snapshot'],
        ['id' => 'monthly_profit', 'icon' => 'fa-chart-line', 'label' => 'Monthly Profit'],
        ['id' => 'litrat', 'icon' => 'fa-tint', 'label' => 'Litrat'],
        // Klientet tab removed per user request
        ['id' => 'stoku_zyrtar', 'icon' => 'fa-boxes', 'label' => 'Stoku Zyrtar'],
        ['id' => 'depo', 'icon' => 'fa-warehouse', 'label' => 'Depo'],
        ['id' => 'notes', 'icon' => 'fa-sticky-note', 'label' => 'Notes'],
        ['id' => 'import', 'icon' => 'fa-file-upload', 'label' => 'Import Excel'],
        ['id' => 'users', 'icon' => 'fa-users-cog', 'label' => 'Perdoruesit'],
    ];
?>
<!DOCTYPE html>
<html lang="sq">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - DARN Group Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head>
<body>
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h2>DARN Group</h2>
            <small>LPG Dashboard</small>
        </div>
        <nav class="sidebar-nav">
            <a href="/index.php" class="nav-item <?= $currentPage === 'overview' ? 'active' : '' ?>">
                <i class="fas fa-tachometer-alt"></i> <span>Pasqyra</span>
            </a>
            <?php foreach ($pages as $page): ?>
            <a href="/pages/<?= $page['id'] ?>.php" 
               class="nav-item <?= $currentPage === $page['id'] ? 'active' : '' ?>">
                <i class="fas <?= $page['icon'] ?>"></i> <span><?= $page['label'] ?></span>
            </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">
            <div class="nav-item" style="color:var(--text-muted);font-size:0.8rem;cursor:default;">
                <i class="fas fa-user-circle"></i> <span><?= htmlspecialchars(getCurrentUser()) ?></span>
            </div>
            <a href="?logout=1" class="nav-item" style="color:#f87171;"><i class="fas fa-sign-out-alt"></i> <span>Dil</span></a>
        </div>
    </aside>

    <!-- Sidebar backdrop for mobile -->
    <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeMobileSidebar()"></div>

    <!-- Main Content -->
    <main class="main-content">
        <header class="top-bar">
            <button class="menu-toggle" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>
            <h1><?= htmlspecialchars($pageTitle) ?></h1>
            <div class="top-bar-right">
                <span class="date-display"><?= date('d/m/Y H:i') ?></span>
            </div>
        </header>
        <div class="content-area">
            <?= $content ?>
        </div>
    </main>

    <script src="/assets/js/app.js?v=<?= filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
</body>
</html>
<?php
}

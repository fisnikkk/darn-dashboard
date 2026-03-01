<?php
/**
 * DARN Dashboard - Main Layout
 * Mirrors the Excel workbook tabs as sidebar navigation
 */
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
        ['id' => 'monthly_profit', 'icon' => 'fa-chart-line', 'label' => 'Monthly Profit'],
        ['id' => 'litrat', 'icon' => 'fa-tint', 'label' => 'Litrat'],
        ['id' => 'klientet', 'icon' => 'fa-users', 'label' => 'Klientët'],
        ['id' => 'stoku_zyrtar', 'icon' => 'fa-boxes', 'label' => 'Stoku Zyrtar'],
        ['id' => 'depo', 'icon' => 'fa-warehouse', 'label' => 'Depo'],
    ];
?>
<!DOCTYPE html>
<html lang="sq">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - DARN Group Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/style.css">
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
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <header class="top-bar">
            <button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('collapsed')">
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

<?php
require_once __DIR__ . '/config/database.php';
header('Content-Type: text/plain; charset=utf-8');
$db = getDB();

// Production cash total and count
$total = $db->query("SELECT SUM(totali) FROM shitje_produkteve WHERE LOWER(TRIM(menyra_pageses)) = 'cash'")->fetchColumn();
$count = $db->query("SELECT COUNT(*) FROM shitje_produkteve WHERE LOWER(TRIM(menyra_pageses)) = 'cash'")->fetchColumn();
echo "DB cash: $total ($count rows)\n\n";

// Rows added after Excel date (Feb 9 2026)
echo "Rows with date AFTER 2026-02-09:\n";
$rows = $db->query("SELECT id, data, totali, menyra_pageses, klienti, produkti FROM shitje_produkteve WHERE data > '2026-02-09' ORDER BY data DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) echo "  ID={$r['id']} date={$r['data']} total={$r['totali']} pay={$r['menyra_pageses']} client={$r['klienti']} product={$r['produkti']}\n";
if (!$rows) echo "  (none)\n";

// Also show the last 10 rows by ID to see most recently added
echo "\nLast 10 rows by ID (most recently added):\n";
$rows = $db->query("SELECT id, data, totali, menyra_pageses, klienti, produkti FROM shitje_produkteve ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) echo "  ID={$r['id']} date={$r['data']} total={$r['totali']} pay={$r['menyra_pageses']} client={$r['klienti']} product={$r['produkti']}\n";

// Check gratis rows
echo "\nGratis rows:\n";
$rows = $db->query("SELECT id, data, totali, klienti, produkti FROM shitje_produkteve WHERE LOWER(TRIM(menyra_pageses)) = 'gratis'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) echo "  ID={$r['id']} date={$r['data']} total={$r['totali']} client={$r['klienti']} product={$r['produkti']}\n";

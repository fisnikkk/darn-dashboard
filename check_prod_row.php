<?php
header('Content-Type: text/plain; charset=utf-8');
require 'config/database.php';
$db = getDB();
echo "=== ROW 312651 ON THIS DB ===\n";
$row = $db->prepare('SELECT id, klienti, sasia, litra, cmimi, pagesa, menyra_e_pageses, litrat_total, litrat_e_konvertuara FROM distribuimi WHERE id = ?');
$row->execute([312651]);
$r = $row->fetch();
if ($r) {
    foreach ($r as $k => $v) echo "  $k: " . ($v ?? 'NULL') . "\n";
    echo "\n  Expected pagesa (sasia*litra*cmimi): " . round($r['sasia'] * $r['litra'] * $r['cmimi'], 2) . "\n";
} else {
    echo "  (row not found)\n";
}
echo "\n=== CHANGELOG COUNT ===\n";
echo "Total entries: " . $db->query("SELECT COUNT(*) FROM changelog")->fetchColumn() . "\n";

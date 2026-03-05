<?php
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$table = $input['table'] ?? '';
$id = (int)($input['id'] ?? 0);

$allowed = ['distribuimi','shpenzimet','plini_depo','shitje_produkteve',
            'kontrata','gjendja_bankare','nxemese','klientet',
            'stoku_zyrtar','depo'];

if (!in_array($table, $allowed) || !$id) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

try {
    $db = getDB();

    // Fetch full row before deletion for changelog
    $fetchRow = $db->prepare("SELECT * FROM {$table} WHERE id = ?");
    $fetchRow->execute([$id]);
    $deletedRow = $fetchRow->fetch();

    // For gjendja_bankare, recalculate running bilanci after deletion
    if ($table === 'gjendja_bankare') {
        $db->beginTransaction();

        $db->prepare("DELETE FROM {$table} WHERE id = ?")->execute([$id]);

        // Log the deletion
        if ($deletedRow) {
            $db->prepare("INSERT INTO changelog (action_type, table_name, row_id, field_name, old_value, new_value) VALUES ('delete', ?, ?, NULL, ?, NULL)")
                ->execute([$table, $id, json_encode($deletedRow, JSON_UNESCAPED_UNICODE)]);
        }

        // Recalculate bilanci for ALL rows from the beginning (safest approach)
        $all = $db->query("SELECT id, debia, kredi FROM gjendja_bankare ORDER BY data ASC, id ASC")->fetchAll();
        $running = 0;
        foreach ($all as $r) {
            $running = round($running + (float)$r['kredi'] + (float)$r['debia'], 2);
            $db->prepare("UPDATE gjendja_bankare SET bilanci = ? WHERE id = ?")->execute([$running, $r['id']]);
        }

        $db->commit();
    } else {
        $db->prepare("DELETE FROM {$table} WHERE id = ?")->execute([$id]);

        // Log the deletion
        if ($deletedRow) {
            $db->prepare("INSERT INTO changelog (action_type, table_name, row_id, field_name, old_value, new_value) VALUES ('delete', ?, ?, NULL, ?, NULL)")
                ->execute([$table, $id, json_encode($deletedRow, JSON_UNESCAPED_UNICODE)]);
        }
    }

    echo json_encode(['success' => true, 'reload' => true]);
} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

<?php
/**
 * DARN Dashboard - Invoice API
 * Actions: next_number, preview, create, download, history
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json');

try {
    $db = getDB();
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    switch ($action) {

        // ─── Get next invoice number ───
        case 'next_number':
            $stmt = $db->query("SELECT setting_value FROM invoice_settings WHERE setting_key = 'next_invoice_number'");
            $num = $stmt->fetchColumn();
            echo json_encode(['success' => true, 'next_number' => intval($num ?: 131)]);
            break;

        // ─── Preview transactions (no side effects) ───
        case 'preview':
            $client = trim($_GET['client'] ?? '');
            $dateFrom = $_GET['date_from'] ?? '';
            $dateTo = $_GET['date_to'] ?? '';

            if (!$client || !$dateFrom || !$dateTo) {
                echo json_encode(['success' => false, 'error' => 'Mungojne parametrat (client, date_from, date_to)']);
                break;
            }

            $stmt = $db->prepare("
                SELECT id, klienti, data, sasia, boca_te_kthyera, litra, cmimi, pagesa, menyra_e_pageses, koment
                FROM distribuimi
                WHERE klienti = ? AND data BETWEEN ? AND ?
                ORDER BY data ASC, id ASC
            ");
            $stmt->execute([$client, $dateFrom, $dateTo]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $totalAmount = 0;
            $totalDelivered = 0;
            $totalReturned = 0;
            foreach ($rows as $r) {
                $totalAmount += floatval($r['pagesa']);
                $totalDelivered += intval($r['sasia']);
                $totalReturned += intval($r['boca_te_kthyera']);
            }

            echo json_encode([
                'success' => true,
                'rows' => $rows,
                'total_amount' => round($totalAmount, 2),
                'total_delivered' => $totalDelivered,
                'total_returned' => $totalReturned,
                'count' => count($rows)
            ]);
            break;

        // ─── Create invoice (generate PDF, update statuses) ───
        case 'create':
            $input = json_decode(file_get_contents('php://input'), true);
            $client = trim($input['client'] ?? '');
            $dateFrom = $input['date_from'] ?? '';
            $dateTo = $input['date_to'] ?? '';
            $invoiceNum = intval($input['invoice_number'] ?? 0);

            if (!$client || !$dateFrom || !$dateTo || !$invoiceNum) {
                echo json_encode(['success' => false, 'error' => 'Mungojne parametrat']);
                break;
            }

            // Check invoice number not already used
            $check = $db->prepare("SELECT id FROM invoices WHERE invoice_number = ?");
            $check->execute([$invoiceNum]);
            if ($check->fetch()) {
                echo json_encode(['success' => false, 'error' => "Fatura nr {$invoiceNum} ekziston tashme"]);
                break;
            }

            // Fetch transaction rows
            $stmt = $db->prepare("
                SELECT id, klienti, data, sasia, boca_te_kthyera, litra, cmimi, pagesa, menyra_e_pageses, koment
                FROM distribuimi
                WHERE klienti = ? AND data BETWEEN ? AND ?
                ORDER BY data ASC, id ASC
            ");
            $stmt->execute([$client, $dateFrom, $dateTo]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                echo json_encode(['success' => false, 'error' => 'Nuk ka te dhena per kete periudhe']);
                break;
            }

            // Get client info for PDF
            $clientStmt = $db->prepare("SELECT * FROM klientet WHERE emri = ? LIMIT 1");
            $clientStmt->execute([$client]);
            $clientInfo = $clientStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            // Generate PDF
            require_once __DIR__ . '/../lib/InvoicePDF.php';
            $pdf = new InvoicePDF(
                $invoiceNum,
                $dateFrom,
                $dateTo,
                $client,
                $clientInfo['i_regjistruar_ne_emer'] ?? '',
                $clientInfo['adresa'] ?? '',
                $clientInfo['numri_unik_identifikues'] ?? '',
                $clientInfo['telefoni'] ?? '',
                $clientInfo['email'] ?? '',
                $rows
            );
            $pdf->generate();

            // Save PDF
            $filename = $pdf->getFilename();
            $storagePath = __DIR__ . '/../storage/invoices/';
            if (!is_dir($storagePath)) {
                mkdir($storagePath, 0755, true);
            }
            $pdf->Output('F', $storagePath . $filename);

            // Calculate totals
            $totalAmount = 0;
            $totalDelivered = 0;
            $totalReturned = 0;
            $rowIds = [];
            foreach ($rows as $r) {
                $totalAmount += floatval($r['pagesa']);
                $totalDelivered += intval($r['sasia']);
                $totalReturned += intval($r['boca_te_kthyera']);
                $rowIds[] = $r['id'];
            }

            // Save invoice record
            $ins = $db->prepare("INSERT INTO invoices (invoice_number, klienti, date_from, date_to, total_amount, total_delivered, total_returned, row_ids, pdf_filename) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $ins->execute([
                $invoiceNum, $client, $dateFrom, $dateTo,
                round($totalAmount, 2), $totalDelivered, $totalReturned,
                json_encode($rowIds), $filename
            ]);
            $invoiceId = $db->lastInsertId();

            // ── Auto-update payment status: CASH → PO (FATURE TE RREGULLTE) CASH ──
            $cashIds = [];
            foreach ($rows as $r) {
                if (strtolower(trim($r['menyra_e_pageses'])) === 'cash') {
                    $cashIds[] = $r['id'];
                }
            }

            $updatedCount = 0;
            if (!empty($cashIds)) {
                $placeholders = implode(',', array_fill(0, count($cashIds), '?'));
                $upd = $db->prepare("UPDATE distribuimi SET menyra_e_pageses = 'PO (FATURE TE RREGULLTE) CASH' WHERE id IN ({$placeholders})");
                $upd->execute($cashIds);
                $updatedCount = $upd->rowCount();

                // Log changes to changelog
                $batchId = 'inv_' . $invoiceNum;
                $logStmt = $db->prepare("INSERT INTO changelog (action_type, table_name, row_id, field_name, old_value, new_value, batch_id) VALUES ('update', 'distribuimi', ?, 'menyra_e_pageses', 'CASH', 'PO (FATURE TE RREGULLTE) CASH', ?)");
                foreach ($cashIds as $rid) {
                    $logStmt->execute([$rid, $batchId]);
                }
            }

            // Increment invoice number counter
            $nextNum = $invoiceNum + 1;
            $db->prepare("UPDATE invoice_settings SET setting_value = ? WHERE setting_key = 'next_invoice_number'")->execute([$nextNum]);

            // Get client email for response
            $clientEmailForResponse = $clientInfo['email'] ?? '';

            echo json_encode([
                'success' => true,
                'invoice_id' => $invoiceId,
                'invoice_number' => $invoiceNum,
                'filename' => $filename,
                'download_url' => '/api/invoice.php?action=download&id=' . $invoiceId,
                'total_amount' => round($totalAmount, 2),
                'total_rows' => count($rows),
                'cash_updated' => $updatedCount,
                'client_email' => $clientEmailForResponse
            ]);
            break;

        // ─── Download PDF ───
        case 'download':
            $id = intval($_GET['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'Mungon ID']);
                break;
            }

            $stmt = $db->prepare("SELECT pdf_filename FROM invoices WHERE id = ?");
            $stmt->execute([$id]);
            $inv = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$inv || !$inv['pdf_filename']) {
                echo json_encode(['success' => false, 'error' => 'Fatura nuk u gjet']);
                break;
            }

            $filepath = __DIR__ . '/../storage/invoices/' . $inv['pdf_filename'];
            if (!file_exists($filepath)) {
                echo json_encode(['success' => false, 'error' => 'PDF file nuk ekziston']);
                break;
            }

            // Serve the PDF
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $inv['pdf_filename'] . '"');
            header('Content-Length: ' . filesize($filepath));
            header_remove('X-Powered-By');
            readfile($filepath);
            exit;

        // ─── Invoice history ───
        case 'history':
            $stmt = $db->query("SELECT id, invoice_number, klienti, date_from, date_to, total_amount, total_delivered, total_returned, status, created_at FROM invoices ORDER BY created_at DESC LIMIT 100");
            $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'invoices' => $invoices]);
            break;

        // ─── Client list with emails ───
        case 'clients':
            $stmt = $db->query("SELECT emri, i_regjistruar_ne_emer, email, telefoni, adresa, numri_unik_identifikues FROM klientet ORDER BY emri ASC");
            $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'clients' => $clients]);
            break;

        // ─── Sync emails from GoDaddy ───
        case 'sync_emails':
            $ch = curl_init('http://adaptive.darn-group.com/api_product.php?GetAllClients');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || !$resp) {
                echo json_encode(['success' => false, 'error' => 'Nuk u lidh me GoDaddy']);
                break;
            }

            $data = json_decode($resp, true);
            $gdClients = $data['data'] ?? [];
            if (empty($gdClients)) {
                echo json_encode(['success' => false, 'error' => 'Nuk u gjet asnje klient nga GoDaddy']);
                break;
            }

            // Build map: trimmed lowercase name => full GoDaddy record
            $emailMap = [];
            $gdFullMap = [];
            foreach ($gdClients as $gc) {
                $name = trim($gc['Name'] ?? '');
                $email = trim($gc['Email'] ?? '');
                $lowerName = mb_strtolower($name);
                if ($name !== '') {
                    $gdFullMap[$lowerName] = $gc;
                    if ($email !== '') {
                        $emailMap[$lowerName] = $email;
                    }
                }
            }

            // Get all distinct client names from distribuimi
            $distClients = $db->query("SELECT DISTINCT klienti FROM distribuimi")->fetchAll(PDO::FETCH_COLUMN);

            // Update existing klientet rows where name matches
            $updated = 0;
            $stmt = $db->prepare("UPDATE klientet SET email = ? WHERE LOWER(TRIM(emri)) = ? AND (email IS NULL OR email = '')");
            foreach ($emailMap as $lowerName => $email) {
                $stmt->execute([$email, $lowerName]);
                $updated += $stmt->rowCount();
            }

            // Insert missing clients from distribuimi that exist in GoDaddy with emails
            $inserted = 0;
            $existingNames = $db->query("SELECT LOWER(TRIM(emri)) FROM klientet")->fetchAll(PDO::FETCH_COLUMN);
            $existingSet = array_flip($existingNames);

            $insertStmt = $db->prepare("INSERT INTO klientet (emri, email, i_regjistruar_ne_emer, telefoni, adresa, numri_unik_identifikues) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($distClients as $clientName) {
                $lower = mb_strtolower(trim($clientName));
                if (!isset($existingSet[$lower]) && isset($gdFullMap[$lower])) {
                    $gc = $gdFullMap[$lower];
                    $insertStmt->execute([
                        $clientName,
                        trim($gc['Email'] ?? '') ?: null,
                        trim($gc['Bussiness'] ?? '') ?: $clientName,
                        trim($gc['PhoneNo'] ?? '') ?: null,
                        trim(($gc['Street'] ?? '') . ', ' . ($gc['City'] ?? ''), ', ') ?: null,
                        trim($gc['Unique_Number'] ?? '') ?: null
                    ]);
                    $inserted++;
                }
            }

            echo json_encode([
                'success' => true,
                'godaddy_total' => count($gdClients),
                'godaddy_with_email' => count($emailMap),
                'updated' => $updated,
                'inserted' => $inserted
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Veprim i panjohur: ' . $action]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

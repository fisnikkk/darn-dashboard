<?php
/**
 * DARN Dashboard - Invoice API
 * Actions: next_number, preview, create, download, history
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/godaddy.php';

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

            // Auto-sync client data from GoDaddy before looking up (ensures fresh info)
            try {
                $gdBaseUrl = str_replace('dashboard_export.php', '', GD_API_URL);
                $ch = curl_init($gdBaseUrl . 'api_product.php?GetAllClients');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                $resp = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200 && $resp) {
                    $gdData = json_decode($resp, true);
                    $gdClients = $gdData['data'] ?? [];
                    // Build lookup by lowercase name
                    $gdMap = [];
                    foreach ($gdClients as $gc) {
                        $n = trim($gc['Name'] ?? '');
                        if ($n !== '') $gdMap[mb_strtolower($n)] = $gc;
                    }
                    // Update klientet for this specific client
                    $lower = mb_strtolower(trim($client));
                    if (isset($gdMap[$lower])) {
                        $gc = $gdMap[$lower];
                        $email = trim($gc['Email'] ?? '') ?: null;
                        $business = trim($gc['Bussiness'] ?? '') ?: $client;
                        $phone = trim($gc['PhoneNo'] ?? '') ?: null;
                        $street = trim($gc['Street'] ?? '');
                        $city = trim($gc['City'] ?? '');
                        $address = trim($street . ($street && $city ? ', ' : '') . $city) ?: null;
                        $uniqueNum = trim($gc['Unique_Number'] ?? '') ?: null;

                        // Check if exists
                        $existCheck = $db->prepare("SELECT id FROM klientet WHERE LOWER(TRIM(emri)) = ?");
                        $existCheck->execute([$lower]);
                        if ($existCheck->fetch()) {
                            $db->prepare("UPDATE klientet SET email = ?, i_regjistruar_ne_emer = ?, telefoni = ?, adresa = ?, numri_unik_identifikues = ? WHERE LOWER(TRIM(emri)) = ?")
                                ->execute([$email, $business, $phone, $address, $uniqueNum, $lower]);
                        } else {
                            $db->prepare("INSERT INTO klientet (emri, email, i_regjistruar_ne_emer, telefoni, adresa, numri_unik_identifikues) VALUES (?, ?, ?, ?, ?, ?)")
                                ->execute([$client, $email, $business, $phone, $address, $uniqueNum]);
                        }
                    }
                }
            } catch (Exception $syncEx) {
                // Silently fail — use whatever data we have in klientet
            }

            // Get client info for PDF (primary: klientet, fallback: kontrata)
            $clientStmt = $db->prepare("SELECT * FROM klientet WHERE LOWER(TRIM(emri)) = LOWER(TRIM(?)) LIMIT 1");
            $clientStmt->execute([$client]);
            $clientInfo = $clientStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            // Fallback: fill missing fields from kontrata
            $kontrataFields = [
                'i_regjistruar_ne_emer' => 'biznesi',
                'adresa' => 'rruga',
                'numri_unik_identifikues' => 'numri_unik',
                'telefoni' => 'nr_telefonit',
                'email' => 'email',
            ];
            $needsFallback = false;
            foreach ($kontrataFields as $klientField => $kontrataField) {
                if (empty(trim($clientInfo[$klientField] ?? ''))) { $needsFallback = true; break; }
            }
            if ($needsFallback) {
                $kStmt = $db->prepare("SELECT biznesi, numri_unik, rruga, qyteti, perfaqesuesi, nr_telefonit, email FROM kontrata WHERE LOWER(TRIM(name_from_database)) = LOWER(TRIM(?)) LIMIT 1");
                $kStmt->execute([$client]);
                $kontrataInfo = $kStmt->fetch(PDO::FETCH_ASSOC);
                if ($kontrataInfo) {
                    foreach ($kontrataFields as $klientField => $kontrataField) {
                        if (empty(trim($clientInfo[$klientField] ?? '')) && !empty(trim($kontrataInfo[$kontrataField] ?? ''))) {
                            $clientInfo[$klientField] = $kontrataInfo[$kontrataField];
                        }
                    }
                }
            }

            // Get cylinder count for this client (total cylinders at their business)
            $cylStmt = $db->prepare("SELECT COALESCE(SUM(sasia) - SUM(boca_te_kthyera), 0) AS boca_tek_biznesi FROM distribuimi WHERE klienti = ?");
            $cylStmt->execute([$client]);
            $cylinderCount = intval($cylStmt->fetchColumn() ?: 0);

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
                $rows,
                $cylinderCount
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
                $logStmt = $db->prepare("INSERT INTO changelog (action_type, table_name, row_id, field_name, old_value, new_value, batch_id, username) VALUES ('update', 'distribuimi', ?, 'menyra_e_pageses', 'CASH', 'PO (FATURE TE RREGULLTE) CASH', ?, ?)");
                foreach ($cashIds as $rid) {
                    $logStmt->execute([$rid, $batchId, getCurrentUser()]);
                }
            }

            // Increment invoice number counter
            $nextNum = $invoiceNum + 1;
            $db->prepare("UPDATE invoice_settings SET setting_value = ? WHERE setting_key = 'next_invoice_number'")->execute([$nextNum]);

            // Get client email for response
            $clientEmailForResponse = $clientInfo['email'] ?? '';

            // Format invoice number with date
            $monthNum = date('m', strtotime($dateTo));
            $year = date('Y', strtotime($dateTo));
            $formattedNum = $invoiceNum . '-' . $monthNum . '-' . $year;

            echo json_encode([
                'success' => true,
                'invoice_id' => $invoiceId,
                'invoice_number' => $invoiceNum,
                'formatted_number' => $formattedNum,
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

        // ─── Delete invoice ───
        case 'delete':
            $id = $_GET['id'] ?? $_POST['id'] ?? null;
            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'Mungon ID']);
                break;
            }

            $stmt = $db->prepare("SELECT id, invoice_number, pdf_filename, row_ids FROM invoices WHERE id = ?");
            $stmt->execute([$id]);
            $inv = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$inv) {
                echo json_encode(['success' => false, 'error' => 'Fatura nuk u gjet']);
                break;
            }

            $invoiceNum = $inv['invoice_number'];

            // Revert CASH status changes via changelog
            $batchId = 'inv_' . $invoiceNum;
            $logRows = $db->prepare("SELECT row_id, old_value FROM changelog WHERE batch_id = ? AND field_name = 'menyra_e_pageses'");
            $logRows->execute([$batchId]);
            $reverted = 0;
            $revertStmt = $db->prepare("UPDATE distribuimi SET menyra_e_pageses = ? WHERE id = ?");
            foreach ($logRows as $log) {
                $revertStmt->execute([$log['old_value'], $log['row_id']]);
                $reverted += $revertStmt->rowCount();
            }

            // Delete changelog entries
            $db->prepare("DELETE FROM changelog WHERE batch_id = ?")->execute([$batchId]);

            // Delete PDF file
            $filepath = __DIR__ . '/../storage/invoices/' . $inv['pdf_filename'];
            if (file_exists($filepath)) {
                unlink($filepath);
            }

            // Delete invoice record
            $db->prepare("DELETE FROM invoices WHERE id = ?")->execute([$id]);

            // Reset counter to this invoice number (so it gets reused)
            $db->prepare("UPDATE invoice_settings SET setting_value = ? WHERE setting_key = 'next_invoice_number'")->execute([$invoiceNum]);

            echo json_encode([
                'success' => true,
                'deleted_number' => $invoiceNum,
                'reverted_statuses' => $reverted,
                'next_number' => $invoiceNum
            ]);
            break;

        // ─── Client list with emails ───
        case 'clients':
            $stmt = $db->query("SELECT emri, i_regjistruar_ne_emer, email, telefoni, adresa, numri_unik_identifikues FROM klientet ORDER BY emri ASC");
            $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'clients' => $clients]);
            break;

        // ─── Sync emails from GoDaddy ───
        case 'sync_emails':
            $gdBaseUrl = str_replace('dashboard_export.php', '', GD_API_URL);
                $ch = curl_init($gdBaseUrl . 'api_product.php?GetAllClients');
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
            $gdFullMap = [];
            $emailCount = 0;
            foreach ($gdClients as $gc) {
                $name = trim($gc['Name'] ?? '');
                $lowerName = mb_strtolower($name);
                if ($name !== '') {
                    $gdFullMap[$lowerName] = $gc;
                    if (trim($gc['Email'] ?? '') !== '') $emailCount++;
                }
            }

            // Get all distinct client names from distribuimi (the real names)
            $distClients = $db->query("SELECT DISTINCT klienti FROM distribuimi ORDER BY klienti ASC")->fetchAll(PDO::FETCH_COLUMN);

            // Delete junk klientet rows that are just numeric IDs (not real names)
            $cleaned = 0;
            $allKlientet = $db->query("SELECT id, emri FROM klientet")->fetchAll(PDO::FETCH_ASSOC);
            $deleteStmt = $db->prepare("DELETE FROM klientet WHERE id = ?");
            foreach ($allKlientet as $k) {
                if (preg_match('/^\d+$/', trim($k['emri'] ?? ''))) {
                    $deleteStmt->execute([$k['id']]);
                    $cleaned++;
                }
            }

            // Build set of existing real client names in klientet
            $existingNames = $db->query("SELECT LOWER(TRIM(emri)) FROM klientet")->fetchAll(PDO::FETCH_COLUMN);
            $existingSet = array_flip($existingNames);

            // Insert/update clients from distribuimi with GoDaddy data
            $inserted = 0;
            $updated = 0;
            $insertStmt = $db->prepare("INSERT INTO klientet (emri, email, i_regjistruar_ne_emer, telefoni, adresa, numri_unik_identifikues) VALUES (?, ?, ?, ?, ?, ?)");
            $updateStmt = $db->prepare("UPDATE klientet SET email = ?, i_regjistruar_ne_emer = ?, telefoni = ?, adresa = ?, numri_unik_identifikues = ? WHERE LOWER(TRIM(emri)) = ?");

            foreach ($distClients as $clientName) {
                $lower = mb_strtolower(trim($clientName));
                $gc = $gdFullMap[$lower] ?? null;

                if (isset($existingSet[$lower])) {
                    // Update existing record if GoDaddy has data
                    if ($gc) {
                        $email = trim($gc['Email'] ?? '') ?: null;
                        $business = trim($gc['Bussiness'] ?? '') ?: $clientName;
                        $phone = trim($gc['PhoneNo'] ?? '') ?: null;
                        $street = trim($gc['Street'] ?? '');
                        $city = trim($gc['City'] ?? '');
                        $address = trim($street . ($street && $city ? ', ' : '') . $city) ?: null;
                        $uniqueNum = trim($gc['Unique_Number'] ?? '') ?: null;
                        $updateStmt->execute([$email, $business, $phone, $address, $uniqueNum, $lower]);
                        if ($updateStmt->rowCount() > 0) $updated++;
                    }
                } else {
                    // Insert new record
                    $email = null; $business = $clientName; $phone = null; $address = null; $uniqueNum = null;
                    if ($gc) {
                        $email = trim($gc['Email'] ?? '') ?: null;
                        $business = trim($gc['Bussiness'] ?? '') ?: $clientName;
                        $phone = trim($gc['PhoneNo'] ?? '') ?: null;
                        $street = trim($gc['Street'] ?? '');
                        $city = trim($gc['City'] ?? '');
                        $address = trim($street . ($street && $city ? ', ' : '') . $city) ?: null;
                        $uniqueNum = trim($gc['Unique_Number'] ?? '') ?: null;
                    }
                    $insertStmt->execute([$clientName, $email, $business, $phone, $address, $uniqueNum]);
                    $inserted++;
                    $existingSet[$lower] = true;
                }
            }

            echo json_encode([
                'success' => true,
                'godaddy_total' => count($gdClients),
                'godaddy_with_email' => $emailCount,
                'cleaned_junk' => $cleaned,
                'inserted' => $inserted,
                'updated' => $updated
            ]);
            break;

        // ─── Preview PDF (generate temp PDF, serve inline — no side effects) ───
        case 'preview_pdf':
            $client = trim($_GET['client'] ?? '');
            $dateFrom = $_GET['date_from'] ?? '';
            $dateTo = $_GET['date_to'] ?? '';
            $invoiceNum = intval($_GET['invoice_number'] ?? 0);

            if (!$client || !$dateFrom || !$dateTo || !$invoiceNum) {
                echo json_encode(['success' => false, 'error' => 'Mungojne parametrat']);
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

            // Auto-sync client data from GoDaddy
            try {
                $gdBaseUrl = str_replace('dashboard_export.php', '', GD_API_URL);
                $ch = curl_init($gdBaseUrl . 'api_product.php?GetAllClients');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                $resp = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200 && $resp) {
                    $gdData = json_decode($resp, true);
                    $gdClients = $gdData['data'] ?? [];
                    $gdMap = [];
                    foreach ($gdClients as $gc) {
                        $n = trim($gc['Name'] ?? '');
                        if ($n !== '') $gdMap[mb_strtolower($n)] = $gc;
                    }
                    $lower = mb_strtolower(trim($client));
                    if (isset($gdMap[$lower])) {
                        $gc = $gdMap[$lower];
                        $email = trim($gc['Email'] ?? '') ?: null;
                        $business = trim($gc['Bussiness'] ?? '') ?: $client;
                        $phone = trim($gc['PhoneNo'] ?? '') ?: null;
                        $street = trim($gc['Street'] ?? '');
                        $city = trim($gc['City'] ?? '');
                        $address = trim($street . ($street && $city ? ', ' : '') . $city) ?: null;
                        $uniqueNum = trim($gc['Unique_Number'] ?? '') ?: null;

                        $existCheck = $db->prepare("SELECT id FROM klientet WHERE LOWER(TRIM(emri)) = ?");
                        $existCheck->execute([$lower]);
                        if ($existCheck->fetch()) {
                            $db->prepare("UPDATE klientet SET email = ?, i_regjistruar_ne_emer = ?, telefoni = ?, adresa = ?, numri_unik_identifikues = ? WHERE LOWER(TRIM(emri)) = ?")
                                ->execute([$email, $business, $phone, $address, $uniqueNum, $lower]);
                        } else {
                            $db->prepare("INSERT INTO klientet (emri, email, i_regjistruar_ne_emer, telefoni, adresa, numri_unik_identifikues) VALUES (?, ?, ?, ?, ?, ?)")
                                ->execute([$client, $email, $business, $phone, $address, $uniqueNum]);
                        }
                    }
                }
            } catch (Exception $syncEx) {}

            // Get client info
            $clientStmt = $db->prepare("SELECT * FROM klientet WHERE LOWER(TRIM(emri)) = LOWER(TRIM(?)) LIMIT 1");
            $clientStmt->execute([$client]);
            $clientInfo = $clientStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            // Get cylinder count
            $cylStmt = $db->prepare("SELECT COALESCE(SUM(sasia) - SUM(boca_te_kthyera), 0) FROM distribuimi WHERE klienti = ?");
            $cylStmt->execute([$client]);
            $cylinderCount = intval($cylStmt->fetchColumn() ?: 0);

            // Generate PDF (temp — not saved to invoices table)
            require_once __DIR__ . '/../lib/InvoicePDF.php';
            $pdf = new InvoicePDF(
                $invoiceNum, $dateFrom, $dateTo, $client,
                $clientInfo['i_regjistruar_ne_emer'] ?? '',
                $clientInfo['adresa'] ?? '',
                $clientInfo['numri_unik_identifikues'] ?? '',
                $clientInfo['telefoni'] ?? '',
                $clientInfo['email'] ?? '',
                $rows, $cylinderCount
            );
            $pdf->generate();

            // Serve PDF inline (for preview in iframe)
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="preview.pdf"');
            header_remove('X-Powered-By');
            $pdf->Output('I', 'preview.pdf');
            exit;

        // ─── Send email with PDF attachment via Gmail SMTP ───
        case 'send_email':
            $jsonBody = json_decode(file_get_contents('php://input'), true) ?: [];
            $id = intval($jsonBody['id'] ?? $_POST['id'] ?? $_GET['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'Mungon ID']);
                break;
            }

            $stmt = $db->prepare("SELECT invoice_number, klienti, date_from, date_to, pdf_filename FROM invoices WHERE id = ?");
            $stmt->execute([$id]);
            $inv = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$inv) {
                echo json_encode(['success' => false, 'error' => 'Fatura nuk u gjet']);
                break;
            }

            // Get client email
            $clientStmt = $db->prepare("SELECT email FROM klientet WHERE LOWER(TRIM(emri)) = LOWER(TRIM(?)) LIMIT 1");
            $clientStmt->execute([$inv['klienti']]);
            $clientEmail = $clientStmt->fetchColumn() ?: '';

            if (!$clientEmail) {
                echo json_encode(['success' => false, 'error' => 'Klienti nuk ka email te regjistruar']);
                break;
            }

            $filepath = __DIR__ . '/../storage/invoices/' . $inv['pdf_filename'];
            if (!file_exists($filepath)) {
                echo json_encode(['success' => false, 'error' => 'PDF file nuk ekziston']);
                break;
            }

            // Load Gmail API (sends via HTTPS, no SMTP ports needed)
            require_once __DIR__ . '/../lib/GmailAPI.php';

            $senderEmail = 'Sales@darngroup.com';
            $senderName = 'Darn Group L.L.C';
            $serviceAccountFile = __DIR__ . '/../config/credentials/gmail-service-account.json';

            if (!file_exists($serviceAccountFile)) {
                echo json_encode(['success' => false, 'error' => 'Gmail service account key nuk u gjet. Vendosni config/credentials/gmail-service-account.json']);
                break;
            }

            try {
                $gmail = new GmailAPI($serviceAccountFile, $senderEmail);

                // Format month for subject
                $monthNames = ['Janar','Shkurt','Mars','Prill','Maj','Qershor','Korrik','Gusht','Shtator','Tetor','Nentor','Dhjetor'];
                $monthIdx = intval(date('m', strtotime($inv['date_to']))) - 1;
                $monthFull = $monthNames[$monthIdx] ?? '';
                $monthShort = date('M', strtotime($inv['date_to']));
                $yearStr = date('Y', strtotime($inv['date_to']));

                $subject = "Fatura per {$inv['klienti']} per muajin {$monthShort}-{$yearStr}";
                $body =
                    "Pershendetje {$inv['klienti']},\n\n" .
                    "Ju lutemi gjeni te bashkangjitur faturen per muajin {$monthFull} {$yearStr}.\n" .
                    "Falemnderit per bashkepunimin tuaj!\n\n" .
                    "Sabri Kadriu\nFinance Director\nDarn Group L.L.C\n" .
                    "Bulevardi Deshmoret e Kombit, nr. 62 6/1 Prishtine 10000, Kosove\n\n" .
                    "Perfaqesues zyrtar i Hexagon Ragasco ne Kosove\n" .
                    "I autorizuari i vetem ne tere Kosoven per mbushjen dhe kontrollimin e cilindrave LPG\n\n" .
                    "Cell: +383 (0) 49 62 76 76\nE-mail: sales@darngroup.com\nwww.darngroup.com";

                $gmail->sendEmail($clientEmail, $subject, $body, $filepath, $inv['pdf_filename'], $senderName);

                echo json_encode([
                    'success' => true,
                    'message' => "Email u dergua me sukses te {$clientEmail}",
                    'to' => $clientEmail
                ]);
            } catch (Exception $mailEx) {
                echo json_encode(['success' => false, 'error' => 'Gabim ne dergimin e emailit: ' . $mailEx->getMessage()]);
            }
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Veprim i panjohur: ' . $action]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

# Invoice Number Fix — Detailed Changelog

**Date**: 2026-05-06
**Reason**: Lena reported "Fatura nr 131 ekziston tashme" when trying to create a new invoice on the dashboard. Root cause: the `next_number` endpoint was returning a stale counter (131) that pointed to an already-used invoice number from March 2026.

## Pre-deploy state (captured before any change)

- Git HEAD: `5ef753c` — `Invoice: harden next_number against caching + page-load races`
- File MD5s:
  - `api/invoice.php`: `b53eade797e790ab5a65aea5d829b8f4`
  - `api/api_android.php`: `91d8fa8497b4a2bd58263b247bad063b`
  - `config/database.php`: `0feea0289fa304e59bbc367ee95e95a0`
  - `pages/fatura.php`: `8b2cef4560d9c24709f2d7362271fc00`
- DB state: `MAX(invoice_number) = 145`, `counter = 131`, `COUNT(*) = 140`

## Changes applied

### Change 1 — `config/database.php` (NEW helper function)

**What**: Added a top-level helper function `getNextInvoiceNumber($db)` after line 709.

**Why**: A single source of truth used by both the dashboard's `next_number` endpoint and the Android app's `get_invoice_number` endpoint. They will now agree on the suggestion.

**Code added** (appended at end of file):
```php
/**
 * Compute the next free invoice number, considering BOTH the actual
 * invoices table AND the legacy invoice_settings counter. Returns the
 * higher of (MAX+1) and (counter), defaulting counter to 131 if
 * missing/empty (matches the legacy ?:130+1 behavior).
 *
 * Used by api/invoice.php case 'next_number' AND api/api_android.php
 * handleGetInvoiceNumber so the dashboard and the Android app NEVER
 * suggest different numbers.
 */
function getNextInvoiceNumber($db) {
    $maxFromTable = (int)$db->query("SELECT MAX(invoice_number) FROM invoices")->fetchColumn();
    $counterRaw = $db->query("SELECT setting_value FROM invoice_settings WHERE setting_key = 'next_invoice_number'")->fetchColumn();
    $counter = (int)($counterRaw ?: 131);
    return max($maxFromTable + 1, $counter);
}
```

**Risk**: None. Adding a top-level function doesn't affect existing code paths.

---

### Change 2 — `api/invoice.php` `case 'next_number':` (lines 24-33)

**What**: Replace the inlined MAX+1 logic with a call to `getNextInvoiceNumber($db)`.

**Why**: Single source of truth. Functionally identical for the current state (still returns 146).

**Before**:
```php
case 'next_number':
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    $maxStmt = $db->query("SELECT MAX(invoice_number) FROM invoices");
    $maxNum = $maxStmt->fetchColumn();
    if ($maxNum === null || $maxNum === false) {
        $settingStmt = $db->query("SELECT setting_value FROM invoice_settings WHERE setting_key = 'next_invoice_number'");
        $maxNum = ($settingStmt->fetchColumn() ?: 130);
    }
    echo json_encode(['success' => true, 'next_number' => intval($maxNum) + 1]);
    break;
```

**After**:
```php
case 'next_number':
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo json_encode(['success' => true, 'next_number' => getNextInvoiceNumber($db)]);
    break;
```

**Risk**: Low. New helper returns the same value (146) for the current state. Cache headers preserved.

---

### Change 3 — `api/invoice.php` line 305-307 (counter advance after create)

**What**: Make the counter UPDATE conditional so it only ADVANCES, never rolls back.

**Why**: Defends against a future bug where someone creates a backdated invoice (low number) and the counter rolls back, losing track of paper invoices the Android app may have used.

**Before**:
```php
// Increment invoice number counter
$nextNum = $invoiceNum + 1;
$db->prepare("UPDATE invoice_settings SET setting_value = ? WHERE setting_key = 'next_invoice_number'")->execute([$nextNum]);
```

**After**:
```php
// Increment invoice number counter — advance-only (never roll back to a
// lower value, in case Android paper invoices have advanced past this point).
$nextNum = $invoiceNum + 1;
$db->prepare("UPDATE invoice_settings SET setting_value = ? WHERE setting_key = 'next_invoice_number' AND CAST(setting_value AS UNSIGNED) < ?")->execute([$nextNum, $nextNum]);
```

**Risk**: Low. In Lena's current usage (dashboard only, sequential numbering), the counter always advances anyway, so behavior is identical. The new WHERE clause only changes behavior if the counter is somehow already higher than `invoiceNum + 1`, in which case keeping it high is the correct thing.

---

### Change 4 — `api/api_android.php` `handleGetInvoiceNumber` (lines 440-447)

**What**: Replace the counter-only read with a call to `getNextInvoiceNumber($db)`.

**Why**: The Android app currently reads only the counter (131, stale). After this change, it returns the same value as the dashboard (146).

**Before**:
```php
function handleGetInvoiceNumber($db) {
    $stmt = $db->query("SELECT setting_value FROM invoice_settings WHERE setting_key = 'next_invoice_number'");
    $val = $stmt->fetchColumn();
    echo json_encode([
        'inv_number' => $val ?: '1',
    ]);
}
```

**After**:
```php
function handleGetInvoiceNumber($db) {
    // Use the shared helper so the Android app and dashboard ALWAYS
    // suggest the same next invoice number. Otherwise Lena would see
    // different suggestions on phone vs. dashboard, leading to collisions.
    echo json_encode([
        'inv_number' => (string)getNextInvoiceNumber($db),
    ]);
}
```

**Risk**: The Android app expects `{"inv_number": "131"}` as a string. The new code casts to string. No protocol change.

---

### Change 5 — `api/api_android.php` `handleUpdateInvoiceNumber` (lines 456-470)

**What**: Make the counter UPDATE conditional so it only ADVANCES, never rolls back.

**Why**: Same rationale as Change 3 — defensive. If a future Android user types a low number manually, the counter shouldn't roll back below an already-used range.

**Before**:
```php
function handleUpdateInvoiceNumber($db) {
    $nextInv = $_GET['nextInv'] ?? '';
    if (empty($nextInv) || !is_numeric($nextInv)) {
        echo json_encode(['status' => '0', 'message' => 'Invalid invoice number']);
        return;
    }
    $stmt = $db->prepare("UPDATE invoice_settings SET setting_value = ? WHERE setting_key = 'next_invoice_number'");
    $stmt->execute([$nextInv]);
    echo json_encode(['inv_number' => $nextInv]);
}
```

**After**:
```php
function handleUpdateInvoiceNumber($db) {
    $nextInv = $_GET['nextInv'] ?? '';
    if (empty($nextInv) || !is_numeric($nextInv)) {
        echo json_encode(['status' => '0', 'message' => 'Invalid invoice number']);
        return;
    }
    // Advance-only — never roll back the counter to a lower value
    $stmt = $db->prepare("UPDATE invoice_settings SET setting_value = ? WHERE setting_key = 'next_invoice_number' AND CAST(setting_value AS UNSIGNED) < ?");
    $stmt->execute([$nextInv, $nextInv]);
    echo json_encode(['inv_number' => $nextInv]);
}
```

**Risk**: Low. Android only calls this with `currentNumber + 1`, which is always advancing in normal flow. If counter is already higher (because dashboard advanced it), the no-op is correct.

## Edits NOT applied (intentionally skipped)

### Edit 4 (skipped) — `api/invoice.php:410` delete handler

**Why skipped**: The current delete handler resets the counter to the deleted invoice's number, enabling "delete and recreate same number" UX. Removing this rollback would change a user-visible behavior. Lena hasn't complained about this UX, so per the "don't mess anything else" instruction, this stays untouched.

**Trade-off**: In a hypothetical Android-coexistence scenario where paper invoices used numbers above the deleted one, the rollback could cause the counter to drop below them. But since Lena's current data is consistent with dashboard-only usage (counter at 131 matches her last create #130), this is a latent issue that doesn't apply right now. If it ever becomes a problem, this edit can be applied in a follow-up.

## Rollback procedure

If anything breaks after this deploy, here's how to revert:

```bash
# Revert to the pre-fix commit
git revert <new-commit-hash> --no-edit
git push origin master

# Or hard-reset (loses the new commit)
git reset --hard 5ef753c
git push origin master --force-with-lease

# DB state is unchanged by these edits (only the counter and invoices table
# would be modified by the *next* invoice creation, which is normal).
```

To verify rollback succeeded after revert, the file MD5s should match the pre-deploy values listed at the top of this document.

## What we EXPECT to happen after deploy

1. Lena reloads the Fatura page → sees 146 in the field
2. She submits → invoice 146 created, counter advances to 147
3. Future invoices: counter stays in sync with MAX+1
4. Android app: when she eventually opens Generate Invoice on the phone, it'll suggest the same next number (147 or higher) — no more "131 already exists" surprise
5. Delete behavior: unchanged (still rolls back counter, still suggests deleted number for reuse)

## Test plan after deploy

1. Re-run `test_option_b_final.php` against production DB to confirm helper returns 146 for current state
2. Hit the diagnostic URL `https://dashboard.darn-group.com/api/diag_inv_x82j7.php?t=darn_check_2026_05_06_x82j7` to confirm live endpoint returns the right value
3. Ask Lena to hard-refresh (Ctrl+Shift+R) and try creating an invoice
4. After confirmation, remove `api/diag_inv_x82j7.php` (the diagnostic file)

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

---

# Round 2 — UX improvements requested by Lena (2026-05-06, second deploy)

After the round-1 fix, Lena raised two follow-ups: (a) the visible history maxes out at #130 so she couldn't see #131-#145 even though they exist; (b) she wants a way to recreate an invoice with corrections (overwrite) instead of being blocked by the duplicate guard.

## Change 6 — `api/invoice.php` history endpoint (line ~361)

**What**: Removed the hardcoded `LIMIT 100` from the history query.

**Why**: The visible history was sorted by `created_at DESC LIMIT 100`. With 140 invoices in the table, the 13 invoices in #131-#145 (created March-April 2026) fell off the list. Lena could not see them, leading to her confusion when the duplicate guard rejected #131 — from her view, the highest invoice was #130. After this change all 140 invoices appear.

**Before**:
```php
$stmt = $db->query("SELECT ... FROM invoices ORDER BY created_at DESC LIMIT 100");
```

**After**:
```php
$stmt = $db->query("SELECT ... FROM invoices ORDER BY created_at DESC");
```

**Risk**: Low. With 140 rows the page renders quickly. Future-proofing concern: when the table grows past several hundred, pagination should be added. Not urgent.

## Change 7 — `api/invoice.php` `case 'create'` duplicate-check (lines ~88-98)

**What**: When the requested invoice number already exists AND `force_overwrite` is not set, return a structured JSON response so the frontend can offer a "delete the old one and recreate" confirmation dialog. Includes the existing invoice's metadata (client, dates, total, created_at) so the user can make an informed decision.

**Why**: Lena asked for a way to recreate an invoice with corrections (e.g., wrong info on the original) under the same number. Previously the backend just returned a flat error string with no way to retry.

**Before**:
```php
$check = $db->prepare("SELECT id FROM invoices WHERE invoice_number = ?");
$check->execute([$invoiceNum]);
if ($check->fetch()) {
    echo json_encode(['success' => false, 'error' => "Fatura nr {$invoiceNum} ekziston tashme"]);
    break;
}
```

**After**: Structured response with `error_code: 'already_exists'` and `existing` object. Frontend retries with `force_overwrite: true`.

**Risk**: Low. Backwards-compatible: callers without the new flag still get an error, just a richer one (still has `success: false` and `error` string).

## Change 8 — `api/invoice.php` create-flow force-overwrite delete (after empty-rows check)

**What**: When `force_overwrite=true` AND an existing invoice with that number is present, delete the old invoice fully — but only AFTER the new invoice's row data has been validated (so we never wipe the old one if the new one would fail).

**The full delete mirrors `case 'delete'` exactly**: revert CASH→FATURE status changes via changelog, drop changelog entries, remove PDF file, drop the invoice row. Then the normal create flow continues and inserts the new invoice using the now-free number.

**Why**: Atomic-feeling delete-and-recreate for the corrections workflow.

**Risk**: Medium. If the create's INSERT step ever fails after the delete, the old invoice would be lost. Mitigated by: (1) all validation (param check, empty rows check) runs BEFORE the delete; (2) the only thing that can fail between delete and insert is the INSERT itself, which would only fail under DB connection loss (rare).

## Change 9 — `pages/fatura.php` `_proceedWithCreate` overwrite handling

**What**: Frontend now:
1. Accepts a `forceOverwrite` parameter (defaults false).
2. Skips the initial "are you sure" dialog when `forceOverwrite=true` (user already confirmed via the overwrite dialog).
3. On `error_code === 'already_exists'`, shows a detailed confirm dialog with the existing invoice's info, and recursively calls `_proceedWithCreate(... true)` if the user accepts.
4. Sends `force_overwrite` in the POST body.

**Risk**: Low. Self-contained UI change, gracefully falls back to the existing flat-error path if the backend ever returns a non-structured error.

## Verification of round-2 changes

Run `verify_all_fixes.php` against the live DB (read-only, no writes):
- §1: getNextInvoiceNumber() returns 146 ✓
- §2: history returns all 140 invoices (no LIMIT) ✓ — confirms #131 and #145 now appear
- §3: duplicate-check returns structured `error_code: 'already_exists'` with real existing data ✓
- §4: force_overwrite=true bypasses the duplicate check ✓
- §5: free numbers (#146) pass through normally ✓
- §6: production data unchanged after test ✓

All 13 round-2 assertions pass. Plus the 32 round-1 assertions in `test_option_b_final.php`. No data modified.

## Round-2 rollback

If round-2 causes any issue, the round-1 fix from commit `aa5bf80` is still self-contained and works without these UX changes. To revert just round 2:

```bash
git revert <round-2-commit-hash> --no-edit
git push origin master
```

This leaves round-1 (the actual bug fix) in place while removing the UX improvements.

---

# Round 4 — Per-month invoice numbering (2026-05-06, fourth deploy)

After Lena clarified that her business convention is per-month invoice numbering — each month restarts from #1, with the COMBINATION (number + month + year) being the unique identifier. This was the original intent suggested by the "134-02-2026" display format added in commit `24fd005` back on March 12, 2026, but the backend logic and database constraint were never updated to match. Round 4 completes that original intent.

## Schema changes

The migration is in `config/database.php` `runMigrations()`. Each step is idempotent (checks before acting) so it's safe to re-run.

**Order matters for safety**: we ADD the new constraint first, then DROP the old one. This way the table is never without uniqueness protection.

1. **Add stored generated columns**: `year_of_date_to` and `month_of_date_to` (both INT, computed from `date_to`).
2. **Add new composite UNIQUE constraint**: `idx_invoice_number_per_month (invoice_number, year_of_date_to, month_of_date_to)`. Two rows with the same `invoice_number` are now allowed AS LONG AS they're for different month-years.
3. **Drop the old flat constraint**: `idx_invoice_number` (UNIQUE on invoice_number alone). Only after the new constraint is in place.

Verified by running `getDB()` against production: migration completes in ~1 second on a 140-row table. All existing rows now have `year_of_date_to` / `month_of_date_to` populated correctly.

## Logic changes

### `config/database.php` `getNextInvoiceNumber($db, $dateTo = null)`

Now date-aware. If `$dateTo` is provided:
- Returns `MAX(invoice_number for that month-year) + 1`
- For May 2026 with no May invoices: returns 1
- For April 2026 with #4-#143: returns 144

If `$dateTo` is omitted (e.g. legacy Android calls):
- Falls back to global flat: `max(MAX+1, counter)` — same behavior as before

### `api/invoice.php case 'next_number'`

Accepts a new query param `?date_to=YYYY-MM-DD` and passes it to the helper.

### `api/invoice.php case 'create'`

Duplicate check is now month-scoped:
```php
WHERE invoice_number = ? AND YEAR(date_to) = ? AND MONTH(date_to) = ?
```
So creating #1 for May 2026 succeeds even if #1 exists for some other month. The structured `already_exists` error message now includes the month-year (e.g. `"Fatura nr 131-02-2026 ekziston tashme"`).

The `force_overwrite` path inherits the same scope — only the SAME (number, month, year) row is deleted/replaced, never an unrelated invoice in a different month.

### `pages/fatura.php` frontend

- `fetchNextInvoiceNumber()` now reads the current `inv-date-to` value and passes it to the backend
- Added a `change` listener on the `inv-date-to` field — refetches the suggestion whenever the user picks/changes the date_to (so the field updates from "146" to "1" when she picks a May date)
- Existing user-typed values are still respected (no auto-overwrite when the user has manually changed the suggestion)

### `api/api_android.php handleGetInvoiceNumber` — UNCHANGED

The Android app's interface doesn't pass a date with `get_invoice_number`. Calling the helper without a date triggers the legacy fallback (global flat). The Android user gets a flat suggestion which they must manually adjust on phone if they want per-month. This avoids requiring an APK rebuild.

## Behavior change for Lena

| Scenario | Before round 4 | After round 4 |
|---|---|---|
| First May 2026 invoice (suggestion) | 146 (continued from April) | **1** ✓ |
| Second May 2026 invoice | 147 | 2 |
| First June 2026 invoice | 148 | **1** (June restart) |
| Trying to create #1 in May twice | Allowed (system was confused) | Blocked — UNIQUE violation, dialog offers overwrite |
| Trying to create #1 in May AND #1 in June | Second one blocked | Both succeed — different month-year |

## Existing data — left alone (Decision A1)

April 2026 still has invoices #4-#143. They are not renumbered. New April invoices continue at #144 if Lena chooses to create one. Future months (May, June, etc.) start fresh at #1.

The 131-145 batch (Feb-March/April 2026) also stays in place (Decision B1). They're old test/early-use data — Lena can ignore them.

## Verification

`verify_all_fixes.php` runs:
- §1 PREFLIGHT: 0 duplicates in current data → migration safe to apply
- §2 LOGIC: all 8 per-month suggestion checks pass (May→1, June→1, April→144, March→146, Feb→140, Jan→137, plus legacy fallback)
- §3 INSERT TESTS in transactions: #1 for May succeeds, second insert blocked by constraint, #1 for June succeeds (different month), #131 for May succeeds (different month than the existing Feb #131)
- §4 Production data unchanged (COUNT=140, MAX=145)

15 / 15 assertions pass.

## Round-4 rollback

If anything breaks, the rollback has TWO parts:

1. **Code**: `git revert <round-4-commit> --no-edit && git push` to restore the global flat logic.

2. **Schema**: To restore the old flat unique constraint, run:
   ```sql
   ALTER TABLE invoices DROP INDEX idx_invoice_number_per_month;
   ALTER TABLE invoices ADD UNIQUE KEY idx_invoice_number (invoice_number);
   -- Optional: keep the generated columns; they don't hurt and might be useful later
   ```
   But this only works if no per-month duplicates exist. If Lena has already created e.g. #1-05-2026 AND #1-06-2026, the old flat constraint would fail. In that case, you'd have to delete one of the duplicates first — a destructive operation, so be careful.

## Android coexistence note

The Android app (Generate_Invoice_Fragment.java in the Modified version on dashboard.darn-group.com) still fetches `get_invoice_number` without a date, so it gets the legacy fallback (global flat suggestion). Lena will need to manually adjust the suggested number on phone to match the per-month convention for the date she picks.

If the APK is ever rebuilt, two changes would make Android fully per-month-aware:
1. `RailwayApiInterface.java`: `get_invoice_number` signature should accept a `date_to` query param
2. `Generate_Invoice_Fragment.java`: call `get_invoice_number(dateTo, apiKey)` AFTER the user picks the date

That's a follow-up for whenever the APK gets a rebuild — not in scope here.

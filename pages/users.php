<?php
/**
 * DARN Dashboard - User Management
 * Add, edit, delete dashboard users
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/layout.php';

$db = getDB();

// Handle form submissions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'user';

        if ($username === '' || $password === '') {
            $error = 'Shkruaj emrin dhe fjalekalimin.';
        } elseif (strlen($password) < 4) {
            $error = 'Fjalekalimi duhet te kete se paku 4 karaktere.';
        } else {
            // Check duplicate
            $dup = $db->prepare("SELECT COUNT(*) FROM dashboard_users WHERE username = ?");
            $dup->execute([$username]);
            if ((int)$dup->fetchColumn() > 0) {
                $error = "Perdoruesi '{$username}' ekziston tashme.";
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $db->prepare("INSERT INTO dashboard_users (username, password_hash, role) VALUES (?, ?, ?)")
                    ->execute([$username, $hash, $role]);
                $message = "Perdoruesi '{$username}' u shtua me sukses.";
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            // Don't allow deleting yourself
            if ($id === (int)($_SESSION['user_id'] ?? 0)) {
                $error = 'Nuk mundesh me fshi veten.';
            } else {
                $db->prepare("DELETE FROM dashboard_users WHERE id = ?")->execute([$id]);
                $message = 'Perdoruesi u fshi.';
            }
        }
    } elseif ($action === 'change_password') {
        $id = (int)($_POST['id'] ?? 0);
        $newPass = $_POST['new_password'] ?? '';
        if ($id > 0 && strlen($newPass) >= 4) {
            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            $db->prepare("UPDATE dashboard_users SET password_hash = ? WHERE id = ?")->execute([$hash, $id]);
            $message = 'Fjalekalimi u ndryshua.';
        } else {
            $error = 'Fjalekalimi duhet te kete se paku 4 karaktere.';
        }
    } elseif ($action === 'change_role') {
        $id = (int)($_POST['id'] ?? 0);
        $newRole = $_POST['new_role'] ?? 'user';
        if ($id > 0) {
            $db->prepare("UPDATE dashboard_users SET role = ? WHERE id = ?")->execute([$newRole, $id]);
            $message = 'Roli u ndryshua.';
        }
    }
}

// Fetch all users
$users = $db->query("SELECT id, username, role, created_at FROM dashboard_users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>

<?php if ($message): ?>
<div style="padding:12px 16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;margin-bottom:16px;color:#16a34a;">
    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div style="padding:12px 16px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;margin-bottom:16px;color:#dc2626;">
    <i class="fas fa-times-circle"></i> <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<!-- Summary -->
<div class="summary-row">
    <div class="summary-card">
        <div class="label">TOTAL PERDORUES</div>
        <div class="value"><?= count($users) ?></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>Perdoruesit e Dashboard</h3>
        <button class="btn btn-primary btn-sm" onclick="document.getElementById('addUserForm').style.display = document.getElementById('addUserForm').style.display === 'none' ? 'block' : 'none'">
            <i class="fas fa-plus"></i> Shto Perdorues
        </button>
    </div>

    <!-- Add User Form (hidden by default) -->
    <div id="addUserForm" style="display:none;padding:16px;background:#f8fafc;border-bottom:1px solid var(--border);">
        <form method="POST" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
            <input type="hidden" name="action" value="add">
            <div class="form-group" style="margin:0;">
                <label>Emri i perdoruesit</label>
                <input type="text" name="username" required style="padding:6px 10px;" placeholder="p.sh. lena">
            </div>
            <div class="form-group" style="margin:0;">
                <label>Fjalekalimi</label>
                <input type="password" name="password" required style="padding:6px 10px;" placeholder="min. 4 karaktere">
            </div>
            <div class="form-group" style="margin:0;">
                <label>Roli</label>
                <select name="role" style="padding:6px 10px;">
                    <option value="admin">Admin</option>
                    <option value="user">User</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-check"></i> Shto</button>
        </form>
    </div>

    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Perdoruesi</th>
                    <th>Roli</th>
                    <th>Krijuar me</th>
                    <th>Veprime</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= $u['id'] ?></td>
                    <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                    <td>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="change_role">
                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                            <select name="new_role" onchange="this.form.submit()" style="padding:3px 6px;font-size:0.82rem;border:1px solid var(--border);border-radius:4px;">
                                <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                <option value="user" <?= $u['role'] === 'user' ? 'selected' : '' ?>>User</option>
                            </select>
                        </form>
                    </td>
                    <td><?= $u['created_at'] ?></td>
                    <td style="display:flex;gap:6px;">
                        <!-- Change password -->
                        <form method="POST" style="display:inline;" onsubmit="var p=prompt('Fjalekalimi i ri per <?= htmlspecialchars($u['username']) ?>:'); if(!p||p.length<4){alert('Fjalekalimi duhet te kete se paku 4 karaktere.');return false;} this.querySelector('[name=new_password]').value=p;">
                            <input type="hidden" name="action" value="change_password">
                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                            <input type="hidden" name="new_password" value="">
                            <button type="submit" class="btn btn-outline btn-sm" title="Ndrysho fjalekalimin"><i class="fas fa-key"></i></button>
                        </form>
                        <!-- Delete -->
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Fshi perdoruesin <?= htmlspecialchars($u['username']) ?>?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn btn-sm" style="background:#dc2626;color:#fff;border:none;" title="Fshi"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$content = ob_get_clean();
renderLayout('Perdoruesit', 'users', $content);

<?php
/**
 * DARN Dashboard - Login Page
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

$error = '';
$locked = 0;

// Already logged in? Go to dashboard
if (!empty($_SESSION['logged_in'])) {
    header('Location: /index.php');
    exit;
}

// Handle login POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = getDB();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Rate limit check
    $locked = checkRateLimit($db, $ip);
    if ($locked > 0) {
        $error = "Shume tentativa te gabuara. Provo perseri pas {$locked} minutash.";
    } else {
        $user = trim($_POST['username'] ?? '');
        $pass = $_POST['password'] ?? '';

        $authenticated = false;

        // Try dashboard_users table first
        try {
            $stmt = $db->prepare("SELECT id, username, password_hash, role FROM dashboard_users WHERE username = ?");
            $stmt->execute([$user]);
            $dbUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($dbUser && password_verify($pass, $dbUser['password_hash'])) {
                $authenticated = true;
                $_SESSION['user_id'] = $dbUser['id'];
                $_SESSION['username'] = $dbUser['username'];
                $_SESSION['user_role'] = $dbUser['role'];
            }
        } catch (PDOException $e) {
            // Table might not exist yet — fall through to legacy auth
        }

        // Fallback: legacy env var credentials
        if (!$authenticated && $user === AUTH_USER && AUTH_PASS !== '' && hash_equals(AUTH_PASS, $pass)) {
            $authenticated = true;
            $_SESSION['username'] = $user;
            $_SESSION['user_role'] = 'admin';
        }

        if ($authenticated) {
            clearAttempts($db, $ip);
            session_regenerate_id(true);
            $_SESSION['logged_in'] = true;
            $_SESSION['login_time'] = time();
            header('Location: /index.php');
            exit;
        } else {
            recordFailedAttempt($db, $ip);
            $error = 'Kredencialet jane te gabuara.';
        }
    }
}

// Session is started by auth.php
?>
<!DOCTYPE html>
<html lang="sq">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - DARN Group Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body class="login-body">
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h1>DARN Group</h1>
                <p>LPG Dashboard</p>
            </div>

            <?php if ($error): ?>
            <div class="login-error">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <form method="POST" action="/login.php" autocomplete="off">
                <div class="login-field">
                    <label for="username"><i class="fas fa-user"></i> Perdoruesi</label>
                    <input type="text" id="username" name="username" required autofocus
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                           <?= $locked ? 'disabled' : '' ?>>
                </div>

                <div class="login-field">
                    <label for="password"><i class="fas fa-lock"></i> Fjalekalimi</label>
                    <input type="password" id="password" name="password" required
                           <?= $locked ? 'disabled' : '' ?>>
                </div>

                <button type="submit" class="login-btn" <?= $locked ? 'disabled' : '' ?>>
                    <i class="fas fa-sign-in-alt"></i> Hyr
                </button>
            </form>
        </div>
    </div>
</body>
</html>

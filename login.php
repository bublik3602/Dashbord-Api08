<?php
/**
 * Сторінка авторизації у системі моніторингу заводу
 */

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';

Auth::init();

// Якщо вже авторизований — перенаправляємо на відповідну домашню сторінку
if (Auth::check()) {
    $home = Auth::getHomeUrlForUser();
    header("Location: {$home}");
    exit;
}

$error = null;
$returnUrl = $_GET['return'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Будь ласка, введіть логін та пароль.';
    } else {
        if (Auth::login($username, $password)) {
            $user = Auth::user();
            
            // Якщо це користувач semena — суворо на сторінку СЕМЕНА
            if (($user['role'] ?? '') === 'semena') {
                header('Location: semena.php');
                exit;
            }

            // Якщо є валідний return URL і користувач має туди доступ
            if ($returnUrl !== '' && strpos($returnUrl, 'http') === false) {
                header('Location: ' . $returnUrl);
                exit;
            }

            header('Location: index.php');
            exit;
        } else {
            $error = 'Невірний логін або пароль. Спробуйте ще раз.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вхід — Олійноекстракційний Завод</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy-primary: #1F4E78;
            --navy-dark: #0f172a;
            --navy-surface: #1e293b;
            --gold-accent: #f59e0b;
            --gold-hover: #d97706;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --border: #334155;
            --danger: #ef4444;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: radial-gradient(circle at 50% 20%, #1e3a5f 0%, #0f172a 70%);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            width: 100%;
            max-width: 440px;
        }

        .login-card {
            background: rgba(30, 41, 59, 0.88);
            backdrop-filter: blur(16px);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 40px 32px;
            box-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.6), 0 0 0 1px rgba(255, 255, 255, 0.05);
        }

        .brand-header {
            text-align: center;
            margin-bottom: 28px;
        }

        .brand-badge-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            border-radius: 16px;
            font-size: 32px;
            margin-bottom: 16px;
            box-shadow: 0 8px 20px rgba(245, 158, 11, 0.35);
        }

        .brand-title {
            font-size: 22px;
            font-weight: 800;
            color: #ffffff;
            letter-spacing: -0.02em;
            margin-bottom: 6px;
        }

        .brand-subtitle {
            font-size: 13px;
            color: var(--text-muted);
            line-height: 1.4;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.4);
            color: #fca5a5;
            padding: 12px 14px;
            border-radius: 8px;
            font-size: 13.5px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #cbd5e1;
            margin-bottom: 8px;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 14px;
            font-size: 16px;
            color: var(--text-muted);
            pointer-events: none;
        }

        .form-input {
            width: 100%;
            padding: 12px 14px 12px 42px;
            background: #0f172a;
            border: 1px solid var(--border);
            border-radius: 10px;
            color: #ffffff;
            font-size: 14.5px;
            font-family: inherit;
            outline: none;
            transition: all 0.2s ease;
        }

        .form-input:focus {
            border-color: var(--gold-accent);
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.2);
            background: #111e33;
        }

        .btn-submit {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
            margin-top: 8px;
        }

        .btn-submit:hover {
            background: linear-gradient(135deg, #fbbf24, #d97706);
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(245, 158, 11, 0.4);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        .quick-roles-card {
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }

        .quick-roles-title {
            font-size: 12px;
            text-transform: uppercase;
            font-weight: 700;
            color: var(--text-muted);
            letter-spacing: 0.05em;
            margin-bottom: 10px;
            text-align: center;
        }

        .roles-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .role-pill {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.06);
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 12.5px;
            cursor: pointer;
            transition: background 0.15s ease;
        }

        .role-pill:hover {
            background: rgba(245, 158, 11, 0.1);
            border-color: rgba(245, 158, 11, 0.3);
        }

        .role-name {
            font-weight: 600;
            color: #e2e8f0;
        }

        .role-cred {
            color: #94a3b8;
            font-family: monospace;
            font-size: 12px;
        }

        .footer-note {
            text-align: center;
            margin-top: 20px;
            font-size: 12px;
            color: #64748b;
        }
    </style>
</head>
<body>

<div class="login-container">
    <div class="login-card">
        <div class="brand-header">
            <div class="brand-badge-icon">🌻</div>
            <h1 class="brand-title">Єдиний Моніторинг Заводу</h1>
            <div class="brand-subtitle">
                Олійноекстракційний завод &bull; Безпечний вхід
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert-error">
                <span>⚠️</span>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php<?= !empty($returnUrl) ? ('?return=' . urlencode($returnUrl)) : '' ?>" autocomplete="off">
            <div class="form-group">
                <label class="form-label" for="input-username">Логін користувача</label>
                <div class="input-wrapper">
                    <span class="input-icon">👤</span>
                    <input type="text" id="input-username" name="username" class="form-input" 
                           placeholder="semena або admin" required autofocus
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="input-password">Пароль</label>
                <div class="input-wrapper">
                    <span class="input-icon">🔒</span>
                    <input type="password" id="input-password" name="password" class="form-input" 
                           placeholder="Введіть пароль" required>
                </div>
            </div>

            <button type="submit" class="btn-submit">
                Увійти в кабінет &rarr;
            </button>
        </form>

        <div class="quick-roles-card">
            <div class="quick-roles-title">Швидкий вибір користувача:</div>
            <div class="roles-list">
                <div class="role-pill" onclick="fillCreds('admin', 'orrz20054Q+')">
                    <span class="role-name">🏭 admin (Головний дашборд)</span>
                    <span class="role-cred">пароль: orrz20054Q+</span>
                </div>
                <div class="role-pill" onclick="fillCreds('test', '12345')">
                    <span class="role-name">🏭 test (Головний дашборд)</span>
                    <span class="role-cred">пароль: 12345</span>
                </div>
                <div class="role-pill" onclick="fillCreds('semena', '12345')">
                    <span class="role-name">🌻 semena (Тільки СЕМЕНА 1С)</span>
                    <span class="role-cred">пароль: 12345</span>
                </div>
            </div>
        </div>
    </div>

    <div class="footer-note">
        &copy; <?= date('Y') ?> Олійноекстракційний Завод. Всі права захищено.
    </div>
</div>

<script>
function fillCreds(u, p) {
    document.getElementById('input-username').value = u;
    document.getElementById('input-password').value = p;
    document.getElementById('input-password').focus();
}
</script>

</body>
</html>

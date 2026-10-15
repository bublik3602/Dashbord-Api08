<?php
/**
 * Модуль авторизації та розмежування прав доступу (RBAC)
 * Підтримує сесії, ролі користувачів, роботу з БД та автономний JSON-репозиторій.
 */

require_once __DIR__ . '/helpers.php';

class Auth {
    private static ?array $currentUser = null;
    private static string $usersJsonFile = __DIR__ . '/../database/users.json';

    /**
     * Ініціалізація сесії безпечно
     */
    public static function init(): void {
        if (session_status() === PHP_SESSION_NONE) {
            // Налаштування безпеки cookies сесії
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                       (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

            ini_set('session.cookie_httponly', '1');
            ini_set('session.use_only_cookies', '1');
            if ($isHttps) {
                ini_set('session.cookie_secure', '1');
            }
            session_start();
        }
    }

    /**
     * Повертає список зареєстрованих користувачів
     */
    public static function getUsers(): array {
        $defaultUsers = [
            'admin' => [
                'username'      => 'admin',
                'password_hash' => password_hash('orrz20054Q+', PASSWORD_DEFAULT),
                'password'      => 'orrz20054Q+',
                'display_name'  => 'Головний адміністратор',
                'role'          => 'admin',
                'allowed_pages' => ['all'],
                'is_active'     => true,
            ],
            'test' => [
                'username'      => 'test',
                'password_hash' => password_hash('12345', PASSWORD_DEFAULT),
                'password'      => '12345',
                'display_name'  => 'Тестовий користувач (Дашборд)',
                'role'          => 'admin',
                'allowed_pages' => ['all'],
                'is_active'     => true,
            ],
            'semena' => [
                'username'      => 'semena',
                'password_hash' => password_hash('12345', PASSWORD_DEFAULT),
                'password'      => '12345',
                'display_name'  => 'Менеджер з постачання соняшнику',
                'role'          => 'semena',
                'allowed_pages' => ['semena'],
                'is_active'     => true,
            ],
            'energo' => [
                'username'      => 'energo',
                'password_hash' => password_hash('12345', PASSWORD_DEFAULT),
                'password'      => '12345',
                'display_name'  => 'Служба головного енергетика (Блок В)',
                'role'          => 'energo',
                'allowed_pages' => ['dashboard', 'energo'],
                'is_active'     => true,
            ],
        ];

        // 1. Спроба завантажити з файлу database/users.json
        if (file_exists(self::$usersJsonFile)) {
            $json = @file_get_contents(self::$usersJsonFile);
            if ($json) {
                $decoded = json_decode($json, true);
                if (is_array($decoded) && !empty($decoded)) {
                    $needSave = false;
                    foreach ($defaultUsers as $k => $defU) {
                        if (!isset($decoded[$k])) {
                            $decoded[$k] = $defU;
                            $needSave = true;
                        }
                    }
                    if ($needSave) {
                        self::saveUsers($decoded);
                    }
                    return $decoded;
                }
            }
        }

        // 2. Якщо файлу ще немає — зберігаємо початкових користувачів
        self::saveUsers($defaultUsers);
        return $defaultUsers;
    }

    /**
     * Збереження користувачів у файл database/users.json
     */
    public static function saveUsers(array $users): bool {
        $dir = dirname(self::$usersJsonFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return (bool)@file_put_contents(
            self::$usersJsonFile,
            json_encode($users, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    /**
     * Аутентифікація користувача за логіном та паролем
     */
    public static function login(string $username, string $password): bool {
        self::init();

        $uname = trim(mb_strtolower($username, 'UTF-8'));
        $users = self::getUsers();

        if (!isset($users[$uname])) {
            return false;
        }

        $user = $users[$uname];
        if (empty($user['is_active'])) {
            return false;
        }

        $valid = false;
        // Перевірка хешу пароля (або відкритий пароль якщо щойно створений)
        if (!empty($user['password_hash']) && password_verify($password, $user['password_hash'])) {
            $valid = true;
        } elseif (isset($user['password']) && $user['password'] === $password) {
            $valid = true;
            // Оновлюємо на безпечний хеш
            $users[$uname]['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            unset($users[$uname]['password']);
            self::saveUsers($users);
        }

        if ($valid) {
            session_regenerate_id(true);
            $sessionUser = [
                'username'      => $user['username'],
                'display_name'  => $user['display_name'] ?? $user['username'],
                'role'          => $user['role'] ?? 'user',
                'allowed_pages' => $user['allowed_pages'] ?? [],
                'login_time'    => time(),
            ];
            $_SESSION['auth_user'] = $sessionUser;
            self::$currentUser = $sessionUser;

            // Оновлюємо last_login_at
            $users[$uname]['last_login_at'] = date('Y-m-d H:i:s');
            self::saveUsers($users);

            return true;
        }

        return false;
    }

    /**
     * Повертає поточного авторизованого користувача
     */
    public static function user(): ?array {
        self::init();
        if (self::$currentUser !== null) {
            return self::$currentUser;
        }
        if (!empty($_SESSION['auth_user']) && is_array($_SESSION['auth_user'])) {
            self::$currentUser = $_SESSION['auth_user'];
            return self::$currentUser;
        }
        return null;
    }

    /**
     * Чи авторизований користувач
     */
    public static function check(): bool {
        return self::user() !== null;
    }

    /**
     * Перевірка чи має користувач доступ до розділу/сторінки
     */
    public static function hasAccess(string $page): bool {
        $u = self::user();
        if (!$u) {
            return false;
        }

        // Адміністратор має доступ до всіх сторінок
        if (($u['role'] ?? '') === 'admin') {
            return true;
        }

        $allowed = (array)($u['allowed_pages'] ?? []);
        if (in_array('all', $allowed, true)) {
            return true;
        }
        return in_array($page, $allowed, true);
    }

    /**
     * Чи є поточний користувач адміністратором
     */
    public static function isAdmin(): bool {
        $u = self::user();
        return ($u['role'] ?? '') === 'admin';
    }

    /**
     * Чи є поточний користувач енергетиком (роль energo)
     */
    public static function isEnergo(): bool {
        $u = self::user();
        return ($u['role'] ?? '') === 'energo';
    }

    /**
     * Чи дозволено поточному користувачу завантажувати звіти та файли 1С.
     * Користувачам 'semena' та 'energo' заборонено завантажувати приход / оновлювати дані.
     */
    public static function canUpload(): bool {
        $u = self::user();
        if (!$u) {
            return false;
        }
        if (in_array(($u['role'] ?? ''), ['semena', 'energo'], true) || in_array(($u['username'] ?? ''), ['semena', 'energo'], true)) {
            return false;
        }
        return true;
    }

    /**
     * Обов'язкова перевірка авторизації та прав доступу.
     * Якщо користувач не залогінений -> редирект на login.php
     * Якщо користувач semena намагається зайти на головний дашборд -> редирект на semena.php
     */
    public static function requireAuth(string $requiredPage = 'any'): array {
        self::init();

        $u = self::user();
        if (!$u) {
            $returnUrl = $_SERVER['REQUEST_URI'] ?? 'index.php';
            header('Location: login.php?return=' . urlencode($returnUrl));
            exit;
        }

        // Якщо вказано конкретний розділ, перевіряємо права
        if ($requiredPage !== 'any' && !self::hasAccess($requiredPage)) {
            // Якщо це користувач semena і він відкриває dashboard -> відправляємо на semena.php
            if (($u['role'] ?? '') === 'semena') {
                header('Location: semena.php');
                exit;
            }

            // Інші ролі без доступу
            http_response_code(403);
            ?>
            <!DOCTYPE html>
            <html lang="uk">
            <head>
                <meta charset="UTF-8">
                <title>403 — Доступ обмежено</title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
                    .box { background: #1e293b; border: 1px solid #334155; padding: 32px 40px; border-radius: 12px; text-align: center; max-width: 480px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
                    h1 { color: #ef4444; margin-top: 0; font-size: 24px; }
                    p { color: #94a3b8; font-size: 15px; line-height: 1.5; }
                    .btn { display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3b82f6; color: #fff; text-decoration: none; border-radius: 6px; font-weight: 600; }
                </style>
            </head>
            <body>
                <div class="box">
                    <h1>⛔ Доступ обмежено</h1>
                    <p>Для вашого облікового запису (<strong><?= htmlspecialchars($u['username']) ?></strong>) цей розділ недоступний за правами доступу.</p>
                    <a href="<?= self::getHomeUrlForUser($u) ?>" class="btn">Перейти до мого розділу</a>
                    <a href="logout.php" class="btn" style="background: #475569; margin-left: 8px;">Вийти</a>
                </div>
            </body>
            </html>
            <?php
            exit;
        }

        return $u;
    }

    /**
     * Повертає домашню сторінку користувача залежно від його прав
     */
    public static function getHomeUrlForUser(?array $user = null): string {
        $u = $user ?? self::user();
        if (!$u) {
            return 'login.php';
        }
        if (($u['role'] ?? '') === 'semena') {
            return 'semena.php';
        }
        return 'index.php';
    }

    /**
     * Вихід із системи
     */
    public static function logout(): void {
        self::init();
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        @session_destroy();
        header('Location: login.php');
        exit;
    }
}

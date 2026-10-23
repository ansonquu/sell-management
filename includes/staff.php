<?php

function getStaffStorageFile(): string
{
    return defined('STAFF_PATH') ? STAFF_PATH : __DIR__ . '/../data/staff.json';
}

function defaultStaffAccounts(): array
{
    return [];
}

function hasStaffAccounts(): bool
{
    return getStaffAccounts() !== [];
}

function createInitialAdmin(string $username, string $name, string $password): bool
{
    if (hasStaffAccounts()) {
        return false;
    }

    return persistStaffAccounts([
        $username => [
            'name' => $name,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'admin',
            'staff_access' => true,
        ],
    ]);
}

function ensureStaffStorage(): void
{
    $file = getStaffStorageFile();
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    if (!file_exists($file)) {
        file_put_contents($file, json_encode(defaultStaffAccounts(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        @chmod($file, 0600);
    }
}

function getStaffAccounts(): array
{
    ensureStaffStorage();
    $content = file_get_contents(getStaffStorageFile());
    $accounts = json_decode($content ?: '', true);
    if (!is_array($accounts)) {
        $accounts = defaultStaffAccounts();
        persistStaffAccounts($accounts);
    }

    $changed = false;
    foreach ($accounts as $username => &$account) {
        if (!is_array($account)) {
            unset($accounts[$username]);
            $changed = true;
            continue;
        }
        $role = (string) ($account['role'] ?? 'staff');
        if (!in_array($role, ['staff', 'leader', 'finance', 'admin', 'barista', 'reception', 'barista_screen', 'customer_screen'], true)) {
            $role = 'staff';
        }
        if (($account['role'] ?? null) !== $role) {
            $account['role'] = $role;
            $changed = true;
        }
        $expectedStaffAccess = $role === 'finance'
        ? (bool) ($account['staff_access'] ?? false)
        : !in_array($role, ['barista_screen', 'customer_screen', 'barista', 'reception'], true);
        if (!array_key_exists('staff_access', $account) || (bool) $account['staff_access'] !== $expectedStaffAccess) {
            $account['staff_access'] = $expectedStaffAccess;
            $changed = true;
        }
        $frozen = (bool) ($account['frozen'] ?? false);
        if (($account['frozen'] ?? null) !== $frozen || !array_key_exists('frozen', $account)) {
            $account['frozen'] = $frozen;
            $changed = true;
        }

        // 附加职业（财务可兼任）：只接受合法职业，且不含管理员与财务
        $cleanExtraRoles = [];
        foreach ((array) ($account['extra_roles'] ?? []) as $extraRole) {
            $extraRole = (string) $extraRole;
            if (in_array($extraRole, getAssignableRoles(), true) && !in_array($extraRole, $cleanExtraRoles, true)) {
                $cleanExtraRoles[] = $extraRole;
            }
        }
        $currentExtraRoles = is_array($account['extra_roles'] ?? null) ? array_values($account['extra_roles']) : [];
        if ($currentExtraRoles !== $cleanExtraRoles) {
            if ($cleanExtraRoles !== []) {
                $account['extra_roles'] = $cleanExtraRoles;
            } else {
                unset($account['extra_roles']);
            }
            $changed = true;
        }
    }
    unset($account);

    if ($changed) {
        persistStaffAccounts($accounts);
    }
    return $accounts;
}

function persistStaffAccounts(array $accounts): bool
{
    ensureStaffStorage();
    return file_put_contents(
        getStaffStorageFile(),
        json_encode($accounts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        LOCK_EX
    ) !== false;
}

/** 可作为「附加职业」的角色（不含管理员与财务）。 */
function getAssignableRoles(): array
{
    return ['staff', 'leader', 'barista', 'reception', 'barista_screen', 'customer_screen'];
}

function addStaffAccount(
    string $username,
    string $name,
    string $password,
    string $role = 'staff',
    bool $staffAccess = false,
    array $extraRoles = []
): bool {
    $staffAccounts = getStaffAccounts();

    if (isset($staffAccounts[$username])) {
        return false;
    }

    $account = [
        'name' => $name,
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'role' => $role,
        'staff_access' => $role === 'finance' ? $staffAccess : !in_array($role, ['finance', 'barista_screen', 'customer_screen', 'barista', 'reception'], true),
    ];

    $cleanExtraRoles = [];
    foreach ($extraRoles as $extraRole) {
        $extraRole = (string) $extraRole;
        if (in_array($extraRole, getAssignableRoles(), true) && !in_array($extraRole, $cleanExtraRoles, true)) {
            $cleanExtraRoles[] = $extraRole;
        }
    }
    if ($role === 'finance' && $cleanExtraRoles !== []) {
        $account['extra_roles'] = $cleanExtraRoles;
    }

    $staffAccounts[$username] = $account;

    return persistStaffAccounts($staffAccounts);
}

function deleteStaffAccount(string $username): bool
{
    $staffAccounts = getStaffAccounts();

    if (!isset($staffAccounts[$username])) {
        return false;
    }

    unset($staffAccounts[$username]);

    return persistStaffAccounts($staffAccounts);
}

function updateStaffPassword(string $username, string $newPassword): bool
{
    $staffAccounts = getStaffAccounts();

    if (!isset($staffAccounts[$username])) {
        return false;
    }

    $staffAccounts[$username]['password'] = password_hash($newPassword, PASSWORD_DEFAULT);

    return persistStaffAccounts($staffAccounts);
}

function setStaffFrozen(string $username, bool $frozen): bool
{
    $staffAccounts = getStaffAccounts();
    if (!isset($staffAccounts[$username])) {
        return false;
    }
    $staffAccounts[$username]['frozen'] = $frozen;
    if ($frozen) {
        $staffAccounts[$username]['frozen_at'] = currentDateTime();
    } else {
        unset($staffAccounts[$username]['frozen_at']);
    }
    return persistStaffAccounts($staffAccounts);
}

function isStaffFrozen(string $username): bool
{
    $staffAccounts = getStaffAccounts();
    return (bool) (($staffAccounts[$username]['frozen'] ?? false));
}

function getSessionStorageFile(): string
{
    return defined('SESSION_PATH') ? SESSION_PATH : __DIR__ . '/../data/sessions.json';
}

function loadActiveSessions(): array
{
    $file = getSessionStorageFile();
    if (!file_exists($file)) {
        return [];
    }
    $content = file_get_contents($file);
    return json_decode($content, true) ?: [];
}

function saveActiveSessions(array $sessions): bool
{
    $file = getSessionStorageFile();
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return file_put_contents($file, json_encode($sessions, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
}

/**
 * 真实客户端 IP：兼容 CDN / 反向代理（Cloudflare、Nginx、Apache 等）。
 * 依次读取常见转发头并取第一个合法 IP，都不可用时回退 REMOTE_ADDR。
 */
function getRequestIp(): string
{
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
        $_SERVER['HTTP_TRUE_CLIENT_IP'] ?? '',
        $_SERVER['HTTP_X_REAL_IP'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    foreach ($candidates as $candidate) {
        foreach (explode(',', (string) $candidate) as $ip) {
            $ip = trim($ip);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                return $ip;
            }
        }
    }

    return '';
}

function getRequestUserAgent(): string
{
    return $_SERVER['HTTP_USER_AGENT'] ?? '';
}

function makeSessionRecord(string $sessionId, mixed $existing = []): array
{
    $now = date('Y-m-d H:i:s');
    if (!is_array($existing)) {
        $existing = [];
    }

    return [
        'session_id' => $sessionId,
        'login_time' => $existing['login_time'] ?? $now,
        'last_active' => $now,
        'ip' => getRequestIp(),
        'user_agent' => getRequestUserAgent(),
    ];
}

function setActiveSession(string $username, string $sessionId): void
{
    $sessions = loadActiveSessions();
    $sessions[$username] = makeSessionRecord($sessionId);
    saveActiveSessions($sessions);
}

function clearActiveSession(string $username): void
{
    $sessions = loadActiveSessions();
    unset($sessions[$username]);
    saveActiveSessions($sessions);
}

function getSessionIdFromRecord(mixed $record): string
{
    if (is_array($record)) {
        return (string)($record['session_id'] ?? '');
    }

    return (string)$record;
}

function isSessionValid(string $username, string $sessionId): bool
{
    $sessions = loadActiveSessions();
    if (!isset($sessions[$username])) {
        return false;
    }

    return hash_equals(getSessionIdFromRecord($sessions[$username]), $sessionId);
}

function touchActiveSession(string $username, string $sessionId): void
{
    $sessions = loadActiveSessions();
    if (!isset($sessions[$username]) || !isSessionValid($username, $sessionId)) {
        return;
    }

    $sessions[$username] = makeSessionRecord($sessionId, $sessions[$username]);
    saveActiveSessions($sessions);
}

function describeDevice(string $userAgent): string
{
    $platform = '未知设备';
    if (stripos($userAgent, 'iPhone') !== false) {
        $platform = 'iPhone';
    } elseif (stripos($userAgent, 'iPad') !== false) {
        $platform = 'iPad';
    } elseif (stripos($userAgent, 'Android') !== false) {
        $platform = 'Android';
    } elseif (stripos($userAgent, 'Macintosh') !== false || stripos($userAgent, 'Mac OS X') !== false) {
        $platform = 'macOS';
    } elseif (stripos($userAgent, 'Windows') !== false) {
        $platform = 'Windows';
    } elseif (stripos($userAgent, 'Linux') !== false) {
        $platform = 'Linux';
    }

    $browser = '浏览器';
    if (stripos($userAgent, 'Edg/') !== false) {
        $browser = 'Edge';
    } elseif (stripos($userAgent, 'Chrome/') !== false && stripos($userAgent, 'Chromium') === false) {
        $browser = 'Chrome';
    } elseif (stripos($userAgent, 'Firefox/') !== false) {
        $browser = 'Firefox';
    } elseif (stripos($userAgent, 'Safari/') !== false) {
        $browser = 'Safari';
    }

    return $platform . ' / ' . $browser;
}

function getActiveSessionDevices(): array
{
    $sessions = loadActiveSessions();
    $staffAccounts = getStaffAccounts();
    $devices = [];

    foreach ($sessions as $username => $record) {
        $sessionId = getSessionIdFromRecord($record);
        if ($sessionId === '') {
            continue;
        }

        $data = is_array($record) ? $record : [
            'session_id' => $sessionId,
            'login_time' => '',
            'last_active' => '',
            'ip' => '',
            'user_agent' => '',
        ];

        $staff = $staffAccounts[$username] ?? [
            'name' => $username,
            'role' => 'staff',
        ];
        $userAgent = (string)($data['user_agent'] ?? '');

        $devices[] = [
            'username' => $username,
            'name' => $staff['name'] ?? $username,
            'role' => $staff['role'] ?? 'staff',
            'device' => describeDevice($userAgent),
            'ip' => $data['ip'] ?? '',
            'login_time' => $data['login_time'] ?? '',
            'last_active' => $data['last_active'] ?? '',
            'user_agent' => $userAgent,
        ];
    }

    usort($devices, function (array $a, array $b): int {
        return strcmp((string)$b['last_active'], (string)$a['last_active']);
    });

    return $devices;
}

/**
 * 自动头像调色板（咖啡主题渐变），按姓名哈希稳定选取，保证同一员工在任何位置头像一致。
 */
function staffAvatarPalette(): array
{
    return [
        ['#8b5e34', '#5f3c1e'], // 深可可
        ['#b0653a', '#7d4522'], // 焦糖
        ['#a05c2e', '#6f3f1f'], // 烤杏仁
        ['#9c6b3c', '#6d4a26'], // 榛子
        ['#a87d4f', '#73562e'], // 拿铁
        ['#b9864f', '#82592c'], // 摩卡
        ['#91613c', '#62402a'], // 肉桂
        ['#a2673a', '#6f4524'], // 太妃糖
        ['#7a5a3e', '#4e3621'], // 黑糖
        ['#ad7a4b', '#7a4f2b'], // 栗子
    ];
}

/** 按姓名生成稳定的自动头像背景渐变 [起始色, 结束色]。 */
function staffAvatarColors(string $name): array
{
    $hash = 0;
    $length = mb_strlen($name);
    for ($i = 0; $i < $length; $i++) {
        $hash = ($hash * 31 + mb_ord(mb_substr($name, $i, 1))) & 0x7fffffff;
    }
    $palette = staffAvatarPalette();
    return $palette[$hash % count($palette)];
}

/** 生成姓名首字 + 渐变背景的 SVG 头像（data URI），未上传头像时使用。 */
function staffAvatarDataUri(string $name): string
{
    $initial = mb_substr($name, 0, 1);
    [$from, $to] = staffAvatarColors($name);
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
        . '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
        . '<stop offset="0" stop-color="' . $from . '"/><stop offset="1" stop-color="' . $to . '"/>'
        . '</linearGradient></defs>'
        . '<rect width="100" height="100" rx="50" fill="url(#g)"/>'
        . '<text x="50" y="50" dy="0.36em" text-anchor="middle" font-size="44" font-family="Microsoft YaHei, PingFang SC, sans-serif" fill="#ffffff">'
        . $initial . '</text></svg>';
    return 'data:image/svg+xml;charset=utf-8,' . rawurlencode($svg);
}

/**
 * 员工头像 HTML：已上传显示真实图片；未上传自动生成首字母 SVG 头像。
 * @param string $class 头像样式类（如 staff-list-avatar / staff-avatar）
 */
function staffAvatarMarkup(array $account, string $username, string $class = 'staff-list-avatar'): string
{
    $avatar = (string) ($account['avatar'] ?? '');
    $name = (string) (($account['name'] ?? '') ?: $username);
    $src = $avatar !== '' ? $avatar : staffAvatarDataUri($name);
    return '<img class="' . htmlspecialchars($class) . '" src="' . htmlspecialchars($src) . '" alt="">';
}

function getStaffAvatarDirectory(): string
{
    return dirname(__DIR__) . '/assets/uploads/avatars';
}

/**
 * 保存员工头像：校验图片内容、保存到 assets/uploads/avatars/，并更新 staff.json。
 * 返回可访问的 URL；失败返回 null 并把错误写入 $errors。
 */
function storeStaffAvatar(array $upload, string $username, array &$errors): ?string
{
    $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        $errors[] = '请选择要上传的头像文件';
        return null;
    }
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) {
        $errors[] = '头像上传失败，请重新选择文件';
        return null;
    }
    if ((int) ($upload['size'] ?? 0) > 2 * 1024 * 1024) {
        $errors[] = '头像图片不能超过 2MB';
        return null;
    }

    $detected = uploadedImageType((string) $upload['tmp_name']);
    if ($detected === null || !in_array($detected, ['png', 'jpg', 'gif', 'webp'], true)) {
        $errors[] = '头像仅支持 PNG、JPG、GIF 或 WebP 格式';
        return null;
    }

    $dir = getStaffAvatarDirectory();
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        $errors[] = '无法创建头像存储目录';
        return null;
    }

    $safeUsername = preg_replace('/[^A-Za-z0-9_-]/', '_', $username);
    $filename = 'avatar-' . $safeUsername . '-' . bin2hex(random_bytes(8)) . '.' . $detected;
    if (!move_uploaded_file((string) $upload['tmp_name'], $dir . '/' . $filename)) {
        $errors[] = '头像保存失败，请检查 assets/uploads/avatars 目录权限';
        return null;
    }

    // 删除旧头像文件
    $accounts = getStaffAccounts();
    $old = (string) ($accounts[$username]['avatar'] ?? '');
    if ($old !== '') {
        $oldPath = dirname(__DIR__) . $old;
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    }

    $avatarUrl = '/assets/uploads/avatars/' . $filename;
    $accounts[$username]['avatar'] = $avatarUrl;
    persistStaffAccounts($accounts);
    return $avatarUrl;
}

function removeStaffAvatar(string $username): void
{
    $accounts = getStaffAccounts();
    $old = (string) ($accounts[$username]['avatar'] ?? '');
    if ($old === '') {
        return;
    }
    $oldPath = dirname(__DIR__) . $old;
    if (is_file($oldPath)) {
        @unlink($oldPath);
    }
    unset($accounts[$username]['avatar']);
    persistStaffAccounts($accounts);
}

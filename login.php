<?php

session_start();

if (isset($_SESSION['staff'])) {
    $sessionRole = (string) ($_SESSION['staff']['role'] ?? '');
    $sessionTarget = match ($sessionRole) {
        'barista_screen', 'barista' => '/order-board.php',
        'customer_screen', 'reception' => '/customer-board.php',
        default => '/',
    };
    header('Location: ' . $sessionTarget);
    exit;
}

require_once __DIR__ . '/includes/functions.php';

if (!hasStaffAccounts()) {
    header('Location: /setup.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '') {
        $errors[] = '请输入用户名';
    }
    if ($password === '') {
        $errors[] = '请输入密码';
    }

    if (empty($errors)) {
        $staff = authenticateStaff($username, $password);
        if ($staff) {
            session_regenerate_id(true);
            $_SESSION['staff'] = $staff;
            setActiveSession($username, session_id());
            logOperation(getDb(), 'staff_login', 'staff', null, '员工登录：' . $staff['name'], [], $staff);
            if (isStaffFrozen($username)) {
                flash('warning', '您的账号已被冻结，目前仅可使用反馈功能。如有疑问请联系管理员。');
                header('Location: /feedback.php');
                exit;
            }
            $loginTarget = match ((string) ($staff['role'] ?? '')) {
                'barista_screen', 'barista' => '/order-board.php',
                'customer_screen', 'reception' => '/customer-board.php',
                default => '/',
            };
            header('Location: ' . (isLicenseActive() ? $loginTarget : '/activate.php'));
            exit;
        }
        $errors[] = '用户名或密码错误';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(sitePageTitle('登录')) ?></title>
    <link rel="icon" href="<?= htmlspecialchars(getFaviconUrl()) ?>">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars(getFaviconUrl()) ?>">
    <link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
    <script src="<?= assetUrl('/assets/js/session-sync.js') ?>" defer></script>
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background:
                radial-gradient(1000px 480px at 85% -10%, rgba(201, 138, 61, 0.14), transparent 60%),
                radial-gradient(800px 420px at -10% 25%, rgba(111, 78, 55, 0.10), transparent 55%),
                var(--bg);
        }
        .login-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 2.4rem 2.2rem;
            box-shadow: var(--shadow-lg);
            max-width: 400px;
            width: 100%;
            margin: 1rem;
            position: relative;
            overflow: hidden;
            animation: pop-in 0.3s var(--ease);
        }
        .login-card::before {
            content: "";
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 5px;
            background: linear-gradient(90deg, var(--caramel), var(--espresso-500));
        }
        .login-card h1 {
            text-align: center;
            margin-bottom: 0.5rem;
            color: var(--espresso-700);
            font-size: 1.45rem;
        }
        .login-card h1::before {
            content: "";
            display: block;
            width: 46px;
            height: 46px;
            margin: 0 auto 0.55rem;
            background: url("<?= htmlspecialchars(getFaviconUrl()) ?>") center / contain no-repeat;
            border-radius: 12px;
        }
        .login-card p {
            text-align: center;
            color: var(--text-muted);
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <h1><?= htmlspecialchars(getSiteName()) ?></h1>
        <p>员工登录</p>
        
        <?php if ($_GET['reason'] ?? '' === 'logged_in_elsewhere'): ?>
            <div class="alert alert-error">
                <div>您的账号已在其他设备登录，请重新登录</div>
            </div>
        <?php endif; ?>

        <?php $pageFlash = getFlash(); if ($pageFlash): ?>
            <div class="alert alert-<?= htmlspecialchars($pageFlash['type']) ?>"><?= htmlspecialchars($pageFlash['message']) ?></div>
        <?php endif; ?>
        <?php if ($errors): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $e): ?>
                    <div><?= htmlspecialchars($e) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post">
                <?= csrfField() ?>
            <div class="form-group">
                <label>用户名 <span class="required">*</span></label>
                <input type="text" name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" placeholder="请输入用户名" required>
            </div>
            <div class="form-group">
                <label>密码 <span class="required">*</span></label>
                <input type="password" name="password" placeholder="请输入密码" required>
            </div>
            <div class="form-actions" style="justify-content: center;">
                <button type="submit" class="btn btn-primary">登录</button>
            </div>
            <p style="text-align: center; margin-top: 1rem;">
                <a href="/reset_password.php" class="btn btn-link" style="padding: 0;">忘记密码？</a>
            </p>
            <p style="text-align: center; margin-top: 0.75rem;">
                <a href="/cq/" class="btn btn-link" style="padding: 0;">会员查询余额与积分</a>
            </p>
        </form>
    </div>
</body>
</html>

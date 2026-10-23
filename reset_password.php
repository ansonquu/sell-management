<?php

session_start();

if (isset($_SESSION['staff'])) {
    header('Location: /');
    exit;
}

require_once __DIR__ . '/includes/functions.php';

$errors = [];
$step = $_GET['step'] ?? '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    if ($step === '1') {
        $username = trim($_POST['username'] ?? '');
        
        if ($username === '') {
            $errors[] = '请输入用户名';
        } else {
            $accounts = getStaffAccounts();
            if (!isset($accounts[$username])) {
                $errors[] = '用户名不存在';
            } else {
                $_SESSION['reset_username'] = $username;
                header('Location: /reset_password.php?step=2');
                exit;
            }
        }
    } elseif ($step === '2') {
        $username = $_SESSION['reset_username'] ?? '';
        
        if (!$username) {
            header('Location: /reset_password.php?step=1');
            exit;
        }
        
        $oldPassword = trim($_POST['old_password'] ?? '');
        
        if ($oldPassword === '') {
            $errors[] = '请输入旧密码';
        } else {
            $authResult = authenticateStaff($username, $oldPassword);
            if (!$authResult) {
                $errors[] = '旧密码不正确';
            } else {
                $_SESSION['reset_verified'] = true;
                header('Location: /reset_password.php?step=3');
                exit;
            }
        }
    } elseif ($step === '3') {
        $username = $_SESSION['reset_username'] ?? '';
        $verified = $_SESSION['reset_verified'] ?? false;
        
        if (!$username || !$verified) {
            header('Location: /reset_password.php?step=1');
            exit;
        }
        
        $newPassword = trim($_POST['new_password'] ?? '');
        $confirmPassword = trim($_POST['confirm_password'] ?? '');
        
        if ($newPassword === '') {
            $errors[] = '请输入新密码';
        }
        if ($confirmPassword === '') {
            $errors[] = '请确认新密码';
        }
        if ($newPassword !== $confirmPassword) {
            $errors[] = '两次输入的密码不一致';
        }
        if (strlen($newPassword) < 6) {
            $errors[] = '新密码至少需要6个字符';
        }
        
        if (empty($errors)) {
            if (updateStaffPassword($username, $newPassword)) {
                $accounts = getStaffAccounts();
                $account = $accounts[$username] ?? [];
                logOperation(getDb(), 'staff_password_reset', 'staff', null, '重置员工密码：' . ($account['name'] ?? $username), [], [
                    'username' => $username,
                    'name' => $account['name'] ?? $username,
                    'role' => $account['role'] ?? '',
                ]);
                unset($_SESSION['reset_username']);
                unset($_SESSION['reset_verified']);
                flash('success', '密码重置成功，请使用新密码登录');
                header('Location: /login.php');
                exit;
            } else {
                $errors[] = '密码重置失败，请重试';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(sitePageTitle('忘记密码')) ?></title>
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
        .step-indicator {
            text-align: center;
            margin-bottom: 1.5rem;
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        .step-indicator span { margin: 0 0.5rem; }
        .step-indicator .active {
            color: var(--espresso-700);
            font-weight: 700;
            background: var(--caramel-soft);
            padding: 0.15rem 0.65rem;
            border-radius: var(--radius-pill);
        }
    </style>
</head>
<body>
    <div class="login-card">
        <h1>忘记密码</h1>
        
        <div class="step-indicator">
            <span class="<?= $step >= '1' ? 'active' : '' ?>">1. 输入用户名</span>
            <span>→</span>
            <span class="<?= $step >= '2' ? 'active' : '' ?>">2. 验证身份</span>
            <span>→</span>
            <span class="<?= $step >= '3' ? 'active' : '' ?>">3. 设置新密码</span>
        </div>
        
        <?php if ($step === '1'): ?>
            <p>请输入用户名</p>
        <?php elseif ($step === '2'): ?>
            <p>请输入旧密码验证身份</p>
        <?php else: ?>
            <p>请设置新密码</p>
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
            <?php if ($step === '1'): ?>
                <div class="form-group">
                    <label>用户名 <span class="required">*</span></label>
                    <input type="text" name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" placeholder="请输入用户名" required>
                </div>
                <div class="form-actions" style="justify-content: center;">
                    <button type="submit" class="btn btn-primary">下一步</button>
                </div>
                <p style="text-align: center; margin-top: 1rem;">
                    <a href="/login.php" class="btn btn-link" style="padding: 0;">返回登录</a>
                </p>
            <?php elseif ($step === '2'): ?>
                <div class="form-group">
                    <label>旧密码 <span class="required">*</span></label>
                    <input type="password" name="old_password" placeholder="请输入旧密码" required>
                </div>
                <div class="form-actions" style="justify-content: center;">
                    <button type="submit" class="btn btn-primary">下一步</button>
                </div>
                <p style="text-align: center; margin-top: 1rem;">
                    <a href="/reset_password.php?step=1" class="btn btn-link" style="padding: 0;">返回</a>
                    <a href="/login.php" class="btn btn-link" style="padding: 0;">返回登录</a>
                </p>
            <?php else: ?>
                <div class="form-group">
                    <label>新密码 <span class="required">*</span></label>
                    <input type="password" name="new_password" placeholder="请输入新密码（至少6个字符）" required>
                </div>
                <div class="form-group">
                    <label>确认新密码 <span class="required">*</span></label>
                    <input type="password" name="confirm_password" placeholder="请再次输入新密码" required>
                </div>
                <div class="form-actions" style="justify-content: center;">
                    <button type="submit" class="btn btn-primary">确认重置</button>
                </div>
                <p style="text-align: center; margin-top: 1rem;">
                    <a href="/reset_password.php?step=1" class="btn btn-link" style="padding: 0;">返回</a>
                    <a href="/login.php" class="btn btn-link" style="padding: 0;">返回登录</a>
                </p>
            <?php endif; ?>
        </form>
    </div>
</body>
</html>

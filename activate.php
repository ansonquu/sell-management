<?php

session_start();
require_once __DIR__ . '/includes/functions.php';

if (!hasStaffAccounts()) {
    header('Location: /setup.php');
    exit;
}
$staff = getCurrentStaff();
if (!$staff || !isSessionValid($staff['username'], session_id())) {
    header('Location: /login.php');
    exit;
}
if (($staff['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('仅总管理员可以管理系统授权');
}

$errors = [];
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? 'activate';
    if ($action === 'verify') {
        $result = verifyLicenseCode(true);
    } else {
        $code = trim($_POST['activation_code'] ?? '');
        $result = $code === ''
            ? ['ok' => false, 'message' => '请输入激活码']
            : activateLicenseCode($code);
    }
    if ($result['ok']) {
        $success = $action === 'verify' ? '授权校验通过' : '系统激活成功';
    } else {
        $errors[] = $result['message'];
    }
}
$license = getLicenseState();
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars(sitePageTitle('系统授权')) ?></title>
    <link rel="icon" href="<?= htmlspecialchars(getFaviconUrl()) ?>">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars(getFaviconUrl()) ?>">
    <link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
    <script src="<?= assetUrl('/assets/js/session-sync.js') ?>" defer></script>
    <style>body{display:flex;align-items:center;justify-content:center;min-height:100vh;background:radial-gradient(1000px 480px at 85% -10%, rgba(201,138,61,.13), transparent 60%), radial-gradient(800px 420px at -10% 25%, rgba(111,78,55,.09), transparent 55%), var(--bg)}.license-card{max-width:560px;width:100%;margin:1rem;padding:2.2rem;background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);position:relative;overflow:hidden;animation:pop-in .3s var(--ease)}.license-card::before{content:"";position:absolute;top:0;left:0;right:0;height:5px;background:linear-gradient(90deg,var(--caramel),var(--espresso-500))}.license-card h1{text-align:center;color:var(--espresso-700)}.license-card h1::before{content:"";display:block;width:46px;height:46px;margin:0 auto .55rem;background:url("<?= htmlspecialchars(getFaviconUrl()) ?>") center/contain no-repeat;border-radius:12px}.license-meta{margin:1rem 0;padding:1rem 1.1rem;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm)}.license-meta div{margin:.4rem 0;color:var(--text-soft)}</style>
</head>
<body><main class="license-card">
    <h1>系统授权</h1>
    <?php $pageFlash = getFlash(); if ($pageFlash): ?><div class="alert alert-<?= htmlspecialchars($pageFlash['type']) ?>"><?= htmlspecialchars($pageFlash['message']) ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $error): ?><div><?= htmlspecialchars($error) ?></div><?php endforeach; ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <div class="license-meta">
        <div>状态：<strong><?= isLicenseActive() ? '已激活' : '未激活' ?></strong></div>
        <?php if ($license['activation_code']): ?><div>激活码：<?= htmlspecialchars(maskedLicenseCode($license['activation_code'])) ?></div><?php endif; ?>
        <div>设备编号：<?= htmlspecialchars($license['install_id']) ?></div>
        <?php if ($license['last_verified_at']): ?><div>最近通过校验：<?= htmlspecialchars($license['last_verified_at']) ?></div><?php endif; ?>
        <?php if ($license['expires_at']): ?><div>授权到期：<?= htmlspecialchars($license['expires_at']) ?></div><?php endif; ?>
        <?php if ($license['last_error']): ?><div class="text-danger">最近错误：<?= htmlspecialchars($license['last_error']) ?></div><?php endif; ?>
    </div>
    <form method="post">
                <?= csrfField() ?>
        <input type="hidden" name="action" value="activate">
        <div class="form-group"><label>激活码 <span class="required">*</span></label><input name="activation_code" required autocomplete="off" placeholder="请输入授权站生成的激活码"></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">激活系统</button><?php if (isLicenseActive()): ?><a class="btn btn-success" href="/">进入系统</a><?php endif; ?></div>
    </form>
    <?php if ($license['activation_code']): ?><form method="post" style="margin-top:1rem">
                <?= csrfField() ?><input type="hidden" name="action" value="verify"><button class="btn btn-secondary" type="submit">立即校验</button></form><?php endif; ?>
    <p class="text-muted" style="margin-top:1rem">系统会在每天 23:00 后自动进行一次授权校验；服务器计划任务可确保准点执行。</p>
</main></body></html>

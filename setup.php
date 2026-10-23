<?php

session_start();
require_once __DIR__ . '/includes/functions.php';

if (hasStaffAccounts()) {
    header('Location: /login.php');
    exit;
}

$errors = [];
$formSettings = defaultSystemSettings();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $username = trim($_POST['username'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    $formSettings = parseSystemSettingsInput($_POST, $errors);

    if (!preg_match('/^[A-Za-z0-9_-]{3,30}$/', $username)) {
        $errors[] = '管理员账号只能使用 3-30 位英文、数字、下划线或短横线';
    }
    if ($name === '') {
        $errors[] = '请输入管理员姓名';
    }
    if (strlen($password) < 8) {
        $errors[] = '密码至少需要 8 个字符';
    }
    if ($password !== $confirmPassword) {
        $errors[] = '两次输入的密码不一致';
    }

    $uploadedFavicon = !$errors ? storeUploadedFavicon($_FILES['favicon'] ?? [], $errors) : null;
    if ($uploadedFavicon !== null) {
        $formSettings['favicon_path'] = $uploadedFavicon;
    }

    if (!$errors && !saveSystemSettings($formSettings)) {
        $errors[] = '系统设置保存失败，请确认 data 目录可写';
    }

    if (!$errors && createInitialAdmin($username, $name, $password)) {
        session_regenerate_id(true);
        $staff = ['username' => $username, 'name' => $name, 'role' => 'admin'];
        $_SESSION['staff'] = $staff;
        setActiveSession($username, session_id());
        logOperation(getDb(), 'system_setup', 'system', null, '完成系统初始化并创建总管理员', [], $staff);
        header('Location: /activate.php');
        exit;
    }
    if (!$errors) {
        $errors[] = '初始化失败，请确认 data 目录可写';
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars(sitePageTitle('首次初始化')) ?></title>
    <link rel="icon" href="<?= htmlspecialchars(getFaviconUrl()) ?>">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars(getFaviconUrl()) ?>">
    <link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
    <script src="<?= assetUrl('/assets/js/session-sync.js') ?>" defer></script>
    <style>body{background:radial-gradient(1000px 480px at 85% -10%, rgba(201,138,61,.13), transparent 60%), radial-gradient(800px 420px at -10% 25%, rgba(111,78,55,.09), transparent 55%), var(--bg)}.setup-card{max-width:920px;width:calc(100% - 2rem);margin:2rem auto;padding:2.2rem;background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);position:relative;overflow:hidden;animation:pop-in .3s var(--ease)}.setup-card::before{content:"";position:absolute;top:0;left:0;right:0;height:5px;background:linear-gradient(90deg,var(--caramel),var(--espresso-500))}.setup-card h1{text-align:center;color:var(--espresso-700)}.setup-card h1::before{content:"";display:block;width:46px;height:46px;margin:0 auto .55rem;background:url("<?= htmlspecialchars(getFaviconUrl()) ?>") center/contain no-repeat;border-radius:12px}.setup-card>p{text-align:center;color:var(--text-muted)}.admin-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem}.admin-grid .form-group{margin:0}@media(max-width:700px){.admin-grid{grid-template-columns:1fr}}</style>
</head>
<body><main class="setup-card">
    <h1>初始化系统</h1><p>创建总管理员，并设置门店的基础业务规则</p>
    <?php $pageFlash = getFlash(); if ($pageFlash): ?><div class="alert alert-<?= htmlspecialchars($pageFlash['type']) ?>"><?= htmlspecialchars($pageFlash['message']) ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $error): ?><div><?= htmlspecialchars($error) ?></div><?php endforeach; ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data">
                <?= csrfField() ?>
        <h3>总管理员</h3>
        <div class="admin-grid">
            <div class="form-group"><label>管理员账号 <span class="required">*</span></label><input name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autocomplete="username"></div>
            <div class="form-group"><label>管理员姓名 <span class="required">*</span></label><input name="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required></div>
            <div class="form-group"><label>密码 <span class="required">*</span></label><input type="password" name="password" required minlength="8" autocomplete="new-password"></div>
            <div class="form-group"><label>确认密码 <span class="required">*</span></label><input type="password" name="confirm_password" required minlength="8" autocomplete="new-password"></div>
        </div>
        <?php require __DIR__ . '/includes/settings_form.php'; ?>
        <div class="form-actions" style="justify-content:center;margin-top:2rem"><button class="btn btn-primary" type="submit">保存设置并创建总管理员</button></div>
    </form>
</main></body></html>

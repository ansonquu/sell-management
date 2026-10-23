<?php

session_start();
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';

$errors = [];
$staff = getCurrentStaff();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $oldPassword = trim($_POST['old_password'] ?? '');
    $newPassword = trim($_POST['new_password'] ?? '');
    $confirmPassword = trim($_POST['confirm_password'] ?? '');

    if ($oldPassword === '') {
        $errors[] = '请输入旧密码';
    }
    if ($newPassword === '') {
        $errors[] = '请输入新密码';
    }
    if ($confirmPassword === '') {
        $errors[] = '请确认新密码';
    }
    if ($newPassword !== $confirmPassword) {
        $errors[] = '两次输入的新密码不一致';
    }
    if (strlen($newPassword) < 6) {
        $errors[] = '新密码至少需要6个字符';
    }

    if (empty($errors)) {
        $authResult = authenticateStaff($staff['username'], $oldPassword);
        if (!$authResult) {
            $errors[] = '旧密码不正确';
        } else {
            if (updateStaffPassword($staff['username'], $newPassword)) {
                logOperation(getDb(), 'staff_password_change', 'staff', null, '员工修改密码：' . $staff['name'], [], $staff);
                flash('success', '密码修改成功，请使用新密码重新登录');
                logout();
            } else {
                $errors[] = '密码修改失败，请重试';
            }
        }
    }
}

renderHeader('修改密码 - Anson咖啡厅管理系统', '');
?>

<div class="page-header">
    <h2 class="page-title">修改密码</h2>
</div>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $e): ?>
            <div><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card form-card">
    <form method="post">
                <?= csrfField() ?>
        <div class="form-group">
            <label>旧密码 <span class="required">*</span></label>
            <input type="password" name="old_password" placeholder="请输入当前密码" required>
        </div>
        <div class="form-group">
            <label>新密码 <span class="required">*</span></label>
            <input type="password" name="new_password" placeholder="请输入新密码（至少6个字符）" required>
        </div>
        <div class="form-group">
            <label>确认新密码 <span class="required">*</span></label>
            <input type="password" name="confirm_password" placeholder="请再次输入新密码" required>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">确认修改</button>
            <a href="/" class="btn btn-secondary">取消</a>
        </div>
    </form>
</div>

<?php renderFooter(); ?>

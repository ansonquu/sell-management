<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';

requireAdmin();

$errors = [];
$formSettings = getSystemSettings();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $formSettings = parseSystemSettingsInput($_POST, $errors);
    $uploadedFavicon = !$errors ? storeUploadedFavicon($_FILES['favicon'] ?? [], $errors) : null;
    if ($uploadedFavicon !== null) {
        $formSettings['favicon_path'] = $uploadedFavicon;
    }
    if (!$errors && saveSystemSettings($formSettings)) {
        $pdo = getDb();
        $members = $pdo->query('SELECT id, total_spent FROM member')->fetchAll();
        $pdo->beginTransaction();
        foreach ($members as $member) {
            $pdo->prepare('UPDATE member SET level = ? WHERE id = ?')->execute([
                calculateLevel((float) $member['total_spent']),
                $member['id'],
            ]);
        }
        logOperation($pdo, 'system_settings_update', 'system', null, '更新门店业务设置');
        $pdo->commit();
        flash('success', '系统设置已保存，现有会员等级已重新计算');
        redirect('/admin/settings.php');
    } elseif (!$errors) {
        $errors[] = '设置保存失败，请重试';
    }
}

renderHeader('系统设置', 'settings');
?>
<div class="page-header">
    <h2 class="page-title">系统设置</h2>
    <div class="page-actions"><a href="/admin/logs.php" class="btn btn-secondary">返回操作记录</a></div>
</div>
<?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $error): ?><div><?= htmlspecialchars($error) ?></div><?php endforeach; ?></div><?php endif; ?>
<div class="card form-card" style="max-width:920px">
    <form method="post" enctype="multipart/form-data">
                <?= csrfField() ?>
        <?php require __DIR__ . '/../includes/settings_form.php'; ?>
        <div class="form-actions" style="margin-top:2rem"><button type="submit" class="btn btn-primary">保存系统设置</button></div>
    </form>
</div>

<div class="card" style="max-width:920px">
    <h3>数据导出</h3>
    <p class="text-muted" style="margin-bottom:1rem">导出为 CSV 文件（UTF-8，可直接用 Excel 打开）。消费记录默认导出最近 90 天。</p>
    <div class="export-actions">
        <div class="export-item">
            <div>
                <strong>员工列表</strong>
                <small class="text-muted">账号、姓名、角色、权限、状态</small>
            </div>
            <a class="btn btn-secondary" href="/admin/export.php?type=staff">导出</a>
        </div>
        <div class="export-item">
            <div>
                <strong>会员列表</strong>
                <small class="text-muted">联系方式、等级、余额、积分、累计消费</small>
            </div>
            <a class="btn btn-secondary" href="/admin/export.php?type=members">导出</a>
        </div>
        <div class="export-item">
            <div>
                <strong>消费记录</strong>
                <small class="text-muted">商品、金额、成本、支付方式、操作人、退款/换货状态</small>
            </div>
            <a class="btn btn-secondary" href="/admin/export.php?type=consumption">导出</a>
        </div>
    </div>
    <p class="text-muted" style="margin-top:1rem;margin-bottom:0">咖啡厅资金流水请到「<a href="/finance/entries.php">财务管理 → 收支流水</a>」导出。</p>
</div>
<?php renderFooter(); ?>

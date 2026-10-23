<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/db.php';

$pdo = getDb();

$templates = $pdo->query("SELECT * FROM coupon_templates ORDER BY created_at DESC")->fetchAll();

foreach ($templates as &$t) {
    $count = $pdo->query("SELECT COUNT(*) as cnt FROM member_coupons WHERE template_id = {$t['id']} AND status = 'unused'")->fetch();
    $t['unused_count'] = $count['cnt'];
    $usedCount = $pdo->query("SELECT COUNT(*) as cnt FROM member_coupons WHERE template_id = {$t['id']} AND status = 'used'")->fetch();
    $t['used_count'] = $usedCount['cnt'];
}

renderHeader('优惠券管理 - Anson咖啡厅管理系统', 'coupons');
?>

<div class="page-header">
    <h2 class="page-title">优惠券管理</h2>
    <div class="quick-actions">
        <?php if (isAdmin()): ?>
        <a href="/coupons/add.php" class="btn btn-primary">+ 添加优惠券</a>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($templates)): ?>
    <div class="empty-state">
        <div style="font-size: 3rem; margin-bottom: 1rem;">🎫</div>
        <p>暂无优惠券模板</p>
        <?php if (isAdmin()): ?>
        <a href="/coupons/add.php" class="btn btn-primary">创建第一个优惠券</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="card">
        <div class="coupon-grid">
            <?php foreach ($templates as $t): ?>
                <div class="coupon-card">
                    <div class="coupon-header">
                        <span class="badge <?= $t['status'] === 'active' ? 'badge-success' : 'badge-danger' ?>">
                            <?= $t['status'] === 'active' ? '启用' : '停用' ?>
                        </span>
                        <span class="coupon-type"><?= $t['type'] === 'fixed' ? '代金券' : '折扣券' ?></span>
                    </div>
                    <h3 class="coupon-name"><?= htmlspecialchars($t['name']) ?></h3>
                    <div class="coupon-value">
                        <?php if ($t['type'] === 'fixed'): ?>
                            <span class="coupon-amount">¥<?= $t['value'] ?></span>
                            <?php if ($t['min_amount'] > 0): ?>
                                <span class="coupon-condition">满<?= $t['min_amount'] ?>可用</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="coupon-amount"><?= $t['value'] ?>折</span>
                            <?php if ($t['min_amount'] > 0): ?>
                                <span class="coupon-condition">满<?= $t['min_amount'] ?>可用</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="coupon-stats">
                        <div class="stat-item">
                            <span class="stat-value"><?= $t['total_quantity'] > 0 ? $t['total_quantity'] : '不限量' ?></span>
                            <span class="stat-label">发行总量</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-value"><?= $t['issued_quantity'] ?></span>
                            <span class="stat-label">已发放</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-value"><?= $t['unused_count'] ?></span>
                            <span class="stat-label">未使用</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-value"><?= $t['used_count'] ?></span>
                            <span class="stat-label">已使用</span>
                        </div>
                    </div>
                    <div class="coupon-meta">
                        <span>有效期：<?= $t['expire_days'] ?>天</span>
                        <?php if ($t['operator']): ?>
                            <span>创建人：<?= htmlspecialchars($t['operator']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="coupon-actions">
                        <?php if (isAdmin()): ?>
                        <a href="/coupons/edit.php?id=<?= $t['id'] ?>" class="btn btn-sm">编辑</a>
                        <?php endif; ?>
                        <a href="/coupons/issue.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-success">发放</a>
                        <?php if (isAdmin()): ?>
                        <a href="/coupons/delete.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-danger">删除</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php renderFooter(); ?>
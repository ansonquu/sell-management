<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/db.php';

$pdo = getDb();
$id = $_GET['id'] ?? '';

if (!is_numeric($id)) {
    flash('error', '无效的优惠券ID');
    redirect('/coupons/index.php');
}

$template = $pdo->query("SELECT * FROM coupon_templates WHERE id = $id")->fetch();

if (!$template) {
    flash('error', '优惠券模板不存在');
    redirect('/coupons/index.php');
}

$couponCount = $pdo->query("SELECT COUNT(*) as cnt FROM member_coupons WHERE template_id = $id")->fetch()['cnt'];

if (!isAdmin()) {
    flash('error', '您没有权限删除优惠券');
    redirect('/coupons/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $pdo->beginTransaction();
    try {
        logOperation($pdo, 'coupon_template_delete', 'coupon_template', (int) $id, '删除优惠券模板：' . $template['name'], [
            'name' => $template['name'],
            'type' => $template['type'],
            'value' => $template['value'],
            'issued_count' => $couponCount,
        ]);
        $pdo->exec("DELETE FROM member_coupons WHERE template_id = $id");
        $pdo->exec("DELETE FROM coupon_templates WHERE id = $id");
        $pdo->commit();
        flash('success', '优惠券模板删除成功');
        redirect('/coupons/index.php');
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('error', '删除失败，请重试');
        redirect('/coupons/index.php');
    }
}

renderHeader('删除优惠券 - Anson咖啡厅管理系统', 'coupons');
?>

<div class="page-header">
    <h2 class="page-title">确认删除</h2>
</div>

<div class="card" style="max-width: 500px; margin: 0 auto;">
    <div class="alert alert-warning">
        <strong>⚠️ 警告</strong>：删除后无法恢复！
    </div>

    <div style="padding: 1.5rem; background: var(--bg); border-radius: var(--radius); margin-bottom: 1.5rem;">
        <h3 style="margin-bottom: 1rem;">即将删除的优惠券</h3>
        <p><strong>名称：</strong><?= htmlspecialchars($template['name']) ?></p>
        <p><strong>类型：</strong><?= $template['type'] === 'fixed' ? '代金券' : '折扣券' ?></p>
        <p><strong>面值：</strong><?= $template['type'] === 'fixed' ? '¥' . $template['value'] : $template['value'] . '折' ?></p>
        <p><strong>已发放数量：</strong><?= $couponCount ?> 张</p>
    </div>

    <?php if ($couponCount > 0): ?>
        <div class="alert alert-error">
            该优惠券已有 <?= $couponCount ?> 张已发放给会员，删除后这些会员的优惠券也将被一并删除！
        </div>
    <?php endif; ?>

    <form method="post" class="form">
                <?= csrfField() ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-danger">确认删除</button>
            <a href="/coupons/index.php" class="btn btn-secondary">取消</a>
        </div>
    </form>
</div>

<?php renderFooter(); ?>

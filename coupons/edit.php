<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/db.php';

requireAdmin();

$pdo = getDb();
$id = $_GET['id'] ?? '';

if (!is_numeric($id)) {
    flash('error', '无效的优惠券ID');
    header('Location: /coupons/index.php');
    exit;
}

$template = $pdo->query("SELECT * FROM coupon_templates WHERE id = $id")->fetch();

if (!$template) {
    flash('error', '优惠券模板不存在');
    header('Location: /coupons/index.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $name = trim($_POST['name'] ?? '');
    $type = $_POST['type'] ?? 'fixed';
    $value = $_POST['value'] ?? '';
    $min_amount = $_POST['min_amount'] ?? '0';
    $total_quantity = $_POST['total_quantity'] ?? '0';
    $expire_days = $_POST['expire_days'] ?? '7';
    $status = $_POST['status'] ?? 'active';

    if ($name === '') {
        $errors[] = '请输入优惠券名称';
    }

    if (!is_numeric($value) || $value <= 0) {
        $errors[] = '请输入有效的面值/折扣率';
    }

    if ($type === 'percent' && ($value <= 0 || $value >= 10)) {
        $errors[] = '折扣率必须在0.1-9.9之间';
    }

    if (!is_numeric($min_amount) || $min_amount < 0) {
        $errors[] = '最低消费金额不能为负数';
    }

    if (!is_numeric($total_quantity) || $total_quantity < 0) {
        $errors[] = '发行数量不能为负数';
    }

    if (!is_numeric($expire_days) || $expire_days <= 0) {
        $errors[] = '有效期天数必须大于0';
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("
            UPDATE coupon_templates SET name = ?, type = ?, value = ?, min_amount = ?, 
                total_quantity = ?, expire_days = ?, status = ?
            WHERE id = ?
        ");
        $stmt->execute([$name, $type, $value, $min_amount, $total_quantity, $expire_days, $status, $id]);
        logOperation($pdo, 'coupon_template_update', 'coupon_template', (int) $id, '编辑优惠券模板：' . $name, [
            'old_name' => $template['name'],
            'new_name' => $name,
            'type' => $type,
            'value' => $value,
            'min_amount' => $min_amount,
            'total_quantity' => $total_quantity,
            'expire_days' => $expire_days,
            'status' => $status,
        ]);

        flash('success', '优惠券模板更新成功');
        header('Location: /coupons/index.php');
        exit;
    }
}

renderHeader('编辑优惠券 - Anson咖啡厅管理系统', 'coupons');
?>

<div class="page-header">
    <h2 class="page-title">编辑优惠券模板</h2>
</div>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $e): ?>
            <div><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card">
    <form method="post" class="form">
                <?= csrfField() ?>
        <div class="grid-2">
            <div class="form-group">
                <label>优惠券名称 <span class="required">*</span></label>
                <input type="text" name="name" value="<?= htmlspecialchars($_POST['name'] ?? $template['name']) ?>" required>
            </div>

            <div class="form-group">
                <label>优惠券类型 <span class="required">*</span></label>
                <select name="type" required>
                    <option value="fixed" <?= ($_POST['type'] ?? $template['type']) === 'fixed' ? 'selected' : '' ?>>代金券</option>
                    <option value="percent" <?= ($_POST['type'] ?? $template['type']) === 'percent' ? 'selected' : '' ?>>折扣券</option>
                </select>
            </div>
        </div>

        <div class="grid-2">
            <div class="form-group">
                <label>面值/折扣率 <span class="required">*</span></label>
                <input type="number" step="0.1" name="value" value="<?= htmlspecialchars($_POST['value'] ?? $template['value']) ?>" required>
                <small style="display: block; margin-top: 0.3rem; color: var(--text-muted);">
                    <?= ($_POST['type'] ?? $template['type']) === 'fixed' ? '例如：10 表示减10元' : '例如：8.5 表示8.5折' ?>
                </small>
            </div>

            <div class="form-group">
                <label>最低消费金额</label>
                <input type="number" step="0.01" name="min_amount" value="<?= htmlspecialchars($_POST['min_amount'] ?? $template['min_amount']) ?>">
            </div>
        </div>

        <div class="grid-2">
            <div class="form-group">
                <label>发行数量</label>
                <input type="number" name="total_quantity" value="<?= htmlspecialchars($_POST['total_quantity'] ?? $template['total_quantity']) ?>">
            </div>

            <div class="form-group">
                <label>有效期天数 <span class="required">*</span></label>
                <input type="number" name="expire_days" value="<?= htmlspecialchars($_POST['expire_days'] ?? $template['expire_days']) ?>" required>
            </div>
        </div>

        <div class="form-group">
            <label>状态</label>
            <select name="status">
                <option value="active" <?= ($_POST['status'] ?? $template['status']) === 'active' ? 'selected' : '' ?>>启用</option>
                <option value="inactive" <?= ($_POST['status'] ?? $template['status']) === 'inactive' ? 'selected' : '' ?>>停用</option>
            </select>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">保存修改</button>
            <a href="/coupons/index.php" class="btn btn-secondary">取消</a>
        </div>
    </form>
</div>

<?php renderFooter(); ?>

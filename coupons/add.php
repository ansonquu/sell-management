<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/db.php';

requireAdmin();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $name = trim($_POST['name'] ?? '');
    $type = $_POST['type'] ?? 'fixed';
    $value = $_POST['value'] ?? '';
    $min_amount = $_POST['min_amount'] ?? '0';
    $total_quantity = $_POST['total_quantity'] ?? '0';
    $expire_days = $_POST['expire_days'] ?? '7';

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
        $pdo = getDb();
        $staff = getCurrentStaff();
        $operator = $staff ? $staff['name'] : '';
        
        $stmt = $pdo->prepare("
            INSERT INTO coupon_templates (name, type, value, min_amount, total_quantity, expire_days, operator, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $type, $value, $min_amount, $total_quantity, $expire_days, $operator, currentDateTime()]);
        $templateId = (int) $pdo->lastInsertId();
        logOperation($pdo, 'coupon_template_create', 'coupon_template', $templateId, '创建优惠券模板：' . $name, [
            'name' => $name,
            'type' => $type,
            'value' => $value,
            'min_amount' => $min_amount,
            'total_quantity' => $total_quantity,
            'expire_days' => $expire_days,
        ]);

        flash('success', '优惠券模板创建成功');
        header('Location: /coupons/index.php');
        exit;
    }
}

renderHeader('添加优惠券 - Anson咖啡厅管理系统', 'coupons');
?>

<div class="page-header">
    <h2 class="page-title">添加优惠券模板</h2>
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
                <input type="text" name="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" placeholder="例如：满50减10优惠券" required>
            </div>

            <div class="form-group">
                <label>优惠券类型 <span class="required">*</span></label>
                <select name="type" required>
                    <option value="fixed" <?= ($_POST['type'] ?? '') === 'fixed' ? 'selected' : '' ?>>代金券</option>
                    <option value="percent" <?= ($_POST['type'] ?? '') === 'percent' ? 'selected' : '' ?>>折扣券</option>
                </select>
            </div>
        </div>

        <div class="grid-2">
            <div class="form-group">
                <label>面值/折扣率 <span class="required">*</span></label>
                <input type="number" step="0.1" name="value" value="<?= htmlspecialchars($_POST['value'] ?? '') ?>" placeholder="固定金额填金额，折扣券填折数" required>
                <small style="display: block; margin-top: 0.3rem; color: var(--text-muted);">
                    <?= ($_POST['type'] ?? 'fixed') === 'fixed' ? '例如：10 表示减10元' : '例如：8.5 表示8.5折' ?>
                </small>
            </div>

            <div class="form-group">
                <label>最低消费金额</label>
                <input type="number" step="0.01" name="min_amount" value="<?= htmlspecialchars($_POST['min_amount'] ?? '0') ?>" placeholder="0表示无限制">
            </div>
        </div>

        <div class="grid-2">
            <div class="form-group">
                <label>发行数量</label>
                <input type="number" name="total_quantity" value="<?= htmlspecialchars($_POST['total_quantity'] ?? '0') ?>" placeholder="0表示不限量">
            </div>

            <div class="form-group">
                <label>有效期天数 <span class="required">*</span></label>
                <input type="number" name="expire_days" value="<?= htmlspecialchars($_POST['expire_days'] ?? '7') ?>" placeholder="例如：7" required>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">创建优惠券</button>
            <a href="/coupons/index.php" class="btn btn-secondary">取消</a>
        </div>
    </form>
</div>

<?php renderFooter(); ?>

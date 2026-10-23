<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
requireLogin();
requireRegularStaffPermission();

$pdo = getDb();
$errors = [];

// 原始记录：支持 ?consumption_id=X / ?recharge_id=X / ?target=consumption:X
$consumptionId = (int) ($_GET['consumption_id'] ?? $_POST['consumption_id'] ?? 0);
$rechargeId = (int) ($_GET['recharge_id'] ?? $_POST['recharge_id'] ?? 0);
if ($consumptionId > 0) {
    $sourceParam = 'consumption:' . $consumptionId;
} elseif ($rechargeId > 0) {
    $sourceParam = 'recharge:' . $rechargeId;
} else {
    $sourceParam = trim((string) ($_GET['target'] ?? $_POST['source'] ?? ''));
}

$sourceType = '';
$sourceId = 0;
if (preg_match('/^(consumption|recharge):(\d+)$/', $sourceParam, $matches)) {
    $sourceType = $matches[1];
    $sourceId = (int) $matches[2];
}

$record = null;
if ($sourceType === 'consumption') {
    $stmt = $pdo->prepare("SELECT c.*, m.name AS member_name, m.phone FROM consumption c LEFT JOIN member m ON c.member_id = m.id WHERE c.id = ? AND c.record_type = 'sale' AND c.refunded_at IS NULL");
    $stmt->execute([$sourceId]);
    $record = $stmt->fetch() ?: null;
} elseif ($sourceType === 'recharge') {
    $stmt = $pdo->prepare("SELECT r.*, m.name AS member_name, m.phone FROM recharge r LEFT JOIN member m ON r.member_id = m.id WHERE r.id = ?");
    $stmt->execute([$sourceId]);
    $record = $stmt->fetch() ?: null;
    // 已全部退费的充值不可再退
    if ($record && round((float) $record['amount'] - (float) ($record['refunded_amount'] ?? 0), 2) <= 0.009) {
        $record = null;
    }
}

if ($sourceType !== '' && !$record) {
    flash('error', '原记录不存在或已经全部退款');
    redirect('/refunds/');
}

// 充值的可退金额（部分退费后为剩余未退部分）
$refundableRemaining = 0.0;
if ($record && $sourceType === 'recharge') {
    $refundableRemaining = max(0, round((float) $record['amount'] - (float) ($record['refunded_amount'] ?? 0), 2));
} elseif ($record && $sourceType === 'consumption') {
    $refundableRemaining = max(0, round((float) $record['amount'] - (float) ($record['refunded_amount'] ?? 0), 2));
}

$reasonInput = trim((string) ($_POST['reason'] ?? ''));
$refundAmountInput = trim((string) ($_POST['refund_amount'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyFinanceCsrf()) {
        $errors[] = '页面已过期，请刷新后重试';
    }
    if (!$record) {
        $errors[] = '请选择要退款的原始记录';
    }
    if ($reasonInput === '') {
        $errors[] = '请填写退款原因';
    }

    $requestedAmount = null;
    if ($sourceType === 'recharge' && $refundAmountInput !== '') {
        if (!is_numeric($refundAmountInput) || (float) $refundAmountInput <= 0) {
            $errors[] = '退费金额必须大于 0';
        } else {
            $requestedAmount = round((float) $refundAmountInput, 2);
        }
    }

    if (!$errors) {
        try {
            $refund = refundFinanceSource($pdo, $sourceType, $sourceId, $reasonInput, getCurrentStaff() ?? [], $requestedAmount);
            if ($sourceType === 'recharge') {
                flash('success', '退费完成：本次 ' . formatMoney((float) $refund['amount'])
                    . ((float) $refund['remaining'] > 0 ? '，剩余可退 ' . formatMoney((float) $refund['remaining']) : '，该笔充值已全部退完'));
                redirect('/recharge/');
            }
            flash('success', '退款完成：' . formatMoney((float) $refund['amount']) . '，会员积分 / 累计消费 / 余额已同步回退');
            redirect('/consumption/');
        } catch (Throwable $e) {
            $errors[] = '退款失败：' . $e->getMessage();
        }
    }
}

// 未指定原记录时，提供可退款的记录下拉
$consumptionOptions = [];
$rechargeOptions = [];
if (!$record) {
    $consumptionOptions = $pdo->query("SELECT c.id, c.amount, COALESCE(c.refunded_amount, 0) AS refunded_amount, c.items, c.create_time, m.name AS member_name FROM consumption c LEFT JOIN member m ON c.member_id = m.id WHERE c.refunded_at IS NULL AND c.amount - COALESCE(c.refunded_amount, 0) > 0.009 AND c.record_type = 'sale' ORDER BY c.create_time DESC LIMIT 100")->fetchAll();
    $rechargeOptions = $pdo->query('SELECT r.id, r.amount, COALESCE(r.refunded_amount, 0) AS refunded_amount, r.create_time, m.name AS member_name FROM recharge r LEFT JOIN member m ON r.member_id = m.id WHERE r.amount - COALESCE(r.refunded_amount, 0) > 0.009 ORDER BY r.create_time DESC LIMIT 100')->fetchAll();
}

renderHeader('退款处理 - Anson咖啡厅管理系统', 'records');
?>

<div class="page-header"><h2 class="page-title">退款处理</h2><div class="page-actions"><a class="btn btn-secondary" href="/refunds/">退款记录</a><a class="btn btn-secondary" href="/consumption/">返回消费记录</a></div></div>
<?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $error): ?><div><?= htmlspecialchars($error) ?></div><?php endforeach; ?></div><?php endif; ?>
<?php if ($record): $payMethod = normalizeStoredPaymentMethod((string) ($record['payment_method'] ?? 'cash')); ?>
<div class="grid-2">
    <div class="card">
        <h3>原<?= $sourceType === 'recharge' ? '充值' : '消费' ?>记录 #<?= (int) $record['id'] ?></h3>
        <table class="table"><tbody>
            <tr><th>时间</th><td><?= formatDateTime((string) $record['create_time']) ?></td></tr>
            <tr><th>顾客</th><td><?= htmlspecialchars((string) ($record['member_name'] ?: '散客')) ?><?= $record['phone'] ? ' · ' . htmlspecialchars((string) $record['phone']) : '' ?></td></tr>
            <?php if ($sourceType === 'consumption'): ?>
            <tr><th>消费商品</th><td><?= htmlspecialchars((string) ($record['items'] ?: '-')) ?></td></tr>
            <?php endif; ?>
            <?php if ($sourceType === 'recharge'): ?>
            <tr><th>充值金额</th><td><?= formatMoney((float) $record['amount']) ?></td></tr>
            <tr><th>已退金额</th><td class="text-muted"><?= formatMoney((float) ($record['refunded_amount'] ?? 0)) ?></td></tr>
            <tr><th>可退金额</th><td class="text-danger"><strong><?= formatMoney($refundableRemaining) ?></strong></td></tr>
            <?php else: ?>
            <tr><th>退款金额</th><td class="text-danger"><?= formatMoney($refundableRemaining) ?><?php if ((float) ($record['refunded_amount'] ?? 0) > 0): ?>（原 <?= formatMoney((float) $record['amount']) ?>，已退 <?= formatMoney((float) $record['refunded_amount']) ?>）<?php endif; ?></td></tr>
            <?php endif; ?>
            <tr><th>支付方式</th><td><?= htmlspecialchars(financePaymentLabel($payMethod)) ?></td></tr>
            <?php if ($sourceType === 'consumption'): ?>
            <tr><th>赠送积分</th><td><?= (int) ($record['points_earned'] ?? 0) ?> 分（退款时扣回）</td></tr>
            <?php endif; ?>
        </tbody></table>
    </div>
    <div class="card form-card">
        <h3>退款信息</h3>
        <form method="post" onsubmit="return confirm('<?= $sourceType === 'recharge' ? '确认执行退费？会员余额会相应扣减。' : '确认退回该订单剩余金额？会员积分、累计消费或余额会同步回退。' ?>')">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeCsrfToken()) ?>">
            <input type="hidden" name="<?= $sourceType === 'recharge' ? 'recharge_id' : 'consumption_id' ?>" value="<?= (int) $sourceId ?>">
            <?php if ($sourceType === 'recharge'): ?>
            <div class="form-group">
                <label>本次退费金额（元） <span class="required">*</span></label>
                <input type="number" name="refund_amount" id="refundAmount" step="0.01" min="0.01" max="<?= number_format($refundableRemaining, 2, '.', '') ?>" value="<?= htmlspecialchars($refundAmountInput !== '' ? $refundAmountInput : number_format($refundableRemaining, 2, '.', '')) ?>" required>
                <div class="refund-amount-actions">
                    <button type="button" class="btn btn-sm btn-secondary" onclick="document.getElementById('refundAmount').value='<?= number_format($refundableRemaining, 2, '.', '') ?>'">全部退费（<?= formatMoney($refundableRemaining) ?>）</button>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="document.getElementById('refundAmount').value='<?= number_format(round($refundableRemaining / 2, 2), 2, '.', '') ?>'">退一半</button>
                </div>
                <p class="form-help">可退金额 <?= formatMoney($refundableRemaining) ?>（充值 <?= formatMoney((float) $record['amount']) ?>，已退 <?= formatMoney((float) ($record['refunded_amount'] ?? 0)) ?>）。可多次部分退费，退完后自动标记为已退费；要求会员当前余额不少于本次退费金额。</p>
            </div>
            <?php endif; ?>
            <div class="form-group">
                <label>退款原因 <span class="required">*</span></label>
                <textarea name="reason" rows="4" required maxlength="200" placeholder="例如：客人要求退回部分充值 / 误充值"><?= htmlspecialchars($reasonInput) ?></textarea>
                <p class="form-help">原因将加密保存。<?= $sourceType === 'recharge' ? '退费金额将从会员余额中扣减，并计入资金账户与退款记录。' : '余额支付原路退回会员余额；消费退款会退回订单尚未退款的部分。' ?></p>
            </div>
            <button class="btn btn-danger" type="submit"><?= $sourceType === 'recharge' ? '确认退费' : '确认退款' ?></button>
        </form>
    </div>
</div>
<?php else: ?>
<div class="card form-card">
    <h3>选择原始记录</h3>
    <p class="settings-help">从消费记录点「退款」可直接带入原记录；也可以在这里选择要退款的消费或充值记录。</p>
    <form method="get">
        <div class="form-group">
            <label>原始记录 <span class="required">*</span></label>
            <select name="target" required>
                <option value="">请选择</option>
                <optgroup label="消费记录">
                <?php foreach ($consumptionOptions as $row): ?>
                    <option value="consumption:<?= (int) $row['id'] ?>">#<?= (int) $row['id'] ?> <?= htmlspecialchars((string) ($row['member_name'] ?: '散客')) ?> / 可退 <?= formatMoney((float) $row['amount'] - (float) $row['refunded_amount']) ?><?= (float) $row['refunded_amount'] > 0 ? '（原 ' . formatMoney((float) $row['amount']) . '）' : '' ?> / <?= htmlspecialchars((string) ($row['items'] ?: '-')) ?> / <?= formatDateTime((string) $row['create_time']) ?></option>
                <?php endforeach; ?>
                </optgroup>
                <optgroup label="充值记录">
                <?php foreach ($rechargeOptions as $row): ?>
                    <option value="recharge:<?= (int) $row['id'] ?>">#<?= (int) $row['id'] ?> <?= htmlspecialchars((string) $row['member_name']) ?> / 可退 <?= formatMoney((float) $row['amount'] - (float) $row['refunded_amount']) ?><?= (float) $row['refunded_amount'] > 0 ? '（原 ' . formatMoney((float) $row['amount']) . '）' : '' ?> / <?= formatDateTime((string) $row['create_time']) ?></option>
                <?php endforeach; ?>
                </optgroup>
            </select>
        </div>
        <button class="btn btn-primary" type="submit">下一步</button>
    </form>
</div>
<?php endif; ?>

<?php renderFooter(); ?>

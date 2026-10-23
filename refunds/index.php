<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
requireLogin();
requireRegularStaffPermission();

$pdo = getDb();
$canSeeReason = canManageFinance();
$refunds = $pdo->query('SELECT * FROM finance_refunds ORDER BY created_at DESC LIMIT 200')->fetchAll();
$totalAmount = 0.0;
foreach ($refunds as $row) {
    $totalAmount += (float) $row['amount'];
}

renderHeader('退款记录 - Anson咖啡厅管理系统', 'records');
?>

<div class="page-header"><h2 class="page-title">退款记录</h2><div class="page-actions"><a class="btn btn-primary" href="/refunds/add.php">+ 发起退款</a><a class="btn btn-secondary" href="/consumption/">返回消费记录</a></div></div>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-value"><?= count($refunds) ?></div><div class="stat-label">退款笔数</div></div>
    <div class="stat-card"><div class="stat-value"><?= formatMoney($totalAmount) ?></div><div class="stat-label">退款金额合计</div></div>
</div>

<div class="card table-scroll">
    <h3>退款明细</h3>
    <?php if (!$refunds): ?>
        <p class="text-muted">暂无退款记录</p>
    <?php else: ?>
    <table class="table">
        <thead><tr><th>时间</th><th>类型</th><th>原记录</th><th>金额</th><th>退款渠道</th><?php if ($canSeeReason): ?><th>原因</th><?php endif; ?><th>操作人</th></tr></thead>
        <tbody>
        <?php foreach ($refunds as $row): ?>
            <tr>
                <td class="nowrap-cell"><?= formatDateTime((string) $row['created_at']) ?></td>
                <td><?= $row['source_type'] === 'recharge' ? '充值退款' : ($row['source_type'] === 'consumption_item' ? '饮品退款' : '消费退款') ?></td>
                <td>#<?= (int) $row['source_id'] ?><?php if ($row['source_type'] === 'consumption_item'): ?> · 第<?= (int) $row['item_index'] + 1 ?>杯 <?= htmlspecialchars((string) ($row['item_name'] ?? '')) ?><?php endif; ?></td>
                <td class="text-danger">-<?= formatMoney((float) $row['amount']) ?></td>
                <td><?= htmlspecialchars(financePaymentLabel((string) $row['payment_method'])) ?></td>
                <?php if ($canSeeReason): $reason = decryptFinanceData((string) ($row['encrypted_reason'] ?? '')); ?>
                <td><?= htmlspecialchars((string) ($reason['reason'] ?? '-')) ?></td>
                <?php endif; ?>
                <td><?= htmlspecialchars((string) ($row['operator_name'] ?: '-')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<p class="settings-help">消费记录可整笔退款；制作总览也支持单杯退款。退款会回退对应金额与积分，原因加密保存<?= $canSeeReason ? '' : '（仅财务与管理员可见）' ?>。</p>

<?php renderFooter(); ?>

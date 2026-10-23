<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';

renderHeader('会员等级说明 - Anson咖啡厅管理系统', 'levels');
$levels = getMemberLevels();
$redemption = getRedemptionSettings();
?>

<div class="page-header">
    <h2 class="page-title">会员等级与权益</h2>
</div>

<div class="card">
    <p class="text-muted">会员等级根据累计消费金额自动升级，消费时自动计算积分（<?= getPointsPerYuan() ?> 积分/元）。</p>

    <table class="table">
        <thead>
            <tr>
                <th>等级</th>
                <th>升级条件（累计消费）</th>
                <th>折扣权益</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach (array_reverse($levels) as $level): ?>
                <tr>
                    <td><span class="badge badge-level"><?= htmlspecialchars($level['name']) ?></span></td>
                    <td>满 <?= formatMoney($level['min_spent']) ?></td>
                    <td>
                        <?php if ($level['discount'] < 1): ?>
                            <?= (int)((1 - $level['discount']) * 100) ?>% 折扣（<?= $level['discount'] * 10 ?> 折）
                        <?php else: ?>
                            无折扣
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h3>积分兑换</h3>
    <?php if (empty($redemption['enabled'])): ?>
        <p class="text-muted">积分兑换功能当前未开启。</p>
    <?php else: ?>
    <table class="table">
        <thead>
            <tr><th>实际兑换商品</th><th>所需积分</th></tr>
        </thead>
        <tbody>
            <?php foreach (($redemption['items'] ?? []) as $item): ?>
                <tr><td><?= htmlspecialchars($item['name']) ?></td><td><?= trim((string) ($item['cost_product'] ?? '')) === '' ? '兑换时填写' : (int) $item['points'] ?></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <a href="/points/redeem.php" class="btn btn-warning">去兑换</a>
    <?php endif; ?>
</div>

<?php renderFooter(); ?>

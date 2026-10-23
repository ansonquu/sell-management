<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';

$pdo = getDb();
$q = trim($_GET['q'] ?? '');

$sql = '
    SELECT r.*, m.name as member_name, m.phone, m.student_no
    FROM recharge r
    JOIN member m ON r.member_id = m.id
    WHERE 1=1
';
$params = [];

if ($q !== '') {
    $sql .= ' AND (m.name LIKE ? OR m.phone LIKE ? OR m.student_no LIKE ?)';
    $params = ["%{$q}%", "%{$q}%", "%{$q}%"];
}

$sql .= ' ORDER BY r.create_time DESC LIMIT 100';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

// 导出当前筛选结果
if (($_GET['export'] ?? '') === 'csv') {
    requireLogin();
    $exportRows = [];
    foreach ($records as $row) {
        $refunded = (float) ($row['refunded_amount'] ?? 0);
        $state = $refunded <= 0.009 ? '正常' : ($refunded >= (float) $row['amount'] - 0.009 ? '已退费' : '部分退费');
        $exportRows[] = [
            (string) $row['create_time'],
            (string) ($row['member_name'] ?? ''),
            (string) ($row['phone'] ?? ''),
            (string) ($row['student_no'] ?? ''),
            number_format((float) $row['amount'], 2, '.', ''),
            number_format((float) $row['balance_after'], 2, '.', ''),
            financePaymentLabel($row['payment_method'] ?? 'cash'),
            (string) ($row['operator'] ?? ''),
            $state,
            number_format($refunded, 2, '.', ''),
            (string) ($row['note'] ?? ''),
        ];
    }
    $stamp = date('Ymd_His');
    csvDownload('充值记录_' . $stamp . '.csv', 'recharge_' . $stamp . '.csv',
        ['时间', '会员', '手机号', '学号', '充值金额', '充值后余额', '收款方式', '操作人', '状态', '已退费', '备注'], $exportRows);
}

renderHeader('充值记录 - Anson咖啡厅管理系统', 'recharge');
?>

<div class="page-header">
    <h2 class="page-title">充值记录</h2>
    <div class="page-actions">
        <a href="/recharge/?export=csv<?= $q !== '' ? '&q=' . urlencode($q) : '' ?>" class="btn btn-secondary">导出 CSV</a>
        <a href="/refunds/" class="btn btn-secondary">退款记录</a>
        <a href="/recharge/add.php" class="btn btn-primary">+ 会员充值</a>
    </div>
</div>

<form class="search-bar" method="get">
    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="搜索会员姓名、手机号或学号...">
    <button type="submit" class="btn btn-secondary">搜索</button>
    <?php if ($q): ?>
        <a href="/recharge/" class="btn btn-link">清除</a>
    <?php endif; ?>
</form>

<div class="card">
    <?php if (empty($records)): ?>
        <p class="text-muted">暂无充值记录</p>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>时间</th>
                    <th>会员</th>
                    <th>充值金额</th>
                    <th>充值后余额</th>
                    <th>收款方式</th>
                    <th>操作人</th>
                    <th>状态</th>
                    <th>备注</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $r): ?>
                    <tr>
                        <td><?= formatDateTime($r['create_time']) ?></td>
                        <td>
                            <a href="/members/view.php?id=<?= $r['member_id'] ?>">
                                <?= htmlspecialchars($r['member_name']) ?>
                            </a>
                            <small class="text-muted"><?= htmlspecialchars($r['student_no'] ?: $r['phone']) ?></small>
                        </td>
                        <?php
                            $rechargeRefunded = (float) ($r['refunded_amount'] ?? 0);
                            $rechargeFullyRefunded = $rechargeRefunded > 0.009 && $rechargeRefunded >= (float) $r['amount'] - 0.009;
                        ?>
                        <td class="<?= $rechargeFullyRefunded ? 'text-muted' : 'text-success' ?>"><?= $rechargeFullyRefunded ? '-' . formatMoney($r['amount']) : '+' . formatMoney($r['amount']) ?><?php if ($rechargeRefunded > 0.009 && !$rechargeFullyRefunded): ?><small class="text-muted">（已退 <?= formatMoney($rechargeRefunded) ?>，可退 <?= formatMoney((float) $r['amount'] - $rechargeRefunded) ?>）</small><?php endif; ?></td>
                        <td><?= formatMoney($r['balance_after']) ?></td>
                        <td><?= htmlspecialchars(financePaymentLabel($r['payment_method'] ?? 'cash')) ?></td>
                        <td><?= htmlspecialchars((string) ($r['operator'] ?? '') ?: '-') ?></td>
                        <td>
                            <?php if ($rechargeFullyRefunded): ?>
                                <span class="badge badge-secondary">已退费</span>
                            <?php elseif ($rechargeRefunded > 0.009): ?>
                                <span class="badge badge-warning">部分退费</span>
                            <?php else: ?>
                                <span class="badge badge-success">正常</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($r['note'] ?: '-') ?></td>
                        <td>
                            <?php if ($rechargeFullyRefunded): ?>
                                <small class="text-muted"><?= formatDateTime((string) $r['refunded_at']) ?></small>
                            <?php else: ?>
                                <a href="/refunds/add.php?recharge_id=<?= (int) $r['id'] ?>" class="btn btn-sm btn-warning"><?= $rechargeRefunded > 0.009 ? '继续退费' : '退费' ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
(function () {
    const initialVersion = '<?= getRecordVersion($pdo) ?>';
    let checking = false;

    setInterval(function () {
        if (checking || document.hidden || (window.AACafeAutoRefreshGuard && window.AACafeAutoRefreshGuard.shouldPause())) {
            return;
        }

        checking = true;
        fetch('/api/record_version.php?scope=global', { cache: 'no-store' })
            .then(response => response.ok ? response.json() : null)
            .then(data => {
                if (data && data.version && data.version !== initialVersion) {
                    window.location.reload();
                }
            })
            .catch(() => {})
            .finally(() => {
                checking = false;
            });
    }, 5000);
})();
</script>

<?php renderFooter(); ?>

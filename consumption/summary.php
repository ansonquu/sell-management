<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';

requireLogin();
requireDailySummaryPermission();

$pdo = getDb();

// 日期范围（默认只显示当天）
$today = date('Y-m-d');
$from = (string) ($_GET['from'] ?? $today);
$to = (string) ($_GET['to'] ?? $today);
[$from, $to] = financeDateRange($from, $to);
$isSingleDay = $from === $to;

// 快捷日期
$quickRanges = [
    '今天' => [$today, $today],
    '昨天' => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
    '近 7 天' => [date('Y-m-d', strtotime('-6 days')), $today],
    '本月' => [date('Y-m-01'), $today],
];

// 每日汇总（营业口径与财务模块一致：未退款的 sale / points_redeem 记录）
$stmt = $pdo->prepare("
    SELECT DATE(create_time) AS day,
           SUM(CASE WHEN record_type = 'sale' AND refunded_at IS NULL THEN 1 ELSE 0 END) AS orders,
           COALESCE(SUM(CASE WHEN record_type = 'sale' THEN amount - COALESCE(refunded_amount, 0) ELSE 0 END), 0) AS revenue,
           COALESCE(SUM(CASE WHEN record_type = 'sale' AND refunded_at IS NULL THEN coupon_discount ELSE 0 END), 0) AS discounts,
           COALESCE(SUM(cost_amount), 0) AS product_cost,
           COALESCE(SUM(CASE WHEN record_type = 'points_redeem' THEN cost_amount ELSE 0 END), 0) AS redeem_cost,
           COALESCE(SUM(net_income), 0) AS net_income,
           COALESCE(SUM(CASE WHEN record_type = 'sale' THEN points_earned ELSE 0 END), 0) AS points_earned,
           COALESCE(SUM(CASE WHEN record_type = 'sale' THEN points_spent ELSE 0 END), 0) AS points_spent
    FROM consumption
    WHERE DATE(create_time) BETWEEN ? AND ? AND record_type IN ('sale', 'points_redeem', 'board_redo')
    GROUP BY DATE(create_time)
    ORDER BY day DESC
");
$stmt->execute([$from, $to]);
$dailyRows = $stmt->fetchAll();


// 期间退款（冲正）
$refundStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM finance_refunds WHERE DATE(created_at) BETWEEN ? AND ?');
$refundStmt->execute([$from, $to]);
$refundTotal = (float) $refundStmt->fetchColumn();

// 合计
$totals = ['orders' => 0, 'revenue' => 0.0, 'discounts' => 0.0, 'product_cost' => 0.0, 'redeem_cost' => 0.0, 'net_income' => 0.0];
foreach ($dailyRows as $row) {
    $totals['orders'] += (int) $row['orders'];
    $totals['revenue'] += (float) $row['revenue'];
    $totals['discounts'] += (float) $row['discounts'];
    $totals['product_cost'] += (float) $row['product_cost'];
    $totals['redeem_cost'] += (float) $row['redeem_cost'];
    $totals['net_income'] += (float) $row['net_income'];
}
$averageOrder = $totals['orders'] > 0 ? $totals['revenue'] / $totals['orders'] : 0;

// 会员 / 散客结构
$guestStmt = $pdo->prepare("
    SELECT SUM(CASE WHEN member_id > 0 THEN 1 ELSE 0 END) AS member_orders,
           SUM(CASE WHEN member_id = 0 THEN 1 ELSE 0 END) AS walkin_orders,
           COALESCE(SUM(CASE WHEN member_id > 0 THEN amount - COALESCE(refunded_amount, 0) ELSE 0 END), 0) AS member_amount,
           COALESCE(SUM(CASE WHEN member_id = 0 THEN amount - COALESCE(refunded_amount, 0) ELSE 0 END), 0) AS walkin_amount
    FROM consumption
    WHERE DATE(create_time) BETWEEN ? AND ? AND refunded_at IS NULL AND record_type = 'sale'
");
$guestStmt->execute([$from, $to]);
$guest = $guestStmt->fetch() ?: [];
$memberOrders = (int) ($guest['member_orders'] ?? 0);
$walkinOrders = (int) ($guest['walkin_orders'] ?? 0);
$memberAmount = (float) ($guest['member_amount'] ?? 0);
$walkinAmount = (float) ($guest['walkin_amount'] ?? 0);
$memberAmountShare = $totals['revenue'] > 0 ? $memberAmount / $totals['revenue'] * 100 : 0;
$walkinAmountShare = $totals['revenue'] > 0 ? $walkinAmount / $totals['revenue'] * 100 : 0;

// 客单价分布（小额 < ¥20 / 中额 ¥20-50 / 大额 > ¥50）
$distStmt = $pdo->prepare("
    SELECT SUM(CASE WHEN amount - COALESCE(refunded_amount, 0) < 20 THEN 1 ELSE 0 END) AS low,
           SUM(CASE WHEN amount - COALESCE(refunded_amount, 0) >= 20 AND amount - COALESCE(refunded_amount, 0) <= 50 THEN 1 ELSE 0 END) AS mid,
           SUM(CASE WHEN amount - COALESCE(refunded_amount, 0) > 50 THEN 1 ELSE 0 END) AS high
    FROM consumption
    WHERE DATE(create_time) BETWEEN ? AND ? AND refunded_at IS NULL AND record_type = 'sale'
");
$distStmt->execute([$from, $to]);
$dist = $distStmt->fetch() ?: [];
$lowOrders = (int) ($dist['low'] ?? 0);
$midOrders = (int) ($dist['mid'] ?? 0);
$highOrders = (int) ($dist['high'] ?? 0);
$lowShare = $totals['orders'] > 0 ? $lowOrders / $totals['orders'] * 100 : 0;
$midShare = $totals['orders'] > 0 ? $midOrders / $totals['orders'] * 100 : 0;
$highShare = $totals['orders'] > 0 ? $highOrders / $totals['orders'] * 100 : 0;

// 热销商品（统计全部有销量的商品，按销量降序）
$itemStmt = $pdo->prepare("SELECT items, prep_items FROM consumption WHERE DATE(create_time) BETWEEN ? AND ? AND refunded_at IS NULL AND record_type = 'sale' LIMIT 5000");
$itemStmt->execute([$from, $to]);
$productStats = [];
foreach ($itemStmt->fetchAll() as $itemRow) {
    $prepared = json_decode((string) ($itemRow['prep_items'] ?? ''), true);
    $orderItems = [];
    if (is_array($prepared) && $prepared !== []) {
        foreach ($prepared as $drink) {
            if (($drink['status'] ?? '') === 'refunded') continue;
            $name = trim((string) ($drink['name'] ?? ''));
            if ($name !== '') $orderItems[$name] = ($orderItems[$name] ?? 0) + 1;
        }
    } else {
        $orderItems = parseConsumptionItems((string) $itemRow['items']);
    }
    foreach ($orderItems as $productName => $quantity) {
        if (!isset($productStats[$productName])) {
            $productStats[$productName] = ['quantity' => 0, 'orders' => 0];
        }
        $productStats[$productName]['quantity'] += (int) $quantity;
        $productStats[$productName]['orders']++;
    }
}
uasort($productStats, static fn (array $a, array $b): int => $b['quantity'] <=> $a['quantity']);
$hotProducts = $productStats;
$hotTop = array_slice($productStats, 0, 2, true);

// 环比上期（同一时长区间）
$periodDays = (int) (((strtotime($to) - strtotime($from)) / 86400) + 1);
$prevTo = date('Y-m-d', strtotime($from . ' -1 day'));
$prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . max(0, $periodDays - 1) . ' days'));
$prevStmt = $pdo->prepare("
    SELECT COUNT(*) AS orders, COALESCE(SUM(amount - COALESCE(refunded_amount, 0)), 0) AS revenue
    FROM consumption
    WHERE DATE(create_time) BETWEEN ? AND ? AND refunded_at IS NULL AND record_type = 'sale'
");
$prevStmt->execute([$prevFrom, $prevTo]);
$prev = $prevStmt->fetch() ?: [];
$prevOrders = (int) ($prev['orders'] ?? 0);
$prevRevenue = (float) ($prev['revenue'] ?? 0);
$deltaInfo = static function (float $current, float $previous): array {
    if ($previous <= 0) {
        return ['text' => $current > 0 ? '上期无数据' : '—', 'dir' => 'flat'];
    }
    $percent = ($current - $previous) / $previous * 100;
    $dir = abs($percent) < 0.05 ? 'flat' : ($percent > 0 ? 'up' : 'down');
    return ['text' => '环比 ' . ($percent > 0 ? '↑' : ($percent < 0 ? '↓' : '')) . number_format(abs($percent), 1) . '%', 'dir' => $dir];
};
$revenueDelta = $deltaInfo($totals['revenue'], $prevRevenue);
$ordersDelta = $deltaInfo((float) $totals['orders'], (float) $prevOrders);

// 充值流入：期间充值金额（扣除已退部分）
$rechargeStmt = $pdo->prepare('SELECT COALESCE(SUM(amount - COALESCE(refunded_amount, 0)), 0) FROM recharge WHERE DATE(create_time) BETWEEN ? AND ?');
$rechargeStmt->execute([$from, $to]);
$rechargeTotal = (float) $rechargeStmt->fetchColumn();

// 现金流入 = 现金消费 + 现金充值 - 现金退款（与实际到账现金对应）
$cashSaleStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM consumption WHERE record_type = 'sale' AND payment_method = 'cash' AND DATE(create_time) BETWEEN ? AND ?");
$cashSaleStmt->execute([$from, $to]);
$cashSales = (float) $cashSaleStmt->fetchColumn();

$cashRechargeStmt = $pdo->prepare("SELECT COALESCE(SUM(amount - COALESCE(refunded_amount, 0)), 0) FROM recharge WHERE payment_method = 'cash' AND DATE(create_time) BETWEEN ? AND ?");
$cashRechargeStmt->execute([$from, $to]);
$cashRecharges = (float) $cashRechargeStmt->fetchColumn();

$cashRefundStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM finance_refunds WHERE payment_method = 'cash' AND DATE(created_at) BETWEEN ? AND ?");
$cashRefundStmt->execute([$from, $to]);
$cashRefunds = (float) $cashRefundStmt->fetchColumn();

$cashInflow = $cashSales + $cashRecharges - $cashRefunds;

// 新增会员数
$newMemberStmt = $pdo->prepare('SELECT COUNT(*) FROM member WHERE DATE(create_time) BETWEEN ? AND ?');
$newMemberStmt->execute([$from, $to]);
$newMemberCount = (int) $newMemberStmt->fetchColumn();

// 餐损：客诉重做单数 + 按产品成本估算的重做成本
$redoStmt = $pdo->prepare("SELECT COUNT(*) FROM consumption WHERE record_type = 'complaint_redo' AND DATE(create_time) BETWEEN ? AND ?");
$redoStmt->execute([$from, $to]);
$redoCount = (int) $redoStmt->fetchColumn();
$costMap = getProductCostMap($pdo);
$redoCostStmt = $pdo->prepare('SELECT redo_items FROM complaint_records WHERE DATE(created_at) BETWEEN ? AND ?');
$redoCostStmt->execute([$from, $to]);
$redoCost = 0.0;
foreach ($redoCostStmt->fetchAll() as $redoRow) {
    foreach (parseConsumptionItems((string) $redoRow['redo_items']) as $productName => $quantity) {
        $redoCost += ($costMap[$productName] ?? 0.0) * (int) $quantity;
    }
}
$boardRedoStmt = $pdo->prepare("SELECT meta FROM operation_logs WHERE action = 'drink_redo' AND DATE(created_at) BETWEEN ? AND ?");
$boardRedoStmt->execute([$from, $to]);
foreach ($boardRedoStmt->fetchAll() as $redoRow) {
    $redoMeta = json_decode((string) ($redoRow['meta'] ?? ''), true);
    $redoCount++;
    $redoCost += (float) ($redoMeta['cost_amount'] ?? 0);
}

// 占比条渲染
$shareBar = static function (array $segments): string {
    $html = '';
    foreach ($segments as [$percent, $className]) {
        if ((float) $percent <= 0) {
            continue;
        }
        $html .= '<span class="share-seg ' . $className . '" style="width:' . number_format((float) $percent, 3) . '%"></span>';
    }
    return $html !== '' ? $html : '<span class="share-seg seg-empty" style="width:100%"></span>';
};


renderHeader('每日消费总结', 'daily-summary');
?>

<div class="page-header">
    <h2 class="page-title">每日消费总结</h2>
    <a href="/consumption/" class="btn btn-secondary">消费记录</a>
</div>

<form class="search-bar summary-range" method="get">
    <label>从 <input type="date" name="from" value="<?= htmlspecialchars($from) ?>"></label>
    <label>到 <input type="date" name="to" value="<?= htmlspecialchars($to) ?>"></label>
    <button type="submit" class="btn btn-secondary">查询</button>
    <div class="range-quick">
        <?php foreach ($quickRanges as $quickLabel => [$quickFrom, $quickTo]): ?>
            <a class="btn btn-sm <?= ($from === $quickFrom && $to === $quickTo) ? 'btn-primary' : 'btn-secondary' ?>" href="?from=<?= urlencode($quickFrom) ?>&to=<?= urlencode($quickTo) ?>"><?= htmlspecialchars($quickLabel) ?></a>
        <?php endforeach; ?>
    </div>
</form>

<p class="summary-current">
    <?= $isSingleDay ? '📅 当前显示：' . htmlspecialchars($from) : '📅 当前显示：' . htmlspecialchars($from) . ' 至 ' . htmlspecialchars($to) ?>
    <?php if ($isSingleDay && $from === $today): ?><span class="badge badge-success">今天</span><?php endif; ?>
    <?php if ($from !== $to): ?><span class="text-muted">（共 <?= (int) ((strtotime($to) - strtotime($from)) / 86400 + 1) ?> 天）</span><?php endif; ?>
</p>

<?php if ($totals['orders'] <= 0 && $totals['redeem_cost'] <= 0): ?>
<div class="alert alert-error" style="display:block;">
    <div>所选日期（<?= htmlspecialchars($from) ?><?= $isSingleDay ? '' : ' 至 ' . htmlspecialchars($to) ?>）没有消费记录，可切换日期或用上方快捷按钮查看。</div>
</div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-value"><?= formatMoney($totals['revenue']) ?></div><div class="stat-label">营业额合计</div><div class="stat-delta <?= $revenueDelta['dir'] ?>"><?= htmlspecialchars($revenueDelta['text']) ?></div></div>
    <div class="stat-card"><div class="stat-value"><?= (int) $totals['orders'] ?></div><div class="stat-label">订单数</div><div class="stat-delta <?= $ordersDelta['dir'] ?>"><?= htmlspecialchars($ordersDelta['text']) ?></div></div>
    <div class="stat-card"><div class="stat-value"><?= formatMoney($averageOrder) ?></div><div class="stat-label">平均客单价</div></div>
    <div class="stat-card"><div class="stat-value"><?= formatMoney($totals['net_income']) ?></div><div class="stat-label">净收入</div></div>
    <div class="stat-card"><div class="stat-value"><?= formatMoney($totals['product_cost']) ?></div><div class="stat-label">商品成本（含兑换）</div></div>
    <div class="stat-card"><div class="stat-value"><?= formatMoney($totals['discounts']) ?></div><div class="stat-label">优惠券抵扣</div></div>
    <div class="stat-card"><div class="stat-value"><?= formatMoney($refundTotal) ?></div><div class="stat-label">期间退款</div></div>
    <div class="stat-card"><div class="stat-value"><?= formatMoney($cashInflow) ?></div><div class="stat-label">现金流入</div></div>
<div class="stat-card"><div class="stat-value"><?= formatMoney($rechargeTotal) ?></div><div class="stat-label">充值流入</div></div>
    <div class="stat-card"><div class="stat-value"><?= (int) $newMemberCount ?></div><div class="stat-label">新增会员</div></div>
    <div class="stat-card"><div class="stat-value"><?= (int) $redoCount ?></div><div class="stat-label">餐损（饮品重做）</div><div class="stat-delta flat">约 <?= formatMoney($redoCost) ?> 成本</div></div>
    <div class="stat-card"><div class="stat-value"><?= count($dailyRows) ?></div><div class="stat-label">有记录天数</div></div>
</div>

<div class="grid-2">
    <div class="card">
        <h3>会员 / 散客结构</h3>
        <?php if ($totals['orders'] <= 0): ?>
            <p class="text-muted">所选范围内暂无消费订单</p>
        <?php else: ?>
            <div class="share-bar"><?= $shareBar([[$memberAmountShare, 'seg-member'], [$walkinAmountShare, 'seg-walkin']]) ?></div>
            <div class="share-legend">
                <div class="share-item"><span class="share-dot seg-member"></span><strong>会员</strong><span><?= $memberOrders ?> 单 · <?= formatMoney($memberAmount) ?> · <?= number_format($memberAmountShare, 1) ?>%</span></div>
                <div class="share-item"><span class="share-dot seg-walkin"></span><strong>散客</strong><span><?= $walkinOrders ?> 单 · <?= formatMoney($walkinAmount) ?> · <?= number_format($walkinAmountShare, 1) ?>%</span></div>
            </div>
            <p class="settings-help" style="margin-top:0.6rem;">客单价：会员 <?= formatMoney($memberOrders > 0 ? $memberAmount / $memberOrders : 0) ?> · 散客 <?= formatMoney($walkinOrders > 0 ? $walkinAmount / $walkinOrders : 0) ?></p>
        <?php endif; ?>
    </div>
    <div class="card">
        <h3>客单价分布</h3>
        <?php if ($totals['orders'] <= 0): ?>
            <p class="text-muted">所选范围内暂无消费订单</p>
        <?php else: ?>
            <div class="share-bar"><?= $shareBar([[$lowShare, 'seg-1'], [$midShare, 'seg-2'], [$highShare, 'seg-3']]) ?></div>
            <div class="share-legend">
                <div class="share-item"><span class="share-dot seg-1"></span><strong>小额 &lt; ¥20</strong><span><?= $lowOrders ?> 单 · <?= number_format($lowShare, 1) ?>%</span></div>
                <div class="share-item"><span class="share-dot seg-2"></span><strong>中额 ¥20–50</strong><span><?= $midOrders ?> 单 · <?= number_format($midShare, 1) ?>%</span></div>
                <div class="share-item"><span class="share-dot seg-3"></span><strong>大额 &gt; ¥50</strong><span><?= $highOrders ?> 单 · <?= number_format($highShare, 1) ?>%</span></div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <h3>热销商品 <span class="text-muted" style="font-weight:400;font-size:0.85rem;">按销量排序 · 共 <?= count($hotProducts) ?> 款</span></h3>
    <?php if (!$hotProducts): ?>
        <p class="text-muted">所选范围内暂无商品明细</p>
    <?php else: ?>
        <?php if ($hotTop): ?>
        <div class="hot-top">
            <?php $hotRank = 0; foreach ($hotTop as $hotName => $hotStat): $hotRank++; ?>
                <div class="hot-card hot-rank-<?= $hotRank ?>">
                    <div class="hot-medal"><?= $hotRank === 1 ? '🥇 销量第一' : '🥈 销量第二' ?></div>
                    <div class="hot-name"><?= htmlspecialchars((string) $hotName) ?></div>
                    <div class="hot-qty"><?= (int) $hotStat['quantity'] ?> 件</div>
                    <div class="hot-orders">出现在 <?= (int) $hotStat['orders'] ?> 笔订单</div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="table-responsive">
        <table class="table table-sm">
            <thead><tr><th>排名</th><th>商品</th><th>销量（件）</th><th>出现订单数</th></tr></thead>
            <tbody>
            <?php $hotRank = 0; foreach ($hotProducts as $hotName => $hotStat): $hotRank++; ?>
                <tr<?= $hotRank <= 2 ? ' class="is-hot-row"' : '' ?>>
                    <td><?= $hotRank ?></td>
                    <td><?= htmlspecialchars((string) $hotName) ?></td>
                    <td><strong><?= (int) $hotStat['quantity'] ?></strong></td>
                    <td class="text-muted"><?= (int) $hotStat['orders'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>


<p class="settings-help">说明：营业额 = 未退款消费记录金额合计；净收入 = 营业额 − 商品成本（含积分兑换商品成本）；退款按退款记录统计。仅管理员、当日负责人、财务可见。</p>

<?php renderFooter(); ?>

<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
requireLogin();
requireFinance();

$pdo = getDb();
[$from, $to] = financeDateRange($_GET['from'] ?? date('Y-m-01'), $_GET['to'] ?? date('Y-m-d'));
$summary = getFinanceSummary($pdo, $from, $to);

$paymentStmt = $pdo->prepare("SELECT payment_method, COUNT(*) AS count, COALESCE(SUM(amount - COALESCE(refunded_amount, 0)), 0) AS amount FROM consumption WHERE DATE(create_time) BETWEEN ? AND ? AND refunded_at IS NULL AND record_type = 'sale' GROUP BY payment_method ORDER BY amount DESC");
$paymentStmt->execute([$from, $to]);
$payments = $paymentStmt->fetchAll();

$expenseStmt = $pdo->prepare("SELECT category, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS amount FROM finance_expenses WHERE DATE(occurred_at) BETWEEN ? AND ? AND status = 'active' AND category <> 'balance_adjust' GROUP BY category ORDER BY amount DESC");
$expenseStmt->execute([$from, $to]);
$expenseCategories = $expenseStmt->fetchAll();

$customerStmt = $pdo->prepare("SELECT CASE WHEN member_id > 0 THEN '会员' ELSE '散客' END AS customer_type, COUNT(*) AS count, COALESCE(SUM(amount - COALESCE(refunded_amount, 0)), 0) AS amount FROM consumption WHERE DATE(create_time) BETWEEN ? AND ? AND refunded_at IS NULL AND record_type = 'sale' GROUP BY CASE WHEN member_id > 0 THEN '会员' ELSE '散客' END");
$customerStmt->execute([$from, $to]);
$customerTypes = $customerStmt->fetchAll();

$monthlyStmt = $pdo->prepare("SELECT substr(create_time, 1, 7) AS month, COALESCE(SUM(CASE WHEN record_type = 'sale' AND refunded_at IS NULL THEN 1 ELSE 0 END), 0) AS orders, COALESCE(SUM(CASE WHEN record_type = 'sale' THEN amount - COALESCE(refunded_amount, 0) ELSE 0 END), 0) AS revenue, COALESCE(SUM(cost_amount), 0) AS product_cost, COALESCE(SUM(net_income), 0) AS net_income FROM consumption WHERE DATE(create_time) BETWEEN ? AND ? AND record_type IN ('sale', 'points_redeem', 'board_redo') GROUP BY substr(create_time, 1, 7) ORDER BY month DESC");
$monthlyStmt->execute([$from, $to]);
$monthly = $monthlyStmt->fetchAll();

$itemStmt = $pdo->prepare("SELECT items, prep_items FROM consumption WHERE DATE(create_time) BETWEEN ? AND ? AND refunded_at IS NULL AND record_type = 'sale'");
$itemStmt->execute([$from, $to]);
$itemCounts = [];
foreach ($itemStmt->fetchAll() as $row) {
    $prepared = json_decode((string) ($row['prep_items'] ?? ''), true);
    if (is_array($prepared) && $prepared !== []) {
        foreach ($prepared as $drink) {
            if (($drink['status'] ?? '') === 'refunded') continue;
            $name = trim((string) ($drink['name'] ?? ''));
            if ($name !== '') $itemCounts[$name] = ($itemCounts[$name] ?? 0) + 1;
        }
        continue;
    }
    foreach (explode(',', (string) $row['items']) as $itemText) {
        $itemText = trim($itemText);
        if ($itemText === '') continue;
        if (preg_match('/^(.*?)\s+x(\d+)$/u', $itemText, $match)) {
            $name = trim($match[1]);
            $quantity = (int) $match[2];
        } else {
            $name = $itemText;
            $quantity = 1;
        }
        $itemCounts[$name] = ($itemCounts[$name] ?? 0) + $quantity;
    }
}
arsort($itemCounts);
$itemCounts = array_slice($itemCounts, 0, 15, true);

renderHeader('经营报表 - 财务管理', 'finance');
?>
<div class="page-header"><h2 class="page-title">经营报表</h2><a class="btn btn-primary" href="/finance/entries.php?<?= htmlspecialchars(http_build_query(['from' => $from, 'to' => $to, 'export' => 'csv'])) ?>">导出明细</a></div>
<?php renderFinanceTabs('reports'); ?>
<form class="search-bar" method="get"><label>报表期间</label><input type="date" name="from" value="<?= htmlspecialchars($from) ?>"><span>至</span><input type="date" name="to" value="<?= htmlspecialchars($to) ?>"><button class="btn btn-secondary">生成报表</button></form>
<div class="stats-grid"><div class="stat-card"><div class="stat-value"><?= formatMoney($summary['revenue']) ?></div><div class="stat-label">营业收入</div></div><div class="stat-card"><div class="stat-value"><?= formatMoney($summary['product_cost']) ?></div><div class="stat-label">商品成本（含积分兑换）</div></div><div class="stat-card"><div class="stat-value"><?= formatMoney($summary['redemption_cost']) ?></div><div class="stat-label">其中：积分兑换成本</div></div><div class="stat-card"><div class="stat-value"><?= formatMoney($summary['sales_net_income']) ?></div><div class="stat-label">净收入（含兑换成本）</div></div><div class="stat-card"><div class="stat-value"><?= formatMoney($summary['expenses']) ?></div><div class="stat-label">支出合计</div></div><div class="stat-card"><div class="stat-value"><?= formatMoney($summary['operating_result']) ?></div><div class="stat-label">经营结余</div></div><div class="stat-card"><div class="stat-value"><?= formatMoney($summary['discounts']) ?></div><div class="stat-label">优惠券减免</div></div></div>
<div class="grid-2">
<div class="card"><h3>支付渠道</h3><table class="table"><thead><tr><th>渠道</th><th>订单</th><th>营业收入</th></tr></thead><tbody><?php if (!$payments): ?><tr><td colspan="3" class="text-center text-muted">暂无数据</td></tr><?php endif; ?><?php foreach ($payments as $row): ?><tr><td><?= htmlspecialchars(financePaymentLabel($row['payment_method'])) ?></td><td><?= (int) $row['count'] ?></td><td><?= formatMoney((float) $row['amount']) ?></td></tr><?php endforeach; ?></tbody></table></div>
<div class="card"><h3>支出分类</h3><table class="table"><thead><tr><th>分类</th><th>笔数</th><th>金额</th></tr></thead><tbody><?php if (!$expenseCategories): ?><tr><td colspan="3" class="text-center text-muted">暂无支出</td></tr><?php endif; ?><?php foreach ($expenseCategories as $row): ?><tr><td><?= htmlspecialchars(financeExpenseCategoryLabel($row['category'])) ?></td><td><?= (int) $row['count'] ?></td><td><?= formatMoney((float) $row['amount']) ?></td></tr><?php endforeach; ?></tbody></table></div>
<div class="card"><h3>会员与散客</h3><table class="table"><thead><tr><th>类型</th><th>订单</th><th>收入</th></tr></thead><tbody><?php if (!$customerTypes): ?><tr><td colspan="3" class="text-center text-muted">暂无数据</td></tr><?php endif; ?><?php foreach ($customerTypes as $row): ?><tr><td><?= htmlspecialchars($row['customer_type']) ?></td><td><?= (int) $row['count'] ?></td><td><?= formatMoney((float) $row['amount']) ?></td></tr><?php endforeach; ?></tbody></table></div>
<div class="card"><h3>商品销量排行</h3><table class="table"><thead><tr><th>商品</th><th>销量</th></tr></thead><tbody><?php if (!$itemCounts): ?><tr><td colspan="2" class="text-center text-muted">暂无数据</td></tr><?php endif; ?><?php foreach ($itemCounts as $name => $quantity): ?><tr><td><?= htmlspecialchars($name) ?></td><td><?= (int) $quantity ?></td></tr><?php endforeach; ?></tbody></table></div>
</div>
<div class="card"><h3>月度汇总</h3><table class="table"><thead><tr><th>月份</th><th>销售订单数</th><th>营业收入</th><th>商品成本（含积分兑换）</th><th>净收入</th></tr></thead><tbody><?php if (!$monthly): ?><tr><td colspan="5" class="text-center text-muted">暂无数据</td></tr><?php endif; ?><?php foreach ($monthly as $row): ?><tr><td><?= htmlspecialchars($row['month']) ?></td><td><?= (int) $row['orders'] ?></td><td><?= formatMoney((float) $row['revenue']) ?></td><td><?= formatMoney((float) $row['product_cost']) ?></td><td class="<?= (float) $row['net_income'] < 0 ? 'text-danger' : 'text-success' ?>"><?= formatMoney((float) $row['net_income']) ?></td></tr><?php endforeach; ?></tbody></table><p class="text-muted">积分兑换不增加营业收入或销售订单数，但其实际商品成本会扣减净收入。</p></div>
<?php renderFooter(); ?>

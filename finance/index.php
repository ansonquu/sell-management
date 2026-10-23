<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
requireLogin();
requireFinance();

$pdo = getDb();
[$from, $to] = financeDateRange($_GET['from'] ?? date('Y-m-01'), $_GET['to'] ?? date('Y-m-d'));
$summary = getFinanceSummary($pdo, $from, $to);
$accountBalances = getFinanceAccountBalances($pdo);
$cashBalance = (float) ($accountBalances['cash'] ?? 0);

$trendStmt = $pdo->prepare("
    SELECT DATE(create_time) AS day, COUNT(*) AS orders, COALESCE(SUM(amount - COALESCE(refunded_amount, 0)), 0) AS revenue
    FROM consumption
    WHERE DATE(create_time) BETWEEN ? AND ? AND refunded_at IS NULL AND record_type = 'sale'
    GROUP BY DATE(create_time) ORDER BY day DESC LIMIT 14
");
$trendStmt->execute([$from, $to]);
$trend = $trendStmt->fetchAll();

renderHeader('财务看板 - Anson咖啡厅管理系统', 'finance');
?>

<div class="page-header"><h2 class="page-title">财务管理</h2></div>
<?php renderFinanceTabs('dashboard'); ?>

<form class="search-bar" method="get">
    <label>统计期间</label><input type="date" name="from" value="<?= htmlspecialchars($from) ?>">
    <span>至</span><input type="date" name="to" value="<?= htmlspecialchars($to) ?>">
    <button class="btn btn-secondary" type="submit">查询</button>
    <a class="btn btn-link" href="/finance/">本月</a>
</form>

<div class="stats-grid finance-stats">
    <!-- 经营主线 -->
    <div class="stat-card">
        <div class="stat-value"><?= formatMoney($summary['revenue']) ?></div>
        <div class="stat-label">营业收入</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= formatMoney($summary['sales_net_income']) ?></div>
        <div class="stat-label">净收入（收入 − 商品成本）</div>
    </div>
    <div class="stat-card">
        <div class="stat-value <?= $summary['operating_result'] < 0 ? 'text-danger' : 'text-success' ?>"><?= formatMoney($summary['operating_result']) ?></div>
        <div class="stat-label">经营结余（净收入 − 支出 + 其他收入）</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= (int) $summary['order_count'] ?></div>
        <div class="stat-label">有效订单</div>
    </div>

    <!-- 辅助判读 -->
    <div class="stat-card small">
        <div class="stat-value"><?= formatMoney($summary['average_order']) ?></div>
        <div class="stat-label">平均客单价</div>
    </div>
    <div class="stat-card small">
        <div class="stat-value"><?= formatMoney($summary['product_cost']) ?></div>
        <div class="stat-label">商品成本</div>
    </div>
    <div class="stat-card small">
        <div class="stat-value"><?= formatMoney($summary['expenses']) ?></div>
        <div class="stat-label">经营支出</div>
    </div>
    <div class="stat-card small">
        <div class="stat-value <?= $cashBalance < 0 ? 'text-danger' : 'text-success' ?>"><?= formatMoney($cashBalance) ?></div>
        <div class="stat-label">现金余额（当前）</div>
    </div>
    <div class="stat-card small">
        <div class="stat-value text-success"><?= formatMoney((float) $summary['recharges']) ?></div>
        <div class="stat-label">充值收入（所选期间）</div>
    </div>
    <div class="stat-card small">
        <div class="stat-value <?= (float) $summary['refunds'] > 0 ? 'text-danger' : '' ?>"><?= formatMoney($summary['refunds']) ?></div>
        <div class="stat-label">退款金额</div>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <h3>近期收入趋势</h3>
        <table class="table"><thead><tr><th>日期</th><th>订单数</th><th>营业收入</th></tr></thead><tbody>
        <?php if (!$trend): ?><tr><td colspan="3" class="text-center text-muted">所选期间暂无消费记录</td></tr><?php endif; ?>
        <?php foreach ($trend as $row): ?><tr><td><?= htmlspecialchars($row['day']) ?></td><td><?= (int) $row['orders'] ?></td><td><?= formatMoney((float) $row['revenue']) ?></td></tr><?php endforeach; ?>
        </tbody></table>
    </div>
    <div class="card">
        <h3>财务口径说明</h3>
        <p>营业收入取自未退款的消费登记；会员充值属于资金流入和储值负债，不重复计入营业收入。</p>
        <p>净收入 = 营业收入 − 销售商品成本 − 积分兑换商品成本；经营结余 = 所选期间的净收入 − 该期间登记的支出 + 该期间登记的其他收入。</p>
        <p>「余额调整」（管理员在资金账户页调账产生的流水）属于账实差异调整，<strong>不计入</strong>经营支出与经营结余，但会影响账户余额，可在「收支管理 / 收支流水」中查看。</p>
        <p class="text-muted">充值资金流入、会员储值负债等资金类数据请查看「资金账户」；收入结构、趋势与导出请查看「经营报表 / 收支流水」。</p>
    </div>
</div>

<?php renderFooter(); ?>

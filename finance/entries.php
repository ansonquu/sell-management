<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
requireLogin();
requireFinance();

$pdo = getDb();
[$from, $to] = financeDateRange($_GET['from'] ?? date('Y-m-01'), $_GET['to'] ?? date('Y-m-d'));
$kind = trim((string) ($_GET['kind'] ?? ''));
$ledger = getFinanceLedger($pdo, $from, $to);
if ($kind !== '') {
    $ledger = array_values(array_filter($ledger, fn (array $row): bool => $row['kind'] === $kind));
}

if (($_GET['export'] ?? '') === 'csv') {
    $exportRows = [];
    foreach ($ledger as $row) {
        $exportRows[] = [
            $row['time'],
            $row['kind'],
            $row['category'],
            ($row['direction'] < 0 ? '-' : '') . number_format($row['amount'], 2, '.', ''),
            number_format((float) ($row['cost_amount'] ?? 0), 2, '.', ''),
            number_format((float) ($row['net_income'] ?? 0), 2, '.', ''),
            $row['payment_account'] ?? '',
            $row['summary'] ?? '',
            $row['operator'] ?? '',
            $row['source'] ?? '',
        ];
    }
    csvDownload(
        '咖啡厅流水_' . $from . '_' . $to . '.csv',
        'finance_ledger_' . $from . '_' . $to . '.csv',
        ['时间', '类型', '分类', '金额', '商品成本', '净收入', '资金账户', '摘要', '操作人', '来源'],
        $exportRows
    );
}

$export = http_build_query(['from' => $from, 'to' => $to, 'kind' => $kind, 'export' => 'csv']);
renderHeader('收支流水 - 财务管理', 'finance');
?>
<div class="page-header"><h2 class="page-title">收支流水</h2><a class="btn btn-primary" href="/finance/entries.php?<?= htmlspecialchars($export) ?>">导出 CSV</a></div>
<?php renderFinanceTabs('ledger'); ?>
<form class="search-bar" method="get"><input type="date" name="from" value="<?= htmlspecialchars($from) ?>"><span>至</span><input type="date" name="to" value="<?= htmlspecialchars($to) ?>"><select name="kind"><option value="">全部类型</option><?php foreach (['收入','资金流入','支出','退款'] as $option): ?><option <?= $kind === $option ? 'selected' : '' ?>><?= $option ?></option><?php endforeach; ?></select><button class="btn btn-secondary">筛选</button></form>
<div class="card table-scroll"><table class="table"><thead><tr><th>时间</th><th>类型</th><th>分类</th><th>金额</th><th>商品成本</th><th>净收入</th><th>资金账户</th><th>摘要</th><th>操作人</th><th>来源</th></tr></thead><tbody>
<?php if (!$ledger): ?><tr><td colspan="10" class="text-center text-muted">暂无流水</td></tr><?php endif; ?>
<?php foreach ($ledger as $row): ?><tr><td><?= formatDateTime($row['time']) ?></td><td><span class="badge <?= $row['direction'] < 0 ? 'badge-warning' : 'badge-success' ?>"><?= htmlspecialchars($row['kind']) ?></span></td><td><?= htmlspecialchars($row['category']) ?></td><td class="<?= $row['direction'] < 0 ? 'text-danger' : 'text-success' ?>"><?= $row['direction'] < 0 ? '-' : '+' ?><?= formatMoney($row['amount']) ?></td><td><?= $row['cost_amount'] === null ? '-' : formatMoney($row['cost_amount']) ?></td><td class="<?= $row['net_income'] !== null && $row['net_income'] < 0 ? 'text-danger' : 'text-success' ?>"><?= $row['net_income'] === null ? '-' : formatMoney($row['net_income']) ?></td><td><?= htmlspecialchars($row['account']) ?></td><td><?= htmlspecialchars($row['summary']) ?></td><td><?= htmlspecialchars($row['operator']) ?></td><td><?= htmlspecialchars($row['source']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php renderFooter(); ?>

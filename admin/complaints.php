<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
requireLogin();
requireAdmin();

$pdo = getDb();
$date = trim((string) ($_GET['date'] ?? ''));
$keyword = trim((string) ($_GET['q'] ?? ''));
$where = 'WHERE 1=1';
$params = [];
if ($date !== '') {
    $where .= ' AND DATE(cr.created_at) = ?';
    $params[] = $date;
}
if ($keyword !== '') {
    $where .= ' AND (cr.redo_items LIKE ? OR cr.ticket_number LIKE ? OR cr.operator_name LIKE ? OR m.name LIKE ?)';
    array_push($params, "%{$keyword}%", "%{$keyword}%", "%{$keyword}%", "%{$keyword}%");
}

$stmt = $pdo->prepare("
    SELECT cr.*, c.items AS original_items, c.amount AS original_amount, c.create_time AS original_time,
           m.name AS member_name
    FROM complaint_records cr
    LEFT JOIN consumption c ON c.id = cr.consumption_id
    LEFT JOIN member m ON m.id = c.member_id
    {$where}
    ORDER BY cr.created_at DESC, cr.id DESC
    LIMIT 300
");
$stmt->execute($params);
$records = $stmt->fetchAll();

$summary = $pdo->query("SELECT COUNT(*) AS total, SUM(CASE WHEN resolution_type = 'full' THEN 1 ELSE 0 END) AS full_count, SUM(CASE WHEN resolution_type = 'partial' THEN 1 ELSE 0 END) AS partial_count, SUM(CASE WHEN resolution_type = 'exchange' THEN 1 ELSE 0 END) AS exchange_count, SUM(CASE WHEN has_ticket = 1 THEN 1 ELSE 0 END) AS ticket_count FROM complaint_records")->fetch() ?: [];

renderHeader('客诉记录 - 管理后台', 'admin');
?>
<div class="page-header"><h2 class="page-title">客诉记录</h2><div class="page-actions"><a class="btn btn-secondary" href="/admin/logs.php">返回操作记录</a></div></div>
<div class="stats-grid"><div class="stat-card small"><div class="stat-value"><?= (int) ($summary['total'] ?? 0) ?></div><div class="stat-label">客诉总数</div></div><div class="stat-card small"><div class="stat-value"><?= (int) ($summary['full_count'] ?? 0) ?></div><div class="stat-label">全部重做</div></div><div class="stat-card small"><div class="stat-value"><?= (int) ($summary['partial_count'] ?? 0) ?></div><div class="stat-label">部分重做</div></div><div class="stat-card small"><div class="stat-value"><?= (int) ($summary['exchange_count'] ?? 0) ?></div><div class="stat-label">换货</div></div><div class="stat-card small"><div class="stat-value"><?= (int) ($summary['ticket_count'] ?? 0) ?></div><div class="stat-label">重新取号</div></div></div>
<form class="search-bar" method="get"><input type="date" name="date" value="<?= htmlspecialchars($date) ?>"><input name="q" value="<?= htmlspecialchars($keyword) ?>" placeholder="搜索会员、商品、号牌或处理人"><button class="btn btn-secondary">筛选</button><a class="btn btn-link" href="/admin/complaints.php">全部</a></form>
<div class="card table-scroll"><table class="table"><thead><tr><th>处理时间</th><th>顾客</th><th>原消费</th><th>客诉内容</th><th>补偿方式</th><th>重做商品</th><th>号牌</th><th>处理人</th><th>重做记录</th></tr></thead><tbody>
<?php if (!$records): ?><tr><td colspan="9" class="text-center text-muted">暂无客诉记录</td></tr><?php endif; ?>
<?php foreach ($records as $row): $details = decryptFinanceData($row['encrypted_details'] ?? ''); ?><tr><td><?= formatDateTime($row['created_at']) ?></td><td><?= htmlspecialchars($row['member_name'] ?: '散客') ?></td><td>#<?= (int) $row['consumption_id'] ?><small class="text-muted"><?= htmlspecialchars($row['original_items'] ?: '-') ?> / <?= formatMoney((float) $row['original_amount']) ?></small></td><td><?= nl2br(htmlspecialchars($details['complaint_reason'] ?? '-')) ?><?php if (!empty($details['handling_note'])): ?><small class="text-muted">处理备注：<?= htmlspecialchars($details['handling_note']) ?></small><?php endif; ?></td><td><span class="badge badge-warning"><?= htmlspecialchars(complaintResolutionLabel($row['resolution_type'])) ?></span></td><td><?= htmlspecialchars($row['redo_items']) ?><?php if (($row['resolution_type'] ?? '') === 'exchange'): ?><small class="text-muted">换入 <?= formatMoney((float) $row['exchange_amount']) ?> · 差价 <?= (float) $row['difference_amount'] > 0.009 ? '补收 ' . formatMoney((float) $row['difference_amount']) : ((float) $row['difference_amount'] < -0.009 ? '退还 ' . formatMoney(-(float) $row['difference_amount']) : '无差价') ?></small><?php endif; ?></td><td><?= (int) $row['has_ticket'] === 1 ? htmlspecialchars($row['ticket_number']) : '-' ?></td><td><?= htmlspecialchars($row['operator_name'] ?: $row['operator_username']) ?></td><td><a href="/consumption/?q=<?= urlencode(($row['resolution_type'] ?? '') === 'exchange' ? '[换货]' : '[客诉重做]') ?>">#<?= (int) $row['redo_consumption_id'] ?></a></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php renderFooter(); ?>

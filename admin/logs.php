<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';

requireAdmin();

$pdo = getDb();
$date = trim($_GET['date'] ?? date('Y-m-d'));
$actor = trim($_GET['actor'] ?? '');
$action = trim($_GET['action'] ?? '');
$keyword = trim($_GET['q'] ?? '');
$isExport = ($_GET['export'] ?? '') === 'csv';

$where = 'WHERE 1=1';
$params = [];

if ($date !== '') {
    $where .= ' AND DATE(created_at) = ?';
    $params[] = $date;
}

if ($actor !== '') {
    $where .= ' AND (actor_name LIKE ? OR actor_username LIKE ?)';
    $params[] = "%{$actor}%";
    $params[] = "%{$actor}%";
}

if ($action !== '') {
    $where .= ' AND action = ?';
    $params[] = $action;
}

if ($keyword !== '') {
    $where .= ' AND (description LIKE ? OR target_type LIKE ? OR meta LIKE ?)';
    $params[] = "%{$keyword}%";
    $params[] = "%{$keyword}%";
    $params[] = "%{$keyword}%";
}

$limitSql = $isExport ? '' : 'LIMIT 300';

$stmt = $pdo->prepare("
    SELECT *
    FROM operation_logs
    {$where}
    ORDER BY created_at DESC, id DESC
    {$limitSql}
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$actions = $pdo->query('
    SELECT action, COUNT(*) as count
    FROM operation_logs
    GROUP BY action
    ORDER BY action
')->fetchAll();

$today = date('Y-m-d');
$summaryStmt = $pdo->prepare("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN action = 'member_create' THEN 1 ELSE 0 END) as member_create,
        SUM(CASE WHEN action = 'consumption_create' THEN 1 ELSE 0 END) as consumption_create,
        SUM(CASE WHEN action = 'member_recharge' THEN 1 ELSE 0 END) as member_recharge,
        SUM(CASE WHEN action IN ('coupon_template_create', 'coupon_issue') THEN 1 ELSE 0 END) as coupon_actions
    FROM operation_logs
    WHERE DATE(created_at) = ?
");
$summaryStmt->execute([$today]);
$summary = $summaryStmt->fetch();

function operationActionLabel(string $action): string
{
    return [
        'member_create' => '新建会员',
        'member_update' => '编辑会员',
        'member_note_update' => '更新备注',
        'member_freeze' => '冻结会员',
        'member_unfreeze' => '解冻会员',
        'member_delete' => '删除会员',
        'consumption_create' => '消费登记',
        'consumption_delete' => '删除消费',
        'member_recharge' => '会员充值',
        'points_redeem' => '积分兑换',
        'coupon_template_create' => '创建优惠券',
        'coupon_template_update' => '编辑优惠券',
        'coupon_template_delete' => '删除优惠券',
        'coupon_issue' => '发放优惠券',
        'staff_login' => '员工登录',
        'staff_logout' => '员工退出',
        'staff_create' => '添加员工',
        'staff_delete' => '删除员工',
        'staff_password_change' => '修改密码',
        'staff_password_reset' => '重置密码',
        'staff_password_admin_reset' => '管理员重置密码',
        'system_settings_update' => '更新系统设置',
        'announcement_create' => '发布公告',
        'announcement_update' => '编辑公告',
        'finance_expense_create' => '登记支出',
        'finance_expense_reverse' => '冲正支出',
        'finance_account_create' => '新增资金账户',
        'finance_account_status' => '更新账户状态',
        'finance_transfer_create' => '资金划转',
        'product_cost_create' => '新增产品成本',
        'product_cost_update' => '更新产品成本',
        'finance_refund_create' => '退款',
        'daily_income_report_create' => '提交每日收入上报',
        'daily_income_report_update' => '更新每日收入上报',
        'complaint_create' => '处理客诉',
    ][$action] ?? $action;
}

function formatLogMeta(?string $meta): string
{
    if (!$meta) {
        return '';
    }

    $data = json_decode($meta, true);
    if (!is_array($data)) {
        return $meta;
    }

    $parts = [];
    foreach ($data as $key => $value) {
        if ($value === '' || $value === null) {
            continue;
        }
        if (is_bool($value)) {
            $value = $value ? '是' : '否';
        }
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        $parts[] = $key . ': ' . $value;
    }
    return implode('；', $parts);
}

function getOperatedPerson(array $log): string
{
    $meta = json_decode($log['meta'] ?? '', true);
    if (!is_array($meta)) {
        $meta = [];
    }

    if (!empty($meta['member_name'])) {
        return (string) $meta['member_name'];
    }

    if (($log['target_type'] ?? '') === 'member') {
        foreach (['new_name', 'name'] as $key) {
            if (!empty($meta[$key])) {
                return (string) $meta[$key];
            }
        }
    }

    if (str_starts_with((string) $log['action'], 'staff_')) {
        $description = (string) ($log['description'] ?? '');
        $parts = explode('：', $description, 2);
        return trim($parts[1] ?? '') ?: ($log['actor_name'] ?: '-');
    }

    return '-';
}

if ($isExport) {
    $exportRows = [];
    foreach ($logs as $log) {
        $exportRows[] = [
            formatDateTime($log['created_at']),
            $log['actor_name'] ?: $log['actor_username'] ?: '-',
            getOperatedPerson($log),
            operationActionLabel($log['action']),
            trim(($log['target_type'] ?: '-') . ($log['target_id'] ? ' #' . $log['target_id'] : '')),
            $log['description'],
            formatLogMeta($log['meta'] ?? ''),
        ];
    }
    csvDownload(
        '操作记录_' . date('Ymd_His') . '.csv',
        'operation_logs_' . date('Ymd_His') . '.csv',
        ['时间', '操作人', '被操作人', '操作', '对象', '内容', '详情'],
        $exportRows
    );
}
$exportParams = $_GET;
$exportParams['export'] = 'csv';
$exportUrl = '/admin/logs.php?' . http_build_query($exportParams);

renderHeader('管理后台 - 操作记录', 'admin');
?>

<div class="page-header">
    <h2 class="page-title">管理后台</h2>
    <div class="page-actions"><a href="/admin/settings.php" class="btn btn-secondary">系统设置</a><a href="/recharge/" class="btn btn-secondary">充值记录</a><a href="/admin/complaints.php" class="btn btn-secondary">客诉记录</a><a href="/admin/feedback.php" class="btn btn-secondary">员工反馈</a><a href="/admin/attendance.php" class="btn btn-secondary">签到管理</a><a href="/activate.php" class="btn btn-secondary">系统授权</a><a href="/admin/devices.php" class="btn btn-secondary">员工设备</a><a href="/admin/staff.php" class="btn btn-primary">员工管理</a></div>
</div>

<div class="stats-grid">
    <div class="stat-card small">
        <div class="stat-value"><?= (int) ($summary['total'] ?? 0) ?></div>
        <div class="stat-label">今日操作</div>
    </div>
    <div class="stat-card small">
        <div class="stat-value"><?= (int) ($summary['member_create'] ?? 0) ?></div>
        <div class="stat-label">今日新建会员</div>
    </div>
    <div class="stat-card small">
        <div class="stat-value"><?= (int) ($summary['consumption_create'] ?? 0) ?></div>
        <div class="stat-label">今日消费登记</div>
    </div>
    <div class="stat-card small">
        <div class="stat-value"><?= (int) ($summary['member_recharge'] ?? 0) ?></div>
        <div class="stat-label">今日充值</div>
    </div>
    <div class="stat-card small">
        <div class="stat-value"><?= (int) ($summary['coupon_actions'] ?? 0) ?></div>
        <div class="stat-label">今日优惠券操作</div>
    </div>
</div>

<form class="search-bar" method="get">
    <input type="date" name="date" value="<?= htmlspecialchars($date) ?>">
    <input type="text" name="actor" placeholder="操作人..." value="<?= htmlspecialchars($actor) ?>">
    <select name="action">
        <option value="">全部操作</option>
        <?php foreach ($actions as $row): ?>
            <option value="<?= htmlspecialchars($row['action']) ?>" <?= $action === $row['action'] ? 'selected' : '' ?>>
                <?= htmlspecialchars(operationActionLabel($row['action'])) ?>（<?= $row['count'] ?>）
            </option>
        <?php endforeach; ?>
    </select>
    <input type="text" name="q" placeholder="搜索描述或详情..." value="<?= htmlspecialchars($keyword) ?>">
    <button type="submit" class="btn btn-secondary">筛选</button>
    <a href="<?= htmlspecialchars($exportUrl) ?>" class="btn btn-primary">导出日志</a>
    <a href="/admin/logs.php?date=<?= date('Y-m-d') ?>" class="btn btn-link">今天</a>
    <a href="/admin/logs.php?date=" class="btn btn-link">全部</a>
</form>

<div class="card">
    <h3>操作记录</h3>
    <table class="table">
        <thead>
            <tr>
                <th>时间</th>
                <th>操作人</th>
                <th class="nowrap-cell">被操作人</th>
                <th class="nowrap-cell">操作</th>
                <th>对象</th>
                <th>内容</th>
                <th>详情</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($logs)): ?>
                <tr><td colspan="7" class="text-center text-muted">暂无操作记录</td></tr>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <?php $metaText = formatLogMeta($log['meta'] ?? ''); ?>
                    <tr>
                        <td><?= formatDateTime($log['created_at']) ?></td>
                        <td>
                            <?= htmlspecialchars($log['actor_name'] ?: $log['actor_username'] ?: '-') ?>
                            <?php if ($log['actor_role']): ?>
                                <small class="text-muted"><?= htmlspecialchars($log['actor_role']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="nowrap-cell"><?= htmlspecialchars(getOperatedPerson($log)) ?></td>
                        <td class="nowrap-cell"><span class="badge badge-secondary"><?= htmlspecialchars(operationActionLabel($log['action'])) ?></span></td>
                        <td>
                            <?= htmlspecialchars($log['target_type'] ?: '-') ?>
                            <?php if ($log['target_id']): ?>
                                <small class="text-muted">#<?= (int) $log['target_id'] ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($log['description']) ?></td>
                        <td class="log-meta-cell" title="<?= htmlspecialchars($metaText) ?>"><?= htmlspecialchars($metaText ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
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

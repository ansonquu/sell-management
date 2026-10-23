<?php

session_start();
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';

requireLogin();

$currentStaff = getCurrentStaff();
$username = (string) $currentStaff['username'];
$pdo = getDb();

$errors = [];
$success = '';
$accounts = getStaffAccounts();
$account = $accounts[$username] ?? [];
$avatarPath = (string) ($account['avatar'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = (string) ($_POST['action'] ?? 'upload');
    if ($action === 'remove') {
        removeStaffAvatar($username);
        $success = '头像已移除';
    } else {
        $path = storeStaffAvatar($_FILES['avatar'] ?? [], $username, $errors);
        if ($path !== null) {
            $success = '头像已更新';
        }
    }
    $accounts = getStaffAccounts();
    $account = $accounts[$username] ?? [];
    $avatarPath = (string) ($account['avatar'] ?? '');
}

// 近 7 天业绩
$since = date('Y-m-d H:i:s', strtotime('-7 days'));
$operator = (string) $currentStaff['name'];

$salesStmt = $pdo->prepare("SELECT COALESCE(SUM(amount - COALESCE(refunded_amount, 0)), 0) AS total, COUNT(*) AS cnt FROM consumption WHERE operator = ? AND record_type = 'sale' AND refunded_at IS NULL AND create_time >= ?");
$salesStmt->execute([$operator, $since]);
$salesRow = $salesStmt->fetch();

$lossStmt = $pdo->prepare("SELECT COUNT(*) FROM consumption WHERE operator = ? AND record_type = 'complaint_redo' AND create_time >= ?");
$lossStmt->execute([$operator, $since]);
$lossCount = (int) $lossStmt->fetchColumn();

$recentStmt = $pdo->prepare("SELECT id, create_time, items, amount, points_spent, record_type FROM consumption WHERE operator = ? ORDER BY create_time DESC, id DESC LIMIT 10");
$recentStmt->execute([$operator]);
$recentRecords = $recentStmt->fetchAll();

// 待办：未读公告 / 今日是否已上报 / 未读通知
$unreadAnnouncementCount = getUnreadAnnouncementCount($pdo, $username);
$today = date('Y-m-d');
$todayReportStmt = $pdo->prepare('SELECT id FROM daily_income_reports WHERE report_date = ? AND operator_username = ?');
$todayReportStmt->execute([$today, $username]);
$todayReported = (bool) $todayReportStmt->fetchColumn();
$unreadNotificationCount = getUnreadNotificationCount($username);

// 餐损 / 客诉明细：我处理过的客诉
$complaintStmt = $pdo->prepare("
    SELECT c.id, c.consumption_id, c.resolution_type, c.redo_items, c.has_ticket, c.ticket_number, c.created_at,
           con.items AS original_items
    FROM complaint_records c
    LEFT JOIN consumption con ON con.id = c.consumption_id
    WHERE c.operator_username = ?
    ORDER BY c.created_at DESC, c.id DESC
    LIMIT 10
");
$complaintStmt->execute([$username]);
$myComplaints = $complaintStmt->fetchAll();

// 最近操作
$logStmt = $pdo->prepare('SELECT id, action, description, created_at FROM operation_logs WHERE actor_username = ? ORDER BY created_at DESC, id DESC LIMIT 10');
$logStmt->execute([$username]);
$myLogs = $logStmt->fetchAll();

function profileRecordTypeLabel(string $type): string
{
    return match ($type) {
        'points_redeem' => '积分兑换',
        'complaint_redo' => '客诉重做',
        default => '消费',
    };
}

function profileActionLabel(string $action): string
{
    $map = [
        'staff_login' => '登录系统',
        'staff_logout' => '退出登录',
        'consumption_create' => '消费登记',
        'member_create' => '注册会员',
        'member_update' => '编辑会员',
        'member_delete' => '删除会员',
        'member_note_update' => '更新会员备注',
        'member_recharge' => '会员充值',
        'points_redeem' => '积分兑换',
        'coupon_issue' => '发放优惠券',
        'coupon_template_create' => '创建优惠券',
        'coupon_template_update' => '编辑优惠券',
        'coupon_template_delete' => '删除优惠券',
        'complaint_create' => '客诉处理',
        'daily_income_report_create' => '提交收入上报',
        'daily_income_report_update' => '更新收入上报',
        'finance_expense_create' => '支出登记',
        'finance_expense_reverse' => '支出冲正',
        'finance_refund_create' => '退款',
        'finance_transfer_create' => '资金划转',
        'finance_account_status' => '资金账户设置',
        'announcement_create' => '发布公告',
        'announcement_update' => '编辑公告',
        'announcement_delete' => '删除公告',
        'staff_create' => '新增员工',
        'staff_delete' => '删除员工',
        'staff_password_admin_reset' => '重置员工密码',
        'system_settings_update' => '更新系统设置',
        'cost_ingredient_delete' => '删除成本成分',
        'system_setup' => '系统初始化',
    ];
    return $map[$action] ?? $action;
}

renderHeader('个人中心');
?>

<div class="page-header">
    <h1 class="page-title">个人中心</h1>
    <div class="page-actions">
        <a class="btn btn-secondary" href="/devices.php">我的登录设备</a>
        <a class="btn btn-secondary" href="/change_password.php">修改密码</a>
    </div>
</div>

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $error): ?><div><?= htmlspecialchars($error) ?></div><?php endforeach; ?></div><?php endif; ?>

<div class="card">
    <h3>待办</h3>
    <div class="todo-list">
        <a class="todo-item" href="/announcements/">
            <span class="todo-emoji">📢</span>
            <span class="todo-text">未读公告</span>
            <?php if ($unreadAnnouncementCount > 0): ?><span class="todo-count"><?= $unreadAnnouncementCount ?> 条</span><?php else: ?><span class="todo-done">已读完</span><?php endif; ?>
        </a>
        <a class="todo-item" href="/daily-report/">
            <span class="todo-emoji">💰</span>
            <span class="todo-text">今日收入上报</span>
            <?php if ($todayReported): ?><span class="todo-done">已上报</span><?php else: ?><span class="todo-count">未上报</span><?php endif; ?>
        </a>
        <a class="todo-item" href="/notifications.php">
            <span class="todo-emoji">🔔</span>
            <span class="todo-text">我的通知</span>
            <?php if ($unreadNotificationCount > 0): ?><span class="todo-count"><?= $unreadNotificationCount ?> 条未读</span><?php else: ?><span class="todo-done">全部已读</span><?php endif; ?>
        </a>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="profile-header">
            <div class="profile-avatar">
                <img src="<?= $avatarPath !== '' ? htmlspecialchars($avatarPath) : staffAvatarDataUri((string) ($currentStaff['name'] ?? $username)) ?>" alt="我的头像">
            </div>
            <div>
                <h3><?= htmlspecialchars((string) $currentStaff['name']) ?></h3>
                <p class="text-muted"><?= htmlspecialchars($username) ?> · <?= staffRoleLabel((string) ($currentStaff['role'] ?? 'staff')) ?></p>
            </div>
        </div>

        <h3>个人头像</h3>
        <form method="post" enctype="multipart/form-data">
                <?= csrfField() ?>
            <input type="hidden" name="action" value="upload">
            <div class="form-group">
                <input type="file" name="avatar" accept="image/png,image/jpeg,image/gif,image/webp">
                <p class="form-help">支持 PNG / JPG / GIF / WebP，不超过 2MB</p>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">上传头像</button>
            </div>
        </form>
        <?php if ($avatarPath !== ''): ?>
        <form method="post" class="danger-actions" style="margin-top:0.75rem">
                <?= csrfField() ?>
            <input type="hidden" name="action" value="remove">
            <button class="btn btn-danger" type="submit">移除头像</button>
        </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>近 7 天业绩</h3>
        <div class="stats-grid" style="grid-template-columns: repeat(2, minmax(0, 1fr));">
            <div class="stat-card small">
                <div class="stat-value"><?= formatMoney((float) $salesRow['total']) ?></div>
                <div class="stat-label">销售金额（<?= (int) $salesRow['cnt'] ?> 笔）</div>
            </div>
            <div class="stat-card small">
                <div class="stat-value"><?= $lossCount ?></div>
                <div class="stat-label">餐损（客诉重做）</div>
            </div>
        </div>
        <p class="text-muted" style="margin-top: 1rem; font-size: 0.88rem;">
            统计口径：以本人为操作人的近 7 天记录。餐损按客诉重做次数统计。
        </p>
    </div>
</div>

<div class="card">
    <h3>最近的记录</h3>
    <?php if (empty($recentRecords)): ?>
        <p class="text-muted">暂无记录</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr><th>时间</th><th>内容</th><th>类型</th><th>金额</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($recentRecords as $record): ?>
                        <tr>
                            <td class="nowrap-cell"><?= formatDateTime((string) $record['create_time']) ?></td>
                            <td><?= htmlspecialchars((string) ($record['items'] ?: '-')) ?></td>
                            <td><span class="badge <?= ($record['record_type'] ?? 'sale') === 'complaint_redo' ? 'badge-danger' : (($record['record_type'] ?? 'sale') === 'points_redeem' ? 'badge-warning' : 'badge-level') ?>"><?= profileRecordTypeLabel((string) ($record['record_type'] ?? 'sale')) ?></span></td>
                            <td>
                                <?php if (($record['record_type'] ?? 'sale') === 'points_redeem'): ?>
                                    <span class="text-danger">-<?= (int) $record['points_spent'] ?> 积分</span>
                                <?php elseif (($record['record_type'] ?? 'sale') === 'complaint_redo'): ?>
                                    <span class="text-muted">餐损</span>
                                <?php else: ?>
                                    <?= formatMoney(max(0, (float) $record['amount'] - (float) ($record['refunded_amount'] ?? 0))) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <h3>餐损 / 客诉明细</h3>
    <?php if (empty($myComplaints)): ?>
        <p class="text-muted">暂无客诉处理记录</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr><th>时间</th><th>原消费单</th><th>处理方式</th><th>重做内容</th><th>号牌</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($myComplaints as $complaint): ?>
                        <tr>
                            <td class="nowrap-cell"><?= formatDateTime((string) $complaint['created_at']) ?></td>
                            <td><?= $complaint['original_items'] ? htmlspecialchars((string) $complaint['original_items']) : ('消费单 #' . (int) $complaint['consumption_id']) ?></td>
                            <td><span class="badge badge-danger"><?= complaintResolutionLabel((string) $complaint['resolution_type']) ?></span></td>
                            <td><?= htmlspecialchars((string) ($complaint['redo_items'] ?: '-')) ?></td>
                            <td><?= $complaint['has_ticket'] ? htmlspecialchars((string) $complaint['ticket_number']) : '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <h3>最近操作</h3>
    <?php if (empty($myLogs)): ?>
        <p class="text-muted">暂无操作记录</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr><th>时间</th><th>操作</th><th>详情</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($myLogs as $log): ?>
                        <tr>
                            <td class="nowrap-cell"><?= formatDateTime((string) $log['created_at']) ?></td>
                            <td><span class="badge badge-level"><?= htmlspecialchars(profileActionLabel((string) $log['action'])) ?></span></td>
                            <td><?= htmlspecialchars((string) ($log['description'] ?: '-')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php renderFooter(); ?>

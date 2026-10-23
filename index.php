<?php

session_start();
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';

$pdo = getDb();
$stats = getStats($pdo);
$currentStaff = getCurrentStaff();
$unreadAnnouncementCount = getUnreadAnnouncementCount($pdo, (string) ($currentStaff['username'] ?? ''));
$urgentAnnouncements = array_values(array_filter(getAnnouncements($pdo, (string) ($currentStaff['username'] ?? '')),
    static fn (array $row): bool => ($row['category'] ?? '') === 'urgent' && (int) $row['is_read'] !== 1));

// 最近消费记录
$recentConsumptions = $pdo->query("
    SELECT c.*, m.name as member_name, m.phone
    FROM consumption c
    JOIN member m ON c.member_id = m.id
    ORDER BY c.create_time DESC
    LIMIT 10
")->fetchAll();

renderHeader('首页 - Anson咖啡厅管理系统', 'dashboard');
?>

<?php foreach ($urgentAnnouncements as $urgent): ?>
<section class="urgent-handwritten" aria-label="紧急公告">
    <div class="urgent-handwritten-title">紧急公告 · <?= htmlspecialchars($urgent['title']) ?></div>
    <div class="urgent-handwritten-content markdown-body"><?= renderMarkdown($urgent['content']) ?></div>
    <form method="post" action="/announcements/">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="read">
        <input type="hidden" name="id" value="<?= (int) $urgent['id'] ?>">
        <input type="hidden" name="return_to" value="/">
        <button type="submit" class="btn btn-danger btn-sm">已读，隐藏公告</button>
    </form>
</section>
<?php endforeach; ?>

<?php if ($unreadAnnouncementCount > 0): ?>
    <a href="/announcements/" class="unread-announcement-alert">
        <span class="unread-announcement-message">
            你有 <strong><?= $unreadAnnouncementCount ?></strong> 条未读公告
        </span>
        <span class="unread-announcement-action">立即查看 <span aria-hidden="true">→</span></span>
    </a>
<?php endif; ?>

<h2 class="page-title">首页</h2>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?= $stats['member_count'] ?></div>
        <div class="stat-label">会员总数</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= $stats['active_count'] ?></div>
        <div class="stat-label">活跃会员</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= formatMoney($stats['total_spent']) ?></div>
        <div class="stat-label">消费总额</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= number_format($stats['total_points']) ?></div>
        <div class="stat-label">积分总量</div>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <h3>会员等级分布</h3>
        <?php if (empty($stats['level_distribution'])): ?>
            <p class="text-muted">暂无会员数据</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr><th>等级</th><th>人数</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($stats['level_distribution'] as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['level']) ?></td>
                            <td><?= $row['count'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>快捷操作</h3>
        <div class="quick-actions">
            <a href="/members/add.php" class="btn btn-primary">+ 注册会员</a>
            <a href="/consumption/add.php" class="btn btn-success">+ 消费登记</a>
            <a href="/recharge/add.php" class="btn btn-primary">+ 会员充值</a>
            <?php if (isPointsRedemptionEnabled()): ?><a href="/points/redeem.php" class="btn btn-warning">积分兑换</a><?php endif; ?>
            <a href="/members/" class="btn btn-secondary">查询会员</a>
        </div>
        <p class="text-muted" style="margin-top: 1rem;">
            累计消费 <?= $stats['consumption_count'] ?> 笔，积分规则：每消费 1 元获得 <?= getPointsPerYuan() ?> 积分
        </p>
    </div>
</div>

<div class="card">
    <h3>最近消费记录</h3>
    <?php if (empty($recentConsumptions)): ?>
        <p class="text-muted">暂无消费记录</p>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>时间</th>
                    <th>会员</th>
                    <th>商品</th>
                    <th>金额</th>
                    <th>积分</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentConsumptions as $row): ?>
                    <tr>
                        <td><?= formatDateTime($row['create_time']) ?></td>
                        <td>
                            <a href="/members/view.php?id=<?= $row['member_id'] ?>">
                                <?= htmlspecialchars($row['member_name']) ?>
                            </a>
                            <small class="text-muted"><?= htmlspecialchars($row['phone'] ?: '手机号待补全') ?></small>
                        </td>
                        <td><?= htmlspecialchars($row['items'] ?: '-') ?></td>
                        <td><?= formatMoney(max(0, (float) $row['amount'] - (float) ($row['refunded_amount'] ?? 0))) ?></td>
                        <td>+<?= $row['points_earned'] ?></td>
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

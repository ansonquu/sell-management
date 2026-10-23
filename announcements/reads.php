<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';

requireAdmin();

$pdo = getDb();
$announcementId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM announcements WHERE id = ?');
$stmt->execute([$announcementId]);
$announcement = $stmt->fetch();

if (!$announcement) {
    flash('error', '公告不存在');
    redirect('/announcements/');
}

$readStatus = getAnnouncementReadStatus($pdo, $announcement);
$readCount = count(array_filter($readStatus, fn (array $row): bool => $row['is_read']));
$unreadCount = count($readStatus) - $readCount;

renderHeader('公告已读情况 - Anson咖啡厅管理系统', 'announcements');
?>

<div class="page-header">
    <div>
        <h2 class="page-title">公告已读情况</h2>
        <p class="text-muted"><?= htmlspecialchars($announcement['title']) ?></p>
    </div>
    <a href="/announcements/" class="btn btn-secondary">返回公告列表</a>
</div>

<div class="stats-grid announcement-read-summary">
    <div class="stat-card small">
        <div class="stat-value"><?= count($readStatus) ?></div>
        <div class="stat-label">账号总数</div>
    </div>
    <div class="stat-card small read-stat">
        <div class="stat-value"><?= $readCount ?></div>
        <div class="stat-label">已读</div>
    </div>
    <div class="stat-card small unread-stat">
        <div class="stat-value"><?= $unreadCount ?></div>
        <div class="stat-label">未读</div>
    </div>
</div>

<div class="card">
    <?php if (!$readStatus): ?>
        <p class="text-muted">暂无员工账号</p>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>姓名</th>
                    <th>账号</th>
                    <th>角色</th>
                    <th>状态</th>
                    <th>已读时间</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($readStatus as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['name']) ?></td>
                        <td><?= htmlspecialchars($row['username']) ?></td>
                        <td><?= $row['role'] === 'admin' ? '管理员' : '员工' ?></td>
                        <td><span class="badge <?= $row['is_read'] ? 'badge-success' : 'badge-warning' ?>"><?= $row['is_read'] ? '已读' : '未读' ?></span></td>
                        <td><?= $row['is_read'] ? formatDateTime($row['read_at']) : '-' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php renderFooter(); ?>

<?php

/**
 * 公告详情：查看全文，进入即自动标记已读。
 */

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';

$pdo = getDb();
$announcementId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM announcements WHERE id = ?');
$stmt->execute([$announcementId]);
$announcement = $stmt->fetch();

if (!$announcement || !announcementVisibleToStaff($announcement)) {
    flash('error', '公告不存在或您无权查看');
    redirect('/announcements/');
}

// 打开详情即视为已读
$staff = getCurrentStaff();
$justRead = false;
if ($staff && ($announcement['status'] ?? '') === 'published') {
    $justRead = markAnnouncementRead($pdo, $announcementId, (string) $staff['username']);
}

renderHeader(htmlspecialchars((string) $announcement['title']) . ' - 公告', 'announcements');
?>
<div class="page-header">
    <h2 class="page-title">公告详情</h2>
    <div class="page-actions">
        <a href="/announcements/" class="btn btn-secondary">返回公告列表</a>
        <?php if (isAdmin()): ?>
            <a href="/announcements/edit.php?id=<?= (int) $announcement['id'] ?>" class="btn btn-secondary">编辑</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($justRead): ?>
    <div class="alert alert-success">已自动标记为已读。</div>
<?php endif; ?>

<article class="card announcement-item <?= ($announcement['category'] ?? 'normal') === 'urgent' ? 'is-urgent' : 'is-normal' ?>">
    <div class="announcement-heading">
        <h3>
            <?php if (($announcement['category'] ?? 'normal') === 'urgent'): ?><span class="badge badge-urgent">紧急</span><?php endif; ?>
            <?= htmlspecialchars((string) $announcement['title']) ?>
        </h3>
        <div class="announcement-actions">
            <?php if (($announcement['status'] ?? '') !== 'published'): ?>
                <span class="badge badge-secondary">已撤下</span>
            <?php endif; ?>
            <?php if (isAdmin()): ?>
                <a href="/announcements/reads.php?id=<?= (int) $announcement['id'] ?>" class="btn btn-secondary btn-sm">已读情况</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="announcement-content markdown-body"><?= renderMarkdown((string) $announcement['content']) ?></div>
    <div class="announcement-meta">
        发布人：<?= htmlspecialchars((string) ($announcement['created_by'] ?: '-')) ?> · 更新于 <?= formatDateTime((string) $announcement['updated_at']) ?>
        <?php if (isAdmin()): ?> · 可见范围：<span class="announcement-target-tag"><?= htmlspecialchars(announcementTargetLabel($announcement)) ?></span><?php endif; ?>
    </div>
</article>

<?php renderFooter(); ?>

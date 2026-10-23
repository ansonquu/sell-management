<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';

$pdo = getDb();
$staff = getCurrentStaff();
$username = (string) ($staff['username'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    $announcementId = (int) ($_POST['id'] ?? 0);

    if ($action === 'delete') {
        if (!isAdmin()) {
            flash('error', '您没有权限删除公告');
        } elseif ($announcementId <= 0) {
            flash('error', '公告不存在');
        } else {
            $stmt = $pdo->prepare('SELECT title FROM announcements WHERE id = ?');
            $stmt->execute([$announcementId]);
            $announcement = $stmt->fetch();

            if (!$announcement) {
                flash('error', '公告不存在');
            } else {
                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare('DELETE FROM announcement_reads WHERE announcement_id = ?');
                    $stmt->execute([$announcementId]);

                    $stmt = $pdo->prepare('DELETE FROM announcements WHERE id = ?');
                    $stmt->execute([$announcementId]);

                    logOperation($pdo, 'announcement_delete', 'announcement', $announcementId, '删除公告：' . $announcement['title']);
                    $pdo->commit();
                    flash('success', '公告已删除');
                } catch (Throwable $error) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    flash('error', '公告删除失败，请稍后重试');
                }
            }
        }

        redirect('/announcements/');
    } elseif ($action === 'read' && $announcementId > 0 && markAnnouncementRead($pdo, $announcementId, $username)) {
        flash('success', '公告已设为已读');
    } else {
        flash('error', '公告不存在或已撤下');
    }

    $returnTo = ($_POST['return_to'] ?? '') === '/' ? '/' : '/announcements/';
    redirect($returnTo);
}

$announcements = getAnnouncements($pdo, $username, isAdmin());

renderHeader('公告 - Anson咖啡厅管理系统', 'announcements');
?>

<div class="page-header">
    <h2 class="page-title">公告</h2>
    <?php if (isAdmin()): ?><a href="/announcements/edit.php" class="btn btn-primary">+ 发布公告</a><?php endif; ?>
</div>

<?php if (!$announcements): ?>
    <div class="card empty-state">暂无公告</div>
<?php else: ?>
    <div class="announcement-list">
        <?php foreach ($announcements as $announcement): ?>
            <?php $isPublished = $announcement['status'] === 'published'; ?>
            <article class="card announcement-item <?= ($announcement['category'] ?? 'normal') === 'urgent' ? 'is-urgent' : 'is-normal' ?> <?= (int) $announcement['is_read'] === 1 ? 'is-read' : 'is-unread' ?>">
                <div class="announcement-heading">
                    <h3><?php if (($announcement['category'] ?? 'normal') === 'urgent'): ?><span class="badge badge-urgent">紧急</span><?php endif; ?><?= htmlspecialchars($announcement['title']) ?></h3>
                    <div class="announcement-actions">
                        <?php if (!$isPublished): ?>
                            <span class="badge badge-secondary">已撤下</span>
                        <?php else: ?>
                            <span class="badge <?= (int) $announcement['is_read'] === 1 ? 'badge-secondary' : 'badge-warning' ?>"><?= (int) $announcement['is_read'] === 1 ? '已读' : '未读' ?></span>
                            <?php if ((int) $announcement['is_read'] !== 1): ?>
                                <form method="post" class="inline-form">
                <?= csrfField() ?>
                                    <input type="hidden" name="action" value="read">
                                    <input type="hidden" name="id" value="<?= (int) $announcement['id'] ?>">
                                    <button type="submit" class="btn btn-primary btn-sm">设为已读</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if (isAdmin()): ?><a href="/announcements/reads.php?id=<?= (int) $announcement['id'] ?>" class="btn btn-secondary btn-sm">已读情况</a><?php endif; ?>
                        <?php if (isAdmin()): ?><a href="/announcements/edit.php?id=<?= (int) $announcement['id'] ?>" class="btn btn-secondary btn-sm">编辑</a><?php endif; ?>
                        <?php if (isAdmin()): ?>
                            <form method="post" class="inline-form" onsubmit="return confirm('确定要永久删除这条公告吗？此操作无法撤销。')">
                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $announcement['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">删除</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php
                $plainContent = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $announcement['content'])));
                $contentLength = mb_strlen($plainContent, 'UTF-8');
                $isLongContent = $contentLength > 200;
                ?>
                <div class="announcement-content markdown-body">
                    <?php if ($isLongContent): ?>
                        <p class="announcement-excerpt"><?= htmlspecialchars(mb_substr($plainContent, 0, 200, 'UTF-8')) ?>…</p>
                        <a class="btn btn-secondary btn-sm announcement-more" href="/announcements/view.php?id=<?= (int) $announcement['id'] ?>">
                            查看详情（全文 <?= $contentLength ?> 字）→
                        </a>
                    <?php else: ?>
                        <?= renderMarkdown($announcement['content']) ?>
                    <?php endif; ?>
                </div>
                <div class="announcement-meta">
                    发布人：<?= htmlspecialchars($announcement['created_by'] ?: '-') ?> · 更新于 <?= formatDateTime($announcement['updated_at']) ?>
                    <?php if (isAdmin()): ?> · 可见分组：<span class="announcement-target-tag"><?= htmlspecialchars(announcementTargetLabel($announcement)) ?></span><?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php renderFooter(); ?>

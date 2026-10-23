<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';

requireLogin();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    if ($action === 'reply' && $id > 0) {
        $reply = trim((string) ($_POST['reply_content'] ?? ''));
        if ($reply === '') {
            flash('error', '回复内容不能为空');
        } elseif (replyFeedback($id, $reply)) {
            flash('success', '已回复该反馈，员工将实时收到通知');
        } else {
            flash('error', '反馈不存在');
        }
    }
    redirect('/admin/feedback.php');
}

$feedbackItems = getAdminFeedback();
$pendingCount = getFeedbackPendingCount();
$initialVersion = getFeedbackVersion();

renderHeader('员工反馈 - Anson咖啡厅管理系统', 'admin');
?>
<div class="page-header">
    <h1 class="page-title">员工反馈</h1>
    <div class="page-actions">
        <span class="badge <?= $pendingCount > 0 ? 'badge-warning' : 'badge-success' ?>">待处理 <?= $pendingCount ?> 条</span>
        <a class="btn btn-secondary" href="/admin/logs.php">返回操作记录</a>
    </div>
</div>

<?php if (empty($feedbackItems)): ?>
    <div class="card empty-state">暂无员工反馈</div>
<?php else: ?>
    <div class="card">
        <div class="feedback-list">
            <?php foreach ($feedbackItems as $item): ?>
                <div class="feedback-item <?= $item['status'] === 'done' ? 'is-done' : 'is-pending' ?>">
                    <div class="feedback-head">
                        <span class="badge <?= $item['status'] === 'done' ? 'badge-success' : 'badge-warning' ?>"><?= feedbackStatusLabel((string) $item['status']) ?></span>
                        <span class="feedback-sender"><?= htmlspecialchars((string) ($item['sender_name'] ?: $item['sender_username'])) ?></span>
                        <span class="feedback-time"><?= formatDateTime((string) $item['created_at']) ?></span>
                    </div>
                    <div class="feedback-content"><?= nl2br(htmlspecialchars((string) $item['content'])) ?></div>

                    <?php if ($item['status'] === 'done' && trim((string) $item['reply_content']) !== ''): ?>
                        <div class="feedback-reply">
                            <div class="feedback-reply-label">已回复<?= $item['replied_by'] !== '' ? '（' . htmlspecialchars((string) $item['replied_by']) . '）' : '' ?>：</div>
                            <div><?= nl2br(htmlspecialchars((string) $item['reply_content'])) ?></div>
                            <div class="feedback-time"><?= formatDateTime((string) $item['replied_at']) ?></div>
                        </div>
                    <?php else: ?>
                        <form method="post" class="feedback-reply-form">
                <?= csrfField() ?>
                            <input type="hidden" name="action" value="reply">
                            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                            <div class="form-group" style="margin-bottom:0.5rem;">
                                <textarea name="reply_content" rows="2" required placeholder="输入处理结果或回复内容，提交后员工会实时收到通知"></textarea>
                            </div>
                            <button type="submit" class="btn btn-success btn-sm">回复并处理</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<script>
(function () {
    const initialVersion = <?= json_encode($initialVersion) ?>;
    let checking = false;
    setInterval(function () {
        if (checking || document.hidden) return;
        checking = true;
        fetch('/api/feedback_version.php', { cache: 'no-store' })
            .then(function (res) { return res.ok ? res.json() : null; })
            .then(function (data) {
                if (data && data.version && data.version !== initialVersion) {
                    window.location.reload();
                }
            })
            .catch(function () {})
            .finally(function () { checking = false; });
    }, 5000);
})();
</script>

<?php renderFooter(); ?>

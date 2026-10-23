<?php

session_start();
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';

requireLogin();

$currentStaff = getCurrentStaff();
$username = (string) $currentStaff['username'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'submit') {
        if (isAdmin()) {
            flash('error', '管理员无需提交反馈');
        } else {
            $content = trim((string) ($_POST['content'] ?? ''));
            if ($content === '') {
                flash('error', '反馈内容不能为空');
            } elseif (mb_strlen($content) > 500) {
                flash('error', '反馈内容不能超过 500 字');
            } else {
                submitFeedback($content);
                flash('success', '反馈已提交，管理员会尽快处理');
            }
        }
    }
    redirect('/feedback.php');
}

$feedbackItems = getMyFeedback($username);

// 冻结员工只能查看本次冻结后提交的记录（旧记录不可见）
if (isStaffFrozen($username)) {
    $frozenAt = (string) (getStaffAccounts()[$username]['frozen_at'] ?? '');
    // 兼容旧数据：冻结时未记录冻结时间，则至少显示当天提交的记录
    if ($frozenAt === '') {
        $frozenAt = date('Y-m-d 00:00:00');
    }
    $feedbackItems = array_values(array_filter($feedbackItems, static function (array $item) use ($frozenAt): bool {
        return (string) ($item['created_at'] ?? '') >= $frozenAt;
    }));
}

$initialVersion = getFeedbackVersion();

renderHeader('我的反馈');
?>
<div class="page-header">
    <h1 class="page-title"><?= isStaffFrozen($username) ? '反馈' : '我的反馈' ?></h1>
    <div class="page-actions">
        <button type="button" class="btn btn-primary" onclick="openFeedbackModal()">+ 提交反馈</button>
        <?php if (!isStaffFrozen($username)): ?>
        <a class="btn btn-secondary" href="/profile.php">返回个人中心</a>
        <?php endif; ?>
    </div>
</div>

<?php if (isStaffFrozen($username)): ?>
    <div class="alert alert-warning">⚠️ 您的账号已被冻结，目前仅可提交反馈，并查看本次冻结后提交的记录。如有疑问请联系管理员。</div>
    <?php if (empty($feedbackItems)): ?>
        <div class="card empty-state">冻结期间还没有提交过反馈</div>
    <?php else: ?>
        <div class="card">
            <div class="feedback-list">
                <?php foreach ($feedbackItems as $item): ?>
                    <div class="feedback-item <?= $item['status'] === 'done' ? 'is-done' : '' ?>">
                        <div class="feedback-head">
                            <span class="badge <?= $item['status'] === 'done' ? 'badge-success' : 'badge-warning' ?>"><?= feedbackStatusLabel((string) $item['status']) ?></span>
                            <span class="feedback-time"><?= formatDateTime((string) $item['created_at']) ?></span>
                        </div>
                        <div class="feedback-content"><?= nl2br(htmlspecialchars((string) $item['content'])) ?></div>
                        <?php if ($item['status'] === 'done' && trim((string) $item['reply_content']) !== ''): ?>
                            <div class="feedback-reply">
                                <div class="feedback-reply-label">管理员回复<?= $item['replied_by'] !== '' ? '（' . htmlspecialchars((string) $item['replied_by']) . '）' : '' ?>：</div>
                                <div><?= nl2br(htmlspecialchars((string) $item['reply_content'])) ?></div>
                                <div class="feedback-time"><?= formatDateTime((string) $item['replied_at']) ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
<?php else: ?>
    <?php if (empty($feedbackItems)): ?>
        <div class="card empty-state">还没有提交过反馈</div>
    <?php else: ?>
        <div class="card">
            <div class="feedback-list">
                <?php foreach ($feedbackItems as $item): ?>
                    <div class="feedback-item <?= $item['status'] === 'done' ? 'is-done' : '' ?>">
                        <div class="feedback-head">
                            <span class="badge <?= $item['status'] === 'done' ? 'badge-success' : 'badge-warning' ?>"><?= feedbackStatusLabel((string) $item['status']) ?></span>
                            <span class="feedback-time"><?= formatDateTime((string) $item['created_at']) ?></span>
                        </div>
                        <div class="feedback-content"><?= nl2br(htmlspecialchars((string) $item['content'])) ?></div>
                        <?php if ($item['status'] === 'done' && trim((string) $item['reply_content']) !== ''): ?>
                            <div class="feedback-reply">
                                <div class="feedback-reply-label">管理员回复<?= $item['replied_by'] !== '' ? '（' . htmlspecialchars((string) $item['replied_by']) . '）' : '' ?>：</div>
                                <div><?= nl2br(htmlspecialchars((string) $item['reply_content'])) ?></div>
                                <div class="feedback-time"><?= formatDateTime((string) $item['replied_at']) ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<div id="feedbackModal" class="modal-overlay" style="display:none;">
    <div class="modal-card">
        <div class="modal-header">
            <h3>向管理员反馈</h3>
            <button type="button" class="modal-close" onclick="closeFeedbackModal()">×</button>
        </div>
        <form method="post" action="/feedback.php">
                <?= csrfField() ?>
            <input type="hidden" name="action" value="submit">
            <div class="form-group">
                <textarea name="content" rows="4" maxlength="500" required placeholder="遇到什么问题？有什么建议？说说看..."></textarea>
                <p class="form-help">最多 500 字，提交后管理员会收到通知并尽快处理回复</p>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">提交反馈</button>
                <button type="button" class="btn btn-secondary" onclick="closeFeedbackModal()">取消</button>
            </div>
        </form>
    </div>
</div>

<script>
function openFeedbackModal() {
    document.getElementById('feedbackModal').style.display = 'flex';
    var box = document.getElementById('feedbackModal').querySelector('textarea');
    if (box) box.focus();
}
function closeFeedbackModal() {
    document.getElementById('feedbackModal').style.display = 'none';
}
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

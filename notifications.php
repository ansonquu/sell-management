<?php

session_start();
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';

requireLogin();

$staff = getCurrentStaff();
$username = (string) $staff['username'];

// 打开页面即视为已读
markAllNotificationsRead($username);
$notifications = getNotifications($username);

renderHeader('我的通知');
?>

<div class="page-header">
    <h1 class="page-title">我的通知</h1>
</div>

<div class="card">
    <?php if (empty($notifications)): ?>
        <div class="empty-state">暂无通知</div>
    <?php else: ?>
        <div class="notification-list">
            <?php foreach ($notifications as $notification): ?>
                <?php $notificationLink = (string) ($notification['link'] ?? ''); ?>
                <?php if ($notificationLink !== ''): ?>
                    <a class="notification-item" href="<?= htmlspecialchars($notificationLink) ?>">
                <?php else: ?>
                    <div class="notification-item">
                <?php endif; ?>
                    <div class="notification-title"><?= htmlspecialchars((string) $notification['title']) ?></div>
                    <div class="notification-content"><?= htmlspecialchars((string) $notification['content']) ?></div>
                    <div class="notification-meta"><?= formatDateTime((string) $notification['created_at']) ?></div>
                <?php if ($notificationLink !== ''): ?>
                    </a>
                <?php else: ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php renderFooter(); ?>

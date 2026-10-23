<?php

/**
 * 站内通知：发送给指定员工，在顶栏铃铛与个人中心展示。
 */

function adminUsernames(): array
{
    $admins = [];
    foreach (getStaffAccounts() as $username => $account) {
        if (($account['role'] ?? '') === 'admin') {
            $admins[] = (string) $username;
        }
    }
    return $admins;
}

function notifyStaff(string $username, string $title, string $content, string $link = ''): void
{
    if ($username === '') {
        return;
    }
    $stmt = getDb()->prepare('INSERT INTO staff_notifications (recipient_username, title, content, link, is_read, created_at) VALUES (?, ?, ?, ?, 0, ?)');
    $stmt->execute([$username, $title, $content, $link, currentDateTime()]);
}

function getUnreadNotificationCount(string $username): int
{
    $stmt = getDb()->prepare('SELECT COUNT(*) FROM staff_notifications WHERE recipient_username = ? AND is_read = 0');
    $stmt->execute([$username]);
    return (int) $stmt->fetchColumn();
}

function getNotifications(string $username, int $limit = 30): array
{
    $limit = max(1, min(100, $limit));
    $stmt = getDb()->prepare('SELECT * FROM staff_notifications WHERE recipient_username = ? ORDER BY created_at DESC, id DESC LIMIT ' . $limit);
    $stmt->execute([$username]);
    return $stmt->fetchAll();
}

function markAllNotificationsRead(string $username): void
{
    $stmt = getDb()->prepare('UPDATE staff_notifications SET is_read = 1 WHERE recipient_username = ? AND is_read = 0');
    $stmt->execute([$username]);
}

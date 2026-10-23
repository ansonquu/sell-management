<?php

/**
 * 员工反馈：员工提交 → 管理员处理并回复。
 */

function feedbackStatusLabel(string $status): string
{
    return $status === 'done' ? '已处理' : '待处理';
}

function submitFeedback(string $content): int
{
    $staff = getCurrentStaff() ?? [];
    $stmt = getDb()->prepare("INSERT INTO staff_feedback (sender_username, sender_name, content, status, created_at) VALUES (?, ?, ?, 'pending', ?)");
    $stmt->execute([
        (string) ($staff['username'] ?? ''),
        (string) ($staff['name'] ?? ''),
        $content,
        currentDateTime(),
    ]);
    $id = (int) getDb()->lastInsertId();

    // 通知所有管理员
    foreach (adminUsernames() as $adminUsername) {
        notifyStaff(
            $adminUsername,
            '新的员工反馈',
            ($staff['name'] ?? '员工') . ' 提交了反馈：' . mb_substr($content, 0, 40) . (mb_strlen($content) > 40 ? '…' : ''),
            '/admin/feedback.php'
        );
    }
    return $id;
}

function getMyFeedback(string $username, int $limit = 50): array
{
    $limit = max(1, min(200, $limit));
    $stmt = getDb()->prepare('SELECT * FROM staff_feedback WHERE sender_username = ? ORDER BY created_at DESC, id DESC LIMIT ' . $limit);
    $stmt->execute([$username]);
    return $stmt->fetchAll();
}

function getAdminFeedback(int $limit = 200): array
{
    $limit = max(1, min(500, $limit));
    return getDb()->query("SELECT * FROM staff_feedback ORDER BY CASE WHEN status = 'pending' THEN 0 ELSE 1 END, created_at DESC, id DESC LIMIT " . $limit)->fetchAll();
}

function getFeedbackPendingCount(): int
{
    return (int) getDb()->query("SELECT COUNT(*) FROM staff_feedback WHERE status = 'pending'")->fetchColumn();
}

function replyFeedback(int $id, string $reply): bool
{
    $pdo = getDb();
    $stmt = $pdo->prepare('SELECT * FROM staff_feedback WHERE id = ?');
    $stmt->execute([$id]);
    $feedback = $stmt->fetch();
    if (!$feedback) {
        return false;
    }

    $admin = getCurrentStaff() ?? [];
    $pdo->prepare("UPDATE staff_feedback SET status = 'done', reply_content = ?, replied_by = ?, replied_at = ? WHERE id = ?")
        ->execute([$reply, (string) ($admin['name'] ?? ''), currentDateTime(), $id]);

    // 通知反馈人
    if (!empty($feedback['sender_username'])) {
        notifyStaff(
            (string) $feedback['sender_username'],
            '你的反馈已处理',
            '管理员已回复：' . mb_substr($reply, 0, 40) . (mb_strlen($reply) > 40 ? '…' : ''),
            '/feedback.php'
        );
    }
    return true;
}

function getFeedbackVersion(): string
{
    $row = getDb()->query("SELECT COUNT(*) AS cnt, COALESCE(MAX(id), 0) AS max_id, COALESCE(MAX(created_at), '') AS max_created, COALESCE(MAX(replied_at), '') AS max_replied FROM staff_feedback")->fetch();
    return sha1(json_encode($row));
}

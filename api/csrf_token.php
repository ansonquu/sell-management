<?php

/**
 * 会话同步接口：返回当前会话的最新 CSRF 令牌与会话指纹。
 * 前端脚本据此修正被 CDN 缓存的页面（陈旧令牌）并检测跨会话缓存。
 * 客户端会附加随机查询参数，因此该响应不会被 CDN 命中缓存。
 */

session_start();
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$staff = getCurrentStaff();
$fingerprint = 'guest';
if ($staff) {
    $fingerprint = substr(hash('sha256', session_id() . '|' . (string) ($staff['username'] ?? '')), 0, 16);
}

echo json_encode([
    'token' => csrfToken(),
    'fingerprint' => $fingerprint,
], JSON_UNESCAPED_UNICODE);
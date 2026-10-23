<?php

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/functions.php';

$staff = $_SESSION['staff'] ?? null;
if (!$staff || !isSessionValid($staff['username'], session_id()) || !isLicenseActive()) {
    http_response_code(401);
    echo json_encode(['error' => '未登录']);
    exit;
}
// 大屏账号使用各自的专用轮询接口
if (in_array((string) ($staff['role'] ?? ''), ['barista_screen', 'customer_screen'], true)) {
    http_response_code(403);
    echo json_encode(['error' => '无权访问'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDb();
$scope = $_GET['scope'] ?? 'global';

if ($scope === 'member') {
    $memberId = (int) ($_GET['member_id'] ?? 0);
    if ($memberId <= 0 || !getMemberById($pdo, $memberId)) {
        http_response_code(404);
        echo json_encode(['error' => '会员不存在']);
        exit;
    }

    echo json_encode(['version' => getRecordVersion($pdo, $memberId)]);
    exit;
}

echo json_encode(['version' => getRecordVersion($pdo)]);

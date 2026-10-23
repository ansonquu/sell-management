<?php

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['staff']) || !isSessionValid($_SESSION['staff']['username'], session_id()) || !isLicenseActive()) {
    http_response_code(401);
    echo json_encode(['error' => '未登录']);
    exit;
}
if (!hasRegularStaffPermission()) {
    http_response_code(403);
    echo json_encode(['error' => '无权搜索会员'], JSON_UNESCAPED_UNICODE);
    exit;
}

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    echo json_encode([]);
    exit;
}

$pdo = getDb();
$stmt = $pdo->prepare("
    SELECT id, name, phone, student_no, level, points, total_spent, balance, status
    FROM member
    WHERE status = 1 AND (" . memberSearchCondition() . ")
    ORDER BY name
    LIMIT 10
");
$stmt->execute(["%{$q}%", "%{$q}%", "%{$q}%"]);
echo json_encode($stmt->fetchAll());

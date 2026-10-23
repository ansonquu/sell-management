<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
requireRegularStaffPermission();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
requireCsrf();
header('Content-Type: application/json; charset=utf-8');
$id = (int) ($_POST['member_id'] ?? 0);
$phone = trim((string) ($_POST['phone'] ?? ''));
$pdo = getDb();
$member = getMemberById($pdo, $id);

if (!$member) {
    http_response_code(404);
    echo json_encode(['error' => '会员不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (trim((string) ($member['phone'] ?? '')) !== '') {
    http_response_code(409);
    echo json_encode(['error' => '该会员已有手机号'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
    http_response_code(422);
    echo json_encode(['error' => '请输入有效的 11 位手机号'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (getMemberByPhone($pdo, $phone)) {
    http_response_code(409);
    echo json_encode(['error' => '该手机号已被其他会员使用'], JSON_UNESCAPED_UNICODE);
    exit;
}
try {
    $pdo->prepare("UPDATE member SET phone = ? WHERE id = ? AND (phone IS NULL OR phone = '')")->execute([$phone, $id]);
    logOperation($pdo, 'member_phone_complete', 'member', $id, '补全会员手机号：' . $member['name'], ['phone' => $phone]);
    echo json_encode(['success' => true, 'phone' => $phone], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    http_response_code(409);
    echo json_encode(['error' => '保存失败，请核对手机号后重试'], JSON_UNESCAPED_UNICODE);
}

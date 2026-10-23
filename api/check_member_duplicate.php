<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
if (!hasRegularStaffPermission()) {
    http_response_code(403);
    echo json_encode(['error' => '无权访问'], JSON_UNESCAPED_UNICODE);
    exit;
}
$phone = trim((string) ($_GET['phone'] ?? ''));
$name = trim((string) ($_GET['name'] ?? ''));
$existing = $phone !== '' ? getMemberByPhone(getDb(), $phone) : null;
$same = $existing && $name !== '' && trim((string) $existing['name']) === $name;
echo json_encode(['duplicate' => (bool) $existing, 'message' => $same
    ? '姓名和手机号与已有会员一致，请勿重复注册'
    : ($existing ? '该手机号已注册，请核对会员资料' : '')], JSON_UNESCAPED_UNICODE);

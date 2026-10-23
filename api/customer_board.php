<?php

/**
 * 客户大屏数据接口
 *  - ready：已全部制作完成，请取餐
 *  - waiting：制作中，附带「已好 / 还差」杯数
 * 姓名一律脱敏（仅保留姓）。
 */

session_start();
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

requireLogin();
if (!isLicenseActive()) {
    http_response_code(403);
    echo json_encode(['error' => '系统未激活'], JSON_UNESCAPED_UNICODE);
    exit;
}

$staff = getCurrentStaff();
if (!canAccessCustomerBoard($staff)) {
    http_response_code(403);
    echo json_encode(['error' => '无权访问客户大屏'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDb();
$today = date('Y-m-d');

// 标记 / 撤销「已领取」
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    if (!canOperateCustomerBoard($staff)) {
        http_response_code(403);
        echo json_encode(['error' => '当前权限只能查看'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $action = (string) ($_POST['action'] ?? '');
    $orderId = (int) ($_POST['id'] ?? 0);
    if (!in_array($action, ['pick_up', 'undo_pick_up'], true)) {
        http_response_code(400);
        echo json_encode(['error' => '无效操作'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = markOrderPickedUp($pdo, $orderId, $action === 'pick_up', $staff);
    if (isset($result['error'])) {
        http_response_code(409);
        echo json_encode(['error' => $result['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- 请取餐：已完成且尚未领取 ---------- */
$stmt = $pdo->prepare("
    SELECT c.id, c.ticket_number, c.prepared_at, m.name AS member_name, m.phone AS member_phone
    FROM consumption c
    LEFT JOIN member m ON m.id = c.member_id
    WHERE c.record_type = 'sale'
      AND c.refunded_at IS NULL
      AND c.prep_status = 'done'
      AND c.picked_up_at IS NULL
      AND DATE(c.create_time) = ?
    ORDER BY c.prepared_at ASC, c.id ASC
    LIMIT 48
");
$stmt->execute([$today]);

$ready = [];
foreach ($stmt as $row) {
    [$displayNo, $displayKind] = displayOrderNumber($row);
    $ready[] = [
        'id' => (int) $row['id'],
        'ticket' => $displayNo,
        'display_no' => $displayNo,
        'display_kind' => $displayKind,
        'name' => maskCustomerName($row['member_name']),
        'prepared_at' => trim((string) $row['prepared_at']),
    ];
}

/* ---------- 已领取（页面底部折叠显示） ---------- */
$stmt = $pdo->prepare("
    SELECT c.id, c.ticket_number, c.prepared_at, c.picked_up_at, m.name AS member_name, m.phone AS member_phone
    FROM consumption c
    LEFT JOIN member m ON m.id = c.member_id
    WHERE c.record_type = 'sale'
      AND c.refunded_at IS NULL
      AND c.picked_up_at IS NOT NULL
      AND DATE(c.create_time) = ?
    ORDER BY c.picked_up_at DESC, c.id DESC
    LIMIT 60
");
$stmt->execute([$today]);

$picked = [];
foreach ($stmt as $row) {
    [$displayNo, $displayKind] = displayOrderNumber($row);
    $picked[] = [
        'id' => (int) $row['id'],
        'ticket' => $displayNo,
        'display_no' => $displayNo,
        'display_kind' => $displayKind,
        'name' => maskCustomerName($row['member_name']),
        'picked_up_at' => trim((string) $row['picked_up_at']),
    ];
}

/* ---------- 请等待：制作中（含饮品进度） ---------- */
$stmt = $pdo->prepare("
    SELECT c.id, c.ticket_number, c.create_time, c.prep_items, m.name AS member_name, m.phone AS member_phone
    FROM consumption c
    LEFT JOIN member m ON m.id = c.member_id
    WHERE c.record_type = 'sale'
      AND c.refunded_at IS NULL
      AND c.prep_status <> 'done'
      AND DATE(c.create_time) = ?
    ORDER BY c.create_time ASC, c.id ASC
    LIMIT 48
");
$stmt->execute([$today]);

$waiting = [];
foreach ($stmt as $row) {
    $items = consumptionPreparationItems($row);
    // 已退款的饮品不参与「还差几杯」计算
    $activeItems = array_values(array_filter(
        $items,
        static fn (array $item): bool => ($item['status'] ?? '') !== 'refunded'
    ));
    $totalCups = count($activeItems);
    $doneCups = count(array_filter(
        $activeItems,
        static fn (array $item): bool => ($item['status'] ?? '') === 'done'
    ));

    // 若没有任何可制作饮品（例如全部退款），则不占用等待区
    if ($totalCups <= 0) {
        continue;
    }

    [$displayNo, $displayKind] = displayOrderNumber($row);
    $waiting[] = [
        'id' => (int) $row['id'],
        'ticket' => $displayNo,
        'display_no' => $displayNo,
        'display_kind' => $displayKind,
        'name' => maskCustomerName($row['member_name']),
        'total_cups' => $totalCups,
        'done_cups' => $doneCups,
        'remaining_cups' => max(0, $totalCups - $doneCups),
        'created_at' => trim((string) $row['create_time']),
    ];
}

echo json_encode([
    'ready' => $ready,
    'picked' => $picked,
    'waiting' => $waiting,
    'can_operate' => canOperateCustomerBoard($staff),
    'server_time' => currentDateTime(),
], JSON_UNESCAPED_UNICODE);

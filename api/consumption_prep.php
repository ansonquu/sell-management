<?php

/**
 * 消费记录 · 制作状态接口
 *  - GET  ?id=N                      查询某单的逐杯制作状态
 *  - POST action=done_item|undo_item 标记一杯完成 / 撤销完成
 * 所有普通员工及以上权限均可操作。
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
if (!hasRegularStaffPermission($staff)) {
    http_response_code(403);
    echo json_encode(['error' => '无权操作制作状态'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDb();

/** 组装某单的制作状态数据 */
function prepOrderPayload(PDO $pdo, int $orderId): ?array
{
    $stmt = $pdo->prepare('SELECT c.*, m.name AS member_name FROM consumption c LEFT JOIN member m ON m.id = c.member_id WHERE c.id = ?');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) {
        return null;
    }

    $items = consumptionPreparationItems($order);
    $list = [];
    foreach ($items as $index => $item) {
        $list[] = [
            'index' => $index,
            'name' => (string) ($item['name'] ?? ''),
            'status' => (string) ($item['status'] ?? 'pending'),
            'prepared_at' => trim((string) ($item['prepared_at'] ?? '')),
        ];
    }

    $active = array_values(array_filter($items, static fn (array $item): bool => ($item['status'] ?? '') !== 'refunded'));
    $doneCount = count(array_filter($active, static fn (array $item): bool => ($item['status'] ?? '') === 'done'));

    return [
        'id' => (int) $order['id'],
        'ticket' => trim((string) $order['ticket_number']),
        'member' => (string) ($order['member_name'] ?? ''),
        'items_text' => (string) $order['items'],
        'prep_status' => (string) ($order['prep_status'] ?? 'done'),
        'can_prepare' => ($order['record_type'] ?? 'sale') === 'sale' && empty($order['refunded_at']),
        'picked_up_at' => trim((string) ($order['picked_up_at'] ?? '')),
        'picked_up_by' => trim((string) ($order['picked_up_by'] ?? '')),
        'prep_done' => ($order['prep_status'] ?? '') === 'done',
        'total_cups' => count($active),
        'done_cups' => $doneCount,
        'items' => $list,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $orderId = (int) ($_GET['id'] ?? 0);
    $payload = prepOrderPayload($pdo, $orderId);
    if ($payload === null) {
        http_response_code(404);
        echo json_encode(['error' => '订单不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

requireCsrf();

$action = (string) ($_POST['action'] ?? '');
$orderId = (int) ($_POST['id'] ?? 0);
$rawIndex = $_POST['item_index'] ?? null;
$itemIndex = is_scalar($rawIndex) && ctype_digit((string) $rawIndex) ? (int) $rawIndex : -1;

// 「已领取」标记 / 撤销
if (in_array($action, ['pick_up', 'undo_pick_up'], true)) {
    $result = markOrderPickedUp($pdo, $orderId, $action === 'pick_up', $staff);
    if (isset($result['error'])) {
        http_response_code(409);
        echo json_encode(['error' => $result['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(prepOrderPayload($pdo, $orderId), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!in_array($action, ['done_item', 'undo_item'], true)) {
    http_response_code(400);
    echo json_encode(['error' => '无效操作'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM consumption WHERE id = ? AND record_type = 'sale' AND refunded_at IS NULL");
$stmt->execute([$orderId]);
$order = $stmt->fetch();
if (!$order) {
    http_response_code(409);
    echo json_encode(['error' => '该记录不可操作（非消费单或已退款）'], JSON_UNESCAPED_UNICODE);
    exit;
}

$items = consumptionPreparationItems($order);
if ($itemIndex < 0 || !isset($items[$itemIndex])) {
    http_response_code(404);
    echo json_encode(['error' => '饮品不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($items[$itemIndex]['status'] ?? '') === 'refunded') {
    http_response_code(409);
    echo json_encode(['error' => '已退款的饮品不可操作'], JSON_UNESCAPED_UNICODE);
    exit;
}

$now = currentDateTime();
$staffName = (string) ($staff['name'] ?? '');
if ($action === 'done_item') {
    $items[$itemIndex]['status'] = 'done';
    $items[$itemIndex]['prepared_at'] = $now;
    $items[$itemIndex]['prepared_by'] = $staffName;
} else {
    $items[$itemIndex]['status'] = 'pending';
    $items[$itemIndex]['prepared_at'] = null;
    $items[$itemIndex]['prepared_by'] = '';
}

$activeItems = array_values(array_filter($items, static fn (array $item): bool => ($item['status'] ?? '') !== 'refunded'));
$allDone = $activeItems !== [] && count(array_filter($activeItems, static fn (array $item): bool => ($item['status'] ?? '') === 'done')) === count($activeItems);

$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE consumption SET prep_items = ?, prep_status = ?, prepared_at = ?, prepared_by = ? WHERE id = ?')
        ->execute([
            json_encode($items, JSON_UNESCAPED_UNICODE),
            $allDone ? 'done' : 'pending',
            $allDone ? $now : null,
            $allDone ? $staffName : '',
            $orderId,
        ]);
    logOperation($pdo, $action === 'done_item' ? 'drink_prepared' : 'drink_prepare_undo', 'consumption', $orderId,
        ($action === 'done_item' ? '饮品制作完成：' : '撤销制作完成：') . (string) ($items[$itemIndex]['name'] ?? ''),
        ['item_index' => $itemIndex, 'operator' => $staffName]);
    $pdo->commit();
} catch (Throwable $error) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => '保存失败'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(prepOrderPayload($pdo, $orderId), JSON_UNESCAPED_UNICODE);

<?php

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
if (!canAccessOrderBoard($staff)) {
    http_response_code(403);
    echo json_encode(['error' => '无权访问制作总览'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDb();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    // 接待等只读角色不能执行制作操作
    if (!canOperateOrderBoard($staff)) {
        http_response_code(403);
        echo json_encode(['error' => '当前权限只能查看，不能操作'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    if ($action === 'done_item' && $id > 0) {
        $rawIndex = $_POST['item_index'] ?? null;
        $itemIndex = is_scalar($rawIndex) && ctype_digit((string) $rawIndex) ? (int) $rawIndex : -1;
        $stmt = $pdo->prepare('SELECT * FROM consumption WHERE id = ? AND refunded_at IS NULL AND DATE(create_time) = ?');
        $stmt->execute([$id, date('Y-m-d')]);
        $order = $stmt->fetch();
        $items = $order ? consumptionPreparationItems($order) : [];
        if ($itemIndex < 0 || !isset($items[$itemIndex])) {
            http_response_code(404);
            echo json_encode(['error' => '饮品不存在'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (($items[$itemIndex]['status'] ?? 'pending') === 'refunded') {
            http_response_code(409);
            echo json_encode(['error' => '已退款饮品不能标记完成'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (($items[$itemIndex]['status'] ?? 'pending') !== 'done') {
            $now = currentDateTime();
            $preparedBy = (string) ($staff['name'] ?? '');
            $items[$itemIndex]['status'] = 'done';
            $items[$itemIndex]['prepared_at'] = $now;
            $items[$itemIndex]['prepared_by'] = $preparedBy;
            $allDone = count(array_filter($items, static fn (array $item): bool => in_array(($item['status'] ?? ''), ['done', 'refunded'], true))) === count($items);
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE consumption SET prep_items = ?, prep_status = ?, prepared_at = ?, prepared_by = ? WHERE id = ?')
                    ->execute([json_encode($items, JSON_UNESCAPED_UNICODE), $allDone ? 'done' : 'pending', $allDone ? $now : null, $allDone ? $preparedBy : '', $id]);
                logOperation($pdo, 'drink_prepared', 'consumption', $id, '饮品制作完成：' . $items[$itemIndex]['name'], [
                    'item_index' => $itemIndex,
                    'item_name' => $items[$itemIndex]['name'],
                    'prepared_by' => $preparedBy,
                ]);
                $pdo->commit();
            } catch (Throwable $error) {
                $pdo->rollBack();
                http_response_code(500);
                echo json_encode(['error' => '保存失败'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    } elseif ($action === 'redo_item' && $id > 0) {
        $rawIndex = $_POST['item_index'] ?? null;
        $itemIndex = is_scalar($rawIndex) && ctype_digit((string) $rawIndex) ? (int) $rawIndex : -1;
        $stmt = $pdo->prepare('SELECT id FROM consumption WHERE id = ? AND refunded_at IS NULL AND DATE(create_time) = ?');
        $stmt->execute([$id, date('Y-m-d')]);
        if (!$stmt->fetch() || $itemIndex < 0) {
            http_response_code(409);
            echo json_encode(['error' => '只能重做已完成且未退款的饮品'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        try {
            redoConsumptionItem($pdo, $id, $itemIndex, $staff);
        } catch (RuntimeException $error) {
            http_response_code(409);
            echo json_encode(['error' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $error) {
            http_response_code(500);
            echo json_encode(['error' => '重做保存失败'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } elseif ($action === 'refund_item' && $id > 0) {
        $rawIndex = $_POST['item_index'] ?? null;
        $itemIndex = is_scalar($rawIndex) && ctype_digit((string) $rawIndex) ? (int) $rawIndex : -1;
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $rawAmount = trim((string) ($_POST['refund_amount'] ?? ''));
        $amount = $rawAmount !== '' && is_numeric($rawAmount) ? (float) $rawAmount : null;
        $stmt = $pdo->prepare("SELECT id FROM consumption WHERE id = ? AND record_type = 'sale' AND refunded_at IS NULL AND DATE(create_time) = ?");
        $stmt->execute([$id, date('Y-m-d')]);
        if (!$stmt->fetch() || $itemIndex < 0 || $reason === '' || mb_strlen($reason) > 200 || ($rawAmount !== '' && $amount === null)) {
            http_response_code(422);
            echo json_encode(['error' => '请选择今日有效饮品并填写退款原因'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        try {
            refundConsumptionItem($pdo, $id, $itemIndex, $reason, $staff, $amount);
        } catch (RuntimeException $error) {
            http_response_code(422);
            echo json_encode(['error' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $error) {
            http_response_code(500);
            echo json_encode(['error' => '退款保存失败'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } elseif (($action === 'pick_up' || $action === 'undo_pick_up') && $id > 0) {
        $result = markOrderPickedUp($pdo, $id, $action === 'pick_up', $staff);
        if (isset($result['error'])) {
            http_response_code(409);
            echo json_encode(['error' => $result['error']], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } elseif ($action === 'read_urgent' && $id > 0) {
        $visible = getAnnouncements($pdo, (string) $staff['username']);
        foreach ($visible as $announcement) {
            if ((int) $announcement['id'] === $id && ($announcement['category'] ?? '') === 'urgent') {
                markAnnouncementRead($pdo, $id, (string) $staff['username']);
                break;
            }
        }
    } else {
        http_response_code(400);
        echo json_encode(['error' => '请求无效'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$stmt = $pdo->prepare("SELECT c.id, c.items, c.note, c.ticket_number, c.amount, c.refunded_amount, c.payment_method, c.record_type, c.operator, c.create_time, c.prep_status, c.prep_items, c.prepared_at, c.prepared_by, c.picked_up_at, m.name AS member_name, m.phone AS member_phone
    FROM consumption c LEFT JOIN member m ON c.member_id = m.id
    WHERE DATE(c.create_time) = ? AND c.refunded_at IS NULL AND c.record_type <> 'board_redo' AND COALESCE(c.items, '') <> ''
    ORDER BY CASE WHEN c.prep_status = 'pending' THEN 0 ELSE 1 END,
      CASE WHEN c.prep_status = 'pending' THEN c.create_time END ASC,
      c.create_time DESC, c.id DESC LIMIT 100");
$stmt->execute([date('Y-m-d')]);
$orders = $stmt->fetchAll();
foreach ($orders as &$order) {
    $order['payment_label'] = consumptionPaymentMethodLabel($order['payment_method']);
    $order['prep_items'] = consumptionPreparationItems($order);
    $order['remaining_amount'] = max(0, round((float) $order['amount'] - (float) ($order['refunded_amount'] ?? 0), 2));
    [$order['display_no'], $order['display_kind']] = displayOrderNumber($order);
}
unset($order);
$urgent = array_values(array_filter(getAnnouncements($pdo, (string) $staff['username']),
    static fn (array $row): bool => ($row['category'] ?? '') === 'urgent' && (int) $row['is_read'] !== 1));
echo json_encode(['orders' => $orders, 'urgent' => array_map(static fn (array $row): array => [
    'id' => (int) $row['id'], 'title' => $row['title'], 'content' => $row['content'],
], $urgent)], JSON_UNESCAPED_UNICODE);

<?php

/**
 * 数据导出（管理员）
 * /admin/export.php?type=staff|members|consumption[&from=&to=]
 */

session_start();
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();

$pdo = getDb();
$type = (string) ($_GET['type'] ?? '');
$stamp = date('Ymd_His');

$roleLabels = ['admin' => '管理员', 'finance' => '财务', 'leader' => '组长', 'staff' => '普通员工'];
$paymentLabels = ['cash' => '现金', 'wechat' => '微信', 'alipay' => '支付宝', 'balance' => '会员余额', 'other' => '其他', 'complaint' => '客诉'];
$recordTypeLabels = ['sale' => '消费', 'complaint_redo' => '客诉重做', 'points_redeem' => '积分兑换', 'exchange' => '换货'];

if ($type === 'staff') {
    $accounts = getStaffAccounts();
    $rows = [];
    foreach ($accounts as $username => $account) {
        $rows[] = [
            $username,
            (string) ($account['name'] ?? ''),
            $roleLabels[(string) ($account['role'] ?? 'staff')] ?? (string) ($account['role'] ?? ''),
            !empty($account['staff_access']) ? '是' : '否',
            !empty($account['frozen']) ? '已冻结' : '正常',
            (string) ($account['frozen_at'] ?? ''),
        ];
    }
    logOperation($pdo, 'data_export', 'system', null, '导出员工列表（' . count($rows) . ' 条）');
    csvDownload('员工列表_' . $stamp . '.csv', 'staff_' . $stamp . '.csv', ['账号', '姓名', '角色', '普通员工权限', '状态', '冻结时间'], $rows);
}

if ($type === 'members') {
    $rows = [];
    $stmt = $pdo->query('SELECT * FROM member ORDER BY id ASC');
    foreach ($stmt as $m) {
        $rows[] = [
            (int) $m['id'],
            (string) $m['name'],
            (string) $m['phone'],
            (string) ($m['student_no'] ?? ''),
            genderLabel((int) ($m['gender'] ?? 0)),
            (string) ($m['birthday'] ?? ''),
            (string) $m['level'],
            number_format((float) $m['balance'], 2, '.', ''),
            (int) $m['points'],
            number_format((float) $m['total_spent'], 2, '.', ''),
            (int) $m['status'] === STATUS_NORMAL ? '正常' : '已冻结',
            (string) $m['create_time'],
            (string) ($m['note'] ?? ''),
        ];
    }
    logOperation($pdo, 'data_export', 'system', null, '导出会员列表（' . count($rows) . ' 条）');
    csvDownload('会员列表_' . $stamp . '.csv', 'members_' . $stamp . '.csv', ['ID', '姓名', '手机号', '学号', '性别', '生日', '等级', '余额', '积分', '累计消费', '状态', '注册时间', '备注'], $rows);
}

if ($type === 'consumption') {
    $to = trim((string) ($_GET['to'] ?? '')) ?: date('Y-m-d');
    $from = trim((string) ($_GET['from'] ?? '')) ?: date('Y-m-d', strtotime($to . ' -90 days'));

    $stmt = $pdo->prepare('SELECT c.*, m.name AS member_name, m.phone AS member_phone FROM consumption c LEFT JOIN member m ON m.id = c.member_id WHERE date(c.create_time) BETWEEN ? AND ? ORDER BY c.id DESC');
    $stmt->execute([$from, $to]);

    $rows = [];
    foreach ($stmt as $row) {
        $refundState = '未退款';
        if (!empty($row['refunded_at'])) {
            $refundState = '已退款 ' . number_format((float) $row['refunded_amount'], 2, '.', '');
        }
        $rows[] = [
            (int) $row['id'],
            (string) $row['create_time'],
            $recordTypeLabels[(string) $row['record_type']] ?? (string) $row['record_type'],
            (string) ($row['member_name'] ?? '散客'),
            (string) ($row['member_phone'] ?? ''),
            normalizeConsumptionItemsText((string) $row['items']),
            number_format((float) $row['amount'], 2, '.', ''),
            number_format((float) $row['cost_amount'], 2, '.', ''),
            number_format((float) $row['net_income'], 2, '.', ''),
            $paymentLabels[(string) $row['payment_method']] ?? (string) $row['payment_method'],
            number_format((float) $row['coupon_discount'], 2, '.', ''),
            (int) $row['points_earned'],
            (int) $row['points_spent'],
            (string) ($row['ticket_number'] ?? ''),
            (string) ($row['operator'] ?? ''),
            !empty($row['exchanged_at']) ? '已换货' : '',
            $refundState,
            (string) ($row['note'] ?? ''),
        ];
    }
    logOperation($pdo, 'data_export', 'system', null, '导出消费记录（' . $from . ' 至 ' . $to . '，' . count($rows) . ' 条）');
    csvDownload('消费记录_' . $from . '_' . $to . '.csv', 'consumption_' . $from . '_' . $to . '.csv', ['ID', '时间', '类型', '会员', '手机号', '商品', '金额', '商品成本', '净收入', '支付方式', '优惠', '获得积分', '消耗积分', '号牌', '操作人', '换货', '退款状态', '备注'], $rows);
}

flash('error', '请选择要导出的数据');
redirect('/admin/settings.php');

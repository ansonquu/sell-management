<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
requireLogin();

$pdo = getDb();
$staff = getCurrentStaff() ?? [];
$errors = [];
$selectedDate = (string) ($_POST['report_date'] ?? $_GET['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}
$reference = getDailyIncomeReference($pdo, $selectedDate);
$amountFields = [
    'cash' => '现金',
    'wechat' => '微信',
    'alipay' => '支付宝',
    'bank' => '银行卡',
    'balance' => '会员余额消费',
    'other' => '其他',
];

$ownStmt = $pdo->prepare('SELECT * FROM daily_income_reports WHERE report_date = ? AND operator_username = ?');
$ownStmt->execute([$selectedDate, $staff['username'] ?? '']);
$ownReport = $ownStmt->fetch() ?: null;

// 上报冲正（财务 / 管理员）：输入三个支付渠道的实际金额，生成冲正记录，原上报保留
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reversal') {
    $reversalErrors = [];
    if (!verifyFinanceCsrf()) {
        $reversalErrors[] = '页面已过期，请刷新后重试';
    }
    if (!canManageFinance()) {
        $reversalErrors[] = '您没有上报冲正权限';
    }
    if ($selectedDate > date('Y-m-d')) {
        $reversalErrors[] = '不能冲正未来日期';
    }

    $reversalAmounts = [];
    foreach (['cash', 'wechat', 'alipay'] as $countedField) {
        $raw = $_POST['reversal_' . $countedField] ?? 0;
        if (!is_numeric($raw) || (float) $raw < 0) {
            $reversalErrors[] = $amountFields[$countedField] . '金额不能小于 0';
        }
        $reversalAmounts[$countedField] = round(max(0, (float) $raw), 2);
    }
    $reversalReason = trim((string) ($_POST['reversal_reason'] ?? ''));
    if ($reversalReason === '') {
        $reversalErrors[] = '请填写冲正原因';
    }

    if ($reversalErrors) {
        flash('error', '冲正失败：' . implode('；', $reversalErrors));
        redirect('/daily-report/?date=' . urlencode($selectedDate));
    }

    try {
        $reversalReference = getDailyIncomeReference($pdo, $selectedDate);
        $reversalTotal = round(array_sum($reversalAmounts), 2);
        $reversalDifference = round($reversalTotal - (float) $reversalReference['total'], 2);
        $reversalNote = encryptFinanceData(['note' => '【冲正】' . $reversalReason]);
        $reversalSnapshot = json_encode($reversalReference, JSON_UNESCAPED_UNICODE);

        // 冲正记录使用独立标识，避免与当天本人的正常上报争用 UNIQUE(report_date, operator_username)
        $reversalOwner = 'reversal:' . (string) ($staff['username'] ?? '');
        $existingStmt = $pdo->prepare('SELECT id FROM daily_income_reports WHERE report_date = ? AND operator_username = ?');
        $existingStmt->execute([$selectedDate, $reversalOwner]);
        $existingReversal = $existingStmt->fetch();

        if ($existingReversal) {
            $stmt = $pdo->prepare('UPDATE daily_income_reports SET cash_amount = ?, wechat_amount = ?, alipay_amount = ?, bank_amount = 0, balance_amount = 0, other_amount = 0, reported_total = ?, system_total = ?, difference_amount = ?, system_snapshot = ?, encrypted_note = ?, operator_name = ?, updated_at = ? WHERE id = ?');
            $stmt->execute([
                $reversalAmounts['cash'], $reversalAmounts['wechat'], $reversalAmounts['alipay'],
                $reversalTotal, (float) $reversalReference['total'], $reversalDifference, $reversalSnapshot, $reversalNote,
                $staff['name'] ?? '', currentDateTime(), (int) $existingReversal['id'],
            ]);
            $reversalId = (int) $existingReversal['id'];
            $reversalAction = 'daily_income_report_reversal_update';
            $reversalDescription = '更新每日收入冲正：' . $selectedDate;
        } else {
            $stmt = $pdo->prepare('INSERT INTO daily_income_reports (report_date, cash_amount, wechat_amount, alipay_amount, bank_amount, balance_amount, other_amount, reported_total, system_total, difference_amount, system_snapshot, encrypted_note, operator_username, operator_name, is_reversal, created_at, updated_at) VALUES (?, ?, ?, ?, 0, 0, 0, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)');
            $stmt->execute([
                $selectedDate, $reversalAmounts['cash'], $reversalAmounts['wechat'], $reversalAmounts['alipay'],
                $reversalTotal, (float) $reversalReference['total'], $reversalDifference, $reversalSnapshot, $reversalNote,
                $reversalOwner, $staff['name'] ?? '', currentDateTime(), currentDateTime(),
            ]);
            $reversalId = (int) $pdo->lastInsertId();
            $reversalAction = 'daily_income_report_reversal_create';
            $reversalDescription = '每日收入上报冲正：' . $selectedDate;
        }

        logOperation($pdo, $reversalAction, 'daily_income_report', $reversalId, $reversalDescription, [
            'report_date' => $selectedDate,
            'cash' => $reversalAmounts['cash'],
            'wechat' => $reversalAmounts['wechat'],
            'alipay' => $reversalAmounts['alipay'],
            'reported_total' => $reversalTotal,
            'system_total' => (float) $reversalReference['total'],
            'difference_amount' => $reversalDifference,
            'reason' => $reversalReason,
        ]);
        flash('success', '上报冲正已保存：三渠道合计 ' . formatMoney($reversalTotal) . '，与系统参考差异 ' . formatMoney($reversalDifference));
        redirect('/daily-report/?date=' . urlencode($selectedDate));
    } catch (Throwable $e) {
        flash('error', '冲正保存失败：' . $e->getMessage());
        redirect('/daily-report/?date=' . urlencode($selectedDate));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyFinanceCsrf()) {
        $errors[] = '页面已过期，请刷新后重试';
    }
    if ($selectedDate > date('Y-m-d')) {
        $errors[] = '不能上报未来日期的收入';
    }

    $amounts = [];
    foreach ($amountFields as $field => $label) {
        $raw = $_POST[$field . '_amount'] ?? 0;
        if (!is_numeric($raw) || (float) $raw < 0) {
            $errors[] = $label . '金额不能小于 0';
        }
        $amounts[$field] = round(max(0, (float) $raw), 2);
    }
    // 上报合计只计算现金、微信、支付宝三个渠道（其余字段仅登记，不计入计算）
    $reportedTotal = round($amounts['cash'] + $amounts['wechat'] + $amounts['alipay'], 2);
    $reference = getDailyIncomeReference($pdo, $selectedDate);
    $difference = round($reportedTotal - (float) $reference['total'], 2);

    if (!$errors) {
        try {
            $encryptedNote = encryptFinanceData(['note' => trim((string) ($_POST['note'] ?? ''))]);
            $values = [
                $amounts['cash'], $amounts['wechat'], $amounts['alipay'], $amounts['bank'], $amounts['balance'], $amounts['other'],
                $reportedTotal, (float) $reference['total'], $difference,
                json_encode($reference, JSON_UNESCAPED_UNICODE), $encryptedNote,
                $staff['name'] ?? '', currentDateTime(),
            ];

            if ($ownReport) {
                $stmt = $pdo->prepare('UPDATE daily_income_reports SET cash_amount = ?, wechat_amount = ?, alipay_amount = ?, bank_amount = ?, balance_amount = ?, other_amount = ?, reported_total = ?, system_total = ?, difference_amount = ?, system_snapshot = ?, encrypted_note = ?, operator_name = ?, updated_at = ? WHERE id = ? AND operator_username = ?');
                $stmt->execute([...$values, (int) $ownReport['id'], $staff['username'] ?? '']);
                $reportId = (int) $ownReport['id'];
                $action = 'daily_income_report_update';
                $description = '更新每日收入上报：' . $selectedDate;
            } else {
                $stmt = $pdo->prepare('INSERT INTO daily_income_reports (report_date, cash_amount, wechat_amount, alipay_amount, bank_amount, balance_amount, other_amount, reported_total, system_total, difference_amount, system_snapshot, encrypted_note, operator_username, operator_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$selectedDate, ...array_slice($values, 0, 11), $staff['username'] ?? '', $staff['name'] ?? '', currentDateTime(), currentDateTime()]);
                $reportId = (int) $pdo->lastInsertId();
                $action = 'daily_income_report_create';
                $description = '提交每日收入上报：' . $selectedDate;
            }

            logOperation($pdo, $action, 'daily_income_report', $reportId, $description, [
                'report_date' => $selectedDate,
                'reported_total' => $reportedTotal,
                'system_total' => (float) $reference['total'],
                'difference_amount' => $difference,
            ]);

            // 实报与系统差异超过 ¥20 时，通知所有管理员
            if (abs($difference) > 20) {
                foreach (adminUsernames() as $adminUsername) {
                    notifyStaff(
                        $adminUsername,
                        '每日收入上报差异提醒',
                        ($staff['name'] ?? $staff['username'] ?? '员工') . ' 上报 ' . $selectedDate . ' 收入 ' . formatMoney($reportedTotal)
                            . '，与系统参考 ' . formatMoney((float) $reference['total']) . ' 差异 ' . formatMoney($difference) . '（超过 ¥20）',
                        '/daily-report/?date=' . urlencode($selectedDate)
                    );
                }
            }

            flash('success', '每日收入上报已保存，实报与系统记录差异：' . formatMoney($difference));
            redirect('/daily-report/?date=' . urlencode($selectedDate));
        } catch (Throwable $e) {
            $errors[] = '上报保存失败：' . $e->getMessage();
        }
    }
}

$where = canManageFinance() ? '' : 'WHERE operator_username = ?';
$historyStmt = $pdo->prepare("SELECT * FROM daily_income_reports {$where} ORDER BY report_date DESC, updated_at DESC LIMIT 180");
$historyStmt->execute(canManageFinance() ? [] : [$staff['username'] ?? '']);
$history = $historyStmt->fetchAll();

// 当天的冲正记录（原上报保留，冲正作为独立记录展示）
$reversalStmt = $pdo->prepare('SELECT * FROM daily_income_reports WHERE report_date = ? AND is_reversal = 1 ORDER BY updated_at DESC');
$reversalStmt->execute([$selectedDate]);
$dayReversals = $reversalStmt->fetchAll();

$formValues = [];
foreach ($amountFields as $field => $label) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $formValues[$field] = (float) ($_POST[$field . '_amount'] ?? 0);
    } elseif ($ownReport) {
        $formValues[$field] = (float) $ownReport[$field . '_amount'];
    } else {
        $formValues[$field] = (float) ($reference[$field] ?? 0);
    }
}
$existingNote = $ownReport ? (decryptFinanceData($ownReport['encrypted_note'] ?? '')['note'] ?? '') : '';

renderHeader('每日收入上报 - Anson咖啡厅管理系统', 'daily-report');
?>
<div class="page-header"><h2 class="page-title">每日收入上报</h2></div>
<?php if (canManageFinance()) renderFinanceTabs('daily'); ?>
<?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $error): ?><div><?= htmlspecialchars($error) ?></div><?php endforeach; ?></div><?php endif; ?>

<form class="search-bar" method="get"><label>上报日期</label><input type="date" name="date" max="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($selectedDate) ?>"><button class="btn btn-secondary">切换日期</button><a class="btn btn-link" href="/daily-report/">今天</a></form>

<div class="grid-2">
    <div class="card form-card">
        <h3><?= $ownReport ? '更新我的上报' : '提交收入上报' ?></h3>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeCsrfToken()) ?>">
            <input type="hidden" name="report_date" value="<?= htmlspecialchars($selectedDate) ?>">
            <?php foreach ($amountFields as $field => $label): $isCounted = in_array($field, ['cash', 'wechat', 'alipay'], true); ?>
                <div class="form-group">
                    <label><?= htmlspecialchars($label) ?><?php if (!$isCounted): ?> <span class="text-muted">（不计入计算）</span><?php endif; ?></label>
                    <input type="number" min="0" step="0.01" name="<?= $field ?>_amount" value="<?= number_format($formValues[$field], 2, '.', '') ?>" required>
                    <small class="text-muted"><?php if ($isCounted): ?>系统参考：<?= formatMoney((float) ($reference[$field] ?? 0)) ?><?php else: ?>仅登记保存，不参与合计与差异计算<?php endif; ?></small>
                </div>
            <?php endforeach; ?>
            <div class="form-group"><label>差异说明/备注</label><textarea name="note" rows="3" placeholder="备注会加密保存"><?= htmlspecialchars($_POST['note'] ?? $existingNote) ?></textarea></div>
            <button class="btn btn-primary" type="submit"><?= $ownReport ? '更新上报' : '确认上报' ?></button>
        </form>
    </div>
    <div class="card">
        <h3><?= htmlspecialchars($selectedDate) ?> 系统参考</h3>
        <table class="table"><tbody><?php foreach ($amountFields as $field => $label): $isCounted = in_array($field, ['cash', 'wechat', 'alipay'], true); ?><tr><th><?= htmlspecialchars($label) ?><?php if (!$isCounted): ?> <span class="text-muted" style="font-weight:400;font-size:0.8rem;">（不计入）</span><?php endif; ?></th><td><?= formatMoney((float) ($reference[$field] ?? 0)) ?></td></tr><?php endforeach; ?><tr><th>三渠道参考合计</th><td><strong><?= formatMoney((float) $reference['total']) ?></strong></td></tr></tbody></table>
        <p class="text-muted">计算口径：<strong>现金、微信、支付宝</strong>三个渠道的消费登记 + 会员充值。银行卡、会员余额消费及其他渠道不计入计算（表单中仍可登记，仅供记录）。</p>
    </div>
</div>


<?php if (canManageFinance()): $todayReversal = $dayReversals[0] ?? null; ?>
<div class="card form-card">
    <h3>上报冲正 <span class="text-muted" style="font-weight:400;font-size:0.85rem;"><?= htmlspecialchars($selectedDate) ?></span></h3>
    <p class="settings-help">输入当天<strong>现金、微信、支付宝</strong>三个渠道的实际金额生成冲正记录；原始上报保留，历史中会标记「冲正」。同一日期同一账号重复提交会更新该冲正记录。</p>
    <form method="post" onsubmit="return confirm('确认提交上报冲正？')">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeCsrfToken()) ?>">
        <input type="hidden" name="action" value="reversal">
        <input type="hidden" name="report_date" value="<?= htmlspecialchars($selectedDate) ?>">
        <div class="reversal-fields">
            <?php foreach (['cash' => '现金', 'wechat' => '微信', 'alipay' => '支付宝'] as $reversalField => $reversalLabel): ?>
                <div class="form-group">
                    <label><?= $reversalLabel ?></label>
                    <input type="number" min="0" step="0.01" name="reversal_<?= $reversalField ?>" value="<?= number_format((float) ($todayReversal[$reversalField . '_amount'] ?? $reference[$reversalField] ?? 0), 2, '.', '') ?>" required>
                    <small class="text-muted">系统参考：<?= formatMoney((float) ($reference[$reversalField] ?? 0)) ?></small>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="form-group"><label>冲正原因 <span class="required">*</span></label><textarea name="reversal_reason" rows="2" required placeholder="例如：实收与系统记录不符，经核对修正"><?= htmlspecialchars($_POST['reversal_reason'] ?? '') ?></textarea></div>
        <button class="btn btn-warning" type="submit"><?= $todayReversal ? '更新冲正' : '提交冲正' ?></button>
    </form>
    <?php if ($todayReversal): ?>
        <p class="form-help">当天已有冲正记录：<?= htmlspecialchars($todayReversal['operator_name'] ?: $todayReversal['operator_username']) ?> 于 <?= formatDateTime((string) $todayReversal['updated_at']) ?> 提交，三渠道合计 <?= formatMoney((float) $todayReversal['reported_total']) ?>（现金 <?= formatMoney((float) $todayReversal['cash_amount']) ?> / 微信 <?= formatMoney((float) $todayReversal['wechat_amount']) ?> / 支付宝 <?= formatMoney((float) $todayReversal['alipay_amount']) ?>）。</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card table-scroll"><h3><?= canManageFinance() ? '全部上报记录' : '我的上报记录' ?></h3><table class="table"><thead><tr><th>日期</th><?php if (canManageFinance()): ?><th>上报人</th><?php endif; ?><th>实报合计</th><th>系统金额</th><th>差异</th><th>备注</th><th>更新时间</th></tr></thead><tbody>
<?php if (!$history): ?><tr><td colspan="<?= canManageFinance() ? 7 : 6 ?>" class="text-center text-muted">暂无上报记录</td></tr><?php endif; ?>
<?php foreach ($history as $row): $note = decryptFinanceData($row['encrypted_note'] ?? ''); ?><tr<?= !empty($row['is_reversal']) ? ' class="is-hot-row"' : '' ?>><td><a href="/daily-report/?date=<?= urlencode($row['report_date']) ?>"><?= htmlspecialchars($row['report_date']) ?></a><?php if (!empty($row['is_reversal'])): ?> <span class="badge badge-warning">冲正</span><?php endif; ?></td><?php if (canManageFinance()): ?><td><?= htmlspecialchars($row['operator_name'] ?: $row['operator_username']) ?></td><?php endif; ?><td><?= formatMoney((float) $row['reported_total']) ?></td><td><?= formatMoney((float) $row['system_total']) ?></td><td class="<?= abs((float) $row['difference_amount']) > 0.009 ? 'text-danger' : 'text-success' ?>"><?= formatMoney((float) $row['difference_amount']) ?></td><td><?= htmlspecialchars($note['note'] ?? '-') ?></td><td><?= formatDateTime($row['updated_at']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php renderFooter(); ?>

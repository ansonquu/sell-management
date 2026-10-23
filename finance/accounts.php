<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
requireLogin();
requireFinance();

$pdo = getDb();
$errors = [];
$staff = getCurrentStaff() ?? [];
$accountOptions = financeTransferAccountOptions($pdo);
$balances = getFinanceAccountBalances($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyFinanceCsrf()) {
        $errors[] = '页面已过期，请刷新后重试';
    } else {
        $action = (string) ($_POST['action'] ?? 'transfer');
        if ($action === 'toggle') {
            $id = (int) ($_POST['id'] ?? 0);
            $status = ($_POST['status'] ?? '') === 'active' ? 'active' : 'inactive';
            $stmt = $pdo->prepare('UPDATE finance_accounts SET status = ? WHERE id = ?');
            $stmt->execute([$status, $id]);
            logOperation($pdo, 'finance_account_status', 'finance_account', $id, ($status === 'active' ? '启用' : '停用') . '财务账户 #' . $id);
            flash('success', '账户状态已更新');
            redirect('/finance/accounts.php');
        }

        // 管理员直接调整账户余额（差额自动生成一条「余额调整」流水，可追溯）
        if ($action === 'adjust') {
            if (!isAdmin()) {
                $errors[] = '只有管理员可以调整账户余额';
            }
            $adjustCode = (string) ($_POST['account'] ?? '');
            $targetBalance = round((float) ($_POST['target_balance'] ?? 0), 2);
            $adjustReason = trim((string) ($_POST['reason'] ?? ''));

            if (!isset($accountOptions[$adjustCode])) {
                $errors[] = '请选择有效的账户';
            }
            if ($adjustReason === '') {
                $errors[] = '请填写调整原因';
            }
            if ($targetBalance < 0) {
                $errors[] = '调整后余额不能为负数';
            }

            if (!$errors) {
                $currentBalance = round((float) ($balances[$adjustCode] ?? 0), 2);
                $difference = round($targetBalance - $currentBalance, 2);

                if (abs($difference) < 0.009) {
                    $errors[] = '调整后余额与当前余额相同，无需调整';
                } else {
                    $adjustType = $difference > 0 ? 'income' : 'expense';
                    $adjustCategory = array_key_exists('balance_adjust', financeExpenseCategories()) ? 'balance_adjust' : 'other';
                    $encryptedPayload = encryptFinanceData([
                        'note' => '余额调整：' . $adjustReason . '（' . formatMoney($currentBalance) . ' → ' . formatMoney($targetBalance) . '）',
                    ]);
                    $stmt = $pdo->prepare("INSERT INTO finance_expenses (type, category, amount, payment_account, occurred_at, encrypted_payload, status, operator_username, operator_name, created_at) VALUES (?, ?, ?, ?, ?, ?, 'active', ?, ?, ?)");
                    $stmt->execute([
                        $adjustType,
                        $adjustCategory,
                        abs($difference),
                        $adjustCode,
                        date('Y-m-d H:i:s'),
                        $encryptedPayload,
                        $staff['username'] ?? '',
                        $staff['name'] ?? '',
                        currentDateTime(),
                    ]);
                    $expenseId = (int) $pdo->lastInsertId();
                    logOperation($pdo, 'finance_balance_adjust', 'finance_account', $expenseId,
                        '调整账户余额：' . financeAccountLabel($pdo, $adjustCode) . ' ' . formatMoney($currentBalance) . ' → ' . formatMoney($targetBalance),
                        ['account' => $adjustCode, 'from' => $currentBalance, 'to' => $targetBalance, 'reason' => $adjustReason]);
                    flash('success', '已将「' . financeAccountLabel($pdo, $adjustCode) . '」余额调整为 ' . formatMoney($targetBalance) . '（差额 ' . formatMoney($difference) . '）');
                    redirect('/finance/accounts.php');
                }
            }
        }

        $fromAccount = (string) ($_POST['from_account'] ?? '');
        $toAccount = (string) ($_POST['to_account'] ?? '');
        $amount = round((float) ($_POST['amount'] ?? 0), 2);
        $occurredDate = (string) ($_POST['occurred_date'] ?? date('Y-m-d'));
        if (!isset($accountOptions[$fromAccount]) || !isset($accountOptions[$toAccount])) {
            $errors[] = '请选择有效的转出和转入账户';
        } elseif ($fromAccount === $toAccount) {
            $errors[] = '转出账户和转入账户不能相同';
        }
        if ($amount <= 0) {
            $errors[] = '划转金额必须大于 0';
        } elseif (isset($balances[$fromAccount]) && $amount > (float) $balances[$fromAccount] + 0.009) {
            $errors[] = '划转金额不能超过转出账户当前估算余额 ' . formatMoney((float) $balances[$fromAccount]);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $occurredDate) || $occurredDate > date('Y-m-d')) {
            $errors[] = '请选择有效且不晚于今天的划转日期';
        }

        if (!$errors) {
            try {
                $encryptedNote = encryptFinanceData(['note' => trim((string) ($_POST['note'] ?? ''))]);
                $stmt = $pdo->prepare('INSERT INTO finance_transfers (from_account, to_account, amount, occurred_at, encrypted_note, operator_username, operator_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$fromAccount, $toAccount, $amount, $occurredDate . ' ' . date('H:i:s'), $encryptedNote, $staff['username'] ?? '', $staff['name'] ?? '', currentDateTime()]);
                $transferId = (int) $pdo->lastInsertId();
                logOperation($pdo, 'finance_transfer_create', 'finance_transfer', $transferId, '资金划转：' . financeAccountLabel($pdo, $fromAccount) . ' → ' . financeAccountLabel($pdo, $toAccount) . ' ' . formatMoney($amount), [
                    'from_account' => $fromAccount,
                    'to_account' => $toAccount,
                    'amount' => $amount,
                    'occurred_date' => $occurredDate,
                ]);
                flash('success', '资金划转完成：' . financeAccountLabel($pdo, $fromAccount) . ' → ' . financeAccountLabel($pdo, $toAccount) . ' ' . formatMoney($amount));
                redirect('/finance/accounts.php');
            } catch (Throwable $e) {
                $errors[] = '资金划转失败：' . $e->getMessage();
            }
        }
    }
}

$balances = getFinanceAccountBalances($pdo);
$accounts = $pdo->query('SELECT * FROM finance_accounts ORDER BY status ASC, id DESC')->fetchAll();
foreach ($accounts as &$account) {
    $account['current_balance'] = (float) ($balances['account:' . $account['id']] ?? 0);
}
unset($account);
$transfers = $pdo->query('SELECT * FROM finance_transfers ORDER BY occurred_at DESC, id DESC LIMIT 200')->fetchAll();

renderHeader('资金账户 - 财务管理', 'finance');
?>
<div class="page-header"><h2 class="page-title">资金账户</h2></div>
<?php renderFinanceTabs('accounts'); ?>
<?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $error): ?><div><?= htmlspecialchars($error) ?></div><?php endforeach; ?></div><?php endif; ?>

<div class="stats-grid">
<?php foreach ($accountOptions as $code => $label): ?>
    <div class="stat-card small"><div class="stat-value"><?= formatMoney((float) ($balances[$code] ?? 0)) ?></div><div class="stat-label"><?= htmlspecialchars($label) ?>当前估算余额</div></div>
<?php endforeach; ?>
</div>

<div class="grid-2">
    <div class="card form-card"><h3>资金划转</h3><form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeCsrfToken()) ?>"><input type="hidden" name="action" value="transfer">
        <div class="form-group"><label>划转日期 <span class="required">*</span></label><input type="date" name="occurred_date" max="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($_POST['occurred_date'] ?? date('Y-m-d')) ?>" required></div>
        <div class="form-group"><label>转出账户 <span class="required">*</span></label><select name="from_account" required><option value="">请选择</option><?php foreach ($accountOptions as $code => $label): ?><option value="<?= htmlspecialchars($code) ?>" <?= ($_POST['from_account'] ?? '') === $code ? 'selected' : '' ?>><?= htmlspecialchars($label) ?>（<?= formatMoney((float) ($balances[$code] ?? 0)) ?>）</option><?php endforeach; ?></select></div>
        <div class="form-group"><label>转入账户 <span class="required">*</span></label><select name="to_account" required><option value="">请选择</option><?php foreach ($accountOptions as $code => $label): ?><option value="<?= htmlspecialchars($code) ?>" <?= ($_POST['to_account'] ?? '') === $code ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>划转金额 <span class="required">*</span></label><input type="number" name="amount" min="0.01" step="0.01" value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>" required></div>
        <div class="form-group"><label>划转备注</label><textarea name="note" rows="3" placeholder="备注会加密保存"><?= htmlspecialchars($_POST['note'] ?? '') ?></textarea></div>
        <button class="btn btn-primary" type="submit">确认划转</button>
    </form></div>
    <div class="card"><h3>划转规则</h3><p>划转只是在两个资金账户之间移动金额，不会增加收入、支出或利润。</p><p>划转金额不能超过转出账户当前估算余额，完成后两个账户余额会同步更新。</p><p class="text-muted">页面不再提供添加自定义账户；已有账户及历史流水继续保留。划转备注采用加密存储。</p></div>
</div>

<?php if (isAdmin()): ?>
<div class="card form-card">
    <h3>调整账户余额 <span class="badge badge-danger">仅管理员</span></h3>
    <p class="text-muted" style="margin-bottom:1rem">直接设定某账户的当前余额。系统会自动生成一笔「余额调整」流水（差额计入收入或支出），账目可追溯。</p>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="adjust">
        <div class="form-group">
            <label>账户 <span class="required">*</span></label>
            <select name="account" required>
                <?php foreach ($accountOptions as $code => $label): ?>
                    <option value="<?= htmlspecialchars($code) ?>">
                        <?= htmlspecialchars($label) ?>（当前 <?= formatMoney((float) ($balances[$code] ?? 0)) ?>）
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>调整后余额（元） <span class="required">*</span></label>
            <input type="number" name="target_balance" step="0.01" min="0" required placeholder="输入该账户实际应有的余额">
        </div>
        <div class="form-group">
            <label>调整原因 <span class="required">*</span></label>
            <input type="text" name="reason" maxlength="100" required placeholder="例如：盘点现金差异、补录漏记的收款">
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-danger">确认调整余额</button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="card table-scroll"><h3>资金划转记录</h3><table class="table"><thead><tr><th>时间</th><th>转出账户</th><th>转入账户</th><th>金额</th><th>备注</th><th>操作人</th></tr></thead><tbody>
<?php if (!$transfers): ?><tr><td colspan="6" class="text-center text-muted">暂无资金划转记录</td></tr><?php endif; ?>
<?php foreach ($transfers as $row): $note = decryptFinanceData($row['encrypted_note'] ?? ''); ?><tr><td><?= formatDateTime($row['occurred_at']) ?></td><td><?= htmlspecialchars(financeAccountLabel($pdo, $row['from_account'])) ?></td><td><?= htmlspecialchars(financeAccountLabel($pdo, $row['to_account'])) ?></td><td><?= formatMoney((float) $row['amount']) ?></td><td><?= htmlspecialchars($note['note'] ?? '-') ?></td><td><?= htmlspecialchars($row['operator_name'] ?: $row['operator_username']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>

<?php if ($accounts): ?><div class="card table-scroll"><h3>已有自定义账户</h3><table class="table"><thead><tr><th>名称</th><th>类型</th><th>当前估算余额</th><th>状态</th><th>操作</th></tr></thead><tbody><?php foreach ($accounts as $row): ?><tr><td><?= htmlspecialchars($row['name']) ?></td><td><?= htmlspecialchars(financePaymentLabel($row['type'])) ?></td><td><?= formatMoney((float) $row['current_balance']) ?></td><td><?= $row['status'] === 'active' ? '<span class="badge badge-success">启用</span>' : '<span class="badge badge-secondary">停用</span>' ?></td><td><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeCsrfToken()) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="status" value="<?= $row['status'] === 'active' ? 'inactive' : 'active' ?>"><button class="btn btn-sm btn-secondary"><?= $row['status'] === 'active' ? '停用' : '启用' ?></button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php renderFooter(); ?>

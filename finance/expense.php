<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
requireLogin();
requireFinance();

$pdo = getDb();
$errors = [];
$staff = getCurrentStaff() ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyFinanceCsrf()) {
        $errors[] = '页面已过期，请刷新后重试';
    } else {
        $action = (string) ($_POST['action'] ?? 'create');
        if ($action === 'reverse' || $action === 'return') {
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE finance_expenses SET status = ?, reversed_at = ?, reversed_by = ? WHERE id = ? AND status = 'active'");
            $stmt->execute([$action === 'reverse' ? 'reversed' : 'returned', currentDateTime(), $staff['username'] ?? '', $id]);
            if ($stmt->rowCount() > 0) {
                $row = $pdo->prepare('SELECT type, amount FROM finance_expenses WHERE id = ?');
                $row->execute([$id]);
                $info = $row->fetch() ?: [];
                $typeLabel = (($info['type'] ?? 'expense') === 'income') ? '收入' : '支出';
                if ($action === 'reverse') {
                    logOperation($pdo, 'finance_expense_reverse', 'finance_expense', $id, '冲正' . $typeLabel . '记录 #' . $id);
                    flash('success', $typeLabel . '已冲正，原记录已保留');
                } else {
                    logOperation($pdo, 'finance_expense_return', 'finance_expense', $id, '退回' . $typeLabel . '记录 #' . $id . '（' . formatMoney((float) ($info['amount'] ?? 0)) . '）');
                    flash('success', $typeLabel . '已退回，金额已退回对应账户，原记录保留');
                }
                redirect('/finance/expense.php');
            }
            $errors[] = '记录不存在或已处理';
        } else {
            $type = (string) ($_POST['type'] ?? 'expense');
            $category = (string) ($_POST['category'] ?? '');
            $amount = round((float) ($_POST['amount'] ?? 0), 2);
            $paymentAccount = (string) ($_POST['payment_account'] ?? 'cash');
            $occurredDate = (string) ($_POST['occurred_date'] ?? date('Y-m-d'));
            if (!in_array($type, ['expense', 'income'], true)) $errors[] = '请选择有效的收支类型';
            if (!isset(financeExpenseCategories()[$category])) $errors[] = '请选择有效的分类';
            if ($amount <= 0) $errors[] = ($type === 'income' ? '收入' : '支出') . '金额必须大于 0';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $occurredDate)) $errors[] = '请选择有效的日期';
            $validAccount = isset(financePaymentMethods()[$paymentAccount]);
            if (str_starts_with($paymentAccount, 'account:')) {
                $accountStmt = $pdo->prepare("SELECT COUNT(*) FROM finance_accounts WHERE id = ? AND status = 'active'");
                $accountStmt->execute([(int) substr($paymentAccount, 8)]);
                $validAccount = (int) $accountStmt->fetchColumn() > 0;
            }
            if (!$validAccount) $errors[] = '请选择有效的账户';

            if (!$errors) {
                try {
                    $payload = encryptFinanceData([
                        'counterparty' => trim((string) ($_POST['counterparty'] ?? '')),
                        'voucher_no' => trim((string) ($_POST['voucher_no'] ?? '')),
                        'note' => trim((string) ($_POST['note'] ?? '')),
                    ]);
                    $stmt = $pdo->prepare('INSERT INTO finance_expenses (type, category, amount, payment_account, occurred_at, encrypted_payload, operator_username, operator_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$type, $category, $amount, $paymentAccount, $occurredDate . ' ' . date('H:i:s'), $payload, $staff['username'] ?? '', $staff['name'] ?? '', currentDateTime()]);
                    $id = (int) $pdo->lastInsertId();
                    $typeLabel = $type === 'income' ? '收入' : '支出';
                    logOperation($pdo, 'finance_expense_create', 'finance_expense', $id, '登记' . $typeLabel . '：' . financeExpenseCategoryLabel($category) . ' ' . formatMoney($amount), ['type' => $type, 'category' => $category, 'amount' => $amount, 'payment_account' => $paymentAccount]);
                    flash('success', $typeLabel . '登记成功，敏感详情已加密保存');
                    redirect('/finance/expense.php');
                } catch (Throwable $e) {
                    $errors[] = '保存失败：' . $e->getMessage();
                }
            }
        }
    }
}

$customAccounts = $pdo->query("SELECT id, name FROM finance_accounts WHERE status = 'active' ORDER BY name")->fetchAll();
$records = $pdo->query('SELECT * FROM finance_expenses ORDER BY occurred_at DESC, id DESC LIMIT 200')->fetchAll();
renderHeader('收支管理 - 财务管理', 'finance');
?>
<div class="page-header"><h2 class="page-title">收支管理</h2></div>
<?php renderFinanceTabs('expense'); ?>
<?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $error): ?><div><?= htmlspecialchars($error) ?></div><?php endforeach; ?></div><?php endif; ?>
<div class="grid-2">
<div class="card form-card"><h3>登记收支</h3><form method="post">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeCsrfToken()) ?>"><input type="hidden" name="action" value="create">
<div class="form-group"><label>收支类型 <span class="required">*</span></label><div class="radio-group">
<label class="radio-item"><input type="radio" name="type" value="expense" <?= ($_POST['type'] ?? 'expense') === 'expense' ? 'checked' : '' ?>><span>支出</span></label>
<label class="radio-item"><input type="radio" name="type" value="income" <?= ($_POST['type'] ?? '') === 'income' ? 'checked' : '' ?>><span>收入</span></label>
</div></div>
<div class="form-group"><label>日期 <span class="required">*</span></label><input type="date" name="occurred_date" value="<?= htmlspecialchars($_POST['occurred_date'] ?? date('Y-m-d')) ?>" required></div>
<div class="form-group"><label>分类 <span class="required">*</span></label><select name="category" required><option value="">请选择</option><?php foreach (financeExpenseCategories() as $value => $label): ?><option value="<?= $value ?>" <?= ($_POST['category'] ?? '') === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select></div>
<div class="form-group"><label>金额（元） <span class="required">*</span></label><input type="number" min="0.01" step="0.01" name="amount" value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>" required></div>
<div class="form-group"><label>账户 <span class="required">*</span></label><select name="payment_account" required><?php foreach (financePaymentMethods() as $value => $label): if ($value === 'balance') continue; ?><option value="<?= $value ?>"><?= htmlspecialchars($label) ?></option><?php endforeach; ?><?php foreach ($customAccounts as $account): ?><option value="account:<?= (int) $account['id'] ?>"><?= htmlspecialchars($account['name']) ?></option><?php endforeach; ?></select></div>
<div class="form-group"><label>收付款方</label><input name="counterparty" value="<?= htmlspecialchars($_POST['counterparty'] ?? '') ?>" placeholder="加密保存"></div>
<div class="form-group"><label>票据/凭证号</label><input name="voucher_no" value="<?= htmlspecialchars($_POST['voucher_no'] ?? '') ?>" placeholder="加密保存"></div>
<div class="form-group"><label>备注</label><textarea name="note" rows="3" placeholder="加密保存"><?= htmlspecialchars($_POST['note'] ?? '') ?></textarea></div>
<button class="btn btn-primary" type="submit">保存收支</button>
</form></div>
<div class="card"><h3>安全说明</h3><p>金额、分类和日期用于报表统计；收付款方、凭证号和备注采用 AES-256-GCM 加密后写入数据文件。</p><p class="text-muted">财务记录不允许删除。登记错误时使用"冲正"；收入需退还时使用"退回"（金额退回对应账户），两者都会保留完整操作痕迹。</p></div>
</div>
<div class="card table-scroll"><h3>收支记录</h3><table class="table"><thead><tr><th>日期</th><th>类型</th><th>分类</th><th>金额</th><th>账户</th><th>收付款方/凭证</th><th>备注</th><th>登记人</th><th>状态</th><th>操作</th></tr></thead><tbody>
<?php if (!$records): ?><tr><td colspan="10" class="text-center text-muted">暂无记录</td></tr><?php endif; ?>
<?php foreach ($records as $row): $payload = decryptFinanceData($row['encrypted_payload'] ?? ''); $isIncome = (($row['type'] ?? 'expense') === 'income'); ?><tr class="<?= $row['status'] !== 'active' ? 'is-muted' : '' ?>"><td><?= formatDateTime($row['occurred_at']) ?></td><td><span class="badge <?= $isIncome ? 'badge-success' : 'badge-warning' ?>"><?= $isIncome ? '收入' : '支出' ?></span></td><td><?= htmlspecialchars(financeExpenseCategoryLabel($row['category'])) ?></td><td class="<?= $isIncome ? 'text-success' : '' ?>"><?= $isIncome ? '+' : '' ?><?= formatMoney((float) $row['amount']) ?></td><td><?= htmlspecialchars(financeAccountLabel($pdo, $row['payment_account'])) ?></td><td><?= htmlspecialchars($payload['counterparty'] ?? '-') ?><small class="text-muted"><?= htmlspecialchars($payload['voucher_no'] ?? '') ?></small></td><td><?= htmlspecialchars($payload['note'] ?? '-') ?></td><td><?= htmlspecialchars($row['operator_name'] ?: '-') ?></td><td><?php if ($row['status'] === 'active'): ?><span class="badge badge-success">有效</span><?php elseif ($row['status'] === 'returned'): ?><span class="badge badge-warning">已退回</span><?php else: ?><span class="badge badge-secondary">已冲正</span><?php endif; ?></td><td><?php if ($row['status'] === 'active'): ?>
<div class="inline-reset-form">
<form method="post" onsubmit="return confirm('确认冲正这笔记录？原记录会保留。')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeCsrfToken()) ?>"><input type="hidden" name="action" value="reverse"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="btn btn-sm btn-warning">冲正</button></form>
<form method="post" onsubmit="return confirm('确认退回这笔<?= $isIncome ? '收入' : '支出' ?>？金额将退回对应账户，原记录保留。')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeCsrfToken()) ?>"><input type="hidden" name="action" value="return"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="btn btn-sm btn-danger">退回</button></form>
</div>
<?php else: ?>-<?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php renderFooter(); ?>

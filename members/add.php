<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';

$pdo = getDb();
$errors = [];
$withRecharge = false;
$rechargeAmount = 0.0;
$rechargePayment = 'cash';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $phone = trim($_POST['phone'] ?? '');
    $studentNo = trim($_POST['student_no'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $gender = (int) ($_POST['gender'] ?? 0);
    $birthday = trim($_POST['birthday'] ?? '') ?: null;
    $note = trim($_POST['note'] ?? '');
    $withRecharge = ($_POST['with_recharge'] ?? '0') === '1';
    $rechargeAmount = round((float) ($_POST['recharge_amount'] ?? 0), 2);
    $rechargePayment = (string) ($_POST['recharge_payment'] ?? 'cash');
    if (!in_array($rechargePayment, ['cash', 'wechat', 'alipay'], true)) {
        $rechargePayment = 'cash';
    }

    if ($phone !== '' && ($existingMember = getMemberByPhone($pdo, $phone))) {
        $errors[] = trim((string) $existingMember['name']) === $name
            ? '姓名和手机号与已有会员完全一致，请勿重复注册'
            : '该手机号已由其他会员注册，请核对姓名';
    }

    if ($studentNo !== '' && getMemberByStudentNo($pdo, $studentNo)) {
        $errors[] = '该学号已注册';
    }

    if ($name === '') {
        $errors[] = '姓名不能为空';
    }

    if ($withRecharge && $rechargeAmount <= 0) {
        $errors[] = '选择同时充值后，请填写大于 0 的充值金额';
    }

    if (empty($errors)) {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('
                INSERT INTO member (phone, student_no, name, gender, birthday, note, level, create_time)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            // 学号为选填：留空时存 NULL，避免 UNIQUE 约束下多个空字符串互相冲突
            $stmt->execute([$phone !== '' ? $phone : null, $studentNo !== '' ? $studentNo : null, $name, $gender, $birthday, $note, '普通会员', currentDateTime()]);
            $newMemberId = (int) $pdo->lastInsertId();
            logOperation($pdo, 'member_create', 'member', $newMemberId, '新建会员：' . $name, [
                'name' => $name,
                'phone' => $phone,
                'student_no' => $studentNo,
                'has_note' => $note !== '',
            ]);

            // 注册时一并充值
            if ($withRecharge) {
                $operator = (string) (($staff = getCurrentStaff()) ? ($staff['name'] ?? '') : '');
                $stmt = $pdo->prepare('
                    INSERT INTO recharge (member_id, amount, balance_after, note, payment_method, operator, create_time)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([$newMemberId, $rechargeAmount, $rechargeAmount, '注册时一并充值', $rechargePayment, $operator, currentDateTime()]);

                $pdo->prepare('UPDATE member SET balance = ? WHERE id = ?')->execute([$rechargeAmount, $newMemberId]);

                logOperation($pdo, 'member_recharge', 'member', $newMemberId, '注册时充值：' . $name . ' +' . formatMoney($rechargeAmount), [
                    'name' => $name,
                    'phone' => $phone,
                    'amount' => $rechargeAmount,
                    'balance_after' => $rechargeAmount,
                    'payment_method' => $rechargePayment,
                    'with_register' => true,
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('会员注册失败: ' . $e->getMessage());
            $errors[] = '注册失败：' . (str_contains($e->getMessage(), 'UNIQUE') ? '手机号或学号已被使用' : '请检查填写内容后重试');
        }

        if (empty($errors)) {
            $successMessage = $withRecharge
                ? '会员注册成功，已充值 ' . formatMoney($rechargeAmount) . '，当前余额 ' . formatMoney($rechargeAmount)
                : '会员注册成功';
            flash('success', $successMessage . ($phone === '' ? '，手机号待补全' : ''));
            redirect('/members/view.php?id=' . $newMemberId);
        }
    }
}

renderHeader('注册会员 - Anson咖啡厅管理系统', 'members');
?>

<div class="page-header">
    <h2 class="page-title">注册会员</h2>
    <a href="/members/" class="btn btn-secondary">返回列表</a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $e): ?>
            <div><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card form-card">
    <form method="post" class="form-grid-2">
                <?= csrfField() ?>
        <div class="form-group">
            <label>姓名 <span class="required">*</span></label>
            <input type="text" name="name" id="registerName" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" placeholder="会员姓名" required>
        </div>
        <div class="form-group">
            <label>手机号</label>
            <input type="tel" name="phone" id="registerPhone" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" placeholder="可留空，后期补全">
            <small class="text-muted">留空即标记为待补全，下次会员消费时会提醒。</small>
            <div id="duplicateHint" class="form-help" aria-live="polite"></div>
        </div>
        <div class="form-group">
            <label>学号</label>
            <input type="text" name="student_no" value="<?= htmlspecialchars($_POST['student_no'] ?? '') ?>" placeholder="会员学号（选填）">
        </div>
        <div class="form-group">
            <label>性别</label>
            <select name="gender">
                <option value="0" <?= ($_POST['gender'] ?? '') == '0' ? 'selected' : '' ?>>未知</option>
                <option value="1" <?= ($_POST['gender'] ?? '') == '1' ? 'selected' : '' ?>>男</option>
                <option value="2" <?= ($_POST['gender'] ?? '') == '2' ? 'selected' : '' ?>>女</option>
            </select>
        </div>
        <div class="form-group">
            <label>生日</label>
            <input type="date" name="birthday" value="<?= htmlspecialchars($_POST['birthday'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>备注</label>
            <textarea name="note" rows="4" placeholder="可填写会员偏好、注意事项等（选填）"><?= htmlspecialchars($_POST['note'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
            <label>是否同时充值</label>
            <div class="radio-group">
                <label class="radio-item"><input type="radio" name="with_recharge" value="0" <?= !$withRecharge ? 'checked' : '' ?> onchange="toggleRegisterRecharge()"><span>不充值，仅注册</span></label>
                <label class="radio-item"><input type="radio" name="with_recharge" value="1" <?= $withRecharge ? 'checked' : '' ?> onchange="toggleRegisterRecharge()"><span>同时充值</span></label>
            </div>
        </div>
        <div id="registerRechargeFields" style="<?= $withRecharge ? '' : 'display:none' ?>">
            <div class="form-group">
                <label>充值金额（元） <span class="required">*</span></label>
                <input type="number" name="recharge_amount" id="registerRechargeAmount" step="0.01" min="0.01" value="<?= $rechargeAmount > 0 ? htmlspecialchars(number_format($rechargeAmount, 2, '.', '')) : '' ?>" placeholder="例如：100">
                <small class="text-muted">注册完成后自动充入会员余额，并写入充值记录、计入资金账户与每日上报参考</small>
            </div>
            <div class="form-group">
                <label>收款方式</label>
                <select name="recharge_payment">
                    <option value="cash" <?= $rechargePayment === 'cash' ? 'selected' : '' ?>>现金</option>
                    <option value="wechat" <?= $rechargePayment === 'wechat' ? 'selected' : '' ?>>微信</option>
                    <option value="alipay" <?= $rechargePayment === 'alipay' ? 'selected' : '' ?>>支付宝</option>
                </select>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">确认注册</button>
            <a href="/members/" class="btn btn-secondary">取消</a>
        </div>
    </form>
</div>

<script>
function toggleRegisterRecharge() {
    const checked = document.querySelector('input[name="with_recharge"]:checked');
    const on = checked && checked.value === '1';
    document.getElementById('registerRechargeFields').style.display = on ? 'block' : 'none';
    document.getElementById('registerRechargeAmount').required = on;
}
toggleRegisterRecharge();
const registerPhone = document.getElementById('registerPhone');
const duplicateHint = document.getElementById('duplicateHint');
let duplicateTimer;
function checkDuplicate() {
    clearTimeout(duplicateTimer);
    const name = document.getElementById('registerName').value.trim();
    const phone = registerPhone.value.trim();
    if (!phone) { duplicateHint.textContent = '手机号待补全'; duplicateHint.style.color = ''; return; }
    if (!name) { duplicateHint.textContent = ''; return; }
    duplicateTimer = setTimeout(async () => {
        try {
            const response = await fetch('/api/check_member_duplicate.php?name=' + encodeURIComponent(name) + '&phone=' + encodeURIComponent(phone), {cache:'no-store'});
            if (!response.ok) return;
            const result = await response.json();
            if (registerPhone.value.trim() !== phone || document.getElementById('registerName').value.trim() !== name) return;
            duplicateHint.textContent = result.message || '';
            duplicateHint.style.color = result.duplicate ? 'var(--danger)' : '';
        } catch (_) {}
    }, 250);
}
registerPhone.addEventListener('input', checkDuplicate);
document.getElementById('registerName').addEventListener('input', checkDuplicate);
checkDuplicate();
</script>

<?php renderFooter(); ?>

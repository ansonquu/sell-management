<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';

$pdo = getDb();
$errors = [];
$selectedMember = null;
$preselectedId = (int) ($_GET['member_id'] ?? 0);

if ($preselectedId) {
    $selectedMember = getMemberById($pdo, $preselectedId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $memberId = (int) ($_POST['member_id'] ?? 0);
    $amount = (float) ($_POST['amount'] ?? 0);
    $note = trim($_POST['note'] ?? '');
    $paymentMethod = (string) ($_POST['payment_method'] ?? 'cash');
    if (!in_array($paymentMethod, ['cash', 'wechat', 'alipay'], true)) {
        $paymentMethod = 'cash';
    }

    $member = getMemberById($pdo, $memberId);

    if (!$member) {
        $errors[] = '请选择有效的会员';
    } elseif ($member['status'] != STATUS_NORMAL) {
        $errors[] = '该会员已冻结，无法充值';
    }

    if ($amount <= 0) {
        $errors[] = '充值金额必须大于 0';
    }

    if (empty($errors)) {
        $newBalance = $member['balance'] + $amount;

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('
                INSERT INTO recharge (member_id, amount, balance_after, note, payment_method, operator, create_time)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $staff = getCurrentStaff();
            $operator = $staff['name'] ?? '';
            $stmt->execute([$memberId, $amount, $newBalance, $note ?: null, $paymentMethod, $operator, currentDateTime()]);

            $pdo->prepare('UPDATE member SET balance = ? WHERE id = ?')
                ->execute([$newBalance, $memberId]);

            logOperation($pdo, 'member_recharge', 'member', $memberId, '会员充值：' . $member['name'] . ' +' . formatMoney($amount), [
                'name' => $member['name'],
                'phone' => $member['phone'],
                'amount' => $amount,
                'balance_after' => $newBalance,
                'payment_method' => $paymentMethod,
            ]);

            $pdo->commit();
            flash('success', '充值成功！' . formatMoney($amount) . '，当前余额 ' . formatMoney($newBalance));
            redirect('/members/view.php?id=' . $memberId);
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = '充值失败，请重试';
        }
    }

    $selectedMember = $member;
}

renderHeader('会员充值 - Anson咖啡厅管理系统', 'recharge');
?>

<div class="page-header">
    <h2 class="page-title">会员充值</h2>
    <a href="/recharge/" class="btn btn-secondary">充值记录</a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $e): ?>
            <div><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card form-card">
    <form method="post" id="rechargeForm" class="form-grid-2">
                <?= csrfField() ?>
        <div class="form-group">
            <label>选择会员 <span class="required">*</span></label>
            <div class="member-search">
                <input type="text" id="memberSearch" placeholder="输入姓名、手机号或学号搜索..." autocomplete="off"
                    value="<?= $selectedMember ? htmlspecialchars($selectedMember['name'] . ' (' . ($selectedMember['student_no'] ?: '无学号') . ')') : '' ?>">
                <input type="hidden" name="member_id" id="memberId" value="<?= $selectedMember['id'] ?? '' ?>" required>
                <div id="searchResults" class="search-results"></div>
            </div>
            <div id="memberInfo" class="selected-member" style="<?= $selectedMember ? '' : 'display:none' ?>">
                <?php if ($selectedMember): ?>
                    <span class="badge badge-level"><?= htmlspecialchars($selectedMember['level']) ?></span>
                    当前余额：<?= formatMoney($selectedMember['balance']) ?> | 当前积分：<?= $selectedMember['points'] ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-group">
            <label>充值金额（元） <span class="required">*</span></label>
            <input type="number" name="amount" id="amount" step="0.01" min="0.01" inputmode="decimal"
                value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>" placeholder="请输入充值金额" required>
            <small class="text-muted">充值后余额：<span id="previewBalance">-</span></small>
        </div>

        <div class="form-group">
            <label>收款方式</label>
            <div class="radio-group">
                <?php foreach (['cash' => '现金', 'wechat' => '微信', 'alipay' => '支付宝'] as $value => $label): ?>
                    <label class="radio-item"><input type="radio" name="payment_method" value="<?= $value ?>" <?= ($_POST['payment_method'] ?? 'cash') === $value ? 'checked' : '' ?>><span><?= $label ?></span></label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="form-group">
            <label>备注</label>
            <input type="text" name="note" value="<?= htmlspecialchars($_POST['note'] ?? '') ?>" placeholder="可选备注">
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">确认充值</button>
            <a href="/" class="btn btn-secondary">取消</a>
        </div>
    </form>
</div>

<script>
const searchInput = document.getElementById('memberSearch');
const memberIdInput = document.getElementById('memberId');
const searchResults = document.getElementById('searchResults');
const memberInfo = document.getElementById('memberInfo');
const amountInput = document.getElementById('amount');
const previewBalance = document.getElementById('previewBalance');
let searchTimeout;
let currentBalance = <?= $selectedMember ? (float) $selectedMember['balance'] : 0 ?>;

function updatePreview() {
    const amount = parseFloat(amountInput.value) || 0;
    if (memberIdInput.value && amount > 0) {
        previewBalance.textContent = '¥' + (currentBalance + amount).toFixed(2);
    } else {
        previewBalance.textContent = '-';
    }
}

amountInput.addEventListener('input', updatePreview);

searchInput.addEventListener('input', function() {
    clearTimeout(searchTimeout);
    const q = this.value.trim();
    if (q.length < 1) {
        searchResults.innerHTML = '';
        return;
    }
    searchTimeout = setTimeout(() => {
        fetch('/api/search_members.php?q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => {
                searchResults.innerHTML = data.length
                    ? data.map(m => `<div class="search-item" data-id="${m.id}" data-balance="${m.balance}" data-points="${m.points}" data-level="${m.level}" data-label="${m.name} (${m.student_no || m.phone})">
                        <strong>${m.name}</strong> ${m.student_no || '-'} / ${m.phone}
                        <span class="badge badge-level">${m.level}</span>
                    </div>`).join('')
                    : '<div class="search-item text-muted">未找到会员</div>';
            });
    }, 300);
});

searchResults.addEventListener('click', function(e) {
    const item = e.target.closest('.search-item');
    if (item && item.dataset.id) {
        memberIdInput.value = item.dataset.id;
        searchInput.value = item.dataset.label;
        searchResults.innerHTML = '';
        currentBalance = parseFloat(item.dataset.balance) || 0;
        memberInfo.style.display = 'block';
        memberInfo.innerHTML = `<span class="badge badge-level">${item.dataset.level}</span> 当前余额：¥${currentBalance.toFixed(2)} | 当前积分：${item.dataset.points}`;
        updatePreview();
    }
});

updatePreview();
</script>

<?php renderFooter(); ?>

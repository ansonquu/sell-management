<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';

$pdo = getDb();
$errors = [];
$selectedMember = null;
$redemption = getRedemptionSettings();
$redeemItems = $redemption['items'] ?? [];
$redeemItems[] = ['name' => '其它', 'points' => 0, 'cost_product' => '', 'type' => 'other'];
$selectedRedeemIndex = (int) ($_POST['redeem_item'] ?? 0);
$hasTicket = ($_POST['has_ticket'] ?? '0') === '1';

if (empty($redemption['enabled'])) {
    flash('error', '积分兑换功能当前未开启');
    redirect('/levels/');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refund') {
    requireCsrf();
    $recordId = (int) ($_POST['record_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT c.*, m.name AS member_name FROM consumption c LEFT JOIN member m ON m.id = c.member_id WHERE c.id = ? AND c.record_type = 'points_redeem'");
    $stmt->execute([$recordId]);
    $record = $stmt->fetch();

    if (!$record) {
        $errors[] = '兑换记录不存在';
    } elseif (!empty($record['refunded_at'])) {
        $errors[] = '该兑换已经撤销过，无需重复操作';
    } elseif ((int) $record['points_spent'] <= 0) {
        $errors[] = '该记录没有可退还的积分';
    } else {
        $refundPoints = (int) $record['points_spent'];
        $refundName = preg_replace('/^\[积分兑换\]\s*/u', '', (string) $record['items']) ?: '其它';
        $staff = getCurrentStaff();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE member SET points = points + ? WHERE id = ?')->execute([$refundPoints, (int) $record['member_id']]);
            addPointsLog($pdo, (int) $record['member_id'], $refundPoints, '撤销兑换退还积分：' . $refundName);
            $newNote = trim((string) $record['note'] . '（已撤销兑换）');
            $pdo->prepare('UPDATE consumption SET refunded_at = ?, note = ? WHERE id = ?')->execute([currentDateTime(), $newNote, $recordId]);
            logOperation($pdo, 'points_redeem_refund', 'member', (int) $record['member_id'], '撤销积分兑换：' . ($record['member_name'] ?? '') . ' 退还 ' . $refundPoints . ' 积分', [
                'redemption_record_id' => $recordId,
                'refund_points' => $refundPoints,
                'operator' => $staff['name'] ?? '',
            ]);
            $pdo->commit();
            flash('success', '已撤销兑换「' . $refundName . '」，退还 ' . $refundPoints . ' 积分');
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = '撤销失败，请重试';
        }
        redirect('/points/redeem.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $memberId = (int) ($_POST['member_id'] ?? 0);
    $ticketNumber = trim((string) ($_POST['ticket_number'] ?? ''));
    $reason = trim($_POST['reason'] ?? '');
    $redeemIndex = $selectedRedeemIndex;
    $redeemItem = $redeemItems[$redeemIndex] ?? null;
    $costProduct = trim((string) ($redeemItem['cost_product'] ?? ''));
    $isOther = ($redeemItem['type'] ?? '') === 'other';
    $otherPointsInput = trim((string) ($_POST['other_points'] ?? ''));

    $member = getMemberById($pdo, $memberId);

    if (!$member) {
        $errors[] = '请选择有效的会员';
    } elseif ($member['status'] != STATUS_NORMAL) {
        $errors[] = '该会员已冻结，无法兑换';
    }

    if (!$redeemItem) {
        $errors[] = '请选择有效的兑换项目';
    }

    $pointsCost = (int) ($redeemItem['points'] ?? 0);
    $redeemName = $costProduct !== '' ? $costProduct : (trim((string) ($redeemItem['name'] ?? '')) ?: '其它');
    if ($isOther && $reason === '') {
        $errors[] = '选择“' . $redeemName . '”时，请在备注中说明具体兑换内容';
    }
    if ($isOther) {
        if ($otherPointsInput === '' || filter_var($otherPointsInput, FILTER_VALIDATE_INT) === false || (int) $otherPointsInput <= 0) {
            $errors[] = '选择“' . $redeemName . '”时，请填写大于 0 的整数积分';
        } else {
            $pointsCost = (int) $otherPointsInput;
        }
    }
    $reason = $reason ?: '兑换：' . $redeemName;

    if ($member && $pointsCost > $member['points']) {
        $errors[] = '积分不足，当前积分：' . $member['points'] . '，兑换需要 ' . $pointsCost . ' 积分';
    }

    if ($hasTicket && $ticketNumber === '') {
        $errors[] = '选择取号后必须填写号牌号码';
    }

    if (empty($errors)) {
        $newPoints = $member['points'] - $pointsCost;
        $costAmount = $costProduct === '' ? 0.0 : calculateSelectedItemsCost($pdo, [
            ['item' => ['name' => $costProduct], 'quantity' => 1],
        ]);
        $itemsText = '[积分兑换] ' . $redeemName;
        $staff = getCurrentStaff();
        $operator = $staff ? $staff['name'] : '';

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE member SET points = ? WHERE id = ?')->execute([$newPoints, $memberId]);
            addPointsLog($pdo, $memberId, -$pointsCost, $reason);
            $stmt = $pdo->prepare("INSERT INTO consumption (member_id, amount, points_earned, points_spent, items, note, operator, payment_method, ticket_number, record_type, cost_amount, net_income, prep_status, create_time) VALUES (?, 0, 0, ?, ?, ?, ?, 'points', ?, 'points_redeem', ?, ?, 'pending', ?)");
            $stmt->execute([$memberId, $pointsCost, $itemsText, $reason, $operator, $hasTicket ? $ticketNumber : '', $costAmount, -$costAmount, currentDateTime()]);
            $redemptionRecordId = (int) $pdo->lastInsertId();
            logOperation($pdo, 'points_redeem', 'member', $memberId, '积分兑换：' . $member['name'] . ' 兑换' . $redeemName, [
                'name' => $member['name'],
                'phone' => $member['phone'],
                'points_cost' => $pointsCost,
                'points_after' => $newPoints,
                'cost_product' => $costProduct,
                'cost_amount' => $costAmount,
                'redemption_record_id' => $redemptionRecordId,
                'reason' => $reason,
            ]);
            $pdo->commit();
            $message = '兑换成功！' . $redeemName . '，消耗 ' . $pointsCost . ' 积分，剩余 ' . $newPoints . ' 积分';
            flash('success', $message);
            redirect('/points/redeem.php');
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = '兑换失败，请重试';
        }
    }

    $selectedMember = $member;
}

$redemptionRecords = $pdo->query("
    SELECT c.id, c.member_id, c.items, c.points_spent, c.cost_amount, c.note, c.operator, c.create_time, c.ticket_number, c.refunded_at,
           m.name AS member_name, m.phone
    FROM consumption c
    LEFT JOIN member m ON c.member_id = m.id
    WHERE c.record_type = 'points_redeem'
    ORDER BY c.create_time DESC, c.id DESC
    LIMIT 100
")->fetchAll();

renderHeader('积分兑换 - Anson咖啡厅管理系统', 'points');
?>

<div class="page-header">
    <h2 class="page-title">积分兑换</h2>
</div>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $e): ?>
            <div><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card form-card">
    <form method="post">
                <?= csrfField() ?>
        <div class="form-group">
            <label>选择会员 <span class="required">*</span></label>
            <div class="member-search">
                <input type="text" id="memberSearch" placeholder="输入姓名、手机号或学号搜索..." autocomplete="off">
                <input type="hidden" name="member_id" id="memberId" required>
                <div id="searchResults" class="search-results"></div>
            </div>
            <div id="memberPoints" class="selected-member" style="display:none"></div>
        </div>

        <div class="form-group">
            <label>实际兑换商品</label>
            <div class="redeem-grid">
                <?php foreach ($redeemItems as $index => $item): ?>
                    <label class="redeem-item">
                        <?php $isNonPhysical = trim((string) ($item['cost_product'] ?? '')) === ''; $isOther = ($item['type'] ?? '') === 'other'; $productName = $isNonPhysical ? (trim((string) ($item['name'] ?? '')) ?: '其它') : (string) $item['cost_product']; ?>
                        <input type="radio" name="redeem_item" value="<?= $index ?>" data-is-other="<?= $isOther ? '1' : '0' ?>" <?= $index === $selectedRedeemIndex ? 'checked' : '' ?> required>
                        <span class="redeem-name"><?= htmlspecialchars($productName) ?></span>
                        <span class="redeem-points"><?= $isOther ? '手动填写积分' : (int) $item['points'] . ' 积分' ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="form-group" id="otherPointsGroup" style="display:none">
            <label>需要积分 <span class="required">*</span></label>
            <input type="number" name="other_points" id="otherPoints" value="<?= htmlspecialchars((string) ($_POST['other_points'] ?? '')) ?>" min="1" step="1" placeholder="填写本次需要扣除的积分">
            <small class="text-muted">只有选择“其它”时，才需要手动填写本次扣除积分。</small>
        </div>

        <div class="form-group">
            <label>备注 <span id="reasonRequired" class="required" style="display:none">*</span></label>
            <input type="text" name="reason" id="reason" value="<?= htmlspecialchars((string) ($_POST['reason'] ?? '')) ?>" placeholder="选择“其它”时必须说明具体兑换内容">
            <small class="text-muted">只有选择“其它”时，才必须在备注中写明实际兑换内容。</small>
        </div>

        <div class="form-group">
            <label>是否取号</label>
            <div class="radio-group">
                <label class="radio-item"><input type="radio" name="has_ticket" value="0" <?= !$hasTicket ? 'checked' : '' ?> onchange="toggleTicket()"><span>不取号</span></label>
                <label class="radio-item"><input type="radio" name="has_ticket" value="1" <?= $hasTicket ? 'checked' : '' ?> onchange="toggleTicket()"><span>取号</span></label>
            </div>
        </div>
        <div id="ticketInput" class="form-group" style="<?= $hasTicket ? '' : 'display:none' ?>">
            <label>号牌号码 <span class="required">*</span></label>
            <input name="ticket_number" maxlength="20" value="<?= htmlspecialchars((string) ($_POST['ticket_number'] ?? '')) ?>" placeholder="例如：A08、12号">
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-warning">确认兑换</button>
        </div>
    </form>
</div>

<div class="card table-scroll">
    <h3>积分兑换记录</h3>
    <table class="table">
        <thead><tr><th>时间</th><th>会员</th><th>实际兑换商品</th><th>扣除积分</th><th>号牌</th><th>备注</th><th>操作人</th><th>状态</th><th>操作</th></tr></thead>
        <tbody>
        <?php if (!$redemptionRecords): ?><tr><td colspan="9" class="text-center text-muted">暂无积分兑换记录</td></tr><?php endif; ?>
        <?php foreach ($redemptionRecords as $record): ?>
            <?php $recordProduct = preg_replace('/^\[积分兑换\]\s*/u', '', (string) $record['items']) ?: '其它'; ?>
            <tr>
                <td><?= formatDateTime($record['create_time']) ?></td>
                <td><?php if ((int) $record['member_id'] > 0): ?><a href="/members/view.php?id=<?= (int) $record['member_id'] ?>"><?= htmlspecialchars($record['member_name'] ?: '已删除会员') ?></a><small class="text-muted"><?= htmlspecialchars($record['phone'] ?: '-') ?></small><?php else: ?>-<?php endif; ?></td>
                <td><?= htmlspecialchars($recordProduct) ?></td>
                <td class="text-danger"><?= (int) $record['points_spent'] > 0 ? '-' . (int) $record['points_spent'] : '-' ?></td>
                <td><?= htmlspecialchars((string) ($record['ticket_number'] ?? '') ?: '-') ?></td>
                <td><?= htmlspecialchars($record['note'] ?: '-') ?></td>
                <td><?= htmlspecialchars($record['operator'] ?: '-') ?></td>
                <td>
                    <?php if (!empty($record['refunded_at'])): ?>
                        <span class="badge badge-secondary">已撤销</span>
                    <?php else: ?>
                        <span class="badge badge-success">正常</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (empty($record['refunded_at']) && (int) $record['points_spent'] > 0): ?>
                        <form method="post" onsubmit="return confirm('确定要撤销这次兑换吗？将退还 <?= (int) $record['points_spent'] ?> 积分给会员。')" style="display:inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="refund">
                            <input type="hidden" name="record_id" value="<?= (int) $record['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-warning">撤销退积分</button>
                        </form>
                    <?php else: ?>
                        <small class="text-muted"><?= !empty($record['refunded_at']) ? formatDateTime((string) $record['refunded_at']) : '-' ?></small>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
const searchInput = document.getElementById('memberSearch');
const memberIdInput = document.getElementById('memberId');
const searchResults = document.getElementById('searchResults');
const memberPoints = document.getElementById('memberPoints');
const reasonInput = document.getElementById('reason');
const reasonRequired = document.getElementById('reasonRequired');
const otherPointsGroup = document.getElementById('otherPointsGroup');
const otherPointsInput = document.getElementById('otherPoints');
let searchTimeout;

function updateReasonRequirement() {
    const selected = document.querySelector('[name="redeem_item"]:checked');
    const isOther = selected && selected.dataset.isOther === '1';
    reasonInput.required = Boolean(isOther);
    otherPointsInput.required = Boolean(isOther);
    reasonRequired.style.display = isOther ? '' : 'none';
    otherPointsGroup.style.display = isOther ? '' : 'none';
}
document.querySelectorAll('[name="redeem_item"]').forEach(input => {
    input.addEventListener('change', updateReasonRequirement);
});
updateReasonRequirement();

function toggleTicket() {
    const checked = document.querySelector('input[name="has_ticket"]:checked');
    const section = document.getElementById('ticketInput');
    const show = checked && checked.value === '1';
    section.style.display = show ? '' : 'none';
    const input = section.querySelector('input');
    input.required = show;
}
document.querySelectorAll('input[name="has_ticket"]').forEach(input => {
    input.addEventListener('change', toggleTicket);
});
toggleTicket();

searchInput.addEventListener('input', function() {
    clearTimeout(searchTimeout);
    const q = this.value.trim();
    if (q.length < 1) { searchResults.innerHTML = ''; return; }
    searchTimeout = setTimeout(() => {
        fetch('/api/search_members.php?q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => {
                searchResults.innerHTML = data.length
                    ? data.map(m => `<div class="search-item" data-id="${m.id}" data-points="${m.points}" data-label="${m.name} (${m.student_no || m.phone})">
                        <strong>${m.name}</strong> ${m.student_no || '-'} / ${m.phone} — ${m.points} 积分
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
        memberPoints.style.display = 'block';
        memberPoints.textContent = '可用积分：' + item.dataset.points;
    }
});
</script>

<?php renderFooter(); ?>

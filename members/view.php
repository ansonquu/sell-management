<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';

$pdo = getDb();
$id = (int) ($_GET['id'] ?? 0);
$member = getMemberById($pdo, $id);

if (!$member) {
    flash('error', '会员不存在');
    redirect('/members/');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_note') {
        $note = trim($_POST['note'] ?? '');
        $stmt = $pdo->prepare('UPDATE member SET note = ? WHERE id = ?');
        $stmt->execute([$note, $id]);
        logOperation($pdo, 'member_note_update', 'member', $id, '更新会员备注：' . $member['name'], [
            'name' => $member['name'],
            'phone' => $member['phone'],
            'has_note' => $note !== '',
        ]);
        flash('success', '会员备注已保存');
        redirect('/members/view.php?id=' . $id);
    }
}

$consumptions = $pdo->prepare('SELECT c.*, ct.name as coupon_name FROM consumption c LEFT JOIN member_coupons mc ON c.coupon_id = mc.id LEFT JOIN coupon_templates ct ON mc.template_id = ct.id WHERE c.member_id = ? ORDER BY c.create_time DESC LIMIT 20');
$consumptions->execute([$id]);
$consumptions = $consumptions->fetchAll();

$pointsLogs = $pdo->prepare('SELECT * FROM points_log WHERE member_id = ? ORDER BY create_time DESC LIMIT 20');
$pointsLogs->execute([$id]);
$pointsLogs = $pointsLogs->fetchAll();

$recharges = $pdo->prepare('SELECT * FROM recharge WHERE member_id = ? ORDER BY create_time DESC LIMIT 10');
$recharges->execute([$id]);
$recharges = $recharges->fetchAll();

$availableCoupons = $pdo->prepare('SELECT mc.*, ct.name, ct.type, ct.value, ct.min_amount FROM member_coupons mc JOIN coupon_templates ct ON mc.template_id = ct.id WHERE mc.member_id = ? AND mc.status = "unused" AND mc.expire_at > ? ORDER BY mc.expire_at ASC');
$availableCoupons->execute([$id, date('Y-m-d H:i:s')]);
$availableCoupons = $availableCoupons->fetchAll();

$usedCoupons = $pdo->prepare('SELECT mc.*, ct.name, ct.type, ct.value FROM member_coupons mc JOIN coupon_templates ct ON mc.template_id = ct.id WHERE mc.member_id = ? AND mc.status = "used" ORDER BY mc.used_at DESC LIMIT 10');
$usedCoupons->execute([$id]);
$usedCoupons = $usedCoupons->fetchAll();

renderHeader('会员详情 - Anson咖啡厅管理系统', 'members');
?>

<div class="page-header">
    <h2 class="page-title">会员详情</h2>
    <div>
        <a href="/members/edit.php?id=<?= $id ?>" class="btn btn-secondary">编辑</a>
        <a href="/consumption/add.php?member_id=<?= $id ?>" class="btn btn-primary">消费登记</a>
        <a href="/recharge/add.php?member_id=<?= $id ?>" class="btn btn-success">充值</a>
        <a href="/members/" class="btn btn-link">返回列表</a>
    </div>
</div>

<div class="member-profile">
    <div class="card profile-card">
        <div class="profile-header">
            <div class="avatar"><?= mb_substr($member['name'], 0, 1) ?></div>
            <div>
                <h3><?= htmlspecialchars($member['name']) ?></h3>
                <span class="badge badge-level"><?= htmlspecialchars($member['level']) ?></span>
                <span class="badge <?= $member['status'] == STATUS_NORMAL ? 'badge-success' : 'badge-danger' ?>">
                    <?= statusLabel($member['status']) ?>
                </span>
            </div>
        </div>
        <div class="profile-info">
            <div class="info-item"><label>学号</label><span><?= htmlspecialchars($member['student_no'] ?: '-') ?></span></div>
            <div class="info-item">
                <label>手机号</label>
                <div class="member-phone-value">
                    <span id="memberPhoneText"><?= htmlspecialchars($member['phone'] ?: '待补全') ?></span>
                    <?php if (!$member['phone']): ?><button type="button" class="btn btn-sm btn-primary" onclick="openCompletePhone()">补全手机号</button><?php endif; ?>
                </div>
            </div>
            <div class="info-item"><label>性别</label><span><?= genderLabel($member['gender']) ?></span></div>
            <div class="info-item"><label>生日</label><span><?= formatDate($member['birthday']) ?></span></div>
            <div class="info-item"><label>注册时间</label><span><?= formatDateTime($member['create_time']) ?></span></div>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card small">
            <div class="stat-value"><?= formatMoney($member['balance']) ?></div>
            <div class="stat-label">账户余额</div>
        </div>
        <div class="stat-card small">
            <div class="stat-value"><?= $member['points'] ?></div>
            <div class="stat-label">当前积分</div>
        </div>
        <div class="stat-card small">
            <div class="stat-value"><?= formatMoney($member['total_spent']) ?></div>
            <div class="stat-label">累计消费</div>
        </div>
    </div>
</div>

<?php if (!$member['phone']): ?>
<div id="completePhoneModal" class="modal-overlay" style="display:none">
    <div class="modal-card">
        <div class="modal-header"><h3>补全手机号</h3><button type="button" class="modal-close" onclick="closeCompletePhone()">×</button></div>
        <form id="completePhoneForm">
            <?= csrfField() ?>
            <input type="hidden" name="member_id" value="<?= $id ?>">
            <div class="form-group"><label>手机号</label><input type="tel" name="phone" pattern="1[3-9][0-9]{9}" maxlength="11" required placeholder="请输入 11 位手机号"></div>
            <p id="completePhoneError" class="text-danger"></p>
            <div class="form-actions"><button type="submit" class="btn btn-primary">保存手机号</button><button type="button" class="btn btn-secondary" onclick="closeCompletePhone()">稍后再填</button></div>
        </form>
    </div>
</div>
<script>
function openCompletePhone() { document.getElementById('completePhoneModal').style.display = 'flex'; }
function closeCompletePhone() { document.getElementById('completePhoneModal').style.display = 'none'; }
document.getElementById('completePhoneForm').addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const error = document.getElementById('completePhoneError');
    error.textContent = '';
    try {
        const body = new FormData(form);
        if (window.__DSH_CSRF__) body.set('csrf_token', window.__DSH_CSRF__);
        const response = await fetch('/members/complete_phone.php', {method:'POST', body});
        const result = await response.json();
        if (!response.ok) { error.textContent = result.error || '保存失败'; return; }
        location.reload();
    } catch (_) { error.textContent = '保存失败，请重试'; }
});
</script>
<?php endif; ?>

<div class="card note-card">
    <h3>会员备注</h3>
    <form method="post">
                <?= csrfField() ?>
        <input type="hidden" name="action" value="update_note">
        <div class="form-group">
            <textarea name="note" rows="4" placeholder="可直接记录会员偏好、禁忌、沟通事项等"><?= htmlspecialchars($member['note'] ?? '') ?></textarea>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">保存备注</button>
        </div>
    </form>
</div>

<div class="grid-2">
    <div class="card">
        <h3>消费记录</h3>
        <?php if (empty($consumptions)): ?>
            <p class="text-muted">暂无消费记录</p>
        <?php else: ?>
            <table class="table table-sm">
                <thead>
                    <tr><th>时间</th><th>商品</th><th>金额</th><th>支付方式</th><th>号牌</th><th>优惠券</th><th>积分</th><th>操作人</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($consumptions as $c): ?>
                        <tr>
                            <td><?= formatDateTime($c['create_time']) ?></td>
                            <td><?= htmlspecialchars($c['items'] ?: '-') ?></td>
                            <td><?= formatMoney(max(0, (float) $c['amount'] - (float) ($c['refunded_amount'] ?? 0))) ?><?php if ((float) ($c['refunded_amount'] ?? 0) > 0): ?><small class="text-muted">已退款 <?= formatMoney((float) $c['refunded_amount']) ?></small><?php endif; ?></td>
                            <td>
                                <?php $displayPaymentMethod = normalizeStoredPaymentMethod($c['payment_method']); ?>
                                <span class="badge <?= $displayPaymentMethod === 'balance' ? 'badge-success' : 'badge-secondary' ?>">
                                    <?= consumptionPaymentMethodLabel($c['payment_method']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($c['ticket_number']): ?>
                                    <span class="badge badge-warning"><?= htmlspecialchars($c['ticket_number']) ?></span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($c['coupon_name'] ?: '-') ?></td>
                            <td><?php if (($c['record_type'] ?? 'sale') === 'points_redeem'): ?><span class="text-danger">-<?= (int) ($c['points_spent'] ?? 0) ?></span><?php else: ?>+<?= (int) $c['points_earned'] ?><?php endif; ?></td>
                            <td><?= htmlspecialchars($c['operator'] ?: '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>充值记录</h3>
        <?php if (empty($recharges)): ?>
            <p class="text-muted">暂无充值记录</p>
        <?php else: ?>
            <table class="table table-sm">
                <thead>
                    <tr><th>时间</th><th>金额</th><th>余额</th><th>备注</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($recharges as $r): ?>
                        <tr>
                            <td><?= formatDateTime($r['create_time']) ?></td>
                            <td class="text-success">+<?= formatMoney($r['amount']) ?></td>
                            <td><?= formatMoney($r['balance_after']) ?></td>
                            <td><?= htmlspecialchars($r['note'] ?: '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <h3>可用优惠券</h3>
        <?php if (empty($availableCoupons)): ?>
            <p class="text-muted">暂无可用优惠券</p>
        <?php else: ?>
            <table class="table table-sm">
                <thead>
                    <tr><th>名称</th><th>面值</th><th>最低消费</th><th>有效期</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($availableCoupons as $c): ?>
                        <tr>
                            <td><?= htmlspecialchars($c['name']) ?></td>
                            <td><?= $c['type'] === 'fixed' ? '¥' . $c['value'] : $c['value'] . '折' ?></td>
                            <td><?= $c['min_amount'] > 0 ? '¥' . $c['min_amount'] : '无限制' ?></td>
                            <td><?= formatDate($c['expire_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>已使用优惠券</h3>
        <?php if (empty($usedCoupons)): ?>
            <p class="text-muted">暂无已使用优惠券</p>
        <?php else: ?>
            <table class="table table-sm">
                <thead>
                    <tr><th>名称</th><th>面值</th><th>抵扣金额</th><th>使用时间</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($usedCoupons as $c): ?>
                        <tr>
                            <td><?= htmlspecialchars($c['name']) ?></td>
                            <td><?= $c['type'] === 'fixed' ? '¥' . $c['value'] : $c['value'] . '折' ?></td>
                            <td><?= formatMoney($c['used_amount']) ?></td>
                            <td><?= formatDateTime($c['used_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <h3>积分变动</h3>
    <?php if (empty($pointsLogs)): ?>
        <p class="text-muted">暂无积分记录</p>
    <?php else: ?>
        <table class="table table-sm">
            <thead>
                <tr><th>时间</th><th>变动</th><th>原因</th></tr>
            </thead>
            <tbody>
                <?php foreach ($pointsLogs as $log): ?>
                    <tr>
                        <td><?= formatDateTime($log['create_time']) ?></td>
                        <td class="<?= $log['change_amount'] >= 0 ? 'text-success' : 'text-danger' ?>">
                            <?= $log['change_amount'] >= 0 ? '+' : '' ?><?= $log['change_amount'] ?>
                        </td>
                        <td><?= htmlspecialchars($log['reason']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
(function () {
    const initialVersion = '<?= getRecordVersion($pdo, $id) ?>';
    let checking = false;

    setInterval(function () {
        if (checking || document.hidden || (window.AACafeAutoRefreshGuard && window.AACafeAutoRefreshGuard.shouldPause())) {
            return;
        }

        checking = true;
        fetch('/api/record_version.php?scope=member&member_id=<?= $id ?>', { cache: 'no-store' })
            .then(response => response.ok ? response.json() : null)
            .then(data => {
                if (data && data.version && data.version !== initialVersion) {
                    window.location.reload();
                }
            })
            .catch(() => {})
            .finally(() => {
                checking = false;
            });
    }, 5000);
})();
</script>

<?php renderFooter(); ?>

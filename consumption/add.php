<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';

$pdo = getDb();
$errors = [];
$selectedMember = null;
$preselectedId = (int) ($_GET['member_id'] ?? 0);
$menuItems = getMenuItems();
$pointsPerYuan = getPointsPerYuan();
$isWalkIn = ($_POST['is_walk_in'] ?? '0') === '1';

$availableCoupons = [];
$selectedCouponId = (int) ($_POST['coupon_id'] ?? 0);

if ($preselectedId) {
    $selectedMember = getMemberById($pdo, $preselectedId);
    $availableCoupons = getMemberAvailableCoupons($pdo, $preselectedId);
}

function getMemberAvailableCoupons(PDO $pdo, int $memberId): array
{
    $now = currentDateTime();
    $stmt = $pdo->prepare("
        SELECT mc.id, mc.code, ct.name, ct.type, ct.value, ct.min_amount, ct.expire_days, mc.expire_at
        FROM member_coupons mc
        JOIN coupon_templates ct ON mc.template_id = ct.id
        WHERE mc.member_id = ? AND mc.status = 'unused' AND mc.expire_at > ?
        ORDER BY ct.min_amount ASC
    ");
    $stmt->execute([$memberId, $now]);
    return $stmt->fetchAll();
}

function calculateDiscount(PDO $pdo, int $couponId, float $amount): float
{
    $stmt = $pdo->prepare("
        SELECT ct.type, ct.value, ct.min_amount
        FROM member_coupons mc
        JOIN coupon_templates ct ON mc.template_id = ct.id
        WHERE mc.id = ? AND mc.status = 'unused'
    ");
    $stmt->execute([$couponId]);
    $coupon = $stmt->fetch();

    if (!$coupon) {
        return 0;
    }

    if ($amount < $coupon['min_amount']) {
        return 0;
    }

    if ($coupon['type'] === 'fixed') {
        return min($coupon['value'], $amount);
    } else {
        return $amount * (1 - $coupon['value'] / 10);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $memberId = (int) ($_POST['member_id'] ?? 0);
    $note = trim($_POST['note'] ?? '');
    $isWalkIn = ($_POST['is_walk_in'] ?? '0') === '1';
    $paymentMethod = resolveConsumptionPaymentMethod($isWalkIn, $_POST['payment_method'] ?? 'balance');
    $hasTicket = (bool) ($_POST['has_ticket'] ?? false);
    $ticketNumber = trim($_POST['ticket_number'] ?? '');

    if ($hasTicket && $ticketNumber === '') {
        $errors[] = '请输入号牌号码';
    }

    $member = null;
    if (!$isWalkIn) {
        $member = getMemberById($pdo, $memberId);

        if (!$member) {
            $errors[] = '请选择有效的会员';
        } elseif ($member['status'] != STATUS_NORMAL) {
            $errors[] = '该会员已冻结，无法登记消费';
        }
    }

    $selectedItems = [];
    $itemQuantities = $_POST['item_quantity'] ?? [];

    foreach ($menuItems as $index => $item) {
        $qty = (int) ($itemQuantities[$index] ?? 0);
        if ($qty > 99) {
            $errors[] = '单种饮品一次最多登记 99 杯';
            continue;
        }
        if ($qty > 0) {
            $selectedItems[] = ['item' => $item, 'quantity' => $qty];
        }
    }

    if (empty($selectedItems)) {
        $errors[] = '请至少选择一种饮品';
    }

    if (empty($errors)) {
        $amount = 0;
        $items = [];
        foreach ($selectedItems as $si) {
            $amount += $si['item']['price'] * $si['quantity'];
            $items[] = $si['item']['name'] . ' x' . $si['quantity'];
        }
        $itemsText = normalizeConsumptionItemsText(implode(', ', $items));
        $preparationItems = preparationItemsFromSelection($selectedItems);
        $unitCostMap = getProductCostMap($pdo);
        foreach ($preparationItems as &$preparationItem) {
            $preparationItem['unit_cost'] = round((float) ($unitCostMap[$preparationItem['name']] ?? 0), 2);
        }
        unset($preparationItem);
        $costAmount = calculateSelectedItemsCost($pdo, $selectedItems);

        $memberDiscount = 0;
        $memberDiscountRate = 1.0;
        if ($member) {
            $memberDiscountRate = getMemberDiscountForSpent((float) $member['total_spent']);
            $memberDiscount = $amount * (1 - $memberDiscountRate);
        }
        $amountAfterMemberDiscount = $amount - $memberDiscount;

        $couponDiscount = 0;
        if ($selectedCouponId > 0) {
            $couponDiscount = calculateDiscount($pdo, $selectedCouponId, $amountAfterMemberDiscount);
            if ($couponDiscount <= 0) {
                $errors[] = '优惠券使用条件不满足';
            }
        }

        if (empty($errors)) {
            $finalAmount = $amountAfterMemberDiscount - $couponDiscount;
            $netIncome = round($finalAmount - $costAmount, 2);

            if ($paymentMethod === 'balance' && $member && $member['balance'] < $finalAmount) {
                $errors[] = '余额不足，当前余额：' . formatMoney($member['balance']);
            }

            if (empty($errors)) {
                $pointsEarned = 0;
                $newTotalSpent = 0;
                $newPoints = 0;
                $newLevel = '';

                if ($member) {
                    $pointsEarned = calculatePoints($finalAmount);
                    $newTotalSpent = $member['total_spent'] + $finalAmount;
                    $newPoints = $member['points'] + $pointsEarned;
                    $newLevel = calculateLevel($newTotalSpent);
                }

                $preparationItems = allocatePreparationItemCharges($preparationItems, $finalAmount, $pointsEarned);

                $staff = getCurrentStaff();
                $operator = $staff ? $staff['name'] : '';

                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare('
                        INSERT INTO consumption (member_id, amount, points_earned, items, note, operator, coupon_id, coupon_discount, payment_method, ticket_number, cost_amount, net_income, prep_status, prep_items, create_time)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ');
                    $stmt->execute([$memberId, $finalAmount, $pointsEarned, $itemsText, $note, $operator, $selectedCouponId, $couponDiscount, $paymentMethod, $ticketNumber, $costAmount, $netIncome, 'pending', json_encode($preparationItems, JSON_UNESCAPED_UNICODE), currentDateTime()]);
                    $consumptionId = (int) $pdo->lastInsertId();

                    if ($member) {
                        $newBalance = $member['balance'];
                        if ($paymentMethod === 'balance') {
                            $newBalance = $member['balance'] - $finalAmount;
                        }

                        $stmt = $pdo->prepare('
                            UPDATE member SET points = ?, total_spent = ?, level = ?, balance = ? WHERE id = ?
                        ');
                        $stmt->execute([$newPoints, $newTotalSpent, $newLevel, $newBalance, $memberId]);

                        if ($pointsEarned > 0) {
                            addPointsLog($pdo, $memberId, $pointsEarned, '消费获得积分：' . formatMoney($finalAmount));
                        }
                    }

                    if ($selectedCouponId > 0) {
                        $stmt = $pdo->prepare('
                            UPDATE member_coupons SET status = ?, used_at = ?, used_amount = ? WHERE id = ?
                        ');
                        $stmt->execute(['used', currentDateTime(), $couponDiscount, $selectedCouponId]);
                    }

                    logOperation($pdo, 'consumption_create', 'consumption', $consumptionId, ($member ? '会员消费：' . $member['name'] : '散客消费') . ' ' . formatMoney($finalAmount), [
                        'member_id' => $member ? $memberId : 0,
                        'member_name' => $member['name'] ?? '散客',
                        'items' => $itemsText,
                        'amount' => $amount,
                        'member_discount' => $memberDiscount,
                        'member_discount_rate' => $memberDiscountRate,
                        'coupon_discount' => $couponDiscount,
                        'final_amount' => $finalAmount,
                        'cost_amount' => $costAmount,
                        'net_income' => $netIncome,
                        'payment_method' => $paymentMethod,
                        'ticket_number' => $ticketNumber,
                        'points_earned' => $pointsEarned,
                    ]);

                    $pdo->commit();

                    $msg = $isWalkIn ? "散客消费登记成功！" : "消费登记成功！";
                    $msg .= "{$itemsText} 共计 " . formatMoney($amount);
                    if ($memberDiscount > 0) {
                        $msg .= "，会员折扣减免 " . formatMoney($memberDiscount);
                    }
                    if ($couponDiscount > 0) {
                        $msg .= "，使用优惠券抵扣 " . formatMoney($couponDiscount);
                    }
                    $msg .= "，实付 " . formatMoney($finalAmount);
                    if ($ticketNumber) {
                        $msg .= "，号牌：{$ticketNumber}";
                    }
                    if ($member && $pointsEarned > 0) {
                        $msg .= "，获得 {$pointsEarned} 积分" . ($newLevel !== $member['level'] ? "，已升级为 {$newLevel}" : '');
                    }
                    if ($paymentMethod === 'balance' && $member) {
                        $msg .= "，当前余额：" . formatMoney($member['balance'] - $finalAmount);
                    }
                    flash('success', $msg);

                    // 登记完成后继续留在登记页面，方便连续录入
                    redirect('/consumption/add.php');
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $errors[] = '登记失败，请重试';
                }
            }
        }
    }

    $selectedMember = $member;
    if ($selectedMember) {
        $availableCoupons = getMemberAvailableCoupons($pdo, $selectedMember['id']);
    }
}

$selectedItemQuantities = $_POST['item_quantity'] ?? [];

renderHeader('消费登记 - Anson咖啡厅管理系统', 'consumption');
?>

<div class="page-header">
    <h2 class="page-title">消费登记</h2>
    <a href="/consumption/" class="btn btn-secondary">消费记录</a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $e): ?>
            <div><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card form-card form-card-wide">
    <form method="post" id="consumptionForm">
                <?= csrfField() ?>
        <div class="form-group">
            <label>消费类型</label>
            <div class="radio-group">
                <label class="radio-item">
                    <input type="radio" name="is_walk_in" value="0" <?= !$isWalkIn ? 'checked' : '' ?> onchange="toggleMemberSection()">
                    <span>会员消费</span>
                </label>
                <label class="radio-item">
                    <input type="radio" name="is_walk_in" value="1" <?= $isWalkIn ? 'checked' : '' ?> onchange="toggleMemberSection()">
                    <span>散客消费</span>
                </label>
            </div>
        </div>

        <div id="memberSection" <?= $isWalkIn ? 'style="display: none;"' : '' ?>>
            <div class="form-group">
                <label>选择会员 <span class="required">*</span></label>
                <div class="member-search">
                    <input type="text" id="memberSearch" placeholder="输入姓名、手机号或学号搜索..." autocomplete="off"
                        value="<?= $selectedMember ? htmlspecialchars($selectedMember['name'] . ' (' . memberPhoneDisplay($selectedMember['phone']) . ')') : '' ?>">
                    <input type="hidden" name="member_id" id="memberId" value="<?= $selectedMember['id'] ?? '' ?>" required>
                    <div id="searchResults" class="search-results"></div>
                </div>
                <?php if ($selectedMember): ?>
                    <div class="selected-member" id="selectedMemberInfo">
                        <span class="badge badge-level"><?= htmlspecialchars($selectedMember['level']) ?></span>
                        <span id="selectedMemberPhone"><?= htmlspecialchars(memberPhoneDisplay($selectedMember['phone'])) ?></span> | 当前积分：<?= $selectedMember['points'] ?> | 累计消费：<?= formatMoney($selectedMember['total_spent']) ?> | 余额：<?= formatMoney($selectedMember['balance']) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-group">
            <label>选择饮品 <span class="required">*</span></label>
            <div class="menu-grid">
                <?php foreach ($menuItems as $i => $item): ?>
                    <div class="menu-item">
                        <div class="menu-item-info">
                            <span class="menu-item-name"><?= htmlspecialchars($item['name']) ?></span>
                            <span class="menu-item-price"><?= formatMoney($item['price']) ?></span>
                        </div>
                        <div class="menu-item-quantity">
                            <button type="button" class="btn btn-sm" onclick="updateQuantity(<?= $i ?>, -1)">-</button>
                            <input type="number" name="item_quantity[<?= $i ?>]" value="<?= (int) ($selectedItemQuantities[$i] ?? 0) ?>" min="0" max="99" class="quantity-input">
                            <button type="button" class="btn btn-sm" onclick="updateQuantity(<?= $i ?>, 1)">+</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="order-summary">
                <div class="summary-row">
                    <span>商品小计：</span>
                    <span class="summary-amount" id="subtotalAmount">-</span>
                </div>
                <div id="discountPreview" class="text-muted"></div>
                <div class="summary-row">
                    <span>预计获得积分：</span>
                    <span id="previewPoints">0</span>（<?= $pointsPerYuan ?> 积分/元）
                </div>
            </div>
        </div>

        <div id="paymentSection">
            <div class="form-group">
                <label>支付方式</label>
                <div class="radio-group">
                    <label class="radio-item external-payment-option">
                        <input type="radio" name="payment_method" value="cash" <?= ($_POST['payment_method'] ?? 'balance') === 'cash' ? 'checked' : '' ?> onchange="updatePreview()">
                        <span>现金支付</span>
                    </label>
                    <label class="radio-item balance-payment-option">
                        <input type="radio" name="payment_method" value="balance" <?= ($_POST['payment_method'] ?? 'balance') === 'balance' ? 'checked' : '' ?> onchange="updatePreview()">
                        <span>余额支付（默认）</span>
                    </label>
                    <label class="radio-item external-payment-option"><input type="radio" name="payment_method" value="wechat" <?= ($_POST['payment_method'] ?? '') === 'wechat' ? 'checked' : '' ?> onchange="updatePreview()"><span>微信支付</span></label>
                    <label class="radio-item external-payment-option"><input type="radio" name="payment_method" value="alipay" <?= ($_POST['payment_method'] ?? '') === 'alipay' ? 'checked' : '' ?> onchange="updatePreview()"><span>支付宝</span></label>
                </div>
                <small class="text-muted">会员消费只允许使用会员余额；现金、微信和支付宝仅用于散客消费。</small>
            </div>
        </div>

        <div id="couponSection" <?= $isWalkIn ? 'style="display: none;"' : '' ?>>
            <?php if (!empty($availableCoupons)): ?>
            <div class="form-group">
                <label>选择优惠券（可选）</label>
                <select name="coupon_id" id="couponSelect" onchange="updatePreview()">
                    <option value="0">不使用优惠券</option>
                    <?php foreach ($availableCoupons as $coupon): ?>
                    <option value="<?= $coupon['id'] ?>" data-value="<?= $coupon['value'] ?>" data-type="<?= $coupon['type'] ?>" data-min="<?= $coupon['min_amount'] ?>"
                        <?= $selectedCouponId === $coupon['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($coupon['name']) ?> -
                        <?= $coupon['type'] === 'fixed' ? '¥' . $coupon['value'] : $coupon['value'] . '折' ?>
                        <?= $coupon['min_amount'] > 0 ? '(满' . $coupon['min_amount'] . '可用)' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div id="couponPreview" style="margin-top: 0.5rem;"></div>
            </div>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label>是否取号</label>
            <div class="radio-group">
                <label class="radio-item">
                    <input type="radio" name="has_ticket" value="0" <?= !((bool) ($_POST['has_ticket'] ?? false)) ? 'checked' : '' ?> onchange="toggleTicketSection()">
                    <span>不取号</span>
                </label>
                <label class="radio-item">
                    <input type="radio" name="has_ticket" value="1" <?= (bool) ($_POST['has_ticket'] ?? false) ? 'checked' : '' ?> onchange="toggleTicketSection()">
                    <span>取号</span>
                </label>
            </div>
        </div>

        <div id="ticketSection" <?= (bool) ($_POST['has_ticket'] ?? false) ? '' : 'style="display: none;"' ?>>
            <div class="form-group">
                <label>号牌号码 <span class="required">*</span></label>
                <input type="text" name="ticket_number" value="<?= htmlspecialchars($_POST['ticket_number'] ?? '') ?>" placeholder="例如：A01、5号、102" maxlength="20">
            </div>
        </div>

        <div class="form-group">
            <label>备注</label>
            <input type="text" name="note" value="<?= htmlspecialchars($_POST['note'] ?? '') ?>" placeholder="可选备注">
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-success">确认登记</button>
            <a href="/" class="btn btn-secondary">取消</a>
        </div>
    </form>
</div>

<?php if ($selectedMember && !$selectedMember['phone'] && !$isWalkIn): ?>
<div id="phoneReminder" class="modal-overlay" style="display:flex">
    <div class="modal-card">
        <div class="modal-header"><h3>提醒补全手机号</h3><button type="button" class="modal-close" onclick="closePhoneReminder()">×</button></div>
        <p>会员 <?= htmlspecialchars($selectedMember['name']) ?> 注册时未填写手机号，请在本次消费时补全。</p>
        <form id="phoneReminderForm">
            <?= csrfField() ?>
            <input type="hidden" name="member_id" value="<?= (int) $selectedMember['id'] ?>">
            <div class="form-group"><label>手机号</label><input type="tel" name="phone" pattern="1[3-9][0-9]{9}" maxlength="11" required placeholder="请输入 11 位手机号"></div>
            <p id="phoneReminderError" class="text-danger"></p>
            <div class="form-actions"><button type="submit" class="btn btn-primary">补全并继续消费</button><button type="button" class="btn btn-secondary" onclick="closePhoneReminder()">稍后补全</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function closePhoneReminder() { const modal = document.getElementById('phoneReminder'); if (modal) modal.style.display = 'none'; }
const phoneReminderForm = document.getElementById('phoneReminderForm');
if (phoneReminderForm) phoneReminderForm.addEventListener('submit', async event => {
    event.preventDefault();
    const error = document.getElementById('phoneReminderError');
    error.textContent = '';
    try {
        const body = new FormData(phoneReminderForm);
        if (window.__DSH_CSRF__) body.set('csrf_token', window.__DSH_CSRF__);
        const response = await fetch('/members/complete_phone.php', {method:'POST', body});
        const result = await response.json();
        if (!response.ok) { error.textContent = result.error || '保存失败'; return; }
        document.getElementById('memberSearch').value = <?= json_encode($selectedMember['name'] ?? '', JSON_UNESCAPED_UNICODE) ?> + ' (' + phoneSuffix(result.phone) + ')';
        const phoneText = document.getElementById('selectedMemberPhone');
        if (phoneText) phoneText.textContent = phoneSuffix(result.phone);
        closePhoneReminder();
    } catch (_) { error.textContent = '保存失败，请重试'; }
});
const searchInput = document.getElementById('memberSearch');
const memberIdInput = document.getElementById('memberId');
const searchResults = document.getElementById('searchResults');
const subtotalAmount = document.getElementById('subtotalAmount');
const previewPoints = document.getElementById('previewPoints');
const menuItems = <?= json_encode($menuItems, JSON_UNESCAPED_UNICODE) ?>;
const couponSelect = document.getElementById('couponSelect');
const couponPreview = document.getElementById('couponPreview');
const discountPreview = document.getElementById('discountPreview');
const memberDiscountRate = <?= json_encode($selectedMember ? getMemberDiscountForSpent((float) $selectedMember['total_spent']) : 1.0) ?>;
let searchTimeout;
const escapeMemberSearch = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
const phoneSuffix = phone => phone ? String(phone).slice(-4) : '手机号待补全';

function toggleMemberSection() {
    const isWalkIn = document.querySelector('input[name="is_walk_in"]:checked').value === '1';
    const memberSection = document.getElementById('memberSection');
    const paymentSection = document.getElementById('paymentSection');
    const couponSection = document.getElementById('couponSection');

    if (isWalkIn) {
        memberSection.style.display = 'none';
        paymentSection.style.display = 'block';
        document.querySelector('.balance-payment-option').style.display = 'none';
        document.querySelectorAll('.external-payment-option').forEach(option => option.style.display = '');
        const selectedPayment = document.querySelector('input[name="payment_method"]:checked');
        if (!selectedPayment || !['cash', 'wechat', 'alipay'].includes(selectedPayment.value)) {
            document.querySelector('input[name="payment_method"][value="cash"]').checked = true;
        }
        couponSection.style.display = 'none';
        memberIdInput.removeAttribute('required');
    } else {
        memberSection.style.display = 'block';
        paymentSection.style.display = 'block';
        document.querySelector('.balance-payment-option').style.display = '';
        document.querySelectorAll('.external-payment-option').forEach(option => option.style.display = 'none');
        document.querySelector('input[name="payment_method"][value="balance"]').checked = true;
        couponSection.style.display = 'block';
        memberIdInput.setAttribute('required', 'required');
    }
    updatePreview();
}

function toggleTicketSection() {
    const hasTicket = document.querySelector('input[name="has_ticket"]:checked').value === '1';
    const ticketSection = document.getElementById('ticketSection');

    if (hasTicket) {
        ticketSection.style.display = 'block';
    } else {
        ticketSection.style.display = 'none';
    }
}

function updateQuantity(index, delta) {
    const input = document.querySelector('input[name="item_quantity[' + index + ']"]');
    let value = parseInt(input.value) || 0;
    value = Math.max(0, Math.min(99, value + delta));
    input.value = value;
    updatePreview();
}

function updatePreview() {
    let subtotal = 0;
    document.querySelectorAll('input[name^="item_quantity"]').forEach((input, index) => {
        const qty = parseInt(input.value) || 0;
        subtotal += menuItems[index].price * qty;
    });

    subtotalAmount.textContent = '¥' + subtotal.toFixed(2);

    let couponDiscount = 0;
    const isWalkIn = document.querySelector('input[name="is_walk_in"]:checked').value === '1';
    const memberDiscount = isWalkIn ? 0 : subtotal * (1 - memberDiscountRate);
    const amountAfterMemberDiscount = subtotal - memberDiscount;

    if (!isWalkIn && couponSelect && couponSelect.value > 0) {
        const option = couponSelect.options[couponSelect.selectedIndex];
        const type = option.dataset.type;
        const value = parseFloat(option.dataset.value);
        const minAmount = parseFloat(option.dataset.min);

        if (amountAfterMemberDiscount >= minAmount) {
            if (type === 'fixed') {
                couponDiscount = Math.min(value, amountAfterMemberDiscount);
            } else {
                couponDiscount = amountAfterMemberDiscount * (1 - value / 10);
            }
        }
    }

    const finalAmount = amountAfterMemberDiscount - couponDiscount;

    if (isWalkIn) {
        previewPoints.textContent = '0';
    } else {
        previewPoints.textContent = Math.floor(finalAmount * <?= json_encode($pointsPerYuan) ?>);
    }

    const discountParts = [];
    if (memberDiscount > 0) discountParts.push(`会员折扣减免 ¥${memberDiscount.toFixed(2)}`);
    if (couponDiscount > 0) discountParts.push(`优惠券抵扣 ¥${couponDiscount.toFixed(2)}`);
    discountPreview.innerHTML = discountParts.length ? `<span style="color: var(--danger);font-weight:500;">${discountParts.join('，')}，实付 ¥${finalAmount.toFixed(2)}</span>` : '';
    if (couponPreview) couponPreview.innerHTML = '';
}

document.querySelectorAll('input[name^="item_quantity"]').forEach(input => {
    input.addEventListener('change', updatePreview);
    input.addEventListener('input', updatePreview);
});

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
                    ? data.map(m => `<div class="search-item" data-id="${Number(m.id)}" data-label="${escapeMemberSearch(m.name + ' (' + phoneSuffix(m.phone) + ')')}">
                        <strong>${escapeMemberSearch(m.name)}</strong> ${m.student_no ? '学号 ' + escapeMemberSearch(m.student_no) + ' · ' : ''}${escapeMemberSearch(phoneSuffix(m.phone))}
                        <span class="badge badge-level">${escapeMemberSearch(m.level)}</span>
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
        location.href = '/consumption/add.php?member_id=' + item.dataset.id;
    }
});

toggleMemberSection();
</script>

<?php renderFooter(); ?>

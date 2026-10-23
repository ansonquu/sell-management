<?php

session_start();
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$staff = getCurrentStaff();
if (!canAccessOrderBoard($staff)) {
    redirect('/');
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars(sitePageTitle('制作总览')) ?></title>
    <link rel="icon" href="<?= htmlspecialchars(getFaviconUrl()) ?>">
    <link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
    <script>window.BOARD_CAN_OPERATE = <?= canOperateOrderBoard($staff) ? 'true' : 'false' ?>;</script>
    <script>window.__DSH_SESSION__ = <?= json_encode(substr(hash('sha256', session_id() . '|' . (string) ($staff['username'] ?? '')), 0, 16)) ?>;</script>
    <script src="<?= assetUrl('/assets/js/session-sync.js') ?>" defer></script>
    <style>
        body { min-height:100vh; background:#151b20; color:#f5f1e9; }
        .board-shell { max-width:1920px; margin:0 auto; padding:clamp(1rem,2vw,2.2rem); }
        .board-header { display:flex; justify-content:space-between; align-items:center; gap:1rem 1.5rem; flex-wrap:wrap; margin-bottom:1.5rem; padding-bottom:1.15rem; border-bottom:1px solid #50606b; }
        .board-brand { display:flex; align-items:center; gap:1rem; min-width:0; }
        .board-brand h1 { margin: 0; }
        .board-header h1 { color:#fff; font-size:clamp(1.6rem,3vw,2.8rem); margin:0; }
        .board-header p { color:#cbd4db; margin:.2rem 0 0; }
        .board-actions { display:flex; align-items:center; gap:.7rem; flex-wrap:wrap; }
        .board-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(min(100%,390px),1fr)); gap:0.55rem; align-items:start; }
        .order-card { min-width:0; background:#fff; color:#1f2931; border-radius:18px; padding:1.25rem; border:3px solid #a86414; box-shadow:0 9px 28px #0005; }
        .order-card.done { border-color:#25734a; background:#f4faf6; }
        .order-top { display:flex; justify-content:space-between; align-items:start; gap:.5rem 1rem; flex-wrap:wrap; }
        .order-id { font-size:clamp(1.25rem,2vw,1.5rem); font-weight:800; line-height:1.3; }
        .order-time { color:#283841; font-size:.95rem; font-weight:650; font-variant-numeric:tabular-nums; }
        .order-member { margin-top:.45rem; font-size:1.05rem; font-weight:650; }
        .order-ticket { display:block; width:fit-content; max-width:100%; background:#fff0c3; color:#573500; border:1px solid #d29a36; border-radius:10px; padding:.4rem .8rem; font-size:clamp(1.7rem,2.5vw,2.2rem); font-weight:800; line-height:1.2; font-variant-numeric:tabular-nums; overflow-wrap:anywhere; margin:0 0 .85rem; }
        .order-progress { display:flex; align-items:center; justify-content:space-between; gap:.5rem; margin:.75rem 0 .55rem; font-size:.94rem; font-weight:700; color:#34434c; }
        .order-progress strong { color:#74420d; }
        .order-card.done .order-progress strong { color:#155b34; }
        .drink-list { display:grid; gap:.6rem; }
        .drink-row { display:grid; gap:.55rem; padding:.8rem .9rem; border:1px solid #d5dfe4; border-left:4px solid #a86414; border-radius:10px; background:#fbfcfd; }
        .drink-row.is-done { border-color:#bfdac8; border-left-color:#25734a; background:#eef8f1; }
        .drink-row.is-refunded { border-color:#d7dce0; border-left-color:#67747d; background:#f1f3f4; }
        .drink-main { display:flex; align-items:baseline; gap:.5rem; min-width:0; }
        .drink-number { flex:0 0 auto; color:#52616a; font-size:.88rem; font-weight:700; }
        .drink-name { color:#1f2931; font-size:clamp(1.2rem,2vw,1.4rem); font-weight:800; line-height:1.4; white-space:pre-wrap; overflow-wrap:anywhere; }
        .order-meta { color:#34434c; font-size:.94rem; line-height:1.55; overflow-wrap:anywhere; margin-top:.85rem; padding-top:.75rem; border-top:1px solid #d5dfe4; }
        .order-note { background:#fff0e3; color:#613810; border-left:4px solid #a86414; padding:.65rem .75rem; border-radius:8px; margin:.8rem 0; overflow-wrap:anywhere; }
        .drink-actions { display:flex; align-items:center; gap:.5rem; flex-wrap:wrap; }
        .drink-actions .btn { min-height:44px; }
        .drink-check { display:inline-flex; align-items:center; justify-content:center; flex:0 0 44px; width:44px; height:44px; border-radius:9px; font-size:1.45rem; font-weight:800; }
        .drink-check.btn { padding:0; }
        .drink-check.is-complete { background:#d5efdd; color:#155b34; border:1px solid #91cda3; }
        .drink-actions .btn[disabled] { color:#5a4d41; background:#e8dfd4; border-color:#c8b7a2; }
        .order-complete-time { color:#155b34; font-size:.94rem; font-weight:700; font-variant-numeric:tabular-nums; }
        .drink-refunded { color:#44515a; font-weight:700; }
        .refund-modal-note { color:#51443a; margin:.4rem 0 1rem; }
        .refund-modal-error { color:#a11f1f; font-weight:700; min-height:1.5rem; margin-bottom:.5rem; }
        .board-section { display:flex; justify-content:space-between; align-items:center; gap:.75rem; margin:1.5rem 0 .8rem; }
        .board-section h2 { color:#fff; margin:0; font-size:1.4rem; }
        #refreshState { color:#d1dbe1; font-weight:600; }
        .board-empty { color:#d1dbe1; padding:3rem 1rem; text-align:center; grid-column:1/-1; border:1px dashed #61717a; border-radius:14px; background:#1e2930; }
        .urgent-board { background:#fff6f2; color:#842c26; border:4px solid #d22a25; border-radius:13px 18px 11px 16px; padding:1rem 1.2rem; margin-bottom:1rem; transform:rotate(-.2deg); }
        .urgent-board h2 { color:#ad211c; font-size:1.4rem; margin:0 0 .35rem; }
        .urgent-board button { margin-top:.6rem; }
        .board-error { color:#ffc3bf; }
        .board-shell :focus-visible { outline-color:#fff; box-shadow:0 0 0 3px rgba(255,255,255,.35); }
        .order-card :focus-visible, .urgent-board :focus-visible { outline-color:#714018; box-shadow:0 0 0 3px rgba(113,64,24,.25); }
        @media (max-width:600px) {
            .board-brand { align-items:flex-start; }
            .board-actions { width:100%; }
            .board-actions .btn { flex:1 1 auto; }
            .order-card { padding:1rem; }
        }

        /* ---------------- 视觉提升 ---------------- */
        body {
            background:
                radial-gradient(1400px 700px at 88% -20%, rgba(192, 122, 42, 0.20), transparent 60%),
                radial-gradient(1000px 600px at -10% 10%, rgba(96, 128, 168, 0.16), transparent 55%),
                linear-gradient(160deg, #10161b 0%, #151b20 45%, #10151a 100%);
        }
        .board-header { border-bottom-width: 1px; }
        .board-header h1 { font-weight: 800; letter-spacing: 0.04em; }
        .board-header p { font-size: 1rem; }

        .order-card {
            border-width: 2px;
            border-radius: 20px;
            box-shadow: 0 14px 34px rgba(0, 0, 0, 0.34);
            transition: transform 0.16s var(--ease), box-shadow 0.16s var(--ease);
        }
        .order-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 20px 44px rgba(0, 0, 0, 0.42);
        }
        .order-ticket {
            font-size: clamp(1.5rem, 2.4vw, 2rem);
            font-weight: 800;
            letter-spacing: 0.05em;
        }
        .order-picked {
            margin-top: 0.85rem;
            padding: 0.55rem 0.8rem;
            border-radius: 10px;
            background: #e6f6ec;
            border: 1px solid #a8dabd;
            color: #155b34;
            font-weight: 700;
            text-align: center;
        }
        .order-pick-row { display: flex; justify-content: flex-end; margin-top: 0.85rem; }
        .order-pick-row .btn { min-height: 46px; font-size: 0.98rem; }

        .order-ticket small {
            margin-right: 0.18rem;
            font-size: 0.58em;
            font-weight: 700;
            letter-spacing: 0.1em;
            color: #8a6a3a;
        }
        .order-member { font-size: 1.12rem; }

        .board-section { margin-top: 1.9rem; }
        .board-section h2 { font-size: 1.5rem; font-weight: 800; letter-spacing: 0.02em; }
        .board-section h2 span { color: #f0d9b5; font-weight: 650; font-size: 1.05rem; }

        .drink-row {
            border-radius: 12px;
            transition: background 0.16s var(--ease), border-color 0.16s var(--ease);
        }
        .drink-actions .btn { min-height: 48px; font-size: 0.95rem; }
        .drink-actions .btn.btn-danger,
        .drink-actions .btn.btn-warning { min-width: 84px; }

        .board-empty {
            border-style: dashed;
            border-width: 2px;
            border-radius: 18px;
            font-size: 1.05rem;
        }
        .urgent-board { box-shadow: 0 12px 30px rgba(210, 42, 37, 0.22); }

        /* ================= 视觉增强 ================= */

        /* 待制作卡片：左侧暖色强调条，一眼分辨未完成 */
        .order-card:not(.done) {
            border-left-width: 9px;
            border-left-color: #d97b16;
        }
        /* 已完成卡片：弱化边框，让待制作更突出 */
        .order-card.done {
            border-color: #4a8f6a;
            border-left-width: 9px;
            border-left-color: #4a8f6a;
        }

        /* 号牌更醒目 */
        .order-ticket {
            font-size: clamp(1.9rem, 3vw, 2.5rem) !important;
            box-shadow: 0 4px 12px rgba(210, 154, 54, 0.28);
        }
        .order-card.done .order-ticket {
            background: #e6f4ea;
            color: #155b34;
            border-color: #9ccdaf;
            box-shadow: none;
        }

        /* 卡内信息层次：订单号弱化、顾客名突出 */
        .order-id { font-size: 0.95rem !important; font-weight: 700; color: #6b7b86; }
        .order-time { font-size: 0.82rem; color: #8a99a3; }
        .order-member { font-size: 1.18rem; }
        .order-note {
            background: #fff6e0;
            border-left: 4px solid #e0a83c;
            padding: 0.45rem 0.6rem;
            border-radius: 8px;
        }

        /* 制作按钮触控更舒适 */
        .drink-actions .btn { min-height: 50px; }
        .drink-check { flex: 0 0 50px; width: 50px; height: 50px; font-size: 1.6rem; }

        /* 卡片轻微悬停反馈（大屏鼠标操作时） */
        .order-card { transition: box-shadow 0.18s ease, transform 0.18s ease; }
        .order-card:hover { box-shadow: 0 14px 36px #0007; }
    </style>
</head>
<body>
<div class="board-shell">
    <header class="board-header">
        <div class="board-brand">
            <div><h1>制作总览</h1><p>今日消费实时更新 · <?= htmlspecialchars((string) ($staff['name'] ?? '')) ?></p></div>
        </div>
        <div class="board-actions"><button type="button" class="btn btn-secondary" id="fullscreenButton">全屏展示</button><a href="/logout.php" class="btn btn-secondary">退出登录</a></div>
    </header>
    <div id="urgentAnnouncements"></div>
    <div class="board-section"><h2>待制作 <span id="pendingCount">0 单 · 0 杯</span></h2><span id="refreshState">正在连接…</span></div>
    <div class="board-grid" id="pendingOrders"></div>
    <div class="board-section"><h2>已处理</h2></div>
    <div class="board-grid" id="doneOrders"></div>
</div>
<div class="modal-overlay" id="refundModal" role="dialog" aria-modal="true" aria-labelledby="refundModalTitle" style="display:none">
    <div class="modal-card">
        <div class="modal-header"><h3 id="refundModalTitle">退款当前饮品</h3><button type="button" class="modal-close" id="closeRefundModal" aria-label="关闭">×</button></div>
        <p class="refund-modal-note" id="refundItemLabel"></p>
        <form id="refundForm">
            <div class="form-group"><label for="refundAmount">本杯退款金额（元）</label><input id="refundAmount" name="refund_amount" type="number" min="0.01" step="0.01" required><p class="form-help" id="refundAmountHelp"></p></div>
            <div class="form-group"><label for="refundReason">退款原因 <span class="required">*</span></label><textarea id="refundReason" name="reason" rows="3" maxlength="200" required placeholder="填写这杯饮品的退款原因"></textarea></div>
            <p class="refund-modal-error" id="refundError" role="alert"></p>
            <div class="form-actions"><button type="submit" class="btn btn-danger" id="confirmRefund">确认退款</button><button type="button" class="btn btn-secondary" id="cancelRefund">取消</button></div>
        </form>
    </div>
</div>
<script>
const csrfToken = <?= json_encode(csrfToken()) ?>;
const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const pendingEl = document.getElementById('pendingOrders');
const doneEl = document.getElementById('doneOrders');
let loading = false;
let lastOrders = [];
let refundTarget = null;
function card(order) {
    const drinks = order.prep_items || [];
    const completed = drinks.filter(drink => drink.status === 'done').length;
    const refunded = drinks.filter(drink => drink.status === 'refunded').length;
    const done = completed + refunded === drinks.length;
    return `<article class="order-card ${done ? 'done' : ''}">
        ${order.display_no ? '<div class="order-ticket order-no-' + esc(order.display_kind || 'ticket') + '">' + (order.display_kind === 'phone' ? '<small>尾号</small>' : (order.display_kind === 'order' ? '<small>订单</small>#' : '')) + esc(order.display_no) + '</div>' : ''}
        <div class="order-top"><span class="order-id">订单 #${Number(order.id)}</span><span class="order-time">${esc(order.create_time)}</span></div>
        <div class="order-member">${esc(order.member_name || '散客')}</div>
        ${order.note ? `<div class="order-note">备注：${esc(order.note)}</div>` : ''}
        <div class="order-progress"><span>饮品明细</span><strong>完成 ${completed} / ${drinks.length}${refunded ? ` · 退款 ${refunded}` : ''}</strong></div>
        <div class="drink-list">${drinks.map((drink, index) => `<div class="drink-row ${drink.status === 'done' ? 'is-done' : drink.status === 'refunded' ? 'is-refunded' : ''}">
            <div class="drink-main"><span class="drink-number">第${index + 1}杯</span><strong class="drink-name">${esc(drink.name)}</strong></div>
            ${drink.status === 'refunded' ? `<div class="drink-refunded">已退款 ¥${Number(drink.refund_amount || 0).toFixed(2)}</div>` : `<div class="drink-actions">
                ${drink.status === 'done' ? `<span class="drink-check is-complete" aria-label="已完成" title="已完成">✓</span>` : `<button type="button" class="btn btn-success drink-check" data-done-item="${Number(order.id)}" data-item-index="${index}" aria-label="完成第${index + 1}杯 ${esc(drink.name)}" title="完成这杯">✓</button>`}
                <button type="button" class="btn btn-danger" data-refund-item="${Number(order.id)}" data-item-index="${index}" ${order.record_type !== 'sale' || Number(order.remaining_amount || 0) <= 0 || (Object.prototype.hasOwnProperty.call(drink, 'charged_amount') && Number(drink.charged_amount) <= 0) ? 'disabled title="本杯没有可退金额"' : ''}>退款</button>
                <button type="button" class="btn btn-warning" data-redo-item="${Number(order.id)}" data-item-index="${index}" ${drink.status !== 'done' ? 'disabled title="完成后可重做"' : ''}>重做</button>
            </div>${drink.status === 'done' ? `<div class="order-complete-time">${esc(drink.prepared_at || '')}${Number(drink.redo_count || 0) ? ` · 已重做 ${Number(drink.redo_count)} 次` : ''}</div>` : Number(drink.redo_count || 0) ? `<div class="order-complete-time">重做中 · 第${Number(drink.redo_count)}次</div>` : ''}`}
        </div>`).join('')}</div>
        <div class="order-meta">${Number(order.refunded_amount || 0) > 0 ? `订单实付 ¥${Number(order.amount || 0).toFixed(2)} · 已退款 ¥${Number(order.refunded_amount).toFixed(2)} · 剩余 ¥${Number(order.remaining_amount || 0).toFixed(2)}` : `订单总额 ¥${Number(order.amount || 0).toFixed(2)}`} · ${esc(order.payment_label)} · 登记：${esc(order.operator || '—')}</div>
        ${order.prep_status === 'done' ? (order.picked_up_at ? '<div class="order-picked">已取餐 ' + esc(String(order.picked_up_at).slice(11, 16)) + '</div>' : (window.BOARD_CAN_OPERATE ? '<div class="order-pick-row"><button type="button" class="btn btn-success" data-pick-order="' + Number(order.id) + '">标记已取餐</button></div>' : '')) : ''}
    </article>`;
}
async function loadBoard() {
    if (loading) return;
    loading = true;
    try {
        const response = await fetch('/api/order_board.php', {cache:'no-store'});
        if (response.status === 401 || response.redirected) { location.href = '/login.php'; return; }
        if (!response.ok) throw new Error('加载失败');
        const data = await response.json();
        const orders = data.orders.filter(order => (order.prep_items || []).length > 0);
        lastOrders = orders;
        const pending = orders.filter(order => order.prep_items.some(drink => drink.status === 'pending'));
        const done = orders.filter(order => order.prep_items.every(drink => drink.status !== 'pending'));
        const pendingDrinks = pending.reduce((count, order) => count + order.prep_items.filter(drink => drink.status === 'pending').length, 0);
        document.getElementById('pendingCount').textContent = `${pending.length} 单 · ${pendingDrinks} 杯`;
        pendingEl.innerHTML = pending.length ? pending.map(card).join('') : '<div class="board-empty">当前没有待制作订单</div>';
        doneEl.innerHTML = done.length ? done.map(card).join('') : '<div class="board-empty">暂无已处理订单</div>';

        // 只读角色（如接待）不显示任何操作按钮
        if (!window.BOARD_CAN_OPERATE) {
            document.querySelectorAll('[data-done-item], [data-refund-item], [data-redo-item], [data-pick-order]').forEach(function (button) {
                button.remove();
            });
        }
        document.getElementById('urgentAnnouncements').innerHTML = data.urgent.map(a => `<section class="urgent-board"><h2>紧急公告 · ${esc(a.title)}</h2><div>${esc(a.content).replace(/\n/g,'<br>')}</div><button class="btn btn-danger" data-read="${Number(a.id)}">已读，隐藏公告</button></section>`).join('');
        document.getElementById('refreshState').textContent = '实时同步中';
    } catch (error) {
        document.getElementById('refreshState').innerHTML = '<span class="board-error">连接失败，正在重试</span>';
    } finally { loading = false; }
}
let saving = false;
const refundModal = document.getElementById('refundModal');
function closeRefundModal() {
    refundModal.style.display = 'none';
    refundTarget = null;
    document.getElementById('refundForm').reset();
    document.getElementById('refundError').textContent = '';
}
function openRefundModal(orderId, itemIndex) {
    const order = lastOrders.find(row => Number(row.id) === orderId);
    const item = order?.prep_items?.[itemIndex];
    if (!order || !item || item.status === 'refunded') return;
    refundTarget = {orderId, itemIndex};
    document.getElementById('refundItemLabel').textContent = `订单 #${orderId} · 第${itemIndex + 1}杯 ${item.name}`;
    const amountInput = document.getElementById('refundAmount');
    const hasSavedAmount = Object.prototype.hasOwnProperty.call(item, 'charged_amount');
    amountInput.readOnly = hasSavedAmount;
    amountInput.max = Number(order.remaining_amount || 0).toFixed(2);
    amountInput.value = hasSavedAmount ? Number(item.charged_amount).toFixed(2) : '';
    const amountHelp = hasSavedAmount
        ? '按下单时这杯饮品分摊的实付金额退款；系统将同步调整消费金额和积分。'
        : `旧订单未保存逐杯实付金额，请填写这杯实际退款金额，最多 ¥${Number(order.remaining_amount || 0).toFixed(2)}。`;
    document.getElementById('refundAmountHelp').textContent = amountHelp + (order.payment_method === 'balance'
        ? ' 余额支付会自动退回会员余额。'
        : ' 现金、微信或支付宝请同步在原收款渠道完成实际退款，本系统负责记账。');
    document.getElementById('refundError').textContent = '';
    refundModal.style.display = 'flex';
    document.getElementById('refundReason').focus();
}
document.getElementById('closeRefundModal').addEventListener('click', closeRefundModal);
document.getElementById('cancelRefund').addEventListener('click', closeRefundModal);
document.addEventListener('keydown', event => { if (event.key === 'Escape' && refundModal.style.display !== 'none') closeRefundModal(); });
document.getElementById('refundForm').addEventListener('submit', async event => {
    event.preventDefault();
    if (!refundTarget || saving) return;
    const amount = document.getElementById('refundAmount').value;
    const reason = document.getElementById('refundReason').value.trim();
    if (!reason || !amount) return;
    saving = true;
    const submit = document.getElementById('confirmRefund');
    submit.disabled = true;
    document.getElementById('refundError').textContent = '';
    const body = new URLSearchParams({csrf_token:window.__DSH_CSRF__ || csrfToken, action:'refund_item', id:String(refundTarget.orderId), item_index:String(refundTarget.itemIndex), refund_amount:amount, reason});
    try {
        const response = await fetch('/api/order_board.php', {method:'POST', body, cache:'no-store'});
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || '退款失败');
        closeRefundModal();
        await loadBoard();
    } catch (error) {
        document.getElementById('refundError').textContent = error.message || '退款失败，请重试';
    } finally { saving = false; submit.disabled = false; }
});
document.addEventListener('click', async event => {
    const button = event.target.closest('[data-done-item], [data-redo-item], [data-refund-item], [data-read], [data-pick-order]');
    if (!button) return;
    if (saving) return;
    if (button.dataset.refundItem) {
        openRefundModal(Number(button.dataset.refundItem), Number(button.dataset.itemIndex));
        return;
    }
    if (button.dataset.pickOrder) {
        saving = true;
        button.disabled = true;
        const pickBody = new URLSearchParams({csrf_token:window.__DSH_CSRF__ || csrfToken, action:'pick_up', id:button.dataset.pickOrder});
        try {
            const pickResponse = await fetch('/api/order_board.php', {method:'POST', body:pickBody, cache:'no-store'});
            const pickResult = await pickResponse.json().catch(() => null);
            if (!pickResponse.ok) throw new Error((pickResult && pickResult.error) || '操作失败');
            await loadBoard();
        } catch (error) { button.disabled = false; alert(error.message || '操作失败，请重试'); }
        finally { saving = false; }
        return;
    }
    if (button.dataset.redoItem && !confirm('确认重做当前这杯饮品？')) return;
    saving = true;
    button.disabled = true;
    const action = button.dataset.doneItem ? 'done_item' : button.dataset.redoItem ? 'redo_item' : 'read_urgent';
    const body = new URLSearchParams({csrf_token:window.__DSH_CSRF__ || csrfToken, action, id:button.dataset.doneItem || button.dataset.redoItem || button.dataset.read});
    if (action !== 'read_urgent') body.set('item_index', button.dataset.itemIndex);
    try {
        const response = await fetch('/api/order_board.php', {method:'POST', body, cache:'no-store'});
        if (!response.ok) {
            const result = await response.json();
            throw new Error(result.error || '提交失败');
        }
        await loadBoard();
    } catch (error) { button.disabled = false; alert(error.message || '操作失败，请重试'); }
    finally { saving = false; }
});
document.getElementById('fullscreenButton').addEventListener('click', () => {
    if (document.fullscreenElement) document.exitFullscreen();
    else document.documentElement.requestFullscreen();
});
loadBoard(); setInterval(loadBoard, 3000);
</script>
</body>
</html>

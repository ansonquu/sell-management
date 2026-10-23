<?php

/**
 * 客户大屏：显示「请取餐」（已完成）与「请等待」（制作中，含杯数进度）。
 * 只显示取餐号与脱敏姓名，独立全屏页面，自动刷新。
 */

session_start();
require_once __DIR__ . '/includes/functions.php';

requireLogin();
if (!isLicenseActive()) {
    header('Location: /activate.php');
    exit;
}

$staff = getCurrentStaff();
if (!canAccessCustomerBoard($staff)) {
    flash('error', '无权访问客户大屏');
    redirect('/');
}

$faviconUrl = getFaviconUrl();
$siteName = getSiteName();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>取餐提示 - <?= htmlspecialchars($siteName) ?></title>
    <link rel="icon" href="<?= htmlspecialchars($faviconUrl) ?>">
    <link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
    <style>
        body.customer-board {
            min-height: 100vh;
            margin: 0;
            background:
                radial-gradient(1200px 600px at 85% -15%, rgba(201, 138, 61, 0.18), transparent 60%),
                radial-gradient(900px 500px at -10% 20%, rgba(111, 78, 55, 0.10), transparent 55%),
                var(--bg);
            display: flex;
            flex-direction: column;
        }
        .cb-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
            padding: 1.3rem 2rem 0.9rem;
        }
        .cb-head h1 {
            margin: 0;
            font-size: clamp(1.5rem, 2.8vw, 2.3rem);
            font-weight: 800;
            letter-spacing: 0.05em;
            color: var(--espresso-800);
        }
        .cb-favicon {
            width: 1.45em;
            height: 1.45em;
            margin-right: 0.45rem;
            border-radius: 0.32em;
            object-fit: cover;
            vertical-align: -0.34em;
        }
        .cb-meta { display: flex; align-items: center; gap: 1.1rem; color: var(--text-muted); font-size: 1rem; }
        .cb-meta strong { color: var(--caramel-strong); font-size: 1.2rem; }
        .cb-exit {
            padding: 0.4rem 0.9rem;
            border: 1px solid var(--border-strong);
            border-radius: var(--radius-pill);
            color: var(--text-soft);
            font-size: 0.85rem;
            text-decoration: none;
            background: rgba(255, 255, 255, 0.7);
        }
        .cb-exit:hover { background: #fff; color: var(--caramel-strong); }

        .cb-main { flex: 1; padding: 0 2rem 2rem; display: flex; flex-direction: column; gap: 1.6rem; }

        .cb-section-title {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            margin: 0 0 0.85rem;
            font-size: clamp(1.2rem, 2.1vw, 1.6rem);
            font-weight: 800;
            letter-spacing: 0.05em;
            color: var(--caramel-strong);
        }
        .cb-section-title::before {
            content: "";
            width: 0.62em;
            height: 0.62em;
            border-radius: 50%;
            background: var(--caramel);
            box-shadow: 0 0 0 6px rgba(192, 122, 42, 0.16);
            flex: 0 0 auto;
        }
        .cb-section-title.is-waiting { color: var(--espresso-600); }
        .cb-section-title.is-waiting::before {
            background: var(--espresso-300);
            box-shadow: 0 0 0 6px rgba(107, 67, 36, 0.12);
        }

        .cb-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 1rem;
            align-content: start;
        }
        .cb-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.3rem;
            padding: 1.25rem 1rem;
            border-radius: 20px;
            border: 2px solid transparent;
        }
        /* 请取餐卡片 */
        .cb-card.is-ready {
            background: #fff;
            box-shadow: 0 8px 26px rgba(74, 46, 26, 0.12);
        }
        .cb-card.is-ready .cb-ticket {
            font-size: clamp(2.4rem, 4.6vw, 3.3rem);
            font-weight: 800;
            line-height: 1.05;
            color: var(--caramel-strong);
            letter-spacing: 0.05em;
            word-break: break-all;
        }
        /* 请等待卡片 */
        .cb-card.is-waiting {
            background: linear-gradient(180deg, #fff 0%, #f6f2ec 100%);
            border-color: var(--border);
            box-shadow: 0 4px 16px rgba(74, 46, 26, 0.07);
        }
        .cb-card.is-waiting .cb-ticket {
            font-size: clamp(1.7rem, 3vw, 2.2rem);
            font-weight: 800;
            line-height: 1.1;
            color: var(--espresso-600);
            letter-spacing: 0.04em;
            word-break: break-all;
        }
        .cb-name { font-size: 1.15rem; font-weight: 600; color: var(--text-soft); letter-spacing: 0.08em; }
        .cb-ticket small {
            display: block;
            margin-bottom: 0.05rem;
            font-size: 0.36em;
            font-weight: 700;
            letter-spacing: 0.16em;
            color: var(--text-muted);
        }
        .cb-card.is-waiting .cb-ticket small { font-size: 0.44em; color: var(--espresso-300); }

        .cb-progress { width: 100%; margin-top: 0.5rem; text-align: center; }
        .cb-bar {
            height: 7px;
            border-radius: 999px;
            background: rgba(107, 67, 36, 0.12);
            overflow: hidden;
        }
        .cb-bar i {
            display: block;
            height: 100%;
            border-radius: 999px;
            background: linear-gradient(90deg, var(--caramel), #d9a253);
            transition: width 0.4s var(--ease);
        }
        .cb-progress-text {
            margin-top: 0.42rem;
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--espresso-600);
            line-height: 1.45;
        }
        .cb-progress-text em { font-style: normal; color: var(--caramel-strong); }

        .cb-card.is-new {
            animation: cb-pop 1.6s var(--ease);
        }
        @keyframes cb-pop {
            0% { transform: scale(0.92); box-shadow: 0 0 0 6px rgba(192, 122, 42, 0.25); }
            60% { transform: scale(1.02); }
            100% { transform: scale(1); }
        }
        .cb-empty {
            grid-column: 1 / -1;
            padding: 1.6rem 1rem;
            text-align: center;
            color: var(--text-muted);
            font-size: 1rem;
            border: 1px dashed var(--border-strong);
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.55);
        }
        /* 可点击领取的卡片 */
        .cb-card.is-clickable { cursor: pointer; transition: transform 0.14s var(--ease), box-shadow 0.14s var(--ease); }
        .cb-card.is-clickable:hover { transform: translateY(-3px); box-shadow: 0 14px 32px rgba(74, 46, 26, 0.18); }
        .cb-card.is-clickable:active { transform: translateY(0) scale(0.985); }
        .cb-hint { margin-top: 0.3rem; font-size: 0.76rem; color: var(--text-muted); }

        /* 已领取折叠区 */
        .cb-picked { margin-top: 0.4rem; }
        .cb-picked > summary {
            padding: 0.65rem 1rem;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.7);
            color: var(--text-soft);
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            list-style: none;
        }
        .cb-picked > summary::-webkit-details-marker { display: none; }
        .cb-picked > summary::before { content: "▸ "; color: var(--caramel); }
        .cb-picked[open] > summary::before { content: "▾ "; }
        .cb-grid-picked { margin-top: 0.7rem; }
        .cb-grid-picked .cb-card {
            background: rgba(255, 255, 255, 0.62);
            border-color: var(--border);
            box-shadow: none;
            opacity: 0.85;
        }
        .cb-grid-picked .cb-ticket { font-size: clamp(1.3rem, 2.2vw, 1.7rem); color: var(--text-soft); }

        .cb-foot {
            padding: 0.9rem 2rem 1.3rem;
            text-align: center;
            color: var(--text-muted);
            font-size: 0.88rem;
        }
        @media (max-width: 640px) {
            .cb-head, .cb-main, .cb-foot { padding-left: 1rem; padding-right: 1rem; }
            .cb-grid { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); }
        }

        /* ================= 视觉增强 ================= */

        /* 顶部信息条：更清晰的层次 */
        .cb-meta { gap: 1.25rem; }
        .cb-meta > span { display: inline-flex; align-items: baseline; gap: 0.35rem; }
        .cb-meta strong { font-size: 1.35rem; font-weight: 800; }

        /* 取餐卡片：号牌更醒目 + 「请取餐」标识 */
        .cb-card.is-ready {
            border-width: 2px;
            border-color: var(--caramel);
            background: linear-gradient(180deg, #fffdf8, #fff6e9);
            box-shadow: 0 10px 26px rgba(168, 100, 20, 0.14);
        }
        .cb-card.is-ready .cb-ticket {
            font-size: clamp(2.1rem, 4.4vw, 3.2rem);
            letter-spacing: 0.02em;
        }
        .cb-card.is-ready .cb-name { font-size: 1.02rem; font-weight: 700; }

        /* 等待卡片：弱化处理，突出进度条 */
        .cb-card.is-waiting {
            background: linear-gradient(180deg, #ffffff, #fbf8f4);
            border-color: var(--border);
        }
        .cb-card.is-waiting .cb-ticket { color: var(--espresso-700); }

        /* 新订单更有存在感 */
        .cb-card.is-new { animation: cb-pop 0.5s var(--ease); }
        @keyframes cb-pop {
            0%   { transform: scale(0.94); box-shadow: 0 0 0 0 rgba(168, 100, 20, 0.5); }
            60%  { transform: scale(1.015); }
            100% { transform: scale(1); box-shadow: 0 0 0 14px rgba(168, 100, 20, 0); }
        }

        /* 已领取折叠区：更紧凑、更像"收纳" */
        .cb-picked > summary {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(255, 255, 255, 0.55);
            color: var(--text-muted);
            font-size: 0.95rem;
            border-style: dashed;
        }
        .cb-picked > summary:hover { background: rgba(255, 255, 255, 0.8); }
        .cb-grid-picked { gap: 0.55rem; }
        .cb-grid-picked .cb-card { padding: 0.7rem 0.5rem; border-radius: 12px; }
        .cb-grid-picked .cb-ticket { font-size: clamp(1.15rem, 2vw, 1.5rem); }
        .cb-grid-picked .cb-name { font-size: 0.86rem; }

        /* 卡片内的提示文字 */
        .cb-hint { font-weight: 600; letter-spacing: 0.02em; }

        /* 卡片顶部的状态标识 */
        .cb-badge-ready {
            display: inline-block;
            margin-bottom: 0.4rem;
            padding: 0.16rem 0.6rem;
            border-radius: 999px;
            background: var(--caramel);
            color: #fff;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.14em;
        }
    </style>
</head>
<body class="customer-board">
    <header class="cb-head">
        <h1>
            <img class="cb-favicon" src="<?= htmlspecialchars($faviconUrl) ?>" alt="">
            <?= htmlspecialchars($siteName) ?> · 取餐提示
        </h1>
        <div class="cb-meta">
            <span id="cbClock">--:--</span>
            <span>待取 <strong id="cbReadyCount">0</strong> 单 · 制作中 <strong id="cbWaitCount">0</strong> 单</span>
            <button type="button" class="cb-exit" id="cbFullscreen">全屏</button>
            <a class="cb-exit" href="/logout.php">退出</a>
        </div>
    </header>

    <main class="cb-main">
        <section>
            <h2 class="cb-section-title">请取餐</h2>
            <div class="cb-grid" id="cbReady">
                <div class="cb-empty">正在加载…</div>
            </div>
        </section>
        <section>
            <h2 class="cb-section-title is-waiting">请等待</h2>
            <div class="cb-grid" id="cbWaiting">
                <div class="cb-empty">正在加载…</div>
            </div>
        </section>

        <details class="cb-picked" id="cbPickedWrap">
            <summary>已领取（<span id="cbPickedCount">0</span>）</summary>
            <div class="cb-grid cb-grid-picked" id="cbPicked"></div>
        </details>
    </main>

    <footer class="cb-foot">请凭号牌取餐 · 如有疑问请咨询店员</footer>

    <script>
    (function () {
        const readyEl = document.getElementById('cbReady');
        const waitingEl = document.getElementById('cbWaiting');
        const pickedEl = document.getElementById('cbPicked');
        const pickedCountEl = document.getElementById('cbPickedCount');
        const readyCountEl = document.getElementById('cbReadyCount');
        const waitCountEl = document.getElementById('cbWaitCount');
        const clockEl = document.getElementById('cbClock');
        const knownReady = new Set();
        const knownWaiting = new Set();
        let firstLoad = true;
        let canOperate = false;
        let csrfToken = '';

        function fetchToken() {
            return fetch('/api/csrf_token.php?_=' + Date.now(), { cache: 'no-store', credentials: 'same-origin' })
                .then(response => (response.ok ? response.json() : null))
                .then(data => { if (data && data.token) csrfToken = data.token; })
                .catch(() => {});
        }

        const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

        /* 号码显示：号牌 → 手机尾号 → 订单号 */
        function numberHtml(order) {
            const kind = order.display_kind || 'ticket';
            const no = esc(order.display_no || order.ticket || '');
            if (kind === 'phone') {
                return '<div class="cb-ticket is-phone"><small>尾号</small>' + no + '</div>';
            }
            if (kind === 'order') {
                return '<div class="cb-ticket is-order"><small>订单</small>#' + no + '</div>';
            }
            return '<div class="cb-ticket">' + no + '</div>';
        }

        function readyCard(order) {
            const isNew = !firstLoad && !knownReady.has(order.id);
            return '<article class="cb-card is-ready' + (isNew ? ' is-new' : '') + (canOperate ? ' is-clickable' : '') + '"'
                + (canOperate ? ' data-pick="' + order.id + '"' : '') + '>'
                + '<div class="cb-badge-ready">请取餐</div>'
                + numberHtml(order)
                + '<div class="cb-name">' + esc(order.name) + '</div>'
                + (canOperate ? '<div class="cb-hint">点此标记已领取</div>' : '')
                + '</article>';
        }

        function pickedCard(order) {
            const time = String(order.picked_up_at || '').slice(11, 16);
            return '<article class="cb-card is-picked">'
                + numberHtml(order)
                + '<div class="cb-name">' + esc(order.name) + '</div>'
                + '<div class="cb-hint">' + (time ? esc(time) + ' ' : '') + '已领取</div>'
                + '</article>';
        }

        function waitingCard(order) {
            const isNew = !firstLoad && !knownWaiting.has(order.id);
            const total = Number(order.total_cups) || 0;
            const done = Number(order.done_cups) || 0;
            const remaining = Number(order.remaining_cups) || 0;
            const percent = total > 0 ? Math.round((done / total) * 100) : 0;

            let progressText;
            if (total <= 1) {
                progressText = '制作中…';
            } else if (done <= 0) {
                progressText = '共 <em>' + total + '</em> 杯 · 还没做好';
            } else if (remaining <= 0) {
                progressText = '共 <em>' + total + '</em> 杯 · 全部做好';
            } else {
                progressText = '还有 <em>' + remaining + '</em> 杯没做 · 已好 <em>' + done + '</em> 杯';
            }

            return '<article class="cb-card is-waiting' + (isNew ? ' is-new' : '') + '">'
                + numberHtml(order)
                + '<div class="cb-name">' + esc(order.name) + '</div>'
                + '<div class="cb-progress">'
                + '<div class="cb-bar"><i style="width:' + percent + '%"></i></div>'
                + '<div class="cb-progress-text">' + progressText + '</div>'
                + '</div>'
                + '</article>';
        }

        function render(data) {
            const ready = (data && data.ready) || [];
            const waiting = (data && data.waiting) || [];
            const picked = (data && data.picked) || [];
            canOperate = Boolean(data && data.can_operate);

            readyCountEl.textContent = ready.length;
            waitCountEl.textContent = waiting.length;
            pickedCountEl.textContent = picked.length;

            readyEl.innerHTML = ready.length
                ? ready.map(readyCard).join('')
                : '<div class="cb-empty">暂无待取餐订单</div>';
            waitingEl.innerHTML = waiting.length
                ? waiting.map(waitingCard).join('')
                : '<div class="cb-empty">暂无制作中的订单</div>';
            pickedEl.innerHTML = picked.length
                ? picked.map(pickedCard).join('')
                : '<div class="cb-empty">暂无已领取记录</div>';

            ready.forEach(order => knownReady.add(order.id));
            waiting.forEach(order => knownWaiting.add(order.id));
            firstLoad = false;
        }

        /* 点单卡片标记「已领取」 */
        function pickUp(orderId, card) {
            const body = new URLSearchParams({ action: 'pick_up', id: String(orderId), csrf_token: csrfToken });
            if (card) card.style.opacity = '0.5';
            fetch('/api/customer_board.php', {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
            })
                .then(response => (response.ok ? response.json() : null))
                .then(data => {
                    if (card) card.style.opacity = '';
                    if (data && data.ok) {
                        poll();
                    } else if (data && data.error) {
                        alert(data.error);
                    }
                })
                .catch(() => { if (card) card.style.opacity = ''; });
        }

        document.addEventListener('click', function (event) {
            const card = event.target.closest('[data-pick]');
            if (!card || !canOperate) return;
            const orderId = card.dataset.pick;
            if (!csrfToken) {
                fetchToken().then(() => { if (csrfToken) pickUp(orderId, card); });
                return;
            }
            pickUp(orderId, card);
        });

        /* 全屏切换 */
        const fullscreenButton = document.getElementById('cbFullscreen');
        if (fullscreenButton) {
            fullscreenButton.addEventListener('click', function () {
                if (!document.fullscreenElement) {
                    if (document.documentElement.requestFullscreen) {
                        document.documentElement.requestFullscreen().catch(function () {});
                    }
                } else if (document.exitFullscreen) {
                    document.exitFullscreen();
                }
            });
        }

        function poll() {
            fetch('/api/customer_board.php?_=' + Date.now(), { cache: 'no-store', credentials: 'same-origin' })
                .then(response => (response.ok ? response.json() : null))
                .then(data => { if (data) render(data); })
                .catch(() => {});
        }

        function tick() {
            clockEl.textContent = new Date().toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit' });
        }

        tick();
        fetchToken();
        poll();
        setInterval(tick, 10000);
        setInterval(poll, 4000);
    })();
    </script>
</body>
</html>

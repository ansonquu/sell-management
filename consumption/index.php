<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';

$pdo = getDb();
$keyword = trim($_GET['q'] ?? '');

$sql = "
    SELECT c.*, m.name as member_name, m.phone, ct.name as coupon_name
    FROM consumption c
    LEFT JOIN member m ON c.member_id = m.id
    LEFT JOIN member_coupons mc ON c.coupon_id = mc.id
    LEFT JOIN coupon_templates ct ON mc.template_id = ct.id
    WHERE 1=1
";
$params = [];

if (preg_match('/^#(\d+)$/', $keyword, $idMatch)) {
    $sql .= ' AND c.id = ?';
    $params[] = (int) $idMatch[1];
} elseif ($keyword !== '') {
    $sql .= ' AND (m.name LIKE ? OR m.phone LIKE ? OR c.items LIKE ?)';
    $params[] = "%{$keyword}%";
    $params[] = "%{$keyword}%";
    $params[] = "%{$keyword}%";
}

// 默认只显示当天的记录；传 date=all 可查看全部，或指定具体日期
$dateFilter = trim((string) ($_GET['date'] ?? ''));
if ($dateFilter === '') {
    $dateFilter = date('Y-m-d');
}
if ($dateFilter === 'all') {
    // 不按日期过滤
} elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter)) {
    $sql .= ' AND DATE(c.create_time) = ?';
    $params[] = $dateFilter;
} else {
    $dateFilter = date('Y-m-d');
    $sql .= ' AND DATE(c.create_time) = ?';
    $params[] = $dateFilter;
}

// 最近 120 天内有消费记录的日期（用于日历标记）
$recordDates = $pdo->query("SELECT DISTINCT DATE(create_time) AS d FROM consumption WHERE create_time >= date('now', '-120 days') ORDER BY d")
    ->fetchAll(PDO::FETCH_COLUMN);
$recordDates = array_values(array_filter(array_map('strval', $recordDates)));

$sql .= ' ORDER BY c.create_time DESC LIMIT 100';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();
$visibleFields = getConsumptionRecordFields();
$showField = static fn (string $field): bool => in_array($field, $visibleFields, true);
$visibleColumnCount = count($visibleFields) + 1;

renderHeader('消费记录 - Anson咖啡厅管理系统', 'records');
?>

<div class="page-header">
    <h2 class="page-title">消费记录</h2>
    <div class="page-actions">
        <a href="/refunds/" class="btn btn-secondary">退款记录</a>
        <a href="/consumption/add.php" class="btn btn-primary">+ 消费登记</a>
    </div>
</div>

<form class="search-bar" method="get">
    <?php if ($dateFilter !== '' && $dateFilter !== 'all'): ?><input type="hidden" name="date" value="<?= htmlspecialchars($dateFilter) ?>"><?php elseif ($dateFilter === 'all'): ?><input type="hidden" name="date" value="all"><?php endif; ?>
    <input type="text" name="q" placeholder="搜索会员、手机号或商品..." value="<?= htmlspecialchars($keyword) ?>">
    <button type="submit" class="btn btn-secondary">搜索</button>
    <?php if ($keyword): ?>
        <a href="/consumption/?date=<?= htmlspecialchars($dateFilter) ?>" class="btn btn-link">清除搜索</a>
    <?php endif; ?>
</form>

<div class="date-toolbar">
    <label for="datePicker">日期</label>
    <input type="date" id="datePicker" value="<?= $dateFilter === 'all' ? '' : htmlspecialchars($dateFilter) ?>">
    <a class="btn btn-sm <?= $dateFilter === date('Y-m-d') ? 'btn-primary' : 'btn-secondary' ?>" href="/consumption/?date=<?= date('Y-m-d') ?><?= $keyword ? '&q=' . urlencode($keyword) : '' ?>">今天</a>
    <button type="button" class="btn btn-sm <?= $dateFilter === 'all' ? 'btn-primary' : 'btn-secondary' ?>" id="calendarToggle">日历（有记录的日期）</button>
    <a class="btn btn-sm <?= $dateFilter === 'all' ? 'btn-primary' : 'btn-secondary' ?>" href="/consumption/?date=all<?= $keyword ? '&q=' . urlencode($keyword) : '' ?>">全部记录</a>
    <div class="calendar-popover" id="calendarPopover" hidden>
        <div class="calendar-head">
            <button type="button" id="calPrev" aria-label="上个月">‹</button>
            <strong id="calTitle"></strong>
            <button type="button" id="calNext" aria-label="下个月">›</button>
        </div>
        <div class="calendar-weekdays">
            <span>一</span><span>二</span><span>三</span><span>四</span><span>五</span><span>六</span><span>日</span>
        </div>
        <div class="calendar-grid" id="calendarGrid"></div>
        <div class="calendar-foot"><i class="cal-dot"></i>有消费记录的日期</div>
    </div>
</div>

<?php if ($dateFilter === 'all'): ?>
<p class="settings-help">当前显示全部消费记录，共 <?= count($records) ?> 条（最多 100 条）。</p>
<?php else: ?>
<p class="settings-help">当前显示 <?= htmlspecialchars($dateFilter) ?> 的消费记录，共 <?= count($records) ?> 条。</p>
<?php endif; ?>

<div class="card">
    <table class="table">
        <thead>
            <tr>
                <?php if ($showField('record_id')): ?><th>记录号</th><?php endif; ?>
                <?php if ($showField('time')): ?>
                <th>时间</th>
                <?php endif; ?>
                <?php if ($showField('member')): ?>
                <th>会员</th>
                <?php endif; ?>
                <?php if ($showField('items')): ?>
                <th>商品</th>
                <?php endif; ?>
                <?php if ($showField('amount')): ?>
                <th>金额</th>
                <?php endif; ?>
                <?php if ($showField('payment_method')): ?>
                <th>支付方式</th>
                <?php endif; ?>
                <?php if ($showField('prep_state')): ?>
                <th>制作</th>
                <?php endif; ?>
                <?php if ($showField('ticket_number')): ?>
                <th>号牌</th>
                <?php endif; ?>
                <?php if ($showField('coupon')): ?>
                <th>优惠券</th>
                <?php endif; ?>
                <?php if ($showField('points')): ?>
                <th>积分</th>
                <?php endif; ?>
                <?php if ($showField('operator')): ?>
                <th>操作人</th>
                <?php endif; ?>
                <?php if ($showField('note')): ?>
                <th>备注</th>
                <?php endif; ?>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($records)): ?>
                <tr><td colspan="<?= $visibleColumnCount ?>" class="text-center text-muted">暂无消费记录</td></tr>
            <?php else: ?>
                <?php foreach ($records as $r): ?>
                    <tr>
                        <?php if ($showField('record_id')): ?><td><strong>#<?= (int) $r['id'] ?></strong></td><?php endif; ?>
                        <?php if ($showField('time')): ?>
                        <td><?= formatDateTime($r['create_time']) ?></td>
                        <?php endif; ?>
                        <?php if ($showField('member')): ?>
                        <td>
                            <?php if ($r['member_id'] > 0): ?>
                                <a href="/members/view.php?id=<?= $r['member_id'] ?>">
                                    <?= htmlspecialchars($r['member_name']) ?>
                                </a>
                                <small class="text-muted"><?= htmlspecialchars(memberPhoneDisplay($r['phone'])) ?></small>
                            <?php else: ?>
                                <span class="badge badge-warning">散客</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <?php if ($showField('items')): ?>
                        <td><?= htmlspecialchars(normalizeConsumptionItemsText((string) ($r['items'] ?? '')) ?: '-') ?></td>
                        <?php endif; ?>
                        <?php if ($showField('amount')): ?>
                        <td><?= formatMoney(max(0, (float) $r['amount'] - (float) ($r['refunded_amount'] ?? 0))) ?><?php if ((float) ($r['refunded_amount'] ?? 0) > 0): ?><small class="text-muted">原 <?= formatMoney((float) $r['amount']) ?> · 已退 <?= formatMoney((float) $r['refunded_amount']) ?></small><?php endif; ?></td>
                        <?php endif; ?>
                        <?php if ($showField('payment_method')): ?>
                        <td>
                            <?php $displayPaymentMethod = normalizeStoredPaymentMethod($r['payment_method']); ?>
                            <span class="badge <?= $displayPaymentMethod === 'balance' ? 'badge-success' : 'badge-secondary' ?>">
                                <?= consumptionPaymentMethodLabel($r['payment_method']) ?>
                            </span>
                        </td>
                        <?php endif; ?>
                        <?php if ($showField('prep_state')): ?>
                        <td class="prep-state-cell" data-id="<?= (int) $r['id'] ?>">
                            <?php
                            $prepItems = consumptionPreparationItems($r);
                            $prepActive = array_values(array_filter($prepItems, static fn (array $i): bool => ($i['status'] ?? '') !== 'refunded'));
                            $prepTotal = count($prepActive);
                            $prepDoneCount = count(array_filter($prepActive, static fn (array $i): bool => ($i['status'] ?? '') === 'done'));
                            $isSaleRow = ($r['record_type'] ?? 'sale') === 'sale';
                            ?>
                            <?php if (!$isSaleRow): ?>
                                <span class="text-muted">—</span>
                            <?php elseif (!empty($r['picked_up_at'])): ?>
                                <span class="badge badge-success">已领取</span>
                            <?php elseif (($r['prep_status'] ?? 'done') === 'done'): ?>
                                <span class="badge badge-success">已完成</span>
                                <button type="button" class="btn btn-sm btn-success pick-open" data-id="<?= (int) $r['id'] ?>" title="标记顾客已领取">已领取</button>
                            <?php elseif ($prepDoneCount > 0): ?>
                                <span class="badge badge-warning"><?= $prepDoneCount ?>/<?= $prepTotal ?></span>
                            <?php else: ?>
                                <span class="badge badge-secondary">待制作</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <?php if ($showField('ticket_number')): ?>
                        <td>
                            <?php if ($r['ticket_number']): ?>
                                <span class="badge badge-warning"><?= htmlspecialchars($r['ticket_number']) ?></span>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <?php if ($showField('coupon')): ?>
                        <td><?= htmlspecialchars($r['coupon_name'] ?: '-') ?></td>
                        <?php endif; ?>
                        <?php if ($showField('points')): ?>
                        <td><?php if (($r['record_type'] ?? 'sale') === 'points_redeem'): ?><span class="text-danger">-<?= (int) ($r['points_spent'] ?? 0) ?></span><?php else: ?>+<?= (int) $r['points_earned'] ?><?php endif; ?></td>
                        <?php endif; ?>
                        <?php if ($showField('operator')): ?>
                        <td><?= htmlspecialchars($r['operator'] ?: '-') ?></td>
                        <?php endif; ?>
                        <?php if ($showField('note')): ?>
                        <td><?= htmlspecialchars($r['note'] ?: '-') ?></td>
                        <?php endif; ?>
                        <td>
                            <?php if (($r['record_type'] ?? 'sale') === 'board_redo'): ?>
                                <span class="badge badge-warning">大屏重做</span>
                            <?php elseif (($r['record_type'] ?? 'sale') === 'complaint_redo'): ?>
                                <span class="badge badge-warning">客诉重做</span>
                            <?php elseif (($r['record_type'] ?? 'sale') === 'points_redeem'): ?>
                                <span class="badge badge-warning">积分兑换</span>
                            <?php elseif (($r['record_type'] ?? 'sale') === 'exchange'): ?>
                                <span class="badge badge-warning">换货</span>
                            <?php elseif (str_starts_with((string) ($r['items'] ?? ''), '[换货]')): ?>
                                <span class="badge badge-warning">换货</span>
                                <span class="badge badge-secondary">补差价</span>
                            <?php elseif (!empty($r['exchanged_at'])): ?>
                                <span class="badge badge-secondary">已换货</span>
                            <?php elseif (!empty($r['refunded_at'])): ?>
                                <span class="badge badge-secondary">已退款</span>
                            <?php else: ?>
                                <?php if ((float) ($r['refunded_amount'] ?? 0) > 0): ?><span class="badge badge-warning">部分退款</span><?php endif; ?>
                                <div class="inline-reset-form">
                                    <button type="button" class="btn btn-sm btn-primary prep-open" data-id="<?= (int) $r['id'] ?>" title="标记制作完成 / 撤销">制作</button>
                                    <a href="/complaints/add.php?consumption_id=<?= (int) $r['id'] ?>" class="btn btn-sm btn-secondary">客诉</a>
                                    <a href="/refunds/add.php?consumption_id=<?= (int) $r['id'] ?>" class="btn btn-sm btn-warning">退款</a>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
(function () {
    const initialVersion = '<?= getRecordVersion($pdo) ?>';
    let checking = false;

    setInterval(function () {
        if (checking || document.hidden || (window.AACafeAutoRefreshGuard && window.AACafeAutoRefreshGuard.shouldPause())) {
            return;
        }

        checking = true;
        fetch('/api/record_version.php?scope=global', { cache: 'no-store' })
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

<script>
/* 日期工具栏：日历标记有消费记录的日期 */
(function () {
    const recordDates = <?= json_encode($recordDates, JSON_UNESCAPED_UNICODE) ?>;
    const selectedDate = <?= json_encode($dateFilter === 'all' ? '' : $dateFilter) ?>;
    const keyword = <?= json_encode($keyword, JSON_UNESCAPED_UNICODE) ?>;
    const popover = document.getElementById('calendarPopover');
    const grid = document.getElementById('calendarGrid');
    const titleEl = document.getElementById('calTitle');
    const datePicker = document.getElementById('datePicker');
    const toggle = document.getElementById('calendarToggle');
    if (!popover || !grid) return;

    const pad = n => String(n).padStart(2, '0');
    const todayStr = new Date().toISOString().slice(0, 10);
    let viewYear = 0;
    let viewMonth = 0;

    function goToDate(value) {
        const params = new URLSearchParams();
        if (keyword) params.set('q', keyword);
        if (value) params.set('date', value);
        location.href = '/consumption/' + (params.toString() ? '?' + params.toString() : '');
    }

    function renderCalendar() {
        const first = new Date(viewYear, viewMonth, 1);
        const startWeekday = (first.getDay() + 6) % 7;
        const daysInMonth = new Date(viewYear, viewMonth + 1, 0).getDate();
        titleEl.textContent = viewYear + ' 年 ' + (viewMonth + 1) + ' 月';
        let html = '';
        for (let i = 0; i < startWeekday; i++) html += '<span class="cal-cell empty"></span>';
        for (let d = 1; d <= daysInMonth; d++) {
            const ds = viewYear + '-' + pad(viewMonth + 1) + '-' + pad(d);
            const has = recordDates.indexOf(ds) !== -1;
            const cls = ['cal-cell'];
            if (has) cls.push('has-record');
            if (ds === selectedDate) cls.push('is-selected');
            if (ds === todayStr) cls.push('is-today');
            html += '<button type="button" class="' + cls.join(' ') + '" data-date="' + ds + '">' + d + '<i class="cal-dot' + (has ? '' : ' hidden') + '"></i></button>';
        }
        grid.innerHTML = html;
    }

    grid.addEventListener('click', event => {
        const cell = event.target.closest('[data-date]');
        if (cell) goToDate(cell.dataset.date);
    });

    document.getElementById('calPrev').addEventListener('click', () => {
        viewMonth--;
        if (viewMonth < 0) { viewMonth = 11; viewYear--; }
        renderCalendar();
    });
    document.getElementById('calNext').addEventListener('click', () => {
        viewMonth++;
        if (viewMonth > 11) { viewMonth = 0; viewYear++; }
        renderCalendar();
    });

    toggle.addEventListener('click', () => {
        popover.hidden = !popover.hidden;
    });

    document.addEventListener('click', event => {
        if (popover.hidden) return;
        if (popover.contains(event.target) || event.target === toggle) return;
        popover.hidden = true;
    });

    if (datePicker) {
        datePicker.addEventListener('change', () => {
            if (datePicker.value) goToDate(datePicker.value);
        });
    }

    const anchorDate = selectedDate || todayStr;
    viewYear = parseInt(anchorDate.slice(0, 4), 10);
    viewMonth = parseInt(anchorDate.slice(5, 7), 10) - 1;
    renderCalendar();
})();
</script>

<!-- 制作状态弹窗 -->
<div class="modal-overlay" id="prepModal" style="display:none">
    <div class="modal-card">
        <div class="modal-header">
            <h3>制作状态 <span id="prepModalTicket" class="text-muted"></span></h3>
            <button type="button" class="modal-close" id="prepModalClose" aria-label="关闭">×</button>
        </div>
        <p class="text-muted" id="prepModalMeta" style="margin-bottom:0.75rem"></p>
        <div class="prep-item-list" id="prepModalItems"></div>
        <p class="form-help" style="margin-top:0.9rem">点「完成」标记该杯已做好；点「撤销」可恢复为未制作。操作立即生效，客户大屏会同步显示。</p>
    </div>
</div>

<script>
/* 消费记录 · 制作状态弹窗（逐杯完成 / 撤销） */
(function () {
    const modal = document.getElementById('prepModal');
    const itemsEl = document.getElementById('prepModalItems');
    const ticketEl = document.getElementById('prepModalTicket');
    const metaEl = document.getElementById('prepModalMeta');
    if (!modal || !itemsEl) return;

    let orderId = 0;
    let csrfToken = '';

    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    function fetchToken() {
        return fetch('/api/csrf_token.php?_=' + Date.now(), { cache: 'no-store', credentials: 'same-origin' })
            .then(response => (response.ok ? response.json() : null))
            .then(data => { if (data && data.token) csrfToken = data.token; })
            .catch(() => {});
    }

    function render(data) {
        ticketEl.textContent = data.ticket ? '· ' + data.ticket : '';
        metaEl.textContent = '订单 #' + data.id + ' · ' + (data.items_text || '无商品')
            + '（已好 ' + data.done_cups + ' / ' + data.total_cups + ' 杯）';

        if (!data.items || !data.items.length) {
            itemsEl.innerHTML = '<p class="text-muted">该订单没有可制作的饮品</p>';
            return;
        }

        itemsEl.innerHTML = data.items.map(item => {
            const done = item.status === 'done';
            const refunded = item.status === 'refunded';
            let action = '';
            if (refunded) {
                action = '<span class="badge badge-secondary">已退款</span>';
            } else {
                action = '<button type="button" class="btn btn-sm ' + (done ? 'btn-secondary' : 'btn-success')
                    + ' prep-toggle" data-index="' + item.index + '" data-action="' + (done ? 'undo_item' : 'done_item') + '">'
                    + (done ? '撤销' : '完成') + '</button>';
            }
            return '<div class="prep-item-row' + (done ? ' is-done' : '') + (refunded ? ' is-refunded' : '') + '">'
                + '<div class="prep-item-name"><strong>' + esc(item.name) + '</strong>'
                + (done && item.prepared_at ? '<small class="text-muted">' + esc(item.prepared_at) + '</small>' : '')
                + '</div>' + action + '</div>';
        }).join('');
    }

    function open(id) {
        orderId = id;
        modal.style.display = 'flex';
        itemsEl.innerHTML = '<p class="text-muted">加载中…</p>';
        if (window.AACafeAutoRefreshGuard && window.AACafeAutoRefreshGuard.pause) {
            window.AACafeAutoRefreshGuard.pause();
        }
        if (!csrfToken) fetchToken();
        fetch('/api/consumption_prep.php?id=' + encodeURIComponent(id), { cache: 'no-store', credentials: 'same-origin' })
            .then(response => (response.ok ? response.json() : null))
            .then(data => { if (data) render(data); })
            .catch(() => { itemsEl.innerHTML = '<p class="text-muted">加载失败，请重试</p>'; });
    }

    function close(refresh) {
        modal.style.display = 'none';
        if (window.AACafeAutoRefreshGuard && window.AACafeAutoRefreshGuard.resume) {
            window.AACafeAutoRefreshGuard.resume();
        }
        if (refresh) {
            location.reload();
        }
    }

    /* 标记「已领取」 */
    document.addEventListener('click', function (event) {
        const pickBtn = event.target.closest('.pick-open');
        if (!pickBtn) return;
        const markPicked = function () {
            pickBtn.disabled = true;
            const body = new URLSearchParams({ action: 'pick_up', id: pickBtn.dataset.id, csrf_token: csrfToken });
            fetch('/api/consumption_prep.php', {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
            })
                .then(response => (response.ok ? response.json() : null))
                .then(data => {
                    if (data && data.error) {
                        pickBtn.disabled = false;
                        alert(data.error);
                        return;
                    }
                    location.reload();
                })
                .catch(() => { pickBtn.disabled = false; });
        };
        if (!csrfToken) {
            fetchToken().then(() => { if (csrfToken) markPicked(); });
            return;
        }
        markPicked();
    });

    document.addEventListener('click', function (event) {
        const openBtn = event.target.closest('.prep-open');
        if (openBtn) {
            open(openBtn.dataset.id);
            return;
        }

        if (event.target === modal || event.target.closest('#prepModalClose')) {
            close(window.__prepChanged === true);
            return;
        }

        const toggle = event.target.closest('.prep-toggle');
        if (!toggle) return;
        if (!csrfToken) {
            fetchToken().then(() => toggle.click());
            return;
        }

        const body = new URLSearchParams({
            action: toggle.dataset.action,
            id: String(orderId),
            item_index: toggle.dataset.index,
            csrf_token: csrfToken
        });
        toggle.disabled = true;
        fetch('/api/consumption_prep.php', {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
        })
            .then(response => (response.ok ? response.json() : null))
            .then(data => {
                if (data && !data.error) {
                    window.__prepChanged = true;
                    render(data);
                } else {
                    toggle.disabled = false;
                    if (data && data.error) {
                        itemsEl.insertAdjacentHTML('beforeend', '<p class="text-danger">' + esc(data.error) + '</p>');
                    }
                }
            })
            .catch(() => { toggle.disabled = false; });
    });
})();
</script>

<?php renderFooter(); ?>

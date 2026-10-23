<?php

requireLogin();

function renderHeader(string $title = '', string $active = ''): void
{
    $siteName = getSiteName();
    $title = sitePageTitle($title);
    $faviconUrl = getFaviconUrl();
    $navItems = [];
    if (hasRegularStaffPermission()) {
        $navItems = [
            '/' => ['首页', 'dashboard'],
            '/members/' => ['会员管理', 'members'],
            '/consumption/' => ['消费记录', 'records'],
            '/coupons/' => ['优惠券', 'coupons'],
            '/levels/' => ['等级说明', 'levels'],
            '/announcements/' => ['公告', 'announcements'],
        ];
    }
    if (hasRegularStaffPermission() && isPointsRedemptionEnabled()) {
        $navItems['/points/redeem.php'] = ['积分兑换', 'points'];
    }
    $staff = getCurrentStaff();
    if ($staff && (($staff['role'] ?? '') === 'leader' || isAdmin())) {
        $navItems['/attendance/'] = ['签到记录', 'attendance'];
    }
    $unreadAnnouncementCount = $staff ? getUnreadAnnouncementCount(getDb(), (string) $staff['username']) : 0;
    $navItems['/daily-report/'] = ['每日上报', 'daily-report'];

    // 侧边栏末尾固定顺序：制作总览 → 客户大屏 → 每日总结 → 财务管理 → 管理后台
    if (hasRegularStaffPermission()) {
        $navItems['/order-board.php'] = ['制作总览', 'order-board'];
        $navItems['/customer-board.php'] = ['客户大屏', 'customer-board'];
    }
    if (canViewDailySummary()) {
        $navItems['/consumption/summary.php'] = ['每日总结', 'daily-summary'];
    }
    if (canManageFinance()) {
        $navItems['/finance/'] = ['财务管理', 'finance'];
    }
    if (isAdmin()) {
        $navItems['/admin/logs.php'] = ['管理后台', 'admin'];
    }
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>
    <link rel="icon" href="<?= htmlspecialchars($faviconUrl) ?>">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($faviconUrl) ?>">
    <link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
    <?php
    // 会话指纹：用于前端检测页面是否来自其他会话的 CDN 缓存
    $layoutStaff = getCurrentStaff();
    $sessionFingerprint = $layoutStaff
        ? substr(hash('sha256', session_id() . '|' . (string) ($layoutStaff['username'] ?? '')), 0, 16)
        : 'guest';
    ?>
    <script>window.__DSH_SESSION__ = <?= json_encode($sessionFingerprint) ?>;</script>
    <script src="<?= assetUrl('/assets/js/session-sync.js') ?>" defer></script>
</head>
<body>
    <header class="header">
        <div class="container header-top">
            <h1 class="logo">
                    <a href="/" class="logo-link" title="返回首页">
                        <img class="logo-icon" src="<?= htmlspecialchars($faviconUrl) ?>" alt="" onerror="this.style.display='none'">
                        <span class="logo-text"><?= htmlspecialchars($siteName) ?></span>
                    </a>
                </h1>
            <?php if ($staff): ?>
                <?php $staffAccount = getStaffAccounts()[$staff['username']] ?? []; $staffAvatar = (string) ($staffAccount['avatar'] ?? ''); $unreadNotificationCount = getUnreadNotificationCount((string) $staff['username']); $staffFrozen = isStaffFrozen((string) $staff['username']); ?>
                <div class="staff-info">
                    <?php if (!$staffFrozen): ?>
                    <a class="header-bell" href="/notifications.php" title="通知" aria-label="通知">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                        <?php if ($unreadNotificationCount > 0): ?><span class="header-bell-badge"><?= $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount ?></span><?php endif; ?>
                    </a>
                    <?php endif; ?>
                    <?php if (!isAdmin()): ?>
                    <button type="button" class="header-feedback" onclick="openHeaderFeedbackModal()" title="向管理员反馈" aria-label="向管理员反馈">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    </button>
                    <?php endif; ?>
                    <?php if (!$staffFrozen): ?>
                    <a href="/profile.php" class="staff-name">
                        <?= staffAvatarMarkup($staffAccount, (string) $staff['username'], 'staff-avatar') ?>
                        <?= htmlspecialchars($staff['name']) ?>
                    </a>
                    <a href="/change_password.php" class="btn btn-secondary btn-sm">修改密码</a>
                    <?php endif; ?>
                    <a href="/logout.php" class="btn btn-secondary btn-sm">退出</a>
                </div>
            <?php endif; ?>
        </div>
    </header>
    <div class="app-shell<?= $staffFrozen ? ' is-plain' : '' ?>">
        <?php if (!$staffFrozen): ?>
        <aside class="side-nav">
            <nav class="nav">
                <?php foreach ($navItems as $url => [$label, $key]): ?>
                    <a href="<?= $url ?>" class="nav-link <?= $active === $key ? 'active' : '' ?>">
                        <?= $label ?><?php if ($key === 'announcements' && $unreadAnnouncementCount > 0): ?><span class="nav-badge"><?= $unreadAnnouncementCount > 99 ? '99+' : $unreadAnnouncementCount ?></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </aside>
        <?php endif; ?>
        <div class="app-body">
    <?php if ($staff && !isAdmin()): ?>
    <div id="headerFeedbackModal" class="modal-overlay" style="display:none;">
        <div class="modal-card">
            <div class="modal-header">
                <h3>向管理员反馈</h3>
                <button type="button" class="modal-close" onclick="closeHeaderFeedbackModal()">×</button>
            </div>
            <form method="post" action="/feedback.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="submit">
                <div class="form-group">
                    <textarea name="content" rows="4" maxlength="500" required placeholder="遇到什么问题？有什么建议？说说看..."></textarea>
                    <p class="form-help">最多 500 字，提交后管理员会收到通知并尽快处理回复</p>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">提交反馈</button>
                    <button type="button" class="btn btn-secondary" onclick="closeHeaderFeedbackModal()">取消</button>
                </div>
            </form>
            <p class="form-help" style="margin-top:0.75rem;"><a href="/feedback.php">查看我的反馈记录</a></p>
        </div>
    </div>
    <script>
    function openHeaderFeedbackModal() {
        var m = document.getElementById('headerFeedbackModal');
        if (m) { m.style.display = 'flex'; var b = m.querySelector('textarea'); if (b) b.focus(); }
    }
    function closeHeaderFeedbackModal() {
        var m = document.getElementById('headerFeedbackModal');
        if (m) m.style.display = 'none';
    }
    </script>
    <?php endif; ?>
    <main class="main">
    <?php
    $flash = getFlash();
    if ($flash):
    ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>">
            <?= htmlspecialchars($flash['message']) ?>
        </div>
    <?php endif;
}

function renderFooter(): void
{
    ?>
    </main>
    <footer class="footer">
        <div class="container">&copy; <?= date('Y') ?> Anson Studio</div>
    </footer>
        </div>
    </div>
    <script>
    (function () {
        if (window.AACafeAutoRefreshGuard) {
            return;
        }

        const editableSelector = 'input:not([type="hidden"]):not([type="submit"]):not([type="button"]), textarea, select';
        let formChanged = false;
        let externalPaused = false;

        document.addEventListener('input', function (event) {
            if (event.target.matches(editableSelector)) {
                formChanged = true;
            }
        }, true);

        document.addEventListener('change', function (event) {
            if (event.target.matches(editableSelector)) {
                formChanged = true;
            }
        }, true);

        document.addEventListener('submit', function () {
            formChanged = false;
        }, true);

        window.AACafeAutoRefreshGuard = {
            shouldPause: function () {
                if (externalPaused) {
                    return true;
                }
                const active = document.activeElement;
                const isEditing = active && active.matches && active.matches(editableSelector);
                return formChanged || isEditing;
            },
            /* 页面可通过 pause()/resume() 在弹窗等场景下暂停自动刷新 */
            pause: function () { externalPaused = true; },
            resume: function () { externalPaused = false; }
        };
    })();
    </script>
</body>
</html>
    <?php
}

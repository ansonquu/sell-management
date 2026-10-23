<?php

if (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) === '/customer-query.php') {
    header('Location: /cq/', true, 301);
    exit;
}

session_start();
require_once __DIR__ . '/includes/functions.php';

const CUSTOMER_QUERY_WINDOW_SECONDS = 600;
const CUSTOMER_QUERY_MAX_ATTEMPTS = 10;

// 使用全站统一令牌，便于 CDN 缓存场景下由前端脚本自动刷新

$errors = [];
$member = null;
$consumptions = [];
$values = [
    'name' => trim((string) ($_POST['name'] ?? '')),
    'phone' => trim((string) ($_POST['phone'] ?? '')),
    'student_no' => trim((string) ($_POST['student_no'] ?? '')),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $errors[] = '页面已过期，请刷新后重试';
    }

    $now = time();
    $attempts = array_values(array_filter(
        $_SESSION['customer_query_attempts'] ?? [],
        static fn ($timestamp): bool => is_int($timestamp) && $timestamp > $now - CUSTOMER_QUERY_WINDOW_SECONDS
    ));

    if (count($attempts) >= CUSTOMER_QUERY_MAX_ATTEMPTS) {
        http_response_code(429);
        $errors[] = '查询过于频繁，请10分钟后再试';
    } else {
        $attempts[] = $now;
        $_SESSION['customer_query_attempts'] = $attempts;
    }

    $provided = array_filter($values, static fn (string $value): bool => $value !== '');
    if (count($provided) < 2) {
        $errors[] = '请至少填写姓名、手机号、学号中的两项';
    }

    if (mb_strlen($values['name']) > 50 || mb_strlen($values['phone']) > 20 || mb_strlen($values['student_no']) > 30) {
        $errors[] = '输入内容过长，请检查后重试';
    }

    if (!$errors) {
        $conditions = [];
        $params = [];
        foreach (['name', 'phone', 'student_no'] as $field) {
            if ($values[$field] !== '') {
                $conditions[] = $field . ' = ?';
                $params[] = $values[$field];
            }
        }

        if (isLicenseActive()) {
            $stmt = getDb()->prepare(
                'SELECT id, name, phone, student_no, level, points, balance, total_spent, status, create_time '
                . 'FROM member WHERE ' . implode(' AND ', $conditions) . ' LIMIT 2'
            );
            $stmt->execute($params);
            $matches = $stmt->fetchAll();
            if (count($matches) === 1) {
                $member = $matches[0];
                $consumptionStmt = getDb()->prepare(
                    'SELECT create_time, items FROM consumption '
                    . 'WHERE member_id = ? ORDER BY create_time DESC LIMIT 50'
                );
                $consumptionStmt->execute([(int) $member['id']]);
                $consumptions = $consumptionStmt->fetchAll();
            } else {
                $errors[] = '未查询到匹配的会员信息，请检查输入是否正确';
            }
        } else {
            $errors[] = '查询服务暂时不可用，请联系店员';
        }
    }
}

function maskCustomerPhone(string $phone): string
{
    $length = mb_strlen($phone);
    if ($length <= 7) {
        return mb_substr($phone, 0, 2) . str_repeat('*', max(1, $length - 4)) . mb_substr($phone, -2);
    }
    return mb_substr($phone, 0, 3) . '****' . mb_substr($phone, -4);
}

$siteName = getSiteName();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title><?= htmlspecialchars(sitePageTitle('会员自助查询')) ?></title>
    <link rel="icon" href="<?= htmlspecialchars(getFaviconUrl()) ?>">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars(getFaviconUrl()) ?>">
    <link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
    <script src="<?= assetUrl('/assets/js/session-sync.js') ?>" defer></script>
    <style>
        .customer-query-page {
            min-height: 100vh;
            padding: clamp(1rem, 4vw, 2.5rem);
            background:
                radial-gradient(1000px 480px at 85% -10%, rgba(201, 138, 61, 0.13), transparent 60%),
                radial-gradient(800px 420px at -10% 25%, rgba(111, 78, 55, 0.09), transparent 55%);
        }
        .customer-query-shell { width: 100%; max-width: 720px; margin: 0 auto; }
        .customer-query-shell.has-result { max-width: none; }
        .customer-query-brand { text-align: center; margin-bottom: 1.6rem; }
        .customer-query-brand h1 { color: var(--espresso-700); font-size: 1.85rem; font-weight: 800; }
        .customer-query-brand h1::before {
            content: "";
            display: block;
            width: 52px;
            height: 52px;
            margin: 0 auto 0.55rem;
            background: url("<?= htmlspecialchars(getFaviconUrl()) ?>") center / contain no-repeat;
            border-radius: 12px;
        }
        .customer-query-brand p { color: var(--text-muted); margin-top: 0.35rem; }
        .customer-query-card {
            padding: 2rem;
            background: var(--card-grad);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }
        .customer-query-card::before {
            content: "";
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 5px;
            background: linear-gradient(90deg, var(--caramel), var(--espresso-500));
        }
        .customer-query-card h2 { color: var(--espresso-700); font-size: 1.25rem; margin-bottom: 0.35rem; }
        .query-tip { color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1.5rem; }
        .customer-query-actions { display: flex; align-items: center; gap: 1rem; }
        .customer-query-actions .btn-primary { flex: 1; }
        .customer-result {
            width: 100%;
            border-top: 4px solid transparent;
            border-image: linear-gradient(90deg, var(--caramel), var(--espresso-500)) 1;
            padding: clamp(1rem, 3vw, 2.5rem);
        }
        .customer-result-header { display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem; }
        .customer-result-header h2 { color: var(--espresso-700); }
        .customer-result-heading { display: flex; flex: 1; align-items: center; justify-content: space-between; gap: 1rem; min-width: 0; }
        .customer-result .stats-grid { margin-bottom: 1.25rem; }
        .customer-consumptions { margin-top: 1.5rem; }
        .customer-consumptions h3 { color: var(--espresso-700); font-size: 1.1rem; margin-bottom: 0.75rem; }
        .customer-consumptions .table td:first-child { white-space: nowrap; }
        .customer-consumptions .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .customer-result-note { color: var(--text-muted); font-size: 0.85rem; text-align: center; }
        .customer-query-footer { text-align: center; color: var(--text-muted); font-size: 0.8rem; margin-top: 1.5rem; }
        @media (max-width: 640px) {
            .customer-query-card { padding: 1.25rem; }
            .customer-result-header { align-items: flex-start; }
            .customer-result-heading { align-items: flex-start; flex-direction: column; }
            .customer-result .stats-grid,
            .customer-result .profile-info { grid-template-columns: 1fr; }
            .customer-query-actions { flex-direction: column; align-items: stretch; }
        }
    </style>
</head>
<body>
    <main class="customer-query-page">
        <div class="customer-query-shell <?= $member ? 'has-result' : '' ?>">
            <div class="customer-query-brand">
                <h1><?= htmlspecialchars($siteName) ?></h1>
                <p>会员自助查询</p>
            </div>

            <?php if (!$member): ?>
            <section class="card customer-query-card">
                <h2>查询我的账户</h2>
                <p class="query-tip">请在姓名、手机号、学号中任选至少两项，信息需与注册时完全一致。</p>

                <?php if ($errors): ?>
                    <div class="alert alert-error" role="alert">
                        <?php foreach (array_unique($errors) as $error): ?>
                            <div><?= htmlspecialchars($error) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <div class="form-group">
                        <label for="customerName">姓名</label>
                        <input id="customerName" type="text" name="name" maxlength="50" value="<?= htmlspecialchars($values['name']) ?>" placeholder="请输入注册姓名">
                    </div>
                    <div class="form-group">
                        <label for="customerPhone">手机号</label>
                        <input id="customerPhone" type="tel" name="phone" maxlength="20" inputmode="tel" value="<?= htmlspecialchars($values['phone']) ?>" placeholder="请输入注册手机号">
                    </div>
                    <div class="form-group">
                        <label for="customerStudentNo">学号</label>
                        <input id="customerStudentNo" type="text" name="student_no" maxlength="30" value="<?= htmlspecialchars($values['student_no']) ?>" placeholder="请输入注册学号">
                    </div>
                    <div class="customer-query-actions">
                        <button type="submit" class="btn btn-primary">立即查询</button>
                        <a href="/login.php" class="btn btn-link">员工登录</a>
                    </div>
                </form>
            </section>
            <?php endif; ?>

            <?php if ($member): ?>
                <section class="card customer-result" aria-live="polite">
                    <div class="customer-result-header">
                        <div class="avatar"><?= htmlspecialchars(mb_substr((string) $member['name'], 0, 1)) ?></div>
                        <div class="customer-result-heading">
                            <div>
                                <h2><?= htmlspecialchars((string) $member['name']) ?></h2>
                                <span class="badge badge-level"><?= htmlspecialchars((string) $member['level']) ?></span>
                                <span class="badge <?= (int) $member['status'] === STATUS_NORMAL ? 'badge-success' : 'badge-danger' ?>">
                                    <?= statusLabel((int) $member['status']) ?>
                                </span>
                            </div>
                            <a href="/cq/" class="btn btn-secondary btn-sm">重新查询</a>
                        </div>
                    </div>
                    <div class="stats-grid">
                        <div class="stat-card small">
                            <div class="stat-value"><?= formatMoney((float) $member['balance']) ?></div>
                            <div class="stat-label">账户余额</div>
                        </div>
                        <div class="stat-card small">
                            <div class="stat-value"><?= (int) $member['points'] ?></div>
                            <div class="stat-label">当前积分</div>
                        </div>
                        <div class="stat-card small">
                            <div class="stat-value"><?= formatMoney((float) $member['total_spent']) ?></div>
                            <div class="stat-label">累计消费</div>
                        </div>
                    </div>
                    <div class="profile-info">
                        <div class="info-item"><label>手机号</label><span><?= htmlspecialchars(maskCustomerPhone((string) $member['phone'])) ?></span></div>
                        <div class="info-item"><label>学号</label><span><?= htmlspecialchars((string) ($member['student_no'] ?: '-')) ?></span></div>
                        <div class="info-item"><label>注册时间</label><span><?= formatDate((string) $member['create_time']) ?></span></div>
                    </div>
                    <div class="customer-consumptions">
                        <h3>消费记录</h3>
                        <?php if (!$consumptions): ?>
                            <p class="text-muted">暂无消费记录</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr><th>日期</th><th>消费内容</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($consumptions as $consumption): ?>
                                            <tr>
                                                <td><?= formatDateTime((string) $consumption['create_time']) ?></td>
                                                <td><?= htmlspecialchars((string) ($consumption['items'] ?: '-')) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                    <p class="customer-result-note">账户数据以系统当前记录为准，如有疑问请联系店员。</p>
                </section>
            <?php endif; ?>

            <p class="customer-query-footer">&copy; <?= date('Y') ?> Anson Studio</p>
        </div>
    </main>
</body>
</html>

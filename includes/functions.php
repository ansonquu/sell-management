<?php

/**
 * 动态页面统一禁用缓存（CDN 兼容）
 * ------------------------------------------------------------
 * 所有 PHP 页面都会经过本文件，因此在这里统一声明：
 *  - no-store：避免 CDN / 浏览器缓存到带有他人会话信息或过期 CSRF 令牌的页面
 *  - Vary: Cookie：同一 URL 按 Cookie 区分，进一步防止串号
 * 静态资源（/assets/**）不经过 PHP，由 CDN 按扩展名规则长缓存（见 CDN-DEPLOY.md）。
 */
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Vary: Cookie, Accept-Encoding');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/staff.php';
require_once __DIR__ . '/license.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/feedback.php';
require_once __DIR__ . '/attendance.php';

/**
 * Render the Markdown subset used by announcements.
 *
 * HTML is escaped before Markdown formatting is applied, so announcement
 * authors cannot inject scripts or arbitrary tags.
 */
function renderMarkdown(string $markdown): string
{
    $markdown = str_replace(["\r\n", "\r"], "\n", trim($markdown));
    $lines = explode("\n", $markdown);
    $html = [];
    $paragraph = [];
    $listType = null;
    $inCode = false;
    $code = [];

    $inline = static function (string $text): string {
        $codes = [];
        $text = preg_replace_callback('/`([^`]+)`/u', static function (array $match) use (&$codes): string {
            $key = '@@MD_CODE_' . count($codes) . '@@';
            $codes[$key] = '<code>' . htmlspecialchars($match[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
            return $key;
        }, $text) ?? $text;
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = preg_replace_callback('/\[([^\]]+)\]\(([^\s)]+)\)/u', static function (array $match): string {
            $url = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!preg_match('~^(https?://|mailto:|/)~i', $url)) {
                return $match[0];
            }
            return '<a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">' . $match[1] . '</a>';
        }, $text) ?? $text;
        $text = preg_replace('/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/__([^_]+)__/u', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/~~([^~]+)~~/u', '<del>$1</del>', $text) ?? $text;
        $text = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/u', '<em>$1</em>', $text) ?? $text;
        foreach ($codes as $key => $value) {
            $text = str_replace(htmlspecialchars($key, ENT_QUOTES, 'UTF-8'), $value, $text);
        }
        return $text;
    };

    $flushParagraph = static function () use (&$paragraph, &$html, $inline): void {
        if ($paragraph) {
            $html[] = '<p>' . $inline(implode("\n", $paragraph)) . '</p>';
            $paragraph = [];
        }
    };
    $closeList = static function () use (&$listType, &$html): void {
        if ($listType !== null) {
            $html[] = '</' . $listType . '>';
            $listType = null;
        }
    };

    foreach ($lines as $line) {
        if (preg_match('/^```/', $line)) {
            $flushParagraph();
            $closeList();
            if ($inCode) {
                $html[] = '<pre><code>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>';
                $code = [];
            }
            $inCode = !$inCode;
            continue;
        }
        if ($inCode) {
            $code[] = $line;
            continue;
        }
        if (trim($line) === '') {
            $flushParagraph();
            $closeList();
            continue;
        }
        if (preg_match('/^(#{1,6})\s+(.+)$/u', $line, $match)) {
            $flushParagraph();
            $closeList();
            $level = strlen($match[1]);
            $html[] = '<h' . $level . '>' . $inline($match[2]) . '</h' . $level . '>';
            continue;
        }
        if (preg_match('/^>\s?(.*)$/u', $line, $match)) {
            $flushParagraph();
            $closeList();
            $html[] = '<blockquote>' . $inline($match[1]) . '</blockquote>';
            continue;
        }
        if (preg_match('/^\s*([-+*])\s+(.+)$/u', $line, $match) || preg_match('/^\s*\d+[.)]\s+(.+)$/u', $line, $match)) {
            $flushParagraph();
            $type = isset($match[2]) ? 'ul' : 'ol';
            $content = isset($match[2]) ? $match[2] : $match[1];
            if ($listType !== $type) {
                $closeList();
                $html[] = '<' . $type . '>';
                $listType = $type;
            }
            $html[] = '<li>' . $inline($content) . '</li>';
            continue;
        }
        $closeList();
        $paragraph[] = $line;
    }
    if ($inCode) {
        $html[] = '<pre><code>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>';
    }
    $flushParagraph();
    $closeList();

    return implode("\n", $html);
}

function calculateLevel(float $totalSpent): string
{
    $level = '普通会员';
    foreach (getMemberLevels() as $config) {
        if ($totalSpent >= $config['min_spent']) {
            $level = $config['name'];
        }
    }
    return $level;
}

function calculatePoints(float $amount): int
{
    return (int) floor($amount * getPointsPerYuan());
}

/** 每杯饮品各有一条制作状态，数量 x2 会生成两个独立项目。 */
function preparationItemsFromSelection(array $selectedItems): array
{
    $items = [];
    foreach ($selectedItems as $selection) {
        $name = trim((string) ($selection['item']['name'] ?? ''));
        $unitPrice = round((float) ($selection['item']['price'] ?? 0), 2);
        $quantity = max(0, min(99, (int) ($selection['quantity'] ?? 0)));
        for ($i = 0; $i < $quantity; $i++) {
            $items[] = ['name' => $name, 'unit_price' => $unitPrice, 'status' => 'pending', 'prepared_at' => null, 'prepared_by' => ''];
        }
    }
    return $items;
}

/** 把订单实付与赠送积分分摊到每杯，供逐杯退款使用。 */
function allocatePreparationItemCharges(array $items, float $paidAmount, int $earnedPoints): array
{
    if (!$items) {
        return [];
    }
    $weights = array_map(static fn (array $item): int => max(0, (int) round((float) ($item['unit_price'] ?? 0) * 100)), $items);
    $totalWeight = array_sum($weights);
    if ($totalWeight === 0) {
        $weights = array_fill(0, count($items), 1);
        $totalWeight = count($items);
    }
    $totalCents = max(0, (int) round($paidAmount * 100));
    $earnedPoints = max(0, $earnedPoints);
    $accumulatedWeight = 0;
    $allocatedCents = 0;
    $allocatedPoints = 0;
    foreach ($items as $index => &$item) {
        $accumulatedWeight += $weights[$index];
        $targetCents = (int) round($totalCents * $accumulatedWeight / $totalWeight);
        $targetPoints = (int) round($earnedPoints * $accumulatedWeight / $totalWeight);
        $item['charged_amount'] = ($targetCents - $allocatedCents) / 100;
        $item['points_earned'] = $targetPoints - $allocatedPoints;
        $allocatedCents = $targetCents;
        $allocatedPoints = $targetPoints;
    }
    unset($item);
    return $items;
}

/** 旧消费记录按商品文字还原为单杯项目；新记录使用保存的逐杯状态。 */
function consumptionPreparationItems(array $consumption): array
{
    $stored = json_decode((string) ($consumption['prep_items'] ?? ''), true);
    if (is_array($stored) && $stored !== []) {
        return array_values($stored);
    }

    $text = trim((string) ($consumption['items'] ?? ''));
    if ($text === '') {
        return [];
    }
    $done = ($consumption['prep_status'] ?? 'done') === 'done';
    $items = [];
    foreach (preg_split('/,\s*/u', $text) ?: [] as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $name = $part;
        $quantity = 1;
        if (preg_match('/^(.*?)\s+x(\d+)$/u', $part, $match)) {
            $name = trim($match[1]);
            $quantity = max(1, min(99, (int) $match[2]));
        }
        for ($i = 0; $i < $quantity; $i++) {
            $items[] = [
                'name' => $name,
                'status' => $done ? 'done' : 'pending',
                'prepared_at' => $done ? ($consumption['prepared_at'] ?? null) : null,
                'prepared_by' => $done ? ($consumption['prepared_by'] ?? '') : '',
            ];
        }
    }
    return $items;
}

function resolveConsumptionPaymentMethod(bool $isWalkIn, mixed $submittedMethod): string
{
    if (!$isWalkIn) {
        return 'balance';
    }
    $method = (string) $submittedMethod;
    return in_array($method, ['cash', 'wechat', 'alipay'], true) ? $method : 'cash';
}

function normalizeStoredPaymentMethod(mixed $method): string
{
    $normalized = strtolower(trim((string) $method));
    if (in_array($normalized, ['balance', '余额', '余额支付', '1'], true)) {
        return 'balance';
    }
    return in_array($normalized, ['cash', 'wechat', 'alipay', 'bank'], true) ? $normalized : 'cash';
}

function consumptionPaymentMethodLabel(mixed $method): string
{
    $method = strtolower(trim((string) $method));
    if ($method === 'redo') {
        return '内部重做';
    }
    if ($method === 'complaint') {
        return '客诉补偿';
    }
    if ($method === 'points') {
        return '积分兑换';
    }
    return financePaymentLabel(normalizeStoredPaymentMethod($method));
}

function parseConsumptionItems(?string $items): array
{
    $parsed = [];
    foreach (explode(',', (string) $items) as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        if (preg_match('/^(.*?)\s+x(\d+)$/u', $part, $matches)) {
            $name = trim($matches[1]);
            $quantity = max(1, (int) $matches[2]);
        } else {
            $name = $part;
            $quantity = 1;
        }
        if ($name !== '') {
            $parsed[$name] = ($parsed[$name] ?? 0) + $quantity;
        }
    }
    return $parsed;
}

/**
 * 标记 / 撤销订单「已领取」。
 * 只有已制作完成的消费单才能标记领取。
 *
 * @return array{ok?:bool,error?:string,picked_up_at?:?string}
 */
function markOrderPickedUp(PDO $pdo, int $orderId, bool $picked, array $staff): array
{
    $stmt = $pdo->prepare("SELECT * FROM consumption WHERE id = ? AND record_type = 'sale' AND refunded_at IS NULL");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) {
        return ['error' => '该记录不可操作（仅消费单且未退款）'];
    }
    if ($picked && ($order['prep_status'] ?? '') !== 'done') {
        return ['error' => '订单还没制作完成，暂不能标记已领取'];
    }

    $now = currentDateTime();
    $operator = (string) ($staff['name'] ?? '');
    $pdo->prepare('UPDATE consumption SET picked_up_at = ?, picked_up_by = ? WHERE id = ?')
        ->execute([$picked ? $now : null, $picked ? $operator : '', $orderId]);
    logOperation(
        $pdo,
        $picked ? 'order_picked_up' : 'order_pick_up_undo',
        'consumption',
        $orderId,
        ($picked ? '标记已领取：' : '撤销已领取：') . '订单 #' . $orderId,
        ['operator' => $operator]
    );

    return ['ok' => true, 'picked_up_at' => $picked ? $now : null];
}

/**
 * 订单展示号码：优先取号牌；未取号时用会员手机号后 4 位；散客无手机号时用订单号。
 * 返回 [显示值, 类型]，类型为 ticket / phone / order。
 */
function displayOrderNumber(array $order): array
{
    $ticket = trim((string) ($order['ticket_number'] ?? ''));
    if ($ticket !== '') {
        return [$ticket, 'ticket'];
    }

    $phone = preg_replace('/\D/', '', (string) ($order['member_phone'] ?? $order['phone'] ?? ''));
    if (strlen($phone) >= 4) {
        return [substr($phone, -4), 'phone'];
    }

    return [(string) (int) ($order['id'] ?? 0), 'order'];
}

/**
 * 该员工拥有的全部角色（主角色 + 附加职业）。
 * 出于安全考虑，附加职业不包含管理员与财务。
 */
function staffRoles(?array $staff = null): array
{
    $staff = $staff ?? getCurrentStaff();
    if (!$staff) {
        return [];
    }

    $roles = [(string) ($staff['role'] ?? 'staff')];

    // 附加职业以账号档案为准（会话数据里可能不含该字段）
    $extraRoles = $staff['extra_roles'] ?? null;
    if (!is_array($extraRoles)) {
        $account = getStaffAccounts()[(string) ($staff['username'] ?? '')] ?? null;
        $extraRoles = is_array($account) ? ($account['extra_roles'] ?? []) : [];
    }

    foreach ((array) $extraRoles as $extra) {
        $extra = (string) $extra;
        if ($extra === '' || in_array($extra, ['admin', 'finance'], true) || in_array($extra, $roles, true)) {
            continue;
        }
        $roles[] = $extra;
    }

    return $roles;
}

/** 是否拥有给定角色中的任意一个。 */
function staffHasAnyRole(array $roles, ?array $staff = null): bool
{
    return $roles !== [] && array_intersect($roles, staffRoles($staff)) !== [];
}

/** 是否可查看制作总览。 */
function canAccessOrderBoard(?array $staff = null): bool
{
    return staffHasAnyRole(['admin', 'leader', 'staff', 'barista', 'reception', 'barista_screen'], $staff);
}

/** 是否可操作制作总览（完成 / 重做 / 逐杯退款）；接待只能查看。 */
function canOperateOrderBoard(?array $staff = null): bool
{
    return staffHasAnyRole(['admin', 'leader', 'staff', 'barista', 'barista_screen'], $staff);
}

/** 是否可查看客户大屏。 */
function canAccessCustomerBoard(?array $staff = null): bool
{
    return staffHasAnyRole(['admin', 'leader', 'staff', 'barista', 'reception', 'barista_screen', 'customer_screen'], $staff);
}

/** 是否可操作客户大屏（标记已领取）；接待只能查看。 */
function canOperateCustomerBoard(?array $staff = null): bool
{
    return staffHasAnyRole(['admin', 'leader', 'staff', 'barista'], $staff);
}

/**
 * 客户大屏姓名脱敏：只保留姓，其余用 * 代替（如「张三」→「张*」、「欧阳娜娜」→「欧***」）。
 */
function maskCustomerName(?string $name): string
{
    $name = trim((string) $name);
    if ($name === '') {
        return '顾客';
    }
    $length = mb_strlen($name, 'UTF-8');
    if ($length <= 1) {
        return $name . '*';
    }
    return mb_substr($name, 0, 1, 'UTF-8') . str_repeat('*', $length - 1);
}

/**
 * 输出 CSV 下载（统一处理 UTF-8 BOM 与中文文件名，兼容 Excel）。
 * 调用后脚本结束（内部 exit）。
 */
function csvDownload(string $filename, string $asciiName, array $header, array $rows): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, $header);
    foreach ($rows as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

/**
 * 商品文本规范化：若同一种商品出现多次则合并数量（如「美式咖啡 x1, 美式咖啡 x2」→「美式咖啡 x3」），
 * 没有重复时原样返回，避免影响已有文案。
 */
function normalizeConsumptionItemsText(?string $items): string
{
    $text = (string) $items;
    $parts = array_values(array_filter(array_map('trim', explode(',', $text)), 'strlen'));
    if (count($parts) < 2) {
        return $text;
    }

    $names = [];
    foreach ($parts as $part) {
        $names[] = trim((string) preg_replace('/\s+x\d+$/u', '', $part));
    }

    if (count($names) === count(array_unique($names))) {
        return $text;
    }

    return formatConsumptionItems(parseConsumptionItems($text));
}

function formatConsumptionItems(array $items): string
{
    $parts = [];
    foreach ($items as $name => $quantity) {
        if ((int) $quantity > 0) {
            $parts[] = trim((string) $name) . ' x' . (int) $quantity;
        }
    }
    return implode(', ', $parts);
}

function complaintResolutionLabel(string $type): string
{
    return match ($type) {
        'partial' => '部分重做',
        'exchange' => '换货',
        default => '全部重做',
    };
}

function addPointsLog(PDO $pdo, int $memberId, int $change, string $reason): void
{
    $stmt = $pdo->prepare('INSERT INTO points_log (member_id, change_amount, reason, create_time) VALUES (?, ?, ?, ?)');
    $stmt->execute([$memberId, $change, $reason, currentDateTime()]);
}

function currentDateTime(): string
{
    return date('Y-m-d H:i:s');
}

function logOperation(
    PDO $pdo,
    string $action,
    string $targetType,
    ?int $targetId,
    string $description,
    array $meta = [],
    ?array $actor = null
): void {
    $actor = $actor ?? getCurrentStaff() ?? [];
    $stmt = $pdo->prepare('
        INSERT INTO operation_logs (actor_username, actor_name, actor_role, action, target_type, target_id, description, meta, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $actor['username'] ?? '',
        $actor['name'] ?? '',
        $actor['role'] ?? '',
        $action,
        $targetType,
        $targetId,
        $description,
        $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : '',
        currentDateTime(),
    ]);
}

function getMemberById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM member WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getMemberByPhone(PDO $pdo, string $phone): ?array
{
    if (trim($phone) === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM member WHERE phone = ?');
    $stmt->execute([$phone]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function memberPhoneDisplay(?string $phone): string
{
    $phone = trim((string) $phone);
    return $phone === '' ? '手机号待补全' : substr($phone, -4);
}

function getMemberByStudentNo(PDO $pdo, string $studentNo, ?int $excludeId = null): ?array
{
    $sql = 'SELECT * FROM member WHERE student_no = ?';
    $params = [$studentNo];

    if ($excludeId !== null) {
        $sql .= ' AND id != ?';
        $params[] = $excludeId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function memberSearchCondition(): string
{
    return 'name LIKE ? OR phone LIKE ? OR student_no LIKE ?';
}

function formatMoney(float $amount): string
{
    return '¥' . number_format($amount, 2);
}

function formatDate(?string $date): string
{
    return $date ? date('Y-m-d', strtotime($date)) : '-';
}

function formatDateTime(?string $datetime): string
{
    return $datetime ? date('Y-m-d H:i', strtotime($datetime)) : '-';
}

function genderLabel(int $gender): string
{
    return match ($gender) {
        1 => '男',
        2 => '女',
        default => '未知',
    };
}

function statusLabel(int $status): string
{
    return $status === STATUS_NORMAL ? '正常' : '已冻结';
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/**
 * 静态资源 URL：自动附加文件修改时间作为版本号，
 * 便于浏览器与 CDN 在文件更新后自动失效旧缓存。
 */
function assetUrl(string $path): string
{
    $path = '/' . ltrim($path, '/');
    $full = dirname(__DIR__) . $path;
    $version = @filemtime($full);
    return $path . ($version ? '?v=' . $version : '');
}

/**
 * 全站 CSRF 令牌（与财务模块共用同一会话令牌）。
 */
function csrfToken(): string
{
    return financeCsrfToken();
}

/** 校验当前 POST 请求的 CSRF 令牌。 */
function verifyCsrf(): bool
{
    return verifyFinanceCsrf();
}

/**
 * POST 请求统一入口校验：失败时提示并回到当前页面。
 * 所有处理表单提交的页面都应在 POST 分支开头调用。
 */
function requireCsrf(): void
{
    if (verifyCsrf()) {
        return;
    }

    flash('error', '页面已过期，请刷新后重试');
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    if (!str_starts_with($path, '/')) {
        $path = '/';
    }
    redirect($path);
}

/** 表单内使用的 CSRF 隐藏字段 HTML。 */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array
{
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function getStats(PDO $pdo): array
{
    $stats = [];

    $stats['member_count'] = (int) $pdo->query('SELECT COUNT(*) FROM member')->fetchColumn();
    $stats['active_count'] = (int) $pdo->query('SELECT COUNT(*) FROM member WHERE status = 1')->fetchColumn();
    $stats['total_spent'] = (float) $pdo->query("SELECT COALESCE(SUM(amount - COALESCE(refunded_amount, 0)), 0) FROM consumption WHERE record_type = 'sale' AND refunded_at IS NULL")->fetchColumn();
    $stats['total_points'] = (int) $pdo->query('SELECT COALESCE(SUM(points), 0) FROM member')->fetchColumn();
    $stats['consumption_count'] = (int) $pdo->query("SELECT COUNT(*) FROM consumption WHERE record_type = 'sale' AND refunded_at IS NULL")->fetchColumn();

    $levelStats = $pdo->query('SELECT level, COUNT(*) as count FROM member GROUP BY level')->fetchAll();
    $stats['level_distribution'] = $levelStats;

    return $stats;
}

/** 公告可见范围：目标角色 / 目标职位（空数组表示不限，即全员）。 */
function announcementTargets(array $announcement): array
{
    $decode = static function ($raw): array {
        if (is_array($raw)) {
            $values = $raw;
        } else {
            $decoded = json_decode((string) $raw, true);
            // 兼容单个字符串形式的取值
            $values = is_string($decoded) ? [$decoded] : (is_array($decoded) ? $decoded : []);
        }
        $values = array_filter(array_map(static fn ($value): string => trim((string) $value), $values), static fn (string $value): bool => $value !== '');
        return array_values(array_unique($values));
    };

    return [
        'roles' => $decode($announcement['target_roles'] ?? ''),
        'staff' => $decode($announcement['target_staff'] ?? ''),
    ];
}

/** 公告可见范围文案（如「财务、指定：张三」或「全员」）。 */
function announcementTargetLabel(array $announcement): string
{
    $targets = announcementTargets($announcement);
    $parts = [];
    foreach ($targets['roles'] as $role) {
        $parts[] = staffRoleLabel($role);
    }
    if ($targets['staff']) {
        $accounts = getStaffAccounts();
        $names = [];
        foreach ($targets['staff'] as $username) {
            $names[] = (string) ($accounts[$username]['name'] ?? $username);
        }
        $parts[] = '指定：' . implode('、', $names);
    }
    return $parts ? implode('、', $parts) : '全员';
}

/** 公告可见的员工账号列表（管理员始终可见），用于已读统计。 */
function announcementVisibleUsernames(array $announcement): array
{
    $usernames = [];
    foreach (getStaffAccounts() as $username => $account) {
        if (announcementVisibleToStaff($announcement, [
            'username' => (string) $username,
            'name' => (string) ($account['name'] ?? ''),
            'role' => (string) ($account['role'] ?? 'staff'),
        ])) {
            $usernames[] = (string) $username;
        }
    }
    return $usernames;
}

/** 员工是否在公告可见范围内：角色或指定人任一命中即可见；管理员与未设置范围时始终可见。 */
function announcementVisibleToStaff(array $announcement, ?array $staff = null): bool
{
    $staff = $staff ?? getCurrentStaff();
    if (!$staff) {
        return false;
    }
    if (($staff['role'] ?? '') === 'admin') {
        return true;
    }
    $targets = announcementTargets($announcement);
    if (!$targets['roles'] && !$targets['staff']) {
        return true;
    }
    if ($targets['staff'] && in_array((string) ($staff['username'] ?? ''), $targets['staff'], true)) {
        return true;
    }
    if ($targets['roles'] && in_array((string) ($staff['role'] ?? 'staff'), $targets['roles'], true)) {
        return true;
    }
    return false;
}

/** 公告可选的可见分组：角色 + 职位（取系统设置中的职位）。 */
function announcementGroupOptions(): array
{
    return [
        'roles' => [
            'admin' => '管理员',
            'leader' => '当日负责人',
            'finance' => '财务',
            'staff' => '收银',
            'barista' => '咖啡师',
            'reception' => '接待',
            'barista_screen' => '制作总览',
            'customer_screen' => '客户大屏',
        ],
        'positions' => getAttendancePositions(),
    ];
}

function getAnnouncements(PDO $pdo, string $username, bool $includeArchived = false, ?int $limit = null): array
{
    $sql = '
        SELECT a.*, ar.read_at,
               CASE WHEN ar.read_revision = a.revision THEN 1 ELSE 0 END AS is_read
        FROM announcements a
        LEFT JOIN announcement_reads ar
          ON ar.announcement_id = a.id AND ar.staff_username = ?
    ';
    $params = [$username];

    if (!$includeArchived) {
        $sql .= " WHERE a.status = 'published'";
    }

    $sql .= " ORDER BY CASE WHEN a.status = 'published' THEN 0 ELSE 1 END, CASE WHEN a.category = 'urgent' THEN 0 ELSE 1 END, a.updated_at DESC, a.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // 按可见分组过滤（角色 / 职位任一命中，管理员始终可见）
    $account = getStaffAccounts()[$username] ?? [];
    $staff = [
        'username' => $username,
        'name' => (string) ($account['name'] ?? $username),
        'role' => (string) ($account['role'] ?? 'staff'),
    ];
    $rows = array_values(array_filter($rows, static fn (array $row): bool => announcementVisibleToStaff($row, $staff)));

    if ($limit !== null) {
        $rows = array_slice($rows, 0, max(1, $limit));
    }

    return $rows;
}

function getUnreadAnnouncementCount(PDO $pdo, string $username): int
{
    $stmt = $pdo->prepare("
        SELECT a.*
        FROM announcements a
        LEFT JOIN announcement_reads ar
          ON ar.announcement_id = a.id AND ar.staff_username = ?
        WHERE a.status = 'published'
          AND (ar.read_revision IS NULL OR ar.read_revision != a.revision)
    ");
    $stmt->execute([$username]);
    $rows = $stmt->fetchAll();

    $account = getStaffAccounts()[$username] ?? [];
    $staff = [
        'username' => $username,
        'name' => (string) ($account['name'] ?? $username),
        'role' => (string) ($account['role'] ?? 'staff'),
    ];
    return count(array_filter($rows, static fn (array $row): bool => announcementVisibleToStaff($row, $staff)));
}

function markAnnouncementRead(PDO $pdo, int $announcementId, string $username): bool
{
    $check = $pdo->prepare("SELECT id, revision FROM announcements WHERE id = ? AND status = 'published'");
    $check->execute([$announcementId]);
    $announcement = $check->fetch();
    if (!$announcement) {
        return false;
    }

    $stmt = $pdo->prepare('
        INSERT OR REPLACE INTO announcement_reads (announcement_id, staff_username, read_at, read_revision)
        VALUES (?, ?, ?, ?)
    ');
    return $stmt->execute([$announcementId, $username, currentDateTime(), (int) $announcement['revision']]);
}

function getAnnouncementReadStatus(PDO $pdo, array $announcement): array
{
    $stmt = $pdo->prepare('
        SELECT staff_username, read_at, read_revision
        FROM announcement_reads
        WHERE announcement_id = ?
    ');
    $stmt->execute([(int) $announcement['id']]);

    $readsByUsername = [];
    foreach ($stmt->fetchAll() as $read) {
        $readsByUsername[(string) $read['staff_username']] = $read;
    }

    $visibleUsernames = announcementVisibleUsernames($announcement);
    $rows = [];
    foreach (getStaffAccounts() as $username => $account) {
        // 只统计公告可见范围内的员工
        if (!in_array((string) $username, $visibleUsernames, true)) {
            continue;
        }
        $read = $readsByUsername[(string) $username] ?? null;
        $isRead = $read !== null && (int) $read['read_revision'] === (int) $announcement['revision'];
        $rows[] = [
            'username' => (string) $username,
            'name' => (string) ($account['name'] ?? $username),
            'role' => (string) ($account['role'] ?? 'staff'),
            'is_read' => $isRead,
            'read_at' => $isRead ? (string) $read['read_at'] : '',
        ];
    }

    usort($rows, function (array $a, array $b): int {
        if ($a['is_read'] !== $b['is_read']) {
            return $a['is_read'] ? -1 : 1;
        }
        return strcmp($a['name'], $b['name']);
    });

    return $rows;
}

function getRecordVersion(PDO $pdo, ?int $memberId = null): string
{
    if ($memberId !== null) {
        $memberStmt = $pdo->prepare('
            SELECT id, points, total_spent, balance, status, level, note
            FROM member
            WHERE id = ?
        ');
        $memberStmt->execute([$memberId]);

        $consumptionStmt = $pdo->prepare('
            SELECT COUNT(*) as count, COALESCE(MAX(create_time), "") as latest, COALESCE(SUM(amount), 0) as amount, COALESCE(SUM(refunded_amount), 0) as refunded, COALESCE(SUM(points_earned), 0) as points
            FROM consumption
            WHERE member_id = ?
        ');
        $consumptionStmt->execute([$memberId]);

        $rechargeStmt = $pdo->prepare('
            SELECT COUNT(*) as count, COALESCE(MAX(create_time), "") as latest, COALESCE(SUM(amount), 0) as amount
            FROM recharge
            WHERE member_id = ?
        ');
        $rechargeStmt->execute([$memberId]);

        $pointsStmt = $pdo->prepare('
            SELECT COUNT(*) as count, COALESCE(MAX(create_time), "") as latest, COALESCE(SUM(change_amount), 0) as points
            FROM points_log
            WHERE member_id = ?
        ');
        $pointsStmt->execute([$memberId]);

        $couponStmt = $pdo->prepare('
            SELECT COUNT(*) as count, COALESCE(MAX(created_at), "") as latest_created, COALESCE(MAX(used_at), "") as latest_used
            FROM member_coupons
            WHERE member_id = ?
        ');
        $couponStmt->execute([$memberId]);

        return sha1(json_encode([
            'member' => $memberStmt->fetch() ?: [],
            'consumption' => $consumptionStmt->fetch() ?: [],
            'recharge' => $rechargeStmt->fetch() ?: [],
            'points' => $pointsStmt->fetch() ?: [],
            'coupons' => $couponStmt->fetch() ?: [],
        ]));
    }

    return sha1(json_encode([
        'members' => $pdo->query('
            SELECT COUNT(*) as count, COALESCE(MAX(create_time), "") as latest, COALESCE(SUM(points), 0) as points, COALESCE(SUM(total_spent), 0) as spent, COALESCE(SUM(balance), 0) as balance
            FROM member
        ')->fetch(),
        'consumption' => $pdo->query('
            SELECT COUNT(*) as count, COALESCE(MAX(create_time), "") as latest, COALESCE(SUM(amount), 0) as amount, COALESCE(SUM(refunded_amount), 0) as refunded, COALESCE(SUM(points_earned), 0) as points
            FROM consumption
        ')->fetch(),
        'recharge' => $pdo->query('
            SELECT COUNT(*) as count, COALESCE(MAX(create_time), "") as latest, COALESCE(SUM(amount), 0) as amount
            FROM recharge
        ')->fetch(),
        'points' => $pdo->query('
            SELECT COUNT(*) as count, COALESCE(MAX(create_time), "") as latest, COALESCE(SUM(change_amount), 0) as points
            FROM points_log
        ')->fetch(),
        'coupons' => $pdo->query('
            SELECT COUNT(*) as count, COALESCE(MAX(created_at), "") as latest_created, COALESCE(MAX(used_at), "") as latest_used
            FROM member_coupons
        ')->fetch(),
        'logs' => $pdo->query('
            SELECT COUNT(*) as count, COALESCE(MAX(created_at), "") as latest
            FROM operation_logs
        ')->fetch(),
        'announcements' => $pdo->query("
            SELECT COUNT(*) as count, COALESCE(MAX(updated_at), '') as latest, COALESCE(SUM(revision), 0) as revisions
            FROM announcements
            WHERE status = 'published'
        ")->fetch(),
        'finance_expenses' => $pdo->query("SELECT COUNT(*) AS count, COALESCE(MAX(created_at), '') AS latest, COALESCE(SUM(CASE WHEN status = 'active' THEN amount ELSE 0 END), 0) AS amount FROM finance_expenses")->fetch(),
        'finance_refunds' => $pdo->query("SELECT COUNT(*) AS count, COALESCE(MAX(created_at), '') AS latest, COALESCE(SUM(amount), 0) AS amount FROM finance_refunds")->fetch(),
        'daily_income_reports' => $pdo->query("SELECT COUNT(*) AS count, COALESCE(MAX(updated_at), '') AS latest, COALESCE(SUM(reported_total), 0) AS amount FROM daily_income_reports")->fetch(),
        'complaints' => $pdo->query("SELECT COUNT(*) AS count, COALESCE(MAX(created_at), '') AS latest FROM complaint_records")->fetch(),
        'finance_transfers' => $pdo->query("SELECT COUNT(*) AS count, COALESCE(MAX(created_at), '') AS latest, COALESCE(SUM(amount), 0) AS amount FROM finance_transfers")->fetch(),
        'product_costs' => $pdo->query("SELECT COUNT(*) AS count, COALESCE(MAX(updated_at), '') AS latest, COALESCE(SUM(unit_cost), 0) AS amount FROM product_costs")->fetch(),
        'cost_ingredients' => $pdo->query("SELECT COUNT(*) AS count, COALESCE(MAX(updated_at), '') AS latest FROM cost_ingredients")->fetch(),
        'notifications' => $pdo->query("SELECT COUNT(*) AS count, COALESCE(MAX(created_at), '') AS latest FROM staff_notifications WHERE is_read = 0")->fetch(),
        'feedback' => $pdo->query("SELECT COUNT(*) AS count, COALESCE(MAX(created_at), '') AS latest, COALESCE(MAX(replied_at), '') AS replied FROM staff_feedback")->fetch(),
    ]));
}

function authenticateStaff(string $username, string $password): ?array
{
    $accounts = getStaffAccounts();
    if (!isset($accounts[$username])) {
        return null;
    }
    $account = $accounts[$username];
    if (password_verify($password, $account['password'])) {
        return [
            'username' => $username,
            'name' => $account['name'],
            'role' => $account['role'] ?? 'staff',
            'staff_access' => (bool) ($account['staff_access'] ?? (($account['role'] ?? 'staff') !== 'finance')),
        ];
    }
    return null;
}

function isAdmin(): bool
{
    $staff = getCurrentStaff();
    return $staff !== null && ($staff['role'] ?? '') === 'admin';
}

function isFinance(): bool
{
    $staff = getCurrentStaff();
    return $staff !== null && ($staff['role'] ?? '') === 'finance';
}

function canManageFinance(): bool
{
    return isAdmin() || isFinance();
}

function hasRegularStaffPermission(?array $staff = null): bool
{
    $staff = $staff ?? getCurrentStaff();
    if (!$staff) {
        return false;
    }
    if (staffHasAnyRole(['admin', 'staff', 'leader'], $staff)) {
        return true;
    }
    return ($staff['role'] ?? '') === 'finance' && (bool) ($staff['staff_access'] ?? false);
}

function requireFinance(): void
{
    if (!canManageFinance()) {
        flash('error', '您没有财务管理权限');
        redirect('/');
    }
}

function requireRegularStaffPermission(): void
{
    if (!hasRegularStaffPermission()) {
        flash('error', '您没有普通员工操作权限');
        redirect('/finance/');
    }
}

/** 每日消费日总结：管理员、当日负责人、财务可查看。 */
function canViewDailySummary(): bool
{
    $staff = getCurrentStaff();
    if (!$staff) {
        return false;
    }
    // 仅收银、当日负责人、管理员可查看（财务需附加「收银」或「当日负责人」职业）
    if (staffHasAnyRole(['admin', 'leader', 'staff'], $staff)) {
        return true;
    }
    $role = (string) ($staff['role'] ?? '');
    return $role === 'finance' && (bool) ($staff['staff_access'] ?? false);
}

function requireDailySummaryPermission(): void
{
    if (!canViewDailySummary()) {
        flash('error', '您没有查看每日消费统计的权限');
        redirect('/');
    }
}

function staffRoleLabel(string $role): string
{
    return match ($role) {
        'admin' => '管理员',
        'finance' => '财务',
        'leader' => '当日负责人',
        'barista' => '咖啡师',
        'reception' => '接待',
        'barista_screen' => '制作总览',
        'customer_screen' => '客户大屏',
        default => '收银',
    };
}

function requireAdmin(): void
{
    if (!isAdmin()) {
        flash('error', '您没有权限执行此操作');
        redirect('/');
    }
}

function requireLogin(): void
{
    if (!hasStaffAccounts()) {
        header('Location: /setup.php');
        exit;
    }

    if (!isset($_SESSION['staff'])) {
        header('Location: /login.php');
        exit;
    }

    $staff = $_SESSION['staff'];
    $currentSessionId = session_id();

    if (!isSessionValid($staff['username'], $currentSessionId)) {
        unset($_SESSION['staff']);
        session_destroy();
        header('Location: /login.php?reason=logged_in_elsewhere');
        exit;
    }

    touchActiveSession($staff['username'], $currentSessionId);

    // 已冻结账号只能使用反馈功能（提交/查看）与退出登录。
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (isStaffFrozen((string) ($staff['username'] ?? ''))) {
        $frozenAllowed = in_array($requestPath, ['/feedback.php', '/logout.php', '/api/feedback_version.php', '/api/csrf_token.php'], true);
        if (!$frozenAllowed) {
            redirect('/feedback.php');
        }
    }

    // 制作总览账号：仅能访问制作总览相关页面
    if (($staff['role'] ?? '') === 'barista_screen' && !isStaffFrozen((string) ($staff['username'] ?? ''))
        && !in_array($requestPath, ['/order-board.php', '/api/order_board.php', '/api/csrf_token.php', '/logout.php', '/change_password.php'], true)) {
        redirect('/order-board.php');
    }

    // 客户大屏账号：仅能访问客户大屏相关页面
    if (($staff['role'] ?? '') === 'customer_screen' && !isStaffFrozen((string) ($staff['username'] ?? ''))
        && !in_array($requestPath, ['/customer-board.php', '/api/customer_board.php', '/api/csrf_token.php', '/logout.php', '/change_password.php'], true)) {
        redirect('/customer-board.php');
    }

    // 咖啡师：制作总览（可操作）与客户大屏
    if (($staff['role'] ?? '') === 'barista' && !isStaffFrozen((string) ($staff['username'] ?? ''))
        && !in_array($requestPath, ['/order-board.php', '/customer-board.php', '/api/order_board.php', '/api/customer_board.php', '/api/consumption_prep.php', '/api/csrf_token.php', '/logout.php', '/change_password.php'], true)) {
        redirect('/order-board.php');
    }

    // 接待：可查看两个大屏但不可操作（操作由接口内的权限校验拦截）
    if (($staff['role'] ?? '') === 'reception' && !isStaffFrozen((string) ($staff['username'] ?? ''))
        && !in_array($requestPath, ['/order-board.php', '/customer-board.php', '/api/order_board.php', '/api/customer_board.php', '/api/csrf_token.php', '/logout.php', '/change_password.php'], true)) {
        redirect('/customer-board.php');
    }

    // 财务账号若附加了其他职业，则同时获得对应页面权限
    $financeExtraAllowed = false;
    if (staffHasAnyRole(['barista', 'barista_screen', 'customer_screen', 'reception'], $staff)) {
        $financeExtraAllowed = in_array($requestPath, [
            '/order-board.php', '/customer-board.php',
            '/api/order_board.php', '/api/customer_board.php', '/api/consumption_prep.php',
        ], true);
    }

    // 独立财务账号只能访问财务及账号自助页面；管理员始终拥有全部权限。
    $financeAllowed = $financeExtraAllowed
        || str_starts_with($requestPath, '/finance/')
        || str_starts_with($requestPath, '/daily-report/')
        || in_array($requestPath, ['/consumption/summary.php', '/logout.php', '/change_password.php', '/devices.php', '/api/record_version.php', '/api/csrf_token.php', '/feedback.php', '/api/feedback_version.php'], true);
    if (($staff['role'] ?? '') === 'finance' && !hasRegularStaffPermission($staff) && !$financeAllowed) {
        redirect('/finance/');
    }

    checkLicenseIfDue();
    if (!isLicenseActive()) {
        header('Location: /activate.php');
        exit;
    }
}

function getCurrentStaff(): ?array
{
    return $_SESSION['staff'] ?? null;
}

function logout(): void
{
    $staff = $_SESSION['staff'] ?? null;
    if ($staff) {
        logOperation(getDb(), 'staff_logout', 'staff', null, '员工退出登录：' . $staff['name'], [], $staff);
        clearActiveSession($staff['username']);
    }
    unset($_SESSION['staff']);
    session_destroy();
    header('Location: /login.php');
    exit;
}

// 页面通常在处理表单后才渲染页头，因此这里提前阻止独立财务账号调用普通员工写入接口。
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['staff'])) {
    $routeStaff = $_SESSION['staff'];
    $routePath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    // 财务若附加了咖啡师 / 接待 / 大屏等职业，可访问对应的大屏页面
    $extraBoardAllowed = false;
    if (staffHasAnyRole(['barista', 'barista_screen', 'customer_screen', 'reception'], $routeStaff)) {
        $extraBoardAllowed = in_array($routePath, [
            '/order-board.php', '/customer-board.php',
            '/api/order_board.php', '/api/customer_board.php', '/api/consumption_prep.php',
        ], true);
    }

    $financeOnlyAllowed = $extraBoardAllowed
        || str_starts_with($routePath, '/finance/')
        || str_starts_with($routePath, '/daily-report/')
        || in_array($routePath, ['/consumption/summary.php', '/logout.php', '/change_password.php', '/devices.php', '/activate.php', '/api/record_version.php', '/api/csrf_token.php', '/feedback.php', '/api/feedback_version.php'], true);
    if (($routeStaff['role'] ?? '') === 'finance' && !hasRegularStaffPermission($routeStaff) && !$financeOnlyAllowed) {
        redirect('/finance/');
    }
    // 已冻结账号仅允许反馈相关页面（在渲染前拦截，防止通过表单接口操作）
    if (isStaffFrozen((string) ($routeStaff['username'] ?? ''))) {
        $frozenOnlyAllowed = in_array($routePath, ['/feedback.php', '/logout.php', '/api/feedback_version.php', '/api/csrf_token.php'], true);
        if (!$frozenOnlyAllowed) {
            redirect('/feedback.php');
        }
    }
}

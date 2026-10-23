<?php

function financeExpenseCategories(): array
{
    $categories = [];
    foreach (getSystemSettings()['expense_categories'] ?? [] as $category) {
        $key = (string) ($category['key'] ?? '');
        $name = (string) ($category['name'] ?? '');
        if ($key !== '' && $name !== '') {
            $categories[$key] = $name;
        }
    }
    return $categories ?: ['other' => '其他支出'];
}

function financePaymentMethods(): array
{
    return [
        'cash' => '现金',
        'wechat' => '微信',
        'alipay' => '支付宝',
        'bank' => '银行卡',
        'balance' => '会员余额',
        'other' => '其他',
    ];
}

function financePaymentLabel(mixed $method): string
{
    $method = strtolower(trim((string) $method));
    return financePaymentMethods()[$method] ?? ($method !== '' ? $method : '未标记');
}

function financeAccountLabel(PDO $pdo, mixed $code): string
{
    $code = (string) $code;
    if (str_starts_with($code, 'account:')) {
        $stmt = $pdo->prepare('SELECT name FROM finance_accounts WHERE id = ?');
        $stmt->execute([(int) substr($code, 8)]);
        return (string) ($stmt->fetchColumn() ?: '已停用账户');
    }
    return financePaymentLabel($code);
}

function financeTransferAccountOptions(PDO $pdo): array
{
    $options = [
        'cash' => '现金',
        'wechat' => '微信',
        'alipay' => '支付宝',
        'bank' => '银行卡',
    ];
    foreach ($pdo->query("SELECT id, name FROM finance_accounts WHERE status = 'active' ORDER BY name")->fetchAll() as $account) {
        $options['account:' . (int) $account['id']] = (string) $account['name'];
    }
    return $options;
}

function getFinanceAccountBalances(PDO $pdo): array
{
    $balances = ['cash' => 0.0, 'wechat' => 0.0, 'alipay' => 0.0, 'bank' => 0.0];
    foreach (array_keys($balances) as $method) {
        $sales = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM consumption WHERE payment_method = ? AND record_type = 'sale'");
        $sales->execute([$method]);
        $recharges = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM recharge WHERE payment_method = ?');
        $recharges->execute([$method]);
        $expense = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM finance_expenses WHERE payment_account = ? AND type = 'expense' AND status = 'active'");
        $expense->execute([$method]);
        $income = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM finance_expenses WHERE payment_account = ? AND type = 'income' AND status = 'active'");
        $income->execute([$method]);
        $refunds = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM finance_refunds WHERE payment_method = ?');
        $refunds->execute([$method]);
        $balances[$method] = (float) $sales->fetchColumn() + (float) $recharges->fetchColumn() + (float) $income->fetchColumn() - (float) $expense->fetchColumn() - (float) $refunds->fetchColumn();
    }

    foreach ($pdo->query('SELECT id, opening_balance FROM finance_accounts')->fetchAll() as $account) {
        $code = 'account:' . (int) $account['id'];
        $expense = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM finance_expenses WHERE payment_account = ? AND type = 'expense' AND status = 'active'");
        $expense->execute([$code]);
        $income = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM finance_expenses WHERE payment_account = ? AND type = 'income' AND status = 'active'");
        $income->execute([$code]);
        $balances[$code] = (float) $account['opening_balance'] + (float) $income->fetchColumn() - (float) $expense->fetchColumn();
    }

    foreach ($pdo->query('SELECT from_account, to_account, amount FROM finance_transfers')->fetchAll() as $transfer) {
        $from = (string) $transfer['from_account'];
        $to = (string) $transfer['to_account'];
        $amount = (float) $transfer['amount'];
        $balances[$from] = ($balances[$from] ?? 0) - $amount;
        $balances[$to] = ($balances[$to] ?? 0) + $amount;
    }
    return $balances;
}

function financeCsrfToken(): string
{
    if (empty($_SESSION['finance_csrf'])) {
        $_SESSION['finance_csrf'] = bin2hex(random_bytes(24));
    }
    return (string) $_SESSION['finance_csrf'];
}

function verifyFinanceCsrf(): bool
{
    $submitted = (string) ($_POST['csrf_token'] ?? '');
    return $submitted !== '' && hash_equals(financeCsrfToken(), $submitted);
}

function financeExpenseCategoryLabel(string $category): string
{
    return financeExpenseCategories()[$category] ?? $category;
}

function getProductCostMap(PDO $pdo): array
{
    $map = [];
    foreach ($pdo->query('SELECT product_name, unit_cost FROM product_costs')->fetchAll() as $row) {
        $map[(string) $row['product_name']] = (float) $row['unit_cost'];
    }
    return $map;
}

function getProductCostRecipe(PDO $pdo, string $productName): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM product_costs WHERE product_name = ?');
    $stmt->execute([$productName]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $row['components'] = decryptFinanceData($row['encrypted_components'] ?? '')['components'] ?? [];
    return $row;
}

function getCostIngredients(PDO $pdo): array
{
    $ingredients = [];
    foreach ($pdo->query('SELECT * FROM cost_ingredients ORDER BY name')->fetchAll() as $row) {
        $details = decryptFinanceData((string) ($row['encrypted_details'] ?? ''));
        $row['unit'] = (string) ($details['unit'] ?? '份');
        $row['unit_price'] = (float) ($details['unit_price'] ?? 0);
        $ingredients[] = $row;
    }
    return $ingredients;
}

function getCostIngredientMap(PDO $pdo): array
{
    $map = [];
    foreach (getCostIngredients($pdo) as $ingredient) {
        $map[(int) $ingredient['id']] = $ingredient;
    }
    return $map;
}

function calculateSelectedItemsCost(PDO $pdo, array $selectedItems): float
{
    $costMap = getProductCostMap($pdo);
    $total = 0.0;
    foreach ($selectedItems as $selected) {
        $name = (string) ($selected['item']['name'] ?? '');
        $quantity = max(0, (int) ($selected['quantity'] ?? 0));
        $total += (float) ($costMap[$name] ?? 0) * $quantity;
    }
    return round($total, 2);
}

function financeEncryptionKey(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }

    $environmentKey = trim((string) getenv('FINANCE_ENCRYPTION_KEY'));
    if ($environmentKey !== '') {
        $decodedEnvironmentKey = base64_decode($environmentKey, true);
        if (!is_string($decodedEnvironmentKey) || strlen($decodedEnvironmentKey) !== 32) {
            throw new RuntimeException('FINANCE_ENCRYPTION_KEY 必须是 32 字节密钥的 Base64 编码');
        }
        return $key = $decodedEnvironmentKey;
    }

    $path = defined('FINANCE_KEY_PATH') ? FINANCE_KEY_PATH : __DIR__ . '/../data/finance.key';
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    if (file_exists($path)) {
        $stored = trim((string) file_get_contents($path));
        $decoded = base64_decode($stored, true);
        if (is_string($decoded) && strlen($decoded) === 32) {
            return $key = $decoded;
        }
    }

    $candidate = random_bytes(32);
    $handle = @fopen($path, 'x');
    if (is_resource($handle)) {
        $encoded = base64_encode($candidate);
        $written = fwrite($handle, $encoded);
        fflush($handle);
        fclose($handle);
        if ($written !== strlen($encoded)) {
            throw new RuntimeException('财务加密密钥无法完整写入，请检查磁盘状态');
        }
        @chmod($path, 0600);
        return $key = $candidate;
    }

    // 另一请求可能刚刚创建了密钥，读取其结果，保证所有进程使用同一把密钥。
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $stored = trim((string) @file_get_contents($path));
        $decoded = base64_decode($stored, true);
        if (is_string($decoded) && strlen($decoded) === 32) {
            return $key = $decoded;
        }
        usleep(20000);
    }
    throw new RuntimeException('财务加密密钥无法创建，请检查 data 目录权限');
}

function encryptFinanceData(array $data): string
{
    if ($data === []) {
        return '';
    }
    $plaintext = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($plaintext)) {
        throw new RuntimeException('财务敏感数据编码失败');
    }
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('服务器未启用 OpenSSL，无法安全保存财务数据');
    }
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', financeEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag, 'finance-v1', 16);
    if ($ciphertext === false) {
        throw new RuntimeException('财务敏感数据加密失败');
    }
    return 'v1:' . base64_encode($iv . $tag . $ciphertext);
}

function decryptFinanceData(?string $encrypted): array
{
    if (!$encrypted) {
        return [];
    }
    if (!str_starts_with($encrypted, 'v1:')) {
        return [];
    }
    $raw = base64_decode(substr($encrypted, 3), true);
    if (!is_string($raw) || strlen($raw) < 29 || !function_exists('openssl_decrypt')) {
        return [];
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', financeEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag, 'finance-v1');
    if (!is_string($plaintext)) {
        return [];
    }
    $data = json_decode($plaintext, true);
    return is_array($data) ? $data : [];
}

function financeDateRange(?string $from, ?string $to): array
{
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $from) ? $from : date('Y-m-01');
    $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $to) ? $to : date('Y-m-d');
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    return [$from, $to];
}

function getFinanceSummary(PDO $pdo, string $from, string $to): array
{
    $consumptionStmt = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN record_type = 'sale' AND refunded_at IS NULL THEN 1 ELSE 0 END), 0) AS count,
               COALESCE(SUM(CASE WHEN record_type = 'sale' THEN amount - COALESCE(refunded_amount, 0) ELSE 0 END), 0) AS revenue,
               COALESCE(SUM(cost_amount), 0) AS product_cost,
               COALESCE(SUM(net_income), 0) AS sales_net_income,
               COALESCE(SUM(CASE WHEN record_type = 'points_redeem' THEN cost_amount ELSE 0 END), 0) AS redemption_cost,
               COALESCE(SUM(CASE WHEN record_type = 'sale' AND payment_method = 'balance' THEN amount - COALESCE(refunded_amount, 0) ELSE 0 END), 0) AS balance_revenue,
               COALESCE(SUM(CASE WHEN record_type = 'sale' AND refunded_at IS NULL THEN coupon_discount ELSE 0 END), 0) AS discounts
        FROM consumption
        WHERE DATE(create_time) BETWEEN ? AND ? AND record_type IN ('sale', 'points_redeem', 'board_redo')
    ");
    $consumptionStmt->execute([$from, $to]);
    $sales = $consumptionStmt->fetch() ?: [];

    $rechargeStmt = $pdo->prepare('SELECT COALESCE(SUM(amount - COALESCE(refunded_amount, 0)), 0) FROM recharge WHERE DATE(create_time) BETWEEN ? AND ?');
    $rechargeStmt->execute([$from, $to]);

    // 经营支出：不包含「余额调整」（账实差异调整不属于经营行为）
    $expenseStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM finance_expenses WHERE DATE(occurred_at) BETWEEN ? AND ? AND type = 'expense' AND status = 'active' AND category <> 'balance_adjust'");
    $expenseStmt->execute([$from, $to]);

    $incomeStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM finance_expenses WHERE DATE(occurred_at) BETWEEN ? AND ? AND type = 'income' AND status = 'active' AND category <> 'balance_adjust'");
    $incomeStmt->execute([$from, $to]);

    $refundStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM finance_refunds WHERE DATE(created_at) BETWEEN ? AND ?');
    $refundStmt->execute([$from, $to]);

    $revenue = (float) ($sales['revenue'] ?? 0);
    $productCost = (float) ($sales['product_cost'] ?? 0);
    $salesNetIncome = (float) ($sales['sales_net_income'] ?? ($revenue - $productCost));
    $expenses = (float) $expenseStmt->fetchColumn();
    $otherIncome = (float) $incomeStmt->fetchColumn();
    return [
        'order_count' => (int) ($sales['count'] ?? 0),
        'revenue' => $revenue,
        'product_cost' => $productCost,
        'redemption_cost' => (float) ($sales['redemption_cost'] ?? 0),
        'sales_net_income' => $salesNetIncome,
        'balance_revenue' => (float) ($sales['balance_revenue'] ?? 0),
        'cash_sales' => $revenue - (float) ($sales['balance_revenue'] ?? 0),
        'recharges' => (float) $rechargeStmt->fetchColumn(),
        'expenses' => $expenses,
        'other_income' => $otherIncome,
        'refunds' => (float) $refundStmt->fetchColumn(),
        'discounts' => (float) ($sales['discounts'] ?? 0),
        'operating_result' => $salesNetIncome - $expenses + $otherIncome,
        'stored_value_liability' => (float) $pdo->query('SELECT COALESCE(SUM(balance), 0) FROM member')->fetchColumn(),
        'average_order' => ((int) ($sales['count'] ?? 0)) > 0 ? $revenue / (int) $sales['count'] : 0,
    ];
}

/**
 * 每日上报的系统参考金额。
 * 上报口径：只统计现金 / 微信 / 支付宝三个渠道的消费与会员充值；
 * 银行卡、会员余额消费及其他渠道不计入计算（仍会在表单中展示，仅作登记）。
 */
function getDailyIncomeReference(PDO $pdo, string $date): array
{
    $reference = ['cash' => 0.0, 'wechat' => 0.0, 'alipay' => 0.0, 'bank' => 0.0, 'balance' => 0.0, 'other' => 0.0];
    $countedMethods = ['cash', 'wechat', 'alipay'];

    // 严格区分渠道：余额与「其他」不计入，未知值按现金兜底（与系统既有口径一致）
    $normalizeMethod = static function (mixed $raw): string {
        $method = strtolower(trim((string) $raw));
        if (in_array($method, ['balance', '余额', '余额支付', '1'], true)) {
            return 'balance';
        }
        return in_array($method, ['cash', 'wechat', 'alipay', 'bank', 'other'], true) ? $method : 'cash';
    };

    $stmt = $pdo->prepare("SELECT amount, refunded_amount, payment_method FROM consumption WHERE DATE(create_time) = ? AND refunded_at IS NULL AND record_type = 'sale'");
    $stmt->execute([$date]);
    foreach ($stmt->fetchAll() as $row) {
        $method = $normalizeMethod($row['payment_method']);
        if (in_array($method, $countedMethods, true)) {
            $reference[$method] += max(0, (float) $row['amount'] - (float) $row['refunded_amount']);
        }
    }

    // 会员充值计入上报（按实际收款渠道）
    $rechargeStmt = $pdo->prepare('SELECT amount, COALESCE(refunded_amount, 0) AS refunded_amount, payment_method FROM recharge WHERE DATE(create_time) = ?');
    $rechargeStmt->execute([$date]);
    foreach ($rechargeStmt->fetchAll() as $row) {
        $method = $normalizeMethod($row['payment_method'] ?? 'cash');
        if (in_array($method, $countedMethods, true)) {
            // 已退费的部分不计入上报参考
            $netAmount = (float) $row['amount'] - (float) $row['refunded_amount'];
            if ($netAmount > 0) {
                $reference[$method] += $netAmount;
            }
        }
    }

    $reference['total'] = $reference['cash'] + $reference['wechat'] + $reference['alipay'];
    return $reference;
}

function getFinanceLedger(PDO $pdo, string $from, string $to): array
{
    $rows = [];

    $stmt = $pdo->prepare("SELECT id, amount, cost_amount, net_income, payment_method, items, operator, create_time FROM consumption WHERE DATE(create_time) BETWEEN ? AND ? AND record_type = 'sale'");
    $stmt->execute([$from, $to]);
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'time' => $row['create_time'], 'kind' => '收入', 'category' => '销售收入',
            'amount' => (float) $row['amount'], 'direction' => 1,
            'account' => financePaymentLabel(normalizeStoredPaymentMethod($row['payment_method'])), 'summary' => $row['items'] ?: '消费登记',
            'operator' => $row['operator'] ?: '-', 'source' => '消费 #' . $row['id'],
            'cost_amount' => (float) ($row['cost_amount'] ?? 0), 'net_income' => (float) $row['amount'] - (float) ($row['cost_amount'] ?? 0),
        ];
    }

    $stmt = $pdo->prepare("SELECT id, cost_amount, items, operator, create_time FROM consumption WHERE DATE(create_time) BETWEEN ? AND ? AND record_type = 'points_redeem' AND cost_amount > 0");
    $stmt->execute([$from, $to]);
    foreach ($stmt->fetchAll() as $row) {
        $costAmount = (float) ($row['cost_amount'] ?? 0);
        $rows[] = [
            'time' => $row['create_time'], 'kind' => '成本', 'category' => '积分兑换商品成本',
            'amount' => $costAmount, 'direction' => -1,
            'account' => '积分', 'summary' => $row['items'] ?: '积分兑换',
            'operator' => $row['operator'] ?: '-', 'source' => '积分兑换 #' . $row['id'],
            'cost_amount' => $costAmount, 'net_income' => -$costAmount,
        ];
    }

    $stmt = $pdo->prepare("SELECT id, cost_amount, items, note, operator, create_time FROM consumption WHERE DATE(create_time) BETWEEN ? AND ? AND record_type = 'board_redo'");
    $stmt->execute([$from, $to]);
    foreach ($stmt->fetchAll() as $row) {
        $costAmount = (float) ($row['cost_amount'] ?? 0);
        $rows[] = [
            'time' => $row['create_time'], 'kind' => '成本', 'category' => '饮品重做成本',
            'amount' => $costAmount, 'direction' => -1,
            'account' => '内部成本', 'summary' => ($row['items'] ?: '大屏重做') . ' · ' . ($row['note'] ?: ''),
            'operator' => $row['operator'] ?: '-', 'source' => '重做 #' . $row['id'],
            'cost_amount' => $costAmount, 'net_income' => -$costAmount,
        ];
    }

    $stmt = $pdo->prepare("SELECT id, amount, payment_method, operator, create_time FROM recharge WHERE DATE(create_time) BETWEEN ? AND ?");
    $stmt->execute([$from, $to]);
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'time' => $row['create_time'], 'kind' => '资金流入', 'category' => '会员充值（储值负债）',
            'amount' => (float) $row['amount'], 'direction' => 1,
            'account' => financePaymentLabel($row['payment_method']), 'summary' => '会员充值，不计营业收入',
            'operator' => $row['operator'] ?: '-', 'source' => '充值 #' . $row['id'],
            'cost_amount' => null, 'net_income' => null,
        ];
    }

    $stmt = $pdo->prepare("SELECT * FROM finance_expenses WHERE DATE(occurred_at) BETWEEN ? AND ? AND status = 'active'");
    $stmt->execute([$from, $to]);
    foreach ($stmt->fetchAll() as $row) {
        $payload = decryptFinanceData($row['encrypted_payload'] ?? '');
        $isIncome = (($row['type'] ?? 'expense') === 'income');
        $rows[] = [
            'time' => $row['occurred_at'],
            'kind' => $isIncome ? '收入' : '支出',
            'category' => ($isIncome ? '其他收入·' : '') . financeExpenseCategoryLabel($row['category']),
            'amount' => (float) $row['amount'], 'direction' => $isIncome ? 1 : -1,
            'account' => financeAccountLabel($pdo, $row['payment_account']), 'summary' => $payload['note'] ?? ($isIncome ? '收入登记' : '支出登记'),
            'operator' => $row['operator_name'] ?: '-', 'source' => '收支 #' . $row['id'],
            'cost_amount' => null, 'net_income' => null,
        ];
    }

    $stmt = $pdo->prepare('SELECT * FROM finance_refunds WHERE DATE(created_at) BETWEEN ? AND ?');
    $stmt->execute([$from, $to]);
    foreach ($stmt->fetchAll() as $row) {
        $reason = decryptFinanceData($row['encrypted_reason'] ?? '');
        $rows[] = [
            'time' => $row['created_at'], 'kind' => '退款', 'category' => $row['source_type'] === 'recharge' ? '充值退款' : ($row['source_type'] === 'consumption_item' ? '饮品退款' : '消费退款'),
            'amount' => (float) $row['amount'], 'direction' => -1,
            'account' => financePaymentLabel($row['payment_method']), 'summary' => ($row['source_type'] === 'consumption_item' ? (string) ($row['item_name'] ?? '') . ' · ' : '') . ($reason['reason'] ?? '退款'),
            'operator' => $row['operator_name'] ?: '-', 'source' => ($row['source_type'] === 'recharge' ? '充值' : '消费') . ' #' . $row['source_id'],
            'cost_amount' => null, 'net_income' => $row['source_type'] === 'recharge' ? null : -(float) $row['amount'],
        ];
    }

    usort($rows, fn (array $a, array $b): int => strcmp($b['time'], $a['time']));
    return $rows;
}

/** 重开一杯已完成饮品，并生成零收入的重做记录单独核算追加成本。 */
function redoConsumptionItem(PDO $pdo, int $consumptionId, int $itemIndex, array $staff): array
{
    if ($consumptionId <= 0 || $itemIndex < 0) {
        throw new RuntimeException('请选择有效饮品');
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM consumption WHERE id = ? AND refunded_at IS NULL');
        $stmt->execute([$consumptionId]);
        $order = $stmt->fetch();
        $items = $order ? consumptionPreparationItems($order) : [];
        if (!isset($items[$itemIndex]) || ($items[$itemIndex]['status'] ?? '') !== 'done') {
            throw new RuntimeException('只能重做已完成且未退款的饮品');
        }
        $costMap = getProductCostMap($pdo);
        $redoCost = max(0, (float) ($items[$itemIndex]['unit_cost'] ?? ($costMap[(string) $items[$itemIndex]['name']] ?? 0)));
        $items[$itemIndex]['unit_cost'] = $redoCost;
        $items[$itemIndex]['redo_count'] = (int) ($items[$itemIndex]['redo_count'] ?? 0) + 1;
        $items[$itemIndex]['redo_history'][] = [
            'prepared_at' => $items[$itemIndex]['prepared_at'] ?? null,
            'prepared_by' => $items[$itemIndex]['prepared_by'] ?? '',
        ];
        $items[$itemIndex]['status'] = 'pending';
        $items[$itemIndex]['prepared_at'] = null;
        $items[$itemIndex]['prepared_by'] = '';
        $pdo->prepare("UPDATE consumption SET prep_items = ?, prep_status = 'pending', prepared_at = NULL, prepared_by = '' WHERE id = ?")
            ->execute([json_encode($items, JSON_UNESCAPED_UNICODE), $consumptionId]);
        $now = currentDateTime();
        $pdo->prepare("INSERT INTO consumption (member_id, amount, points_earned, items, note, operator, payment_method, ticket_number, record_type, cost_amount, net_income, prep_status, create_time) VALUES (?, 0, 0, ?, ?, ?, 'redo', ?, 'board_redo', ?, ?, 'done', ?)")
            ->execute([
                (int) ($order['member_id'] ?? 0),
                '[大屏重做] ' . (string) $items[$itemIndex]['name'],
                '原消费记录 #' . $consumptionId . ' · 第' . ($itemIndex + 1) . '杯',
                (string) ($staff['name'] ?? ''),
                (string) ($order['ticket_number'] ?? ''),
                $redoCost,
                -$redoCost,
                $now,
            ]);
        $redoRecordId = (int) $pdo->lastInsertId();
        logOperation($pdo, 'drink_redo', 'consumption', $consumptionId, '饮品重做：' . (string) $items[$itemIndex]['name'], [
            'item_index' => $itemIndex, 'redo_count' => $items[$itemIndex]['redo_count'], 'cost_amount' => $redoCost,
            'redo_record_id' => $redoRecordId,
        ], $staff);
        $pdo->commit();
        return $items[$itemIndex];
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

/** 逐杯退款；新订单按下单时保存的实付金额退款，旧订单由操作人填写该杯金额。 */
function refundConsumptionItem(PDO $pdo, int $consumptionId, int $itemIndex, string $reason, array $staff, ?float $legacyAmount = null): array
{
    $reason = trim($reason);
    if ($consumptionId <= 0 || $itemIndex < 0 || $reason === '') {
        throw new RuntimeException('请选择饮品并填写退款原因');
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM consumption WHERE id = ? AND record_type = 'sale' AND refunded_at IS NULL");
        $stmt->execute([$consumptionId]);
        $record = $stmt->fetch();
        if (!$record) {
            throw new RuntimeException('原订单不存在或已经全部退款');
        }
        $items = consumptionPreparationItems($record);
        if (!isset($items[$itemIndex]) || ($items[$itemIndex]['status'] ?? '') === 'refunded') {
            throw new RuntimeException('该杯饮品不存在或已经退款');
        }
        $remaining = round((float) $record['amount'] - (float) ($record['refunded_amount'] ?? 0), 2);
        if ($remaining < 0.01) {
            throw new RuntimeException('原订单没有可退金额');
        }
        $item = $items[$itemIndex];
        if (array_key_exists('charged_amount', $item)) {
            $amount = round((float) $item['charged_amount'], 2);
        } else {
            if ($legacyAmount === null) {
                throw new RuntimeException('旧订单请填写这杯饮品的实退金额');
            }
            $amount = round($legacyAmount, 2);
        }
        if ($amount <= 0 || $amount > $remaining + 0.009) {
            throw new RuntimeException('退款金额必须大于 0，且不能超过订单剩余可退金额 ' . formatMoney($remaining));
        }
        $points = array_key_exists('points_earned', $item)
            ? min((int) $record['points_earned'], max(0, (int) $item['points_earned']))
            : min((int) $record['points_earned'], (int) round((int) $record['points_earned'] * $amount / $remaining));
        $paymentMethod = normalizeStoredPaymentMethod($record['payment_method']);
        $memberId = (int) $record['member_id'];
        if ($memberId > 0) {
            $member = getMemberById($pdo, $memberId);
            if (!$member) {
                throw new RuntimeException('对应会员不存在');
            }
            $newSpent = max(0, round((float) $member['total_spent'] - $amount, 2));
            $newPoints = max(0, (int) $member['points'] - $points);
            $newBalance = round((float) $member['balance'] + ($paymentMethod === 'balance' ? $amount : 0), 2);
            $pdo->prepare('UPDATE member SET total_spent = ?, points = ?, balance = ?, level = ? WHERE id = ?')
                ->execute([$newSpent, $newPoints, $newBalance, calculateLevel($newSpent), $memberId]);
            if ($points > 0) {
                addPointsLog($pdo, $memberId, -$points, '饮品退款扣回积分：' . (string) ($item['name'] ?? ''));
            }
        }
        $now = currentDateTime();
        $items[$itemIndex]['status'] = 'refunded';
        $items[$itemIndex]['refund_amount'] = $amount;
        $items[$itemIndex]['refunded_at'] = $now;
        $items[$itemIndex]['refunded_by'] = (string) ($staff['name'] ?? '');
        $newRefunded = round((float) ($record['refunded_amount'] ?? 0) + $amount, 2);
        $fullyRefunded = $newRefunded >= (float) $record['amount'] - 0.009;
        $allResolved = count(array_filter($items, static fn (array $row): bool => in_array(($row['status'] ?? ''), ['done', 'refunded'], true))) === count($items);
        $costReduction = ($item['status'] ?? '') === 'pending' && (int) ($item['redo_count'] ?? 0) === 0
            ? max(0, (float) ($item['unit_cost'] ?? 0)) : 0.0;
        $newCostAmount = max(0, round((float) $record['cost_amount'] - $costReduction, 2));
        $newNetIncome = round((float) ($record['net_income'] ?? ((float) $record['amount'] - (float) $record['cost_amount'])) - $amount + $costReduction, 2);
        $newCouponDiscount = $remaining > 0 ? round((float) ($record['coupon_discount'] ?? 0) * max(0, $remaining - $amount) / $remaining, 2) : 0.0;
        $pdo->prepare('UPDATE consumption SET prep_items = ?, prep_status = ?, refunded_amount = ?, points_earned = ?, coupon_discount = ?, cost_amount = ?, net_income = ?, refunded_at = ? WHERE id = ?')
            ->execute([json_encode($items, JSON_UNESCAPED_UNICODE), $allResolved ? 'done' : 'pending', $newRefunded, max(0, (int) $record['points_earned'] - $points), $newCouponDiscount, $newCostAmount, $newNetIncome, $fullyRefunded ? $now : null, $consumptionId]);
        if ($fullyRefunded && (int) ($record['coupon_id'] ?? 0) > 0) {
            $pdo->prepare("UPDATE member_coupons SET status = 'unused', used_at = NULL, used_amount = 0 WHERE id = ?")
                ->execute([(int) $record['coupon_id']]);
        }
        $pdo->prepare('INSERT INTO finance_refunds (source_type, source_id, item_index, item_name, amount, payment_method, encrypted_reason, operator_username, operator_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['consumption_item', $consumptionId, $itemIndex, (string) ($item['name'] ?? ''), $amount, $paymentMethod, encryptFinanceData(['reason' => $reason]), (string) ($staff['username'] ?? ''), (string) ($staff['name'] ?? ''), $now]);
        $refundId = (int) $pdo->lastInsertId();
        logOperation($pdo, 'drink_refunded', 'consumption', $consumptionId, '饮品退款：' . (string) ($item['name'] ?? '') . ' ' . formatMoney($amount), [
            'item_index' => $itemIndex, 'refund_id' => $refundId, 'amount' => $amount,
        ], $staff);
        $pdo->commit();
        return ['amount' => $amount, 'refund_id' => $refundId, 'remaining' => max(0, round((float) $record['amount'] - $newRefunded, 2))];
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

/**
 * 执行退款（消费 / 充值）：回退会员积分、累计消费与余额，恢复优惠券，
 * 标记原始记录已退款并写入退款记录。成功返回 amount / payment_method / refund_id。
 * 失败时抛出 RuntimeException（事务已回滚）。
 */
function refundFinanceSource(PDO $pdo, string $sourceType, int $sourceId, string $reason, array $staff, ?float $requestedAmount = null): array
{
    if (!in_array($sourceType, ['consumption', 'recharge'], true)) {
        throw new RuntimeException('不支持的退款类型');
    }
    if ($sourceId <= 0) {
        throw new RuntimeException('请选择有效的原始记录');
    }
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('请填写退款原因');
    }

    $pdo->beginTransaction();
    try {
        if ($sourceType === 'consumption') {
            $stmt = $pdo->prepare("SELECT * FROM consumption WHERE id = ? AND refunded_at IS NULL AND record_type = 'sale'");
            $stmt->execute([$sourceId]);
            $record = $stmt->fetch();
            if (!$record) {
                throw new RuntimeException('消费记录不存在或已经退款');
            }
            $amount = round((float) $record['amount'] - (float) ($record['refunded_amount'] ?? 0), 2);
            if ($amount <= 0.009) {
                throw new RuntimeException('该消费记录已无可退金额');
            }
            $paymentMethod = normalizeStoredPaymentMethod($record['payment_method']);
            $memberId = (int) $record['member_id'];
            if ($memberId > 0) {
                $member = getMemberById($pdo, $memberId);
                if ($member) {
                    $newSpent = max(0, (float) $member['total_spent'] - $amount);
                    $newPoints = max(0, (int) $member['points'] - (int) $record['points_earned']);
                    $newBalance = (float) $member['balance'] + ($paymentMethod === 'balance' ? $amount : 0);
                    $update = $pdo->prepare('UPDATE member SET total_spent = ?, points = ?, balance = ?, level = ? WHERE id = ?');
                    $update->execute([$newSpent, $newPoints, $newBalance, calculateLevel($newSpent), $memberId]);
                    if ((int) $record['points_earned'] > 0) {
                        addPointsLog($pdo, $memberId, -(int) $record['points_earned'], '消费退款扣回积分');
                    }
                }
            }
            if ((int) ($record['coupon_id'] ?? 0) > 0) {
                $pdo->prepare("UPDATE member_coupons SET status = 'unused', used_at = NULL, used_amount = 0 WHERE id = ?")->execute([(int) $record['coupon_id']]);
            }
            $pdo->prepare('UPDATE consumption SET refunded_at = ?, refunded_amount = ?, points_earned = 0, net_income = net_income - ? WHERE id = ?')
                ->execute([currentDateTime(), (float) $record['amount'], $amount, $sourceId]);
        } else {
            $stmt = $pdo->prepare('SELECT * FROM recharge WHERE id = ?');
            $stmt->execute([$sourceId]);
            $record = $stmt->fetch();
            if (!$record) {
                throw new RuntimeException('充值记录不存在');
            }

            // 支持部分退费：累计已退金额，退完才标记 refunded_at
            $recordAmount = (float) $record['amount'];
            $alreadyRefunded = (float) ($record['refunded_amount'] ?? 0);
            $remaining = round($recordAmount - $alreadyRefunded, 2);
            if ($remaining <= 0.009) {
                throw new RuntimeException('该充值记录已全部退费');
            }

            $amount = $requestedAmount === null ? $remaining : round($requestedAmount, 2);
            if ($amount <= 0.009) {
                throw new RuntimeException('退费金额必须大于 0');
            }
            if ($amount > $remaining + 0.009) {
                throw new RuntimeException('退费金额不能超过可退金额 ' . formatMoney($remaining));
            }

            $paymentMethod = (string) ($record['payment_method'] ?? 'cash');
            $member = getMemberById($pdo, (int) $record['member_id']);
            if (!$member) {
                throw new RuntimeException('对应会员不存在');
            }
            if ((float) $member['balance'] < $amount - 0.009) {
                throw new RuntimeException('会员当前余额不足（余额 ' . formatMoney((float) $member['balance']) . '），无法退费 ' . formatMoney($amount));
            }
            $pdo->prepare('UPDATE member SET balance = balance - ? WHERE id = ?')->execute([$amount, (int) $record['member_id']]);

            $newRefunded = round($alreadyRefunded + $amount, 2);
            $fullyRefunded = $newRefunded >= $recordAmount - 0.009;
            $pdo->prepare('UPDATE recharge SET refunded_amount = ?, refunded_at = ? WHERE id = ?')
                ->execute([$newRefunded, $fullyRefunded ? currentDateTime() : null, $sourceId]);
        }

        $encryptedReason = encryptFinanceData(['reason' => $reason]);
        $insert = $pdo->prepare('INSERT INTO finance_refunds (source_type, source_id, amount, payment_method, encrypted_reason, operator_username, operator_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([$sourceType, $sourceId, $amount, $paymentMethod, $encryptedReason, (string) ($staff['username'] ?? ''), (string) ($staff['name'] ?? ''), currentDateTime()]);
        $refundId = (int) $pdo->lastInsertId();
        logOperation($pdo, 'finance_refund_create', 'finance_refund', $refundId, ($sourceType === 'consumption' ? '消费退款' : '充值退款') . '：' . formatMoney($amount), [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'amount' => $amount,
            'payment_method' => $paymentMethod,
        ]);
        $pdo->commit();

        return [
            'amount' => $amount,
            'payment_method' => $paymentMethod,
            'refund_id' => $refundId,
            'remaining' => $sourceType === 'recharge' ? max(0, round((float) ($record['amount'] ?? 0) - (float) ($newRefunded ?? 0), 2)) : 0.0,
            'fully_refunded' => $sourceType === 'recharge' ? !empty($fullyRefunded) : true,
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function renderFinanceTabs(string $active): void
{
    $tabs = [
        'dashboard' => ['/finance/', '财务看板'],
        'ledger' => ['/finance/entries.php', '收支流水'],
        'expense' => ['/finance/expense.php', '收支管理'],
        'accounts' => ['/finance/accounts.php', '资金账户'],
        'reports' => ['/finance/reports.php', '经营报表'],
        'costs' => ['/finance/costs.php', '成本记录'],
        'daily' => ['/daily-report/', '每日上报'],
        'docs' => ['/finance/docs.php', '财务文档'],
    ];
    echo '<div class="finance-tabs">';
    foreach ($tabs as $key => [$url, $label]) {
        echo '<a class="btn ' . ($key === $active ? 'btn-primary' : 'btn-secondary') . '" href="' . htmlspecialchars($url) . '">' . htmlspecialchars($label) . '</a>';
    }
    echo '</div>';
}

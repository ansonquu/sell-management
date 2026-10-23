<?php

function getSettingsStorageFile(): string
{
    return defined('SETTINGS_PATH') ? SETTINGS_PATH : __DIR__ . '/../data/settings.json';
}

function defaultSystemSettings(): array
{
    return [
        'site_name' => 'Anson咖啡厅管理系统',
        'favicon_path' => '/src/fav.png',
        'points_per_yuan' => 1,
        'menu_items' => [
            ['name' => '美式咖啡', 'price' => 10],
            ['name' => '拿铁咖啡', 'price' => 10],
        ],
        'member_levels' => [
            ['name' => '普通会员', 'min_spent' => 0, 'discount' => 1],
        ],
        'redemption' => [
            'enabled' => true,
            'items' => [
                ['name' => '美式咖啡', 'points' => 50, 'cost_product' => '美式咖啡', 'type' => 'product'],
                ['name' => '拿铁咖啡', 'points' => 50, 'cost_product' => '拿铁咖啡', 'type' => 'product'],
            ],
        ],
        'member_record_fields' => [
            'name',
            'phone',
            'level',
            'balance',
            'points',
            'total_spent',
            'status',
        ],
        'consumption_record_fields' => [
            'record_id',
            'time',
            'member',
            'items',
            'amount',
            'prep_state',
            'payment_method',
            'ticket_number',
            'coupon',
            'points',
            'operator',
            'note',
        ],
        'expense_categories' => [
            ['key' => 'ingredients', 'name' => '原材料采购'],
            ['key' => 'packaging', 'name' => '包装与耗材'],
            ['key' => 'rent', 'name' => '房租'],
            ['key' => 'utilities', 'name' => '水电燃气'],
            ['key' => 'salary', 'name' => '工资与福利'],
            ['key' => 'equipment', 'name' => '设备购置'],
            ['key' => 'maintenance', 'name' => '维修维护'],
            ['key' => 'marketing', 'name' => '营销推广'],
            ['key' => 'platform_fee', 'name' => '平台手续费'],
            ['key' => 'tax', 'name' => '税费'],
            ['key' => 'exchange_refund', 'name' => '换货退差价'],
            ['key' => 'balance_adjust', 'name' => '余额调整'],
            ['key' => 'other', 'name' => '其他支出'],
        ],
        'attendance_roster' => null,
        'attendance_positions' => ['店长', '咖啡师', '收银', '兼职'],
    ];
}

function getSystemSettings(): array
{
    if (isset($GLOBALS['systemSettingsCache']) && is_array($GLOBALS['systemSettingsCache'])) {
        return $GLOBALS['systemSettingsCache'];
    }

    $file = getSettingsStorageFile();
    $stored = file_exists($file) ? json_decode(file_get_contents($file) ?: '', true) : null;
    $defaults = defaultSystemSettings();
    if (!is_array($stored)) {
        saveSystemSettings($defaults);
        return $defaults;
    }

    $merged = $defaults;
    $siteName = trim((string) ($stored['site_name'] ?? $defaults['site_name']));
    $merged['site_name'] = $siteName !== '' ? $siteName : $defaults['site_name'];
    $faviconPath = trim((string) ($stored['favicon_path'] ?? $defaults['favicon_path']));
    $isAllowedFaviconPath = $faviconPath === $defaults['favicon_path']
        || preg_match('#^/assets/uploads/site-favicon-[a-f0-9]{16}\.(png|jpg|webp|gif|ico)$#', $faviconPath);
    $merged['favicon_path'] = $isAllowedFaviconPath ? $faviconPath : $defaults['favicon_path'];
    $merged['points_per_yuan'] = (float) ($stored['points_per_yuan'] ?? $defaults['points_per_yuan']);
    if (isset($stored['menu_items']) && is_array($stored['menu_items'])) {
        $merged['menu_items'] = $stored['menu_items'];
    }
    if (isset($stored['member_levels']) && is_array($stored['member_levels'])) {
        $merged['member_levels'] = $stored['member_levels'];
    }
    if (isset($stored['redemption']) && is_array($stored['redemption'])) {
        $merged['redemption']['enabled'] = (bool) ($stored['redemption']['enabled'] ?? false);
        $merged['redemption']['items'] = isset($stored['redemption']['items']) && is_array($stored['redemption']['items'])
            ? $stored['redemption']['items']
            : [];
        $menuNames = array_column($merged['menu_items'], 'name');
        $normalizedRedemptionItems = [];
        foreach ($merged['redemption']['items'] as $item) {
            $costProduct = trim((string) ($item['cost_product'] ?? ''));
            if ($costProduct === '' && in_array((string) ($item['name'] ?? ''), $menuNames, true)) {
                $costProduct = (string) $item['name'];
            }
            if ($costProduct === '' && (string) ($item['name'] ?? '') === '饮品（任意）一杯') {
                foreach ($menuNames as $menuName) {
                    $normalizedRedemptionItems[] = ['name' => $menuName, 'points' => (int) ($item['points'] ?? 0), 'cost_product' => $menuName, 'type' => 'product'];
                }
                continue;
            }
            $item['cost_product'] = in_array($costProduct, $menuNames, true) ? $costProduct : '';
            $customName = trim((string) ($item['name'] ?? ''));
            if ($item['cost_product'] === '' && ($customName === '' || $customName === '其它')) {
                continue;
            }
            $item['type'] = $item['cost_product'] !== '' ? 'product' : 'non_physical';
            $item['name'] = $item['cost_product'] !== '' ? $item['cost_product'] : $customName;
            $normalizedRedemptionItems[] = $item;
        }
        $merged['redemption']['items'] = $normalizedRedemptionItems;
    }
    if (isset($stored['member_record_fields']) && is_array($stored['member_record_fields'])) {
        $merged['member_record_fields'] = array_values(array_intersect(
            array_keys(getMemberRecordFieldOptions()),
            $stored['member_record_fields']
        ));
    }
    if (isset($stored['consumption_record_fields']) && is_array($stored['consumption_record_fields'])) {
        $allowedFields = array_keys(getConsumptionRecordFieldOptions());
        $merged['consumption_record_fields'] = array_values(array_intersect(
            $allowedFields,
            $stored['consumption_record_fields']
        ));
    }
    if (isset($stored['expense_categories']) && is_array($stored['expense_categories'])) {
        $normalizedCategories = [];
        $seenKeys = [];
        foreach ($stored['expense_categories'] as $category) {
            if (!is_array($category)) {
                continue;
            }
            $name = trim((string) ($category['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $key = trim((string) ($category['key'] ?? ''));
            if ($key === '' || isset($seenKeys[$key])) {
                $key = 'cat_' . bin2hex(random_bytes(4));
            }
            $seenKeys[$key] = true;
            $normalizedCategories[] = ['key' => $key, 'name' => $name];
        }
        if ($normalizedCategories) {
            $merged['expense_categories'] = $normalizedCategories;
        }
    }
    if (array_key_exists('attendance_roster', $stored) && is_array($stored['attendance_roster'])) {
        // 兼容两种格式：纯姓名列表 或 [{name, position}] 条目列表，由 getAttendanceRosterEntries 统一解析
        $merged['attendance_roster'] = $stored['attendance_roster'];
    }
    if (isset($stored['attendance_positions']) && is_array($stored['attendance_positions'])) {
        $merged['attendance_positions'] = array_values(array_unique(array_filter(array_map('trim', $stored['attendance_positions']), 'strlen')));
    }

    // 一次性迁移：为历史配置补上「制作状态」列（用户之后可在系统设置中取消勾选）
    if (empty($stored['record_fields_version'])) {
        $fields = is_array($merged['consumption_record_fields'] ?? null) ? $merged['consumption_record_fields'] : [];
        if (!in_array('prep_state', $fields, true)) {
            $insertAt = array_search('payment_method', $fields, true);
            if ($insertAt === false) {
                $fields[] = 'prep_state';
            } else {
                array_splice($fields, $insertAt + 1, 0, ['prep_state']);
            }
            $merged['consumption_record_fields'] = array_values($fields);
        }
        $merged['record_fields_version'] = 2;

        // 写回一次，避免每次请求重复补齐
        $migrateFile = getSettingsStorageFile();
        $migrateJson = json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($migrateJson !== false && is_writable(dirname($migrateFile))) {
            $migrateTemp = $migrateFile . '.tmp';
            if (@file_put_contents($migrateTemp, $migrateJson) !== false) {
                @rename($migrateTemp, $migrateFile);
            }
        }
    }

    // 一次性迁移：补充「余额调整」类别（管理员调账产生的流水归类）
    if (empty($stored['finance_categories_version'])) {
        $categories = is_array($merged['expense_categories'] ?? null) ? $merged['expense_categories'] : [];
        $hasAdjustCategory = false;
        $insertAt = count($categories);
        foreach ($categories as $index => $category) {
            $key = (string) ($category['key'] ?? '');
            if ($key === 'balance_adjust') {
                $hasAdjustCategory = true;
                break;
            }
            if ($key === 'other') {
                $insertAt = $index;
            }
        }
        if (!$hasAdjustCategory) {
            array_splice($categories, $insertAt, 0, [['key' => 'balance_adjust', 'name' => '余额调整']]);
            $merged['expense_categories'] = array_values($categories);
        }
        $merged['finance_categories_version'] = 2;

        $adjustFile = getSettingsStorageFile();
        $adjustJson = json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($adjustJson !== false && is_writable(dirname($adjustFile))) {
            $adjustTemp = $adjustFile . '.tmp';
            if (@file_put_contents($adjustTemp, $adjustJson) !== false) {
                @rename($adjustTemp, $adjustFile);
            }
        }
    }

    $GLOBALS['systemSettingsCache'] = $merged;
    return $GLOBALS['systemSettingsCache'];
}

function saveSystemSettings(array $settings): bool
{
    $file = getSettingsStorageFile();
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $json = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }
    $temp = $file . '.tmp';
    if (file_put_contents($temp, $json, LOCK_EX) === false) {
        return false;
    }
    $saved = rename($temp, $file);
    if ($saved) {
        $GLOBALS['systemSettingsCache'] = $settings;
    }
    return $saved;
}

function getMenuItems(): array
{
    return getSystemSettings()['menu_items'];
}

function getSiteName(): string
{
    return (string) getSystemSettings()['site_name'];
}

function getFaviconUrl(): string
{
    $path = (string) getSystemSettings()['favicon_path'];
    $localPath = dirname(__DIR__) . $path;
    $version = is_file($localPath) ? (string) filemtime($localPath) : '1';
    return $path . (str_contains($path, '?') ? '&' : '?') . 'v=' . rawurlencode($version);
}

function sitePageTitle(string $pageTitle = ''): string
{
    $siteName = getSiteName();
    $pageTitle = trim((string) preg_replace('/\s*-\s*Anson咖啡厅管理系统$/u', '', $pageTitle));
    if ($pageTitle === '' || $pageTitle === 'Anson咖啡厅管理系统' || $pageTitle === $siteName) {
        return $siteName;
    }
    return $pageTitle . ' - ' . $siteName;
}

function uploadedImageType(string $path): ?string
{
    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) {
        return null;
    }
    $header = (string) fread($handle, 16);
    fclose($handle);

    if (str_starts_with($header, "\x89PNG\r\n\x1a\n")) {
        return 'png';
    }
    if (str_starts_with($header, "\xff\xd8\xff")) {
        return 'jpg';
    }
    if (str_starts_with($header, 'GIF87a') || str_starts_with($header, 'GIF89a')) {
        return 'gif';
    }
    if (substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WEBP') {
        return 'webp';
    }
    if (str_starts_with($header, "\x00\x00\x01\x00")) {
        return 'ico';
    }
    return null;
}

function storeUploadedFavicon(array $upload, array &$errors): ?string
{
    $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) {
        $errors[] = '网站图标上传失败，请重新选择文件';
        return null;
    }
    if ((int) ($upload['size'] ?? 0) > 2 * 1024 * 1024) {
        $errors[] = '网站图标不能超过 2MB';
        return null;
    }

    $originalName = (string) ($upload['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'ico'];
    if (!in_array($extension, $allowedExtensions, true)) {
        $errors[] = '网站图标仅支持 PNG、JPG、WebP、GIF 或 ICO 格式';
        return null;
    }
    $detectedType = uploadedImageType((string) $upload['tmp_name']);
    $expectedType = $extension === 'jpeg' ? 'jpg' : $extension;
    if ($detectedType === null || $detectedType !== $expectedType) {
        $errors[] = '网站图标文件内容与图片格式不符';
        return null;
    }

    $directory = dirname(__DIR__) . '/assets/uploads';
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
        $errors[] = '无法创建网站图标存储目录';
        return null;
    }
    $safeExtension = $expectedType;
    $filename = 'site-favicon-' . bin2hex(random_bytes(8)) . '.' . $safeExtension;
    if (!move_uploaded_file((string) $upload['tmp_name'], $directory . '/' . $filename)) {
        $errors[] = '网站图标保存失败，请检查 assets/uploads 目录权限';
        return null;
    }
    return '/assets/uploads/' . $filename;
}

function getMemberLevels(): array
{
    $levels = getSystemSettings()['member_levels'];
    usort($levels, fn (array $a, array $b): int => $a['min_spent'] <=> $b['min_spent']);
    return $levels;
}

function getMemberDiscountForSpent(float $totalSpent): float
{
    $discount = 1.0;
    foreach (getMemberLevels() as $level) {
        if ($totalSpent >= (float) $level['min_spent']) {
            $discount = (float) $level['discount'];
        }
    }
    return $discount;
}

function getPointsPerYuan(): float
{
    return (float) getSystemSettings()['points_per_yuan'];
}

function getRedemptionSettings(): array
{
    return getSystemSettings()['redemption'];
}

function isPointsRedemptionEnabled(): bool
{
    return (bool) (getRedemptionSettings()['enabled'] ?? false);
}

function getConsumptionRecordFieldOptions(): array
{
    return [
        'record_id' => '记录号',
        'time' => '时间',
        'member' => '会员',
        'items' => '商品',
        'amount' => '金额',
        'payment_method' => '支付方式',
        'prep_state' => '制作状态',
        'ticket_number' => '号牌',
        'coupon' => '优惠券',
        'points' => '积分',
        'operator' => '操作人',
        'note' => '备注',
    ];
}

function getConsumptionRecordFields(): array
{
    return getSystemSettings()['consumption_record_fields'];
}

/** 会员列表可显示的字段 */
function getMemberRecordFieldOptions(): array
{
    return [
        'member_id' => '会员号',
        'name' => '姓名',
        'student_no' => '学号',
        'phone' => '手机号',
        'level' => '等级',
        'balance' => '余额',
        'points' => '积分',
        'total_spent' => '累计消费',
        'status' => '状态',
        'note' => '备注',
        'create_time' => '注册时间',
    ];
}

function getMemberRecordFields(): array
{
    return getSystemSettings()['member_record_fields'];
}

function parseSystemSettingsInput(array $input, array &$errors): array
{
    $settings = defaultSystemSettings();
    $current = getSystemSettings();
    $siteName = trim((string) ($input['site_name'] ?? $current['site_name'] ?? ''));
    if ($siteName === '') {
        $errors[] = '请输入网站名称';
    } elseif (mb_strlen($siteName) > 60) {
        $errors[] = '网站名称不能超过 60 个字符';
    }
    $settings['site_name'] = $siteName !== '' ? $siteName : defaultSystemSettings()['site_name'];
    $settings['favicon_path'] = !empty($input['remove_favicon'])
        ? defaultSystemSettings()['favicon_path']
        : (string) ($current['favicon_path'] ?? defaultSystemSettings()['favicon_path']);
    $settings['points_per_yuan'] = max(0, (float) ($input['points_per_yuan'] ?? 1));

    $menuNames = $input['menu_name'] ?? [];
    $menuPrices = $input['menu_price'] ?? [];
    $settings['menu_items'] = [];
    foreach ($menuNames as $index => $name) {
        $name = trim((string) $name);
        $price = (float) ($menuPrices[$index] ?? 0);
        if ($name === '' && $price <= 0) {
            continue;
        }
        if ($name === '' || $price <= 0) {
            $errors[] = '每个饮品都必须填写名称和大于 0 的价格';
            continue;
        }
        $settings['menu_items'][] = ['name' => $name, 'price' => round($price, 2)];
    }
    if (!$settings['menu_items']) {
        $errors[] = '请至少设置一个饮品';
    }

    $levelNames = $input['level_name'] ?? [];
    $levelSpends = $input['level_min_spent'] ?? [];
    $levelDiscounts = $input['level_discount'] ?? [];
    $settings['member_levels'] = [];
    foreach ($levelNames as $index => $name) {
        $name = trim((string) $name);
        $minSpent = max(0, (float) ($levelSpends[$index] ?? 0));
        $discount = (float) ($levelDiscounts[$index] ?? 10) / 10;
        if ($name === '') {
            continue;
        }
        if ($discount <= 0 || $discount > 1) {
            $errors[] = '会员折扣必须在 0.1 折到 10 折之间';
            continue;
        }
        $settings['member_levels'][] = [
            'name' => $name,
            'min_spent' => round($minSpent, 2),
            'discount' => round($discount, 2),
        ];
    }
    usort($settings['member_levels'], fn (array $a, array $b): int => $a['min_spent'] <=> $b['min_spent']);
    if (!$settings['member_levels']) {
        $errors[] = '请至少设置一个会员等级';
    } elseif ((float) $settings['member_levels'][0]['min_spent'] !== 0.0) {
        $errors[] = '最低会员等级的累计消费门槛必须为 0 元';
    }

    $settings['redemption']['enabled'] = isset($input['redemption_enabled']);
    $settings['redemption']['items'] = [];
    $redeemPoints = $input['redeem_points'] ?? [];
    $redeemCostProducts = $input['redeem_cost_product'] ?? [];
    $redeemNames = $input['redeem_name'] ?? [];
    $redeemTypes = $input['redeem_type'] ?? [];
    $menuNames = array_column($settings['menu_items'], 'name');
    foreach ($redeemTypes as $index => $type) {
        $points = (int) ($redeemPoints[$index] ?? 0);
        $type = $type === 'non_physical' ? 'non_physical' : 'product';
        $costProduct = trim((string) ($redeemCostProducts[$index] ?? ''));
        $customName = trim((string) ($redeemNames[$index] ?? ''));
        if ($type === 'product' && $costProduct === '' && $points <= 0) {
            continue;
        }
        if ($points <= 0) {
            $errors[] = '每个兑换商品都必须填写大于 0 的积分';
            continue;
        }
        if ($type === 'product' && !in_array($costProduct, $menuNames, true)) {
            $errors[] = '积分兑换关联的成本商品无效';
            continue;
        }
        if ($type === 'non_physical' && ($customName === '' || $customName === '其它')) {
            $errors[] = '无实物兑换项目必须填写名称，且不能使用系统保留名称“其它”';
            continue;
        }
        $settings['redemption']['items'][] = [
            'name' => $type === 'product' ? $costProduct : $customName,
            'points' => $points,
            'cost_product' => $type === 'product' ? $costProduct : '',
            'type' => $type,
        ];
    }

    $allowedRecordFields = array_keys(getConsumptionRecordFieldOptions());
    $submittedRecordFields = is_array($input['consumption_record_fields'] ?? null)
        ? $input['consumption_record_fields']
        : [];
    $settings['consumption_record_fields'] = array_values(array_intersect(
        $allowedRecordFields,
        $submittedRecordFields
    ));
    if (!$settings['consumption_record_fields']) {
        $errors[] = '消费记录请至少选择一个显示字段';
    }

    $submittedMemberFields = is_array($input['member_record_fields'] ?? null) ? $input['member_record_fields'] : [];
    $settings['member_record_fields'] = array_values(array_intersect(
        array_keys(getMemberRecordFieldOptions()),
        $submittedMemberFields
    ));
    if (!$settings['member_record_fields']) {
        $errors[] = '会员列表请至少选择一个显示字段';
    }

    // 支出分类：支持重命名（保留原 key，历史记录不受影响）与新增（自动生成 key）
    $catNames = $input['expense_cat_name'] ?? [];
    $catKeys = $input['expense_cat_key'] ?? [];
    $settings['expense_categories'] = [];
    $seenCatKeys = [];
    foreach ($catNames as $index => $catName) {
        $catName = trim((string) $catName);
        if ($catName === '') {
            continue;
        }
        $catKey = trim((string) ($catKeys[$index] ?? ''));
        if ($catKey === '' || isset($seenCatKeys[$catKey])) {
            $catKey = 'cat_' . bin2hex(random_bytes(4));
        }
        $seenCatKeys[$catKey] = true;
        $settings['expense_categories'][] = ['key' => $catKey, 'name' => $catName];
    }
    if (!$settings['expense_categories']) {
        $errors[] = '请至少保留一个支出分类';
    }

    // 签到职位（下拉选项）
    $positions = [];
    foreach ((array) ($input['attendance_positions'] ?? []) as $position) {
        $position = trim((string) $position);
        if ($position !== '') {
            $positions[] = $position;
        }
    }
    $settings['attendance_positions'] = array_values(array_unique($positions));

    // 签到名单在“管理后台 → 签到管理”配置，保存其他设置时不覆盖
    $settings['attendance_roster'] = $current['attendance_roster'] ?? null;

    return $settings;
}

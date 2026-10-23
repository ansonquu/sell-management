<?php

date_default_timezone_set('Asia/Shanghai');

$localAuthConfigFile = __DIR__ . '/data/auth.php';
$localAuthConfig = file_exists($localAuthConfigFile) ? require $localAuthConfigFile : [];
if (!is_array($localAuthConfig)) {
    $localAuthConfig = [];
}

define('JSON_DB_PATH', __DIR__ . '/data/database.json');
define('STAFF_PATH', __DIR__ . '/data/staff.json');
define('SESSION_PATH', __DIR__ . '/data/sessions.json');
define('LICENSE_PATH', __DIR__ . '/data/license.json');
define('SETTINGS_PATH', __DIR__ . '/data/settings.json');
define('FINANCE_KEY_PATH', __DIR__ . '/data/finance.key');

// 授权站配置。生产环境建议通过环境变量注入项目密钥。
define('AUTH_API_BASE_URL', rtrim(getenv('AUTH_API_BASE_URL') ?: 'https://auth.ansonqu.com', '/'));
define('AUTH_ACTIVATE_ENDPOINT', getenv('AUTH_ACTIVATE_ENDPOINT') ?: '/api.php');
define('AUTH_VERIFY_ENDPOINT', getenv('AUTH_VERIFY_ENDPOINT') ?: '/api.php');
define('AUTH_PROJECT_KEY', getenv('AUTH_PROJECT_KEY') ?: ($localAuthConfig['project_key'] ?? ''));
define('AUTH_PROJECT_SECRET', getenv('AUTH_PROJECT_SECRET') ?: '');
define('AUTH_API_KEY', getenv('AUTH_API_KEY') ?: ($localAuthConfig['api_key'] ?? AUTH_PROJECT_SECRET));
define('AUTH_CONNECT_TIMEOUT', 15);
define('AUTH_HTTP_TIMEOUT', 30);

define('STATUS_NORMAL', 1);
define('STATUS_FROZEN', 0);

// 充值快捷金额（元）
define('RECHARGE_AMOUNTS', [50, 60, 70, 80, 90, 100, 200, 300, 400]);

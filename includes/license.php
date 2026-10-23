<?php

function getLicenseStorageFile(): string
{
    return defined('LICENSE_PATH') ? LICENSE_PATH : __DIR__ . '/../data/license.json';
}

function defaultLicenseState(): array
{
    return [
        'install_id' => bin2hex(random_bytes(16)),
        'status' => 'inactive',
        'activation_code' => '',
        'license_token' => '',
        'activated_at' => '',
        'expires_at' => '',
        'last_verified_at' => '',
        'last_daily_check' => '',
        'last_attempt_at' => '',
        'last_error' => '',
    ];
}

function getLicenseState(): array
{
    $file = getLicenseStorageFile();
    if (file_exists($file)) {
        $state = json_decode(file_get_contents($file) ?: '', true);
        if (is_array($state)) {
            $completeState = array_merge(defaultLicenseState(), $state);
            if ($completeState !== $state) {
                saveLicenseState($completeState);
            }
            return $completeState;
        }
    }

    $state = defaultLicenseState();
    saveLicenseState($state);
    return $state;
}

function saveLicenseState(array $state): bool
{
    $file = getLicenseStorageFile();
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }

    $temp = $file . '.tmp';
    if (file_put_contents($temp, $json, LOCK_EX) === false) {
        return false;
    }
    return rename($temp, $file);
}

function licenseApiUrl(string $endpoint): string
{
    if (preg_match('#^https?://#i', $endpoint)) {
        return $endpoint;
    }
    return AUTH_API_BASE_URL . '/' . ltrim($endpoint, '/');
}

function callLicenseApi(string $endpoint, array $payload): array
{
    $payload['project_key'] = AUTH_PROJECT_KEY;
    if (AUTH_PROJECT_SECRET !== '') {
        $payload['project_secret'] = AUTH_PROJECT_SECRET;
    }
    if (AUTH_API_KEY !== '') {
        $payload['api_key'] = AUTH_API_KEY;
    }

    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if (AUTH_PROJECT_KEY !== '') {
        $headers[] = 'X-Project-Key: ' . AUTH_PROJECT_KEY;
    }
    if (AUTH_API_KEY !== '') {
        $headers[] = 'X-API-Key: ' . AUTH_API_KEY;
        $headers[] = 'Authorization: Bearer ' . AUTH_API_KEY;
    }

    $ch = curl_init(licenseApiUrl($endpoint));
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => AUTH_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => AUTH_HTTP_TIMEOUT,
        CURLOPT_DNS_CACHE_TIMEOUT => 300,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        return ['transport_error' => true, 'ok' => false, 'message' => '授权服务器连接失败：' . $curlError];
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return [
            'transport_error' => false,
            'ok' => false,
            'message' => '授权服务器返回了无法识别的响应（HTTP ' . $httpCode . '）',
        ];
    }

    $data = isset($decoded['data']) && is_array($decoded['data'])
        ? array_merge($decoded, $decoded['data'])
        : $decoded;
    $status = strtolower((string) ($data['status'] ?? ''));
    $successValue = filter_var($data['success'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $validValue = filter_var($data['valid'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $ok = $httpCode >= 200 && $httpCode < 300 && (
        $successValue
        || $validValue
        || (int) ($data['code'] ?? 0) === 200
        || in_array($status, ['active', 'valid', 'success'], true)
    );

    return [
        'transport_error' => false,
        'ok' => $ok,
        'message' => (string) ($data['message'] ?? $data['msg'] ?? ($ok ? '授权成功' : '授权校验失败')),
        'data' => $data,
    ];
}

function activateLicenseCode(string $activationCode): array
{
    if (AUTH_PROJECT_KEY === '' && AUTH_API_KEY === '') {
        return ['ok' => false, 'message' => '授权接口尚未配置，请先设置 AUTH_PROJECT_KEY 或 AUTH_API_KEY'];
    }

    $state = getLicenseState();
    $result = callLicenseApi(AUTH_ACTIVATE_ENDPOINT, [
        'action' => 'activate',
        'activation_code' => $activationCode,
        'code' => $activationCode,
        'device_id' => $state['install_id'],
        'machine_code' => $state['install_id'],
    ]);

    $state['last_attempt_at'] = currentDateTime();
    $state['last_error'] = $result['ok'] ? '' : $result['message'];
    if ($result['ok']) {
        $data = $result['data'];
        $state['status'] = 'active';
        $state['activation_code'] = $activationCode;
        $state['license_token'] = (string) ($data['license_token'] ?? $data['token'] ?? '');
        $state['activated_at'] = currentDateTime();
        $state['expires_at'] = (string) ($data['expires_at'] ?? $data['expired_at'] ?? '');
        $state['last_verified_at'] = currentDateTime();
        $state['last_daily_check'] = date('Y-m-d');
    }
    saveLicenseState($state);
    return $result;
}

function verifyLicenseCode(bool $force = false): array
{
    $state = getLicenseState();
    if ($state['activation_code'] === '') {
        return ['ok' => false, 'message' => '系统尚未激活'];
    }

    if (!$force && $state['last_attempt_at'] !== '') {
        $lastAttempt = strtotime($state['last_attempt_at']);
        if ($lastAttempt !== false && time() - $lastAttempt < 900) {
            return ['ok' => isLicenseActive(), 'message' => '校验请求正在限流'];
        }
    }

    $result = callLicenseApi(AUTH_VERIFY_ENDPOINT, [
        'action' => 'verify',
        'activation_code' => $state['activation_code'],
        'code' => $state['activation_code'],
        'license_token' => $state['license_token'],
        'device_id' => $state['install_id'],
        'machine_code' => $state['install_id'],
    ]);
    $state['last_attempt_at'] = currentDateTime();

    if ($result['ok']) {
        $data = $result['data'];
        $state['status'] = 'active';
        $state['last_verified_at'] = currentDateTime();
        $state['last_daily_check'] = date('Y-m-d');
        $state['last_error'] = '';
        $state['expires_at'] = (string) ($data['expires_at'] ?? $data['expired_at'] ?? $state['expires_at']);
        if (!empty($data['license_token']) || !empty($data['token'])) {
            $state['license_token'] = (string) ($data['license_token'] ?? $data['token']);
        }
    } elseif (!($result['transport_error'] ?? false)) {
        $state['status'] = 'invalid';
        $state['last_daily_check'] = date('Y-m-d');
        $state['last_error'] = $result['message'];
    } else {
        // 网络故障不立即锁死收银端，保留已确认的授权并在 15 分钟后重试。
        $state['last_error'] = $result['message'];
    }

    saveLicenseState($state);
    return $result;
}

function isLicenseActive(): bool
{
    $state = getLicenseState();
    if (($state['status'] ?? '') !== 'active') {
        return false;
    }
    if (!empty($state['expires_at'])) {
        $expires = strtotime($state['expires_at']);
        if ($expires !== false && $expires < time()) {
            return false;
        }
    }
    return true;
}

function checkLicenseIfDue(): void
{
    $state = getLicenseState();
    if (($state['status'] ?? '') !== 'active' || (int) date('G') < 23) {
        return;
    }
    if (($state['last_daily_check'] ?? '') !== date('Y-m-d')) {
        verifyLicenseCode();
    }
}

function maskedLicenseCode(string $code): string
{
    if (strlen($code) <= 8) {
        return str_repeat('*', strlen($code));
    }
    return substr($code, 0, 4) . str_repeat('*', max(4, strlen($code) - 8)) . substr($code, -4);
}

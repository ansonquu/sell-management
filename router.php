<?php

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (str_starts_with($uri, '/data/') || str_starts_with($uri, '/storage/')) {
    http_response_code(403);
    echo '403 Forbidden';
    return true;
}

if ($uri !== '/' && file_exists(__DIR__ . $uri)) {
    return false;
}

if ($uri === '/consumption/') {
    require __DIR__ . '/consumption/index.php';
    return true;
}

if ($uri === '/refunds/') {
    require __DIR__ . '/refunds/index.php';
    return true;
}

if ($uri === '/members/') {
    require __DIR__ . '/members/index.php';
    return true;
}

if ($uri === '/recharge/') {
    require __DIR__ . '/recharge/index.php';
    return true;
}

if ($uri === '/levels/') {
    require __DIR__ . '/levels/index.php';
    return true;
}

if ($uri === '/announcements/') {
    require __DIR__ . '/announcements/index.php';
    return true;
}

if ($uri === '/admin/') {
    require __DIR__ . '/admin/logs.php';
    return true;
}

if ($uri === '/finance/') {
    require __DIR__ . '/finance/index.php';
    return true;
}

if ($uri === '/attendance/') {
    require __DIR__ . '/attendance/index.php';
    return true;
}

if ($uri === '/daily-report/') {
    require __DIR__ . '/daily-report/index.php';
    return true;
}

if ($uri === '/setup') {
    require __DIR__ . '/setup.php';
    return true;
}

if ($uri === '/activate') {
    require __DIR__ . '/activate.php';
    return true;
}

if ($uri === '/cq') {
    header('Location: /cq/', true, 301);
    return true;
}

if ($uri === '/cq/') {
    require __DIR__ . '/customer-query.php';
    return true;
}

if ($uri === '/customer-query' || $uri === '/customer-query/') {
    header('Location: /cq/', true, 301);
    return true;
}

if ($uri === '/' || $uri === '/index.php') {
    require __DIR__ . '/index.php';
    return true;
}

http_response_code(404);
echo '404 Not Found';
return true;

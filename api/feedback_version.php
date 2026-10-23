<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'version' => getFeedbackVersion(),
    'pending_count' => getFeedbackPendingCount(),
]);

<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';

requireLogin();
$id = (int) ($_GET['id'] ?? 0);
if (!canManageFinance()) {
    flash('error', '财务记录不能删除，请通过「退款」功能处理');
    redirect('/consumption/');
}
redirect('/refunds/add.php?consumption_id=' . $id);

<?php
session_start();
require_once 'includes/functions.php';
require_once 'includes/layout.php';

requireLogin();

$currentStaff = getCurrentStaff();
$devices = array_values(array_filter(getActiveSessionDevices(), function ($device) use ($currentStaff) {
    return ($device['username'] ?? '') === ($currentStaff['username'] ?? '');
}));

renderHeader('登录设备 - Anson咖啡厅管理系统');
?>

<div class="page-header">
    <h1>我的登录设备</h1>
</div>

<div class="card">
    <?php if (empty($devices)): ?>
        <div class="empty-state">暂无登录设备记录</div>
    <?php else: ?>
        <div class="table-responsive devices-table-wrap">
            <table class="table devices-table devices-table-own">
                <thead>
                    <tr>
                        <th>设备</th>
                        <th>IP地址</th>
                        <th>登录时间</th>
                        <th>最后活跃</th>
                        <th>浏览器详情</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($devices as $device): ?>
                        <tr>
                            <td><?= htmlspecialchars($device['device']) ?></td>
                            <td><?= htmlspecialchars($device['ip'] ?: '-') ?></td>
                            <td><?= htmlspecialchars($device['login_time'] ?: '-') ?></td>
                            <td><?= htmlspecialchars($device['last_active'] ?: '-') ?></td>
                            <td class="log-meta-cell" title="<?= htmlspecialchars($device['user_agent'] ?: '无浏览器信息') ?>">
                                <?= htmlspecialchars($device['user_agent'] ?: '-') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php renderFooter(); ?>

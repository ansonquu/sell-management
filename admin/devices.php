<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';

requireAdmin();

$devices = getActiveSessionDevices();

renderHeader('员工登录设备 - 管理后台', 'admin');
?>

<div class="page-header">
    <h2 class="page-title">员工登录设备</h2>
    <div class="page-actions">
        <a href="/admin/logs.php" class="btn btn-secondary">返回操作记录</a>
    </div>
</div>

<div class="card">
    <?php if (empty($devices)): ?>
        <div class="empty-state">暂无登录设备记录</div>
    <?php else: ?>
        <div class="table-responsive devices-table-wrap">
            <table class="table devices-table devices-table-admin">
                <thead>
                    <tr>
                        <th>员工</th>
                        <th>账号</th>
                        <th>角色</th>
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
                            <td><?= htmlspecialchars($device['name']) ?></td>
                            <td><?= htmlspecialchars($device['username']) ?></td>
                            <td>
                                <span class="badge <?= $device['role'] === 'admin' ? 'badge-success' : 'badge-info' ?>">
                                    <?= htmlspecialchars(staffRoleLabel($device['role'])) ?>
                                </span>
                            </td>
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

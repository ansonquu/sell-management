<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';

$pdo = getDb();
$keyword = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';

$sql = 'SELECT * FROM member WHERE 1=1';
$params = [];

if ($keyword !== '') {
    $sql .= ' AND (' . memberSearchCondition() . ')';
    $params[] = "%{$keyword}%";
    $params[] = "%{$keyword}%";
    $params[] = "%{$keyword}%";
}

if ($status !== '' && in_array($status, ['0', '1'], true)) {
    $sql .= ' AND status = ?';
    $params[] = (int) $status;
}

$sql .= ' ORDER BY create_time DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$members = $stmt->fetchAll();
$visibleFields = getMemberRecordFields();
$showField = static fn (string $field): bool => in_array($field, $visibleFields, true);

renderHeader('会员管理 - Anson咖啡厅管理系统', 'members');
?>

<div class="page-header">
    <h2 class="page-title">会员管理</h2>
    <a href="/members/add.php" class="btn btn-primary">+ 注册会员</a>
</div>

<form class="search-bar" method="get">
    <input type="text" name="q" placeholder="搜索姓名、手机号或学号..." value="<?= htmlspecialchars($keyword) ?>">
    <select name="status">
        <option value="">全部状态</option>
        <option value="1" <?= $status === '1' ? 'selected' : '' ?>>正常</option>
        <option value="0" <?= $status === '0' ? 'selected' : '' ?>>已冻结</option>
    </select>
    <button type="submit" class="btn btn-secondary">搜索</button>
    <?php if ($keyword || $status !== ''): ?>
        <a href="/members/" class="btn btn-link">清除</a>
    <?php endif; ?>
</form>

<div class="card table-responsive">
    <table class="table members-table">
        <thead>
            <tr>
                <?php if ($showField('member_id')): ?><th>会员号</th><?php endif; ?>
                <?php if ($showField('name')): ?><th>姓名</th><?php endif; ?>
                <?php if ($showField('student_no')): ?><th>学号</th><?php endif; ?>
                <?php if ($showField('phone')): ?><th>手机号</th><?php endif; ?>
                <?php if ($showField('level')): ?><th>等级</th><?php endif; ?>
                <?php if ($showField('balance')): ?><th>余额</th><?php endif; ?>
                <?php if ($showField('points')): ?><th>积分</th><?php endif; ?>
                <?php if ($showField('total_spent')): ?><th>累计消费</th><?php endif; ?>
                <?php if ($showField('status')): ?><th>状态</th><?php endif; ?>
                <?php if ($showField('note')): ?><th>备注</th><?php endif; ?>
                <?php if ($showField('create_time')): ?><th>注册时间</th><?php endif; ?>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($members)): ?>
                <tr><td colspan="<?= count($visibleFields) + 1 ?>" class="text-center text-muted">暂无会员</td></tr>
            <?php else: ?>
                <?php foreach ($members as $m): ?>
                    <tr>
                        <?php if ($showField('member_id')): ?><td><strong>#<?= (int) $m['id'] ?></strong></td><?php endif; ?>
                        <?php if ($showField('name')): ?><td><?= htmlspecialchars($m['name']) ?></td><?php endif; ?>
                        <?php if ($showField('student_no')): ?><td><?= htmlspecialchars($m['student_no'] ?: '-') ?></td><?php endif; ?>
                        <?php if ($showField('phone')): ?><td><?php if ($m['phone']): ?><?= htmlspecialchars($m['phone']) ?><?php else: ?><a class="btn btn-sm btn-warning" href="/members/edit.php?id=<?= $m['id'] ?>">补全手机号</a><?php endif; ?></td><?php endif; ?>
                        <?php if ($showField('level')): ?><td><span class="badge badge-level"><?= htmlspecialchars($m['level']) ?></span></td><?php endif; ?>
                        <?php if ($showField('balance')): ?><td class="<?= (float) $m['balance'] > 0 ? 'text-success' : 'text-muted' ?>"><?= formatMoney((float) $m['balance']) ?></td><?php endif; ?>
                        <?php if ($showField('points')): ?><td><?= (int) $m['points'] ?></td><?php endif; ?>
                        <?php if ($showField('total_spent')): ?><td><?= formatMoney((float) $m['total_spent']) ?></td><?php endif; ?>
                        <?php if ($showField('status')): ?><td>
                            <span class="badge <?= $m['status'] == STATUS_NORMAL ? 'badge-success' : 'badge-danger' ?>">
                                <?= statusLabel((int) $m['status']) ?>
                            </span>
                        </td><?php endif; ?>
                        <?php if ($showField('note')): ?>
                        <td class="member-note-cell" title="<?= htmlspecialchars($m['note'] ?? '') ?>">
                            <?php
                            $notePreview = trim((string) ($m['note'] ?? ''));
                            if ($notePreview === '') {
                                $noteDisplay = '-';
                            } elseif (mb_strlen($notePreview) > 8) {
                                $noteDisplay = mb_substr($notePreview, 0, 8) . '…';
                            } else {
                                $noteDisplay = $notePreview;
                            }
                            ?>
                            <?= htmlspecialchars($noteDisplay) ?>
                        </td>
                        <?php endif; ?>
                        <?php if ($showField('create_time')): ?><td><?= formatDateTime($m['create_time']) ?></td><?php endif; ?>
                        <td class="actions">
                            <a href="/members/view.php?id=<?= $m['id'] ?>">详情</a>
                            <a href="/members/edit.php?id=<?= $m['id'] ?>">编辑</a>
                            <a href="/consumption/add.php?member_id=<?= $m['id'] ?>">消费</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
(function () {
    const initialVersion = '<?= getRecordVersion($pdo) ?>';
    let checking = false;

    setInterval(function () {
        if (checking || document.hidden || (window.AACafeAutoRefreshGuard && window.AACafeAutoRefreshGuard.shouldPause())) {
            return;
        }

        checking = true;
        fetch('/api/record_version.php?scope=global', { cache: 'no-store' })
            .then(response => response.ok ? response.json() : null)
            .then(data => {
                if (data && data.version && data.version !== initialVersion) {
                    window.location.reload();
                }
            })
            .catch(() => {})
            .finally(() => {
                checking = false;
            });
    }, 5000);
})();
</script>

<?php renderFooter(); ?>

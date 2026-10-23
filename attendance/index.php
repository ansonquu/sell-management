<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';

requireLogin();

$staff = getCurrentStaff() ?? [];
$isLeader = ($staff['role'] ?? '') === 'leader';
if (!$isLeader && !isAdmin()) {
    flash('error', '您没有签到记录权限');
    redirect('/');
}

$pdo = getDb();
$errors = [];
$staffAccounts = getStaffAccounts();

// 选择日期与班次
$selectedDate = (string) ($_GET['date'] ?? $_POST['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}
$selectedShift = (string) ($_GET['shift'] ?? $_POST['shift'] ?? 'noon');
if (!array_key_exists($selectedShift, attendanceShiftOptions())) {
    $selectedShift = 'noon';
}

$record = getAttendanceRecord($selectedDate, $selectedShift);
$details = $record ? attendanceRecordDetails($record) : ['arrived' => [], 'absent' => []];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = (string) ($_POST['action'] ?? 'save');
    $date = (string) ($_POST['date'] ?? '');
    $shift = (string) ($_POST['shift'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $errors[] = '请选择有效的日期';
    }
    if (!array_key_exists($shift, attendanceShiftOptions())) {
        $errors[] = '请选择有效的班次';
    }
    if ($date > date('Y-m-d')) {
        $errors[] = '不能记录未来日期的签到';
    }
    $roster = getAttendanceRoster();
    $rosterSet = array_flip($roster);
    $arrived = [];
    foreach ((array) ($_POST['arrived'] ?? []) as $username) {
        $username = (string) $username;
        if (isset($rosterSet[$username])) {
            $arrived[] = $username;
        }
    }
    $absent = [];
    foreach ($roster as $username) {
        if (in_array($username, $arrived, true)) {
            continue;
        }
        $status = (string) ($_POST['absent_status'][$username] ?? 'absent');
        if (!in_array($status, ['late', 'absent', 'leave', 'no_need'], true)) {
            $status = 'absent';
        }
        $absent[$username] = $status;
    }

    if (!$errors) {
        $id = saveAttendanceRecord($date, $shift, $arrived, $absent, (string) ($staff['username'] ?? ''), (string) ($staff['name'] ?? ''));
        $typeLabel = $record ? '更新' : '记录';
        logOperation($pdo, 'attendance_record_save', 'attendance_record', $id, $typeLabel . '签到：' . $date . '（' . attendanceShiftLabel($shift) . '）', [
            'date' => $date,
            'shift' => $shift,
            'arrived_count' => count($arrived),
            'absent_count' => count($absent),
        ]);
        flash('success', '签到' . $typeLabel . '已保存');
        redirect('/attendance/?date=' . urlencode($date) . '&shift=' . urlencode($shift));
    }
}

$records = getAttendanceRecords('', 100);
$roster = getAttendanceRoster();
$positionMap = getAttendanceRosterPositionMap();
$arrivedSet = array_flip($details['arrived'] ?? []);

// 统计：用精确查询，不受列表 LIMIT 100 影响
$todayStr = date('Y-m-d');
$monthPrefix = date('Y-m');

$statStmt = $pdo->prepare('SELECT COUNT(*) FROM attendance_records WHERE record_date = ?');
$statStmt->execute([$todayStr]);
$todayCount = (int) $statStmt->fetchColumn();

$statStmt = $pdo->prepare('SELECT COUNT(*) FROM attendance_records WHERE record_date LIKE ?');
$statStmt->execute([$monthPrefix . '%']);
$monthCount = (int) $statStmt->fetchColumn();

$confirmedCount = (int) $pdo->query("SELECT COUNT(*) FROM attendance_records WHERE status = 'confirmed'")->fetchColumn();
$pendingCount = (int) $pdo->query("SELECT COUNT(*) FROM attendance_records WHERE status <> 'confirmed'")->fetchColumn();

renderHeader('签到记录 - Anson咖啡厅管理系统', 'attendance');
?>
<div class="page-header">
    <h1 class="page-title">签到记录</h1>
    <div class="page-actions">
        <?php if (isAdmin()): ?><a class="btn btn-secondary" href="/admin/attendance.php">签到管理</a><?php endif; ?>
    </div>
</div>

<?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?></div><?php endif; ?>

<div class="stats-grid attendance-stats">
    <div class="stat-card small"><div class="stat-value"><?= count($roster) ?></div><div class="stat-label">名单人数</div></div>
    <div class="stat-card small"><div class="stat-value"><?= $todayCount ?></div><div class="stat-label">今日记录</div></div>
    <div class="stat-card small"><div class="stat-value"><?= $monthCount ?></div><div class="stat-label">本月班次</div></div>
    <div class="stat-card small"><div class="stat-value <?= $pendingCount > 0 ? 'text-danger' : '' ?>"><?= $pendingCount ?></div><div class="stat-label">待确认</div></div>
    <div class="stat-card small"><div class="stat-value text-success"><?= $confirmedCount ?></div><div class="stat-label">已确认</div></div>
</div>

<div class="grid-2">
    <div class="card">
        <h3>📅 选择日期与班次</h3>
        <form method="get" id="attendanceSelectForm">
            <div class="attendance-select-row">
                <input type="date" name="date" max="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($selectedDate) ?>" onchange="document.getElementById('attendanceSelectForm').submit()">
                <div class="attendance-shift-pills">
                    <?php foreach (attendanceShiftOptions() as $value => $label): ?>
                        <label class="attendance-pill<?= $selectedShift === $value ? ' active' : '' ?>">
                            <input type="radio" name="shift" value="<?= $value ?>" <?= $selectedShift === $value ? 'checked' : '' ?> onchange="document.getElementById('attendanceSelectForm').submit()">
                            <?= htmlspecialchars($label) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </form>
        <div class="attendance-record-hint">
            <?php if ($record): ?>
                <span class="badge badge-warning">已有记录</span>
                <span class="text-muted">记录人：<?= htmlspecialchars($record['leader_name'] ?: $record['leader_username']) ?>，可更新到岗状态</span>
            <?php else: ?>
                <span class="badge badge-level">新班次</span>
                <span class="text-muted">该班次暂无记录，可新增</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h3><img class="inline-page-icon" src="<?= htmlspecialchars(getFaviconUrl()) ?>" alt=""> <?= attendanceShiftLabel($selectedShift) ?>班次 · <?= htmlspecialchars($selectedDate) ?> 到岗登记 <span id="attendanceLiveCount" class="text-muted" style="font-weight:400;font-size:0.85rem;"></span></h3>
        <form method="post" id="attendanceRosterForm">
                <?= csrfField() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="date" value="<?= htmlspecialchars($selectedDate) ?>">
            <input type="hidden" name="shift" value="<?= htmlspecialchars($selectedShift) ?>">
            <?php if (empty($roster)): ?>
                <p class="text-muted">签到名单尚未配置，请联系管理员在“管理后台 → 签到管理”中添加。</p>
            <?php else: ?>
                <p class="settings-help">点击圆形勾选 = 到岗；未到岗的人员可在右侧选择状态（迟到 / 未到 / 请假 / 无需到岗）。</p>
                <div class="attendance-roster">
                    <?php foreach ($roster as $name): $isArrived = isset($arrivedSet[$name]); ?>
                        <div class="attendance-person <?= $isArrived ? 'is-arrived' : '' ?>">
                            <label class="attendance-person-check">
                                <input type="checkbox" name="arrived[]" value="<?= htmlspecialchars($name) ?>" <?= $isArrived ? 'checked' : '' ?>>
                                <span class="attendance-check-circle"></span>
                                <span class="attendance-person-info">
                                    <span class="attendance-person-name"><?= htmlspecialchars((string) $name) ?></span>
                                    <?php $personPositions = $positionMap[$name] ?? []; if (!empty($personPositions)): ?><small class="attendance-person-position"><?= htmlspecialchars(implode('、', $personPositions)) ?></small><?php endif; ?>
                                </span>
                            </label>
                            <select name="absent_status[<?= htmlspecialchars($name) ?>]" class="attendance-status" <?= $isArrived ? 'style="display:none"' : '' ?>>
                                <?php foreach (attendanceAbsentStatusOptions() as $value => $label): ?>
                                    <option value="<?= $value ?>" <?= normalizeAbsentStatus($details, $name) === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="form-actions">
                    <button class="btn btn-primary" type="submit"><?= $record ? '更新签到' : '保存签到' ?></button>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card">
    <h3>签到记录</h3>
    <?php if (empty($records)): ?>
        <p class="text-muted">暂无签到记录</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead><tr><th>日期</th><th>班次</th><th>记录人</th><th>到岗</th><th>未到（迟到/未到/请假/无需到岗）</th><th>状态</th><th>更新时间</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($records as $row): $rd = attendanceRecordDetails($row); $absentCounts = ['late' => 0, 'absent' => 0, 'leave' => 0, 'no_need' => 0]; $absentLabels = []; foreach (($rd['absent'] ?? []) as $nm => $st) { $absentCounts[$st] = ($absentCounts[$st] ?? 0) + 1; $absentLabels[] = $nm . '(' . attendanceAbsentStatusLabel((string) $st) . ')'; } ?>
                    <tr class="<?= $row['status'] === 'pending' ? '' : 'is-muted' ?>">
                        <td class="nowrap-cell"><?= htmlspecialchars((string) $row['record_date']) ?></td>
                        <td><span class="badge <?= $row['shift'] === 'evening' ? 'badge-level' : 'badge-warning' ?>"><?= attendanceShiftLabel((string) $row['shift']) ?></span></td>
                        <td><?= htmlspecialchars((string) ($row['leader_name'] ?: $row['leader_username'])) ?></td>
                        <td title="<?= htmlspecialchars(implode('、', $rd['arrived'] ?? [])) ?>"><span class="text-success"><?= count($rd['arrived'] ?? []) ?> 人</span></td>
                        <td title="<?= htmlspecialchars(implode('；', $absentLabels)) ?>"><span class="text-danger"><?= (int) $absentCounts['late'] ?> / <?= (int) $absentCounts['absent'] ?> / <?= (int) $absentCounts['leave'] ?></span><?php if ((int) ($absentCounts['no_need'] ?? 0) > 0): ?><span class="text-muted">（无需到岗 <?= (int) $absentCounts['no_need'] ?>）</span><?php endif; ?></td>
                        <td><span class="badge <?= $row['status'] === 'confirmed' ? 'badge-success' : 'badge-warning' ?>"><?= $row['status'] === 'confirmed' ? '已确认' : '待确认' ?></span></td>
                        <td><?= formatDateTime((string) $row['updated_at']) ?></td>
                        <td><a class="btn btn-sm btn-secondary" href="/attendance/?date=<?= urlencode((string) $row['record_date']) ?>&shift=<?= urlencode((string) $row['shift']) ?>">编辑</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    var counter = document.getElementById('attendanceLiveCount');
    var checkboxes = document.querySelectorAll('.attendance-person input[name="arrived[]"]');
    if (!counter || !checkboxes.length) return;

    function updateCount() {
        var total = document.querySelectorAll('.attendance-person').length;
        var checked = document.querySelectorAll('.attendance-person input[name="arrived[]"]:checked').length;
        counter.textContent = '· 已到岗 ' + checked + ' / ' + total + ' 人';
    }

    checkboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            var person = checkbox.closest('.attendance-person');
            var statusSelect = person.querySelector('.attendance-status');
            if (checkbox.checked) {
                person.classList.add('is-arrived');
                if (statusSelect) statusSelect.style.display = 'none';
            } else {
                person.classList.remove('is-arrived');
                if (statusSelect) statusSelect.style.display = '';
            }
            updateCount();
        });
    });

    updateCount();
})();
</script>

<?php renderFooter(); ?>

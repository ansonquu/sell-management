<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';

requireLogin();
requireAdmin();

// 下载签到名单导入模板
if (($_GET['download_template'] ?? '') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="签到名单导入模板.csv"');
    echo "\xEF\xBB\xBF姓名\n张三\n李四\n";
    exit;
}

/** 解析签到名单 CSV：每行一个姓名（首行可为表头“姓名”），支持 UTF-8 / GBK。 */
function parseAttendanceCsvNames(string $raw): array
{
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    } elseif (!mb_check_encoding($raw, 'UTF-8')) {
        $converted = @mb_convert_encoding($raw, 'UTF-8', 'GBK');
        if (is_string($converted)) {
            $raw = $converted;
        }
    }
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $names = [];
    foreach (explode("\n", $raw) as $line) {
        $fields = str_getcsv($line);
        $name = trim((string) ($fields[0] ?? ''));
        if ($name !== '') {
            $names[] = $name;
        }
    }
    if ($names && ($names[0] === '姓名' || mb_strtolower($names[0]) === 'name')) {
        array_shift($names);
    }
    return array_values(array_unique($names));
}

$pdo = getDb();
$errors = [];
$staffAccounts = getStaffAccounts();

// 可选员工：全部员工（含管理员），用户名 => 姓名
$staffOptions = [];
foreach (attendanceStaffOptions() as $username => $info) {
    $staffOptions[$username] = (string) ($info['name'] ?? $username);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_roster') {
        $selected = [];
        // 先选：勾选的现有员工（含管理员，存姓名 + 多职位）
        foreach ((array) ($_POST['roster_staff'] ?? []) as $username) {
            $username = trim((string) $username);
            if (isset($staffOptions[$username])) {
                $positions = [];
                foreach ((array) ($_POST['staff_position'][$username] ?? []) as $position) {
                    $position = trim((string) $position);
                    if ($position !== '') {
                        $positions[] = $position;
                    }
                }
                $selected[] = ['name' => $staffOptions[$username], 'positions' => $positions];
            }
        }
        // 后加：自定义姓名（名单外人员，含多职位）
        foreach ((array) ($_POST['roster_extra_name'] ?? []) as $index => $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $positions = [];
                foreach ((array) ($_POST['roster_extra_position'][$index] ?? []) as $position) {
                    $position = trim((string) $position);
                    if ($position !== '') {
                        $positions[] = $position;
                    }
                }
                $selected[] = ['name' => $name, 'positions' => $positions];
            }
        }
        saveAttendanceRoster($selected);
        flash('success', '签到名单已保存（共 ' . count($selected) . ' 人）');
        redirect('/admin/attendance.php');
    }
    if ($action === 'import_csv') {
        $upload = $_FILES['roster_csv'] ?? [];
        $errorCode = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            $errors[] = '请选择要导入的 CSV 文件';
        } elseif ($errorCode !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) {
            $errors[] = 'CSV 文件上传失败，请重试';
        } elseif ((int) ($upload['size'] ?? 0) > 2 * 1024 * 1024) {
            $errors[] = 'CSV 文件不能超过 2MB';
        } else {
            $raw = (string) file_get_contents((string) $upload['tmp_name']);
            $names = parseAttendanceCsvNames($raw);
            if (!$names) {
                $errors[] = 'CSV 中没有有效的姓名（每行一个姓名）';
            } else {
                $current = getAttendanceRosterEntries();
                $existingNames = array_flip(array_map(static fn (array $entry): string => (string) ($entry['name'] ?? ''), $current));
                foreach ($names as $name) {
                    if (!isset($existingNames[$name])) {
                        $current[] = ['name' => $name, 'position' => ''];
                    }
                }
                saveAttendanceRoster($current);
                flash('success', '已导入 ' . count($names) . ' 个姓名到自定义名单');
                redirect('/admin/attendance.php');
            }
        }
    }
    if ($action === 'confirm') {
        $id = (int) ($_POST['id'] ?? 0);
        if (confirmAttendanceRecord($id)) {
            $row = getAttendanceRecordById($id);
            logOperation($pdo, 'attendance_record_confirm', 'attendance_record', $id, '确认签到记录：' . ($row['record_date'] ?? '') . '（' . attendanceShiftLabel((string) ($row['shift'] ?? 'noon')) . '）');
            flash('success', '签到记录已确认');
        } else {
            $errors[] = '记录不存在或已确认';
        }
        redirect('/admin/attendance.php');
    }
}

$roster = getAttendanceRoster();
$records = getAttendanceRecords('', 200);

// 名单中不属于任何现有员工的条目 → 作为自定义姓名展示（含职位）
$staffNames = array_values($staffOptions);
$customRosterEntries = [];
foreach (getAttendanceRosterEntries() as $entry) {
    if (!in_array((string) ($entry['name'] ?? ''), $staffNames, true)) {
        $customRosterEntries[] = $entry;
    }
}
$positionMap = getAttendanceRosterPositionMap();

// 名单概览统计
$rosterTotal = count($roster);
$selectedStaffCount = count(array_filter($staffOptions, static fn (string $name): bool => in_array($name, $roster, true)));
$customCount = count($customRosterEntries);
$positionCount = count(getAttendancePositions());

renderHeader('签到管理 - 管理后台', 'admin');
?>
<div class="page-header">
    <h1 class="page-title">签到管理</h1>
    <div class="page-actions">
        <a class="btn btn-secondary" href="/admin/logs.php">返回操作记录</a>
    </div>
</div>

<?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?></div><?php endif; ?>

<div class="card">
    <h3>📋 签到名单配置</h3>
    <p class="settings-help">先勾选现有员工加入名单并配置职位；名单外人员（兼职/临时）可在"自定义姓名"中新增。</p>

    <div class="roster-overview">
        <div class="roster-overview-item"><strong><?= $rosterTotal ?></strong><span>名单人数</span></div>
        <div class="roster-overview-item"><strong><?= $selectedStaffCount ?></strong><span>现有员工</span></div>
        <div class="roster-overview-item"><strong><?= $customCount ?></strong><span>自定义人员</span></div>
        <div class="roster-overview-item"><strong><?= $positionCount ?></strong><span>职位选项</span></div>
    </div>

    <form method="post">
                <?= csrfField() ?>
        <input type="hidden" name="action" value="save_roster">
        <div class="roster-section-head">
            <h4>👥 现有员工（含管理员）</h4>
            <span class="text-muted" id="rosterStaffCount">已选 <?= $selectedStaffCount ?> / <?= count($staffOptions) ?> 人</span>
        </div>
        <?php if (empty($staffOptions)): ?>
            <p class="text-muted">暂无员工可加入名单</p>
        <?php else: ?>
            <div class="roster-staff-grid">
                <?php foreach ($staffOptions as $username => $staffName): $checked = in_array($staffName, $roster, true); $savedPositions = (array) ($positionMap[$staffName] ?? []); $staffRole = (string) ($staffAccounts[$username]['role'] ?? 'staff'); ?>
                    <div class="roster-staff-card <?= $checked ? 'is-selected' : '' ?>">
                        <label class="roster-staff-head">
                            <input type="checkbox" name="roster_staff[]" value="<?= htmlspecialchars($username) ?>" <?= $checked ? 'checked' : '' ?>>
                            <span class="roster-check-circle"></span>
                            <span class="roster-staff-name"><?= htmlspecialchars($staffName) ?></span>
                            <span class="badge <?= $staffRole === 'admin' ? 'badge-warning' : ($staffRole === 'leader' ? 'badge-leader' : ($staffRole === 'finance' ? 'badge-success' : 'badge-secondary')) ?>"><?= htmlspecialchars(staffRoleLabel($staffRole)) ?></span>
                        </label>
                        <div class="roster-staff-positions">
                            <?= attendancePositionCheckboxes('staff_position[' . htmlspecialchars($username) . ']', $savedPositions) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="roster-section-head">
            <h4>🆕 自定义姓名（名单外人员）</h4>
            <span class="text-muted">兼职、临时等不在员工账号中的人员</span>
        </div>
        <div id="attendanceRosterRows" class="roster-config-list">
            <?php foreach ($customRosterEntries as $customIndex => $entry): ?>
                <div class="settings-row roster-row">
                    <div class="form-group"><label>姓名</label><input name="roster_extra_name[]" value="<?= htmlspecialchars((string) ($entry['name'] ?? '')) ?>" required></div>
                    <div class="form-group"><label>职位（可多选）</label><?= attendancePositionCheckboxes('roster_extra_position[' . (int) $customIndex . ']', (array) ($entry['positions'] ?? [])) ?></div>
                    <button type="button" class="btn btn-danger remove-row">删除</button>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="roster-actions">
            <button type="button" class="btn btn-secondary" data-add-row="roster_name">+ 添加自定义姓名</button>
            <button type="button" class="btn btn-secondary" onclick="openRosterImportModal()">批量导入 CSV</button>
            <button class="btn btn-primary" type="submit">保存名单</button>
        </div>
    </form>
</div>

<script>
(function () {
    // 员工卡片选中高亮 + 已选人数
    function updateStaffCount() {
        var cards = document.querySelectorAll('.roster-staff-card');
        var checked = document.querySelectorAll('.roster-staff-head input[type="checkbox"]:checked').length;
        var el = document.getElementById('rosterStaffCount');
        if (el) el.textContent = '已选 ' + checked + ' / ' + cards.length + ' 人';
    }
    function syncStaffCard(cb) {
        var card = cb.closest('.roster-staff-card');
        if (card) card.classList.toggle('is-selected', cb.checked);
        updateStaffCount();
    }
    document.querySelectorAll('.roster-staff-head input[type="checkbox"]').forEach(function (cb) {
        cb.addEventListener('change', function () { syncStaffCard(cb); });
    });
    updateStaffCount();

    // 自定义姓名行重编号，避免删除后新行索引冲突
    function renumberRosterRows() {
        var rows = document.querySelectorAll('#attendanceRosterRows .roster-row');
        rows.forEach(function (row, idx) {
            row.querySelectorAll('.attendance-pos-check input').forEach(function (cb) {
                cb.name = 'roster_extra_position[' + idx + '][]';
            });
        });
    }

    document.addEventListener('click', function (event) {
        var add = event.target.closest('[data-add-row]');
        if (add && add.dataset.addRow === 'roster_name') {
            var positionOptions = <?= json_encode(attendancePositionOptionsForJs(), JSON_UNESCAPED_UNICODE) ?>;
            var posHtml = '';
            positionOptions.forEach(function (p) {
                var val = String(p.value).replace(/"/g, '&quot;');
                var lbl = String(p.label).replace(/</g, '&lt;');
                posHtml += '<label class="attendance-pos-check"><input type="checkbox" value="' + val + '"><span>' + lbl + '</span></label>';
            });
            var container = document.getElementById('attendanceRosterRows');
            var rowIndex = container.children.length;
            container.insertAdjacentHTML('beforeend', '<div class="settings-row roster-row"><div class="form-group"><label>姓名</label><input name="roster_extra_name[]" required></div><div class="form-group"><label>职位（可多选）</label><div class="attendance-pos-checks">' + posHtml + '</div></div><button type="button" class="btn btn-danger remove-row">删除</button></div>');
            var newRow = container.children[rowIndex];
            newRow.querySelectorAll('.attendance-pos-check input').forEach(function (cb) { cb.name = 'roster_extra_position[' + rowIndex + '][]'; });
            renumberRosterRows();
            return;
        }
        var rm = event.target.closest('.remove-row');
        if (rm) { rm.closest('.settings-row').remove(); renumberRosterRows(); }
    });
})();

function openRosterImportModal() {
    document.getElementById('rosterImportModal').style.display = 'flex';
}
function closeRosterImportModal() {
    document.getElementById('rosterImportModal').style.display = 'none';
}
document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeRosterImportModal();
});
</script>

<div id="rosterImportModal" class="modal-overlay" style="display:none;">
    <div class="modal-card">
        <div class="modal-header">
            <h3>批量导入自定义姓名</h3>
            <button type="button" class="modal-close" onclick="closeRosterImportModal()">×</button>
        </div>
        <p class="settings-help">CSV 每行一个姓名（UTF-8 或 Excel 导出的 GBK 均可），首行可为表头"姓名"。</p>
        <form method="post" enctype="multipart/form-data">
                <?= csrfField() ?>
            <input type="hidden" name="action" value="import_csv">
            <div class="form-group">
                <label>CSV 文件 <span class="required">*</span></label>
                <input type="file" name="roster_csv" accept=".csv,text/csv" required>
                <p class="form-help"><a href="/admin/attendance.php?download_template=1">下载导入模板</a>（不超过 2MB，重复姓名自动去重）</p>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">开始导入</button>
                <button type="button" class="btn btn-secondary" onclick="closeRosterImportModal()">取消</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <h3>签到记录（<?= count($records) ?> 条）</h3>
    <?php if (empty($records)): ?>
        <p class="text-muted">暂无签到记录</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead><tr><th>日期</th><th>班次</th><th>记录人</th><th>到岗</th><th>未到（迟到/未到/请假/无需到岗）</th><th>状态</th><th>确认人</th><th>更新时间</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($records as $row): $rd = attendanceRecordDetails($row); $absentCounts = ['late' => 0, 'absent' => 0, 'leave' => 0, 'no_need' => 0]; foreach (($rd['absent'] ?? []) as $st) { $absentCounts[$st] = ($absentCounts[$st] ?? 0) + 1; } ?>
                    <tr class="<?= $row['status'] === 'pending' ? '' : 'is-muted' ?>">
                        <td><?= htmlspecialchars((string) $row['record_date']) ?></td>
                        <td><?= attendanceShiftLabel((string) $row['shift']) ?></td>
                        <td><?= htmlspecialchars((string) ($row['leader_name'] ?: $row['leader_username'])) ?></td>
                        <td><span class="text-success"><?= count($rd['arrived'] ?? []) ?> 人</span></td>
                        <td><span class="text-danger"><?= (int) $absentCounts['late'] ?> / <?= (int) $absentCounts['absent'] ?> / <?= (int) $absentCounts['leave'] ?></span><?php if ((int) ($absentCounts['no_need'] ?? 0) > 0): ?><span class="text-muted">（无需到岗 <?= (int) $absentCounts['no_need'] ?>）</span><?php endif; ?></td>
                        <td><span class="badge <?= $row['status'] === 'confirmed' ? 'badge-success' : 'badge-warning' ?>"><?= $row['status'] === 'confirmed' ? '已确认' : '待确认' ?></span></td>
                        <td><?= htmlspecialchars((string) ($row['confirmed_by'] ?: '-')) ?></td>
                        <td><?= formatDateTime((string) $row['updated_at']) ?></td>
                        <td>
                            <div class="inline-reset-form">
                                <a class="btn btn-sm btn-secondary" href="/attendance/?date=<?= urlencode((string) $row['record_date']) ?>&shift=<?= urlencode((string) $row['shift']) ?>">编辑</a>
                                <?php if ($row['status'] === 'pending'): ?>
                                <form method="post" onsubmit="return confirm('确认这条签到记录？')">
                <?= csrfField() ?>
                                    <input type="hidden" name="action" value="confirm">
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <button class="btn btn-sm btn-success" type="submit">确认</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php renderFooter(); ?>

<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';

requireAdmin();

$pdo = getDb();
$errors = [];
$accounts = getStaffAccounts();
$currentStaff = getCurrentStaff();

// 下载员工导入 CSV 模板
if (($_GET['download_template'] ?? '') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="员工导入模板.csv"');
    echo "ï»¿";
    echo "账号,姓名,初始密码,权限,含普通员工权限\n";
    echo "xiaoming,小明,123456,普通员工,是\n";
    echo "xiaohong,小红,123456,财务,否\n";
    echo "zhangsan,张三,123456,普通员工,\n";
    exit;
}

function parseStaffImportCsv(string $raw): array
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
    $lines = explode("\n", $raw);
    $rows = [];
    foreach ($lines as $index => $line) {
        if (trim($line) === '') {
            continue;
        }
        $rows[] = ['line' => $index + 1, 'fields' => array_map('trim', str_getcsv($line))];
    }
    return $rows;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? 'create';

    if ($action === 'freeze' || $action === 'unfreeze') {
        $freezeUsername = trim($_POST['freeze_username'] ?? '');
        $target = $accounts[$freezeUsername] ?? null;
        $freezing = $action === 'freeze';

        if (!$target) {
            $errors[] = '员工不存在';
        } elseif ($freezeUsername === ($currentStaff['username'] ?? '')) {
            $errors[] = '不能冻结当前登录的账号';
        } elseif ($freezing && ($target['role'] ?? 'staff') === 'admin') {
            $errors[] = '不能冻结管理员账号';
        }

        if (empty($errors) && setStaffFrozen($freezeUsername, $freezing)) {
            clearActiveSession($freezeUsername);
            logOperation($pdo, $freezing ? 'staff_freeze' : 'staff_unfreeze', 'staff', null, ($freezing ? '冻结员工：' : '解冻员工：') . $target['name'], [
                'username' => $freezeUsername,
                'name' => $target['name'],
            ]);
            flash('success', $freezing ? '员工已冻结，仅保留反馈功能，并已强制退出登录' : '员工已解冻');
            redirect('/admin/staff.php');
        }
        if (empty($errors)) {
            $errors[] = '操作失败，请重试';
        }
    }

    if ($action === 'import') {
        $upload = $_FILES['staff_csv'] ?? [];
        $errorCode = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            $errors[] = '请选择要导入的 CSV 文件';
        } elseif ($errorCode !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) {
            $errors[] = 'CSV 文件上传失败，请重试';
        } elseif ((int) ($upload['size'] ?? 0) > 2 * 1024 * 1024) {
            $errors[] = 'CSV 文件不能超过 2MB';
        } else {
            $raw = (string) file_get_contents((string) $upload['tmp_name']);
            $rows = parseStaffImportCsv($raw);
            if (!$rows) {
                $errors[] = 'CSV 文件为空';
            } else {
                // 跳过表头行
                $firstCell = mb_strtolower((string) ($rows[0]['fields'][0] ?? ''));
                if (in_array($firstCell, ['账号', 'username'], true)) {
                    array_shift($rows);
                }
                $roleMap = [
                    'staff' => 'staff', '普通员工' => 'staff',
                    'leader' => 'leader', '当日负责人' => 'leader',
                    'finance' => 'finance', '财务' => 'finance',
                    'barista_screen' => 'barista_screen', '制作总览' => 'barista_screen',
                    'customer_screen' => 'customer_screen', '客户大屏' => 'customer_screen',
                    'admin' => 'admin', '管理员' => 'admin',
                ];
                $imported = 0;
                $failures = [];
                $importAccounts = $accounts;
                foreach ($rows as $row) {
                    $fields = $row['fields'];
                    $username = (string) ($fields[0] ?? '');
                    $name = (string) ($fields[1] ?? '');
                    $password = (string) ($fields[2] ?? '');
                    $roleRaw = mb_strtolower(trim((string) ($fields[3] ?? '')));
                    $role = $roleMap[$roleRaw] ?? null;
                    $staffAccessRaw = mb_strtolower(trim((string) ($fields[4] ?? '')));

                    $rowErrors = [];
                    if ($username === '' || !preg_match('/^[A-Za-z0-9_-]{3,30}$/', $username)) {
                        $rowErrors[] = '账号格式错误（3-30 位英文/数字/_/-）';
                    } elseif (isset($importAccounts[$username])) {
                        $rowErrors[] = '账号已存在';
                    }
                    if ($name === '') {
                        $rowErrors[] = '姓名不能为空';
                    }
                    if (strlen($password) < 6) {
                        $rowErrors[] = '初始密码至少 6 位';
                    }
                    if ($role === null) {
                        $rowErrors[] = '权限无效（普通员工/当日负责人/财务/制作总览/客户大屏/管理员）';
                    }
                    if ($rowErrors) {
                        $failures[] = '第 ' . $row['line'] . ' 行（' . ($username !== '' ? $username : '空账号') . '）：' . implode('、', $rowErrors);
                        continue;
                    }
                    $staffAccess = $role === 'finance' && in_array($staffAccessRaw, ['是', 'yes', '1', 'true'], true);
                    if (addStaffAccount($username, $name, $password, $role, $staffAccess)) {
                        $importAccounts[$username] = true;
                        $imported++;
                        logOperation($pdo, 'staff_create', 'staff', null, '批量导入员工：' . $name, [
                            'username' => $username,
                            'name' => $name,
                            'role' => $role,
                            'import' => 'csv',
                        ]);
                    } else {
                        $failures[] = '第 ' . $row['line'] . ' 行（' . $username . '）：保存失败';
                    }
                }
                $message = '批量导入完成：成功 ' . $imported . ' 个';
                if ($failures) {
                    $message .= '，失败 ' . count($failures) . ' 个（' . implode('；', array_slice($failures, 0, 8)) . ($failures > 8 ? '…' : '') . '）';
                }
                flash($failures ? 'warning' : 'success', $message);
                redirect('/admin/staff.php');
            }
        }
    }

    if ($action === 'delete') {
        $deleteUsername = trim($_POST['delete_username'] ?? '');
        $target = $accounts[$deleteUsername] ?? null;

        if (!$target) {
            $errors[] = '员工不存在';
        } elseif ($deleteUsername === ($currentStaff['username'] ?? '')) {
            $errors[] = '不能删除当前登录的账号';
        } else {
            $adminCount = 0;
            foreach ($accounts as $account) {
                if (($account['role'] ?? 'staff') === 'admin') {
                    $adminCount++;
                }
            }

            if (($target['role'] ?? 'staff') === 'admin' && $adminCount <= 1) {
                $errors[] = '不能删除最后一个管理员';
            }
        }

        if (empty($errors)) {
            if (deleteStaffAccount($deleteUsername)) {
                clearActiveSession($deleteUsername);
                logOperation($pdo, 'staff_delete', 'staff', null, '删除员工：' . $target['name'], [
                    'username' => $deleteUsername,
                    'name' => $target['name'],
                    'role' => $target['role'] ?? 'staff',
                ]);
                flash('success', '员工已删除');
                redirect('/admin/staff.php');
            }

            $errors[] = '员工删除失败，请重试';
        }
    }

    // 批量操作：批量冻结 / 批量解冻 / 批量删除
    if ($action === 'bulk') {
        $bulkAction = (string) ($_POST['bulk_action'] ?? '');
        $bulkUsernames = array_values(array_unique(array_filter(
            array_map('trim', (array) ($_POST['bulk_usernames'] ?? [])),
            static fn ($value): bool => $value !== ''
        )));
        $currentUsername = (string) ($currentStaff['username'] ?? '');

        if (!in_array($bulkAction, ['freeze', 'unfreeze', 'delete'], true)) {
            $errors[] = '请选择有效的批量操作';
        } elseif (!$bulkUsernames) {
            $errors[] = '请先勾选要操作的员工';
        } else {
            $adminRemaining = 0;
            foreach ($accounts as $account) {
                if (($account['role'] ?? 'staff') === 'admin') {
                    $adminRemaining++;
                }
            }

            $bulkDone = 0;
            $bulkSkipped = [];
            foreach ($bulkUsernames as $bulkUsername) {
                $target = $accounts[$bulkUsername] ?? null;
                $targetName = (string) (($target['name'] ?? '') ?: $bulkUsername);
                $targetRole = (string) ($target['role'] ?? 'staff');

                if (!$target) {
                    $bulkSkipped[] = $bulkUsername . '（不存在）';
                    continue;
                }
                if ($bulkUsername === $currentUsername) {
                    $bulkSkipped[] = $targetName . '（当前登录账号）';
                    continue;
                }

                if ($bulkAction === 'freeze') {
                    if ($targetRole === 'admin') {
                        $bulkSkipped[] = $targetName . '（管理员不可冻结）';
                        continue;
                    }
                    if (!empty($target['frozen'])) {
                        $bulkSkipped[] = $targetName . '（已冻结）';
                        continue;
                    }
                    if (setStaffFrozen($bulkUsername, true)) {
                        clearActiveSession($bulkUsername);
                        logOperation($pdo, 'staff_freeze', 'staff', null, '批量冻结员工：' . $targetName, [
                            'username' => $bulkUsername,
                            'name' => $targetName,
                            'bulk' => true,
                        ]);
                        $bulkDone++;
                    } else {
                        $bulkSkipped[] = $targetName . '（冻结失败）';
                    }
                    continue;
                }

                if ($bulkAction === 'unfreeze') {
                    if (empty($target['frozen'])) {
                        $bulkSkipped[] = $targetName . '（未冻结）';
                        continue;
                    }
                    if (setStaffFrozen($bulkUsername, false)) {
                        logOperation($pdo, 'staff_unfreeze', 'staff', null, '批量解冻员工：' . $targetName, [
                            'username' => $bulkUsername,
                            'name' => $targetName,
                            'bulk' => true,
                        ]);
                        $bulkDone++;
                    } else {
                        $bulkSkipped[] = $targetName . '（解冻失败）';
                    }
                    continue;
                }

                // delete
                if ($targetRole === 'admin') {
                    if ($adminRemaining <= 1) {
                        $bulkSkipped[] = $targetName . '（最后一个管理员）';
                        continue;
                    }
                    $adminRemaining--;
                }
                if (deleteStaffAccount($bulkUsername)) {
                    clearActiveSession($bulkUsername);
                    logOperation($pdo, 'staff_delete', 'staff', null, '批量删除员工：' . $targetName, [
                        'username' => $bulkUsername,
                        'name' => $targetName,
                        'role' => $targetRole,
                        'bulk' => true,
                    ]);
                    $bulkDone++;
                } else {
                    $bulkSkipped[] = $targetName . '（删除失败）';
                }
            }

            $bulkLabels = ['freeze' => '冻结', 'unfreeze' => '解冻', 'delete' => '删除'];
            if ($bulkDone > 0) {
                $message = '批量' . $bulkLabels[$bulkAction] . '完成：成功 ' . $bulkDone . ' 人';
                if ($bulkSkipped) {
                    $message .= '，跳过 ' . count($bulkSkipped) . ' 人（' . implode('；', array_slice($bulkSkipped, 0, 6)) . (count($bulkSkipped) > 6 ? '…' : '') . '）';
                }
                flash($bulkSkipped ? 'warning' : 'success', $message);
                redirect('/admin/staff.php');
            }
            if ($bulkSkipped) {
                $errors[] = '没有可操作的员工：' . implode('；', array_slice($bulkSkipped, 0, 6));
            }
        }
    }

    if ($action === 'reset_password') {
        $resetUsername = trim($_POST['reset_username'] ?? '');
        $newPassword = trim($_POST['new_password'] ?? '');
        $confirmPassword = trim($_POST['confirm_new_password'] ?? '');
        $target = $accounts[$resetUsername] ?? null;

        if (!$target) {
            $errors[] = '员工不存在';
        } elseif (($target['role'] ?? 'staff') === 'admin') {
            $errors[] = '这里不能重置管理员密码';
        }

        if ($newPassword === '') {
            $errors[] = '请输入新密码';
        } elseif (strlen($newPassword) < 6) {
            $errors[] = '新密码至少需要 6 个字符';
        }

        if ($newPassword !== $confirmPassword) {
            $errors[] = '两次输入的新密码不一致';
        }

        if (empty($errors)) {
            if (updateStaffPassword($resetUsername, $newPassword)) {
                clearActiveSession($resetUsername);
                logOperation($pdo, 'staff_password_admin_reset', 'staff', null, '管理员重置员工密码：' . $target['name'], [
                    'username' => $resetUsername,
                    'name' => $target['name'],
                    'role' => $target['role'] ?? 'staff',
                ]);
                flash('success', '员工密码已重置');
                redirect('/admin/staff.php');
            }

            $errors[] = '密码重置失败，请重试';
        }
    }

    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $confirmPassword = trim($_POST['confirm_password'] ?? '');
        $role = $_POST['role'] ?? 'staff';
        $extraRoles = array_values(array_intersect(
            array_map('strval', (array) ($_POST['extra_roles'] ?? [])),
            getAssignableRoles()
        ));
        // 财务附加「收银」等价于原来的「同时拥有普通员工权限」
        $staffAccess = $role === 'finance' ? in_array('staff', $extraRoles, true) : false;

        if ($username === '') {
            $errors[] = '请输入员工账号';
        } elseif (!preg_match('/^[A-Za-z0-9_-]{3,30}$/', $username)) {
            $errors[] = '员工账号只能使用 3-30 位英文、数字、下划线或短横线';
        } elseif (isset($accounts[$username])) {
            $errors[] = '该员工账号已存在';
        }

        if ($name === '') {
            $errors[] = '请输入员工姓名';
        }

        if ($password === '') {
            $errors[] = '请输入初始密码';
        } elseif (strlen($password) < 6) {
            $errors[] = '初始密码至少需要 6 个字符';
        }

        if ($password !== $confirmPassword) {
            $errors[] = '两次输入的密码不一致';
        }

        if (!in_array($role, ['staff', 'leader', 'finance', 'admin', 'barista', 'reception', 'barista_screen', 'customer_screen'], true)) {
            $errors[] = '请选择有效的员工权限';
        }

        if (empty($errors)) {
            if (addStaffAccount($username, $name, $password, $role, $staffAccess, $extraRoles)) {
                logOperation($pdo, 'staff_create', 'staff', null, '添加新员工：' . $name, [
                    'username' => $username,
                    'name' => $name,
                    'role' => $role,
                    'staff_access' => $staffAccess,
                    'extra_roles' => $extraRoles,
                ]);
                flash('success', '员工添加成功');
                redirect('/admin/staff.php');
            }

            $errors[] = '员工添加失败，请重试';
        }
    }
}

$accounts = getStaffAccounts();

$totalCount = count($accounts);
$adminCount = 0;
$financeCount = 0;
$staffCount = 0;
$screenCount = 0;
$customerScreenCount = 0;
$baristaCount = 0;
$receptionCount = 0;
$frozenCount = 0;
foreach ($accounts as $account) {
    $accRole = (string) ($account['role'] ?? 'staff');
    if ($accRole === 'admin') {
        $adminCount++;
    } elseif ($accRole === 'finance') {
        $financeCount++;
    } elseif ($accRole === 'barista_screen') {
        $screenCount++;
    } elseif ($accRole === 'customer_screen') {
        $customerScreenCount++;
    } elseif ($accRole === 'barista') {
        $baristaCount++;
    } elseif ($accRole === 'reception') {
        $receptionCount++;
    } else {
        $staffCount++;
    }
    if (!empty($account['frozen'])) {
        $frozenCount++;
    }
}

renderHeader('员工管理 - 管理后台', 'admin');
?>

<div class="page-header">
    <h2 class="page-title">员工管理</h2>
    <div class="page-actions">
        <a href="/admin/logs.php" class="btn btn-secondary">返回操作记录</a>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $e): ?>
            <div><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="stats-grid staff-stats">
    <div class="stat-card small"><div class="stat-value"><?= $totalCount ?></div><div class="stat-label">员工总数</div></div>
    <div class="stat-card small"><div class="stat-value"><?= $adminCount ?></div><div class="stat-label">管理员</div></div>
    <div class="stat-card small"><div class="stat-value"><?= $financeCount ?></div><div class="stat-label">财务</div></div>
    <div class="stat-card small"><div class="stat-value"><?= $staffCount ?></div><div class="stat-label">收银</div></div>
    <div class="stat-card small"><div class="stat-value"><?= $baristaCount ?></div><div class="stat-label">咖啡师</div></div>
    <div class="stat-card small"><div class="stat-value"><?= $receptionCount ?></div><div class="stat-label">接待</div></div>
    <div class="stat-card small"><div class="stat-value"><?= $screenCount ?></div><div class="stat-label">制作总览</div></div>
    <div class="stat-card small"><div class="stat-value"><?= $customerScreenCount ?? 0 ?></div><div class="stat-label">客户大屏</div></div>
    <div class="stat-card small"><div class="stat-value <?= $frozenCount > 0 ? 'text-danger' : '' ?>"><?= $frozenCount ?></div><div class="stat-label">已冻结</div></div>
</div>

<div class="staff-layout">
    <div class="card form-card staff-add-form">
        <h3>👤 添加新员工</h3>
        <form method="post">
                <?= csrfField() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-group">
                <label>员工账号 <span class="required">*</span></label>
                <input type="text" name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" placeholder="用于登录的账号" required>
            </div>
            <div class="form-group">
                <label>员工姓名 <span class="required">*</span></label>
                <input type="text" name="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" placeholder="显示在系统里的姓名" required>
            </div>
            <div class="form-group">
                <label>初始密码 <span class="required">*</span></label>
                <input type="password" name="password" placeholder="至少 6 个字符" required>
            </div>
            <div class="form-group">
                <label>确认密码 <span class="required">*</span></label>
                <input type="password" name="confirm_password" placeholder="再次输入初始密码" required>
            </div>
            <div class="form-group">
                <label>权限 <span class="required">*</span></label>
                <select name="role" required>
                    <option value="staff" <?= ($_POST['role'] ?? 'staff') === 'staff' ? 'selected' : '' ?>>收银</option>
                    <option value="barista" <?= ($_POST['role'] ?? '') === 'barista' ? 'selected' : '' ?>>咖啡师</option>
                    <option value="reception" <?= ($_POST['role'] ?? '') === 'reception' ? 'selected' : '' ?>>接待</option>
                    <option value="leader" <?= ($_POST['role'] ?? '') === 'leader' ? 'selected' : '' ?>>当日负责人</option>
                    <option value="finance" <?= ($_POST['role'] ?? '') === 'finance' ? 'selected' : '' ?>>财务</option>
                    <option value="barista_screen" <?= ($_POST['role'] ?? '') === 'barista_screen' ? 'selected' : '' ?>>制作总览</option>
                    <option value="customer_screen" <?= ($_POST['role'] ?? '') === 'customer_screen' ? 'selected' : '' ?>>客户大屏</option>
                    <option value="admin" <?= ($_POST['role'] ?? '') === 'admin' ? 'selected' : '' ?>>管理员</option>
                </select>
            </div>
            <div class="form-group" id="financeStaffAccess" style="display:none">
                <label>附加职业（财务可同时兼任）</label>
                <div class="attendance-pos-checks">
                    <?php foreach (['staff' => '收银', 'leader' => '当日负责人', 'barista' => '咖啡师', 'reception' => '接待', 'barista_screen' => '制作总览', 'customer_screen' => '客户大屏'] as $extraKey => $extraLabel): ?>
                        <label class="attendance-pos-check">
                            <input type="checkbox" name="extra_roles[]" value="<?= htmlspecialchars($extraKey) ?>" <?= in_array($extraKey, (array) ($_POST['extra_roles'] ?? []), true) ? 'checked' : '' ?>>
                            <span><?= htmlspecialchars($extraLabel) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <small class="text-muted">可多选。选择「收银」即同时拥有会员、消费、充值、优惠券等日常运营功能；咖啡师可操作制作总览；接待仅可查看大屏。</small>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">添加员工</button>
            </div>
        </form>
    </div>

    <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;margin-bottom:0.75rem;">
            <h3 style="margin:0;">📋 现有员工 <span class="text-muted" style="font-weight:400;font-size:0.85rem;">共 <?= $totalCount ?> 人</span></h3>
            <div class="page-actions" style="margin:0;">
                <a href="/admin/staff.php?download_template=1" class="btn btn-sm btn-secondary">下载模板</a>
                <button type="button" class="btn btn-sm btn-secondary" onclick="openImportModal()">导入员工</button>
            </div>
        </div>
        <form method="post" id="bulkForm">
                <?= csrfField() ?>
            <input type="hidden" name="action" value="bulk">
            <div class="bulk-bar" id="bulkBar">
                <span class="bulk-bar-info">已选 <strong id="bulkCount">0</strong> / <?= $totalCount ?> 人</span>
                <button type="submit" name="bulk_action" value="unfreeze" class="btn btn-sm btn-success" onclick="return confirmBulkAction('解冻')">批量解冻</button>
                <button type="submit" name="bulk_action" value="freeze" class="btn btn-sm btn-warning" onclick="return confirmBulkAction('冻结')">批量冻结</button>
                <button type="submit" name="bulk_action" value="delete" class="btn btn-sm btn-danger" onclick="return confirmBulkAction('删除')">批量删除</button>
            </div>
        </form>
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th class="staff-bulk-col"><input type="checkbox" id="bulkSelectAll" class="staff-bulk-check" title="全选 / 取消全选"></th>
                    <th>员工</th>
                    <th>权限</th>
                    <th>状态</th>
                    <th>重置密码</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($accounts as $username => $account): $accRole = (string) ($account['role'] ?? 'staff'); ?>
                    <tr class="<?= !empty($account['frozen']) ? 'is-frozen' : '' ?>">
                        <td class="staff-bulk-col">
                            <?php if ($username === ($currentStaff['username'] ?? '')): ?>
                                <span class="text-muted" title="当前登录账号">—</span>
                            <?php else: ?>
                                <input type="checkbox" class="staff-bulk-check bulk-row" form="bulkForm" name="bulk_usernames[]" value="<?= htmlspecialchars($username) ?>" title="选择 <?= htmlspecialchars((string) ($account['name'] ?? $username)) ?>">
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="staff-cell">
                                <?= staffAvatarMarkup($account, $username, 'staff-list-avatar') ?>
                                <div class="staff-cell-info">
                                    <div class="staff-cell-name"><?= htmlspecialchars((string) ($account['name'] ?? $username)) ?><?php if ($username === ($currentStaff['username'] ?? '')): ?> <span class="badge badge-secondary">当前</span><?php endif; ?></div>
                                    <small class="text-muted">@<?= htmlspecialchars($username) ?></small>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="badge <?= $accRole === 'admin' ? 'badge-warning' : ($accRole === 'finance' ? 'badge-success' : ($accRole === 'leader' ? 'badge-leader' : 'badge-secondary')) ?>">
                                <?= htmlspecialchars(staffRoleLabel($accRole)) ?>
                            </span>
                            <?php if (!empty($account['extra_roles'])): ?><small class="text-muted">兼任：<?= htmlspecialchars(implode('、', array_map('staffRoleLabel', (array) $account['extra_roles']))) ?></small><?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= !empty($account['frozen']) ? 'badge-danger' : 'badge-success' ?>"><?= !empty($account['frozen']) ? '已冻结' : '正常' ?></span>
                        </td>
                        <td>
                            <?php if ($accRole !== 'admin'): ?>
                                <button type="button" class="btn btn-sm btn-warning" onclick="openResetModal('<?= htmlspecialchars($username) ?>', '<?= htmlspecialchars((string) ($account['name'] ?? $username)) ?>')">重置密码</button>
                            <?php else: ?>
                                <span class="text-muted">管理员账号</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($username === ($currentStaff['username'] ?? '')): ?>
                                <span class="text-muted">当前账号</span>
                            <?php elseif ($accRole === 'admin'): ?>
                                <span class="text-muted">-</span>
                            <?php else: ?>
                                <div class="inline-reset-form">
                                <?php if (empty($account['frozen'])): ?>
                                <form method="post" onsubmit="return confirm('确定要冻结员工 <?= htmlspecialchars((string) ($account['name'] ?? $username)) ?> 吗？冻结后该员工仅能使用反馈功能，并会强制退出登录。')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="freeze">
                                    <input type="hidden" name="freeze_username" value="<?= htmlspecialchars($username) ?>">
                                    <button type="submit" class="btn btn-sm btn-warning btn-icon" title="冻结该员工" aria-label="冻结该员工"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></button>
                                </form>
                                <?php else: ?>
                                <form method="post" onsubmit="return confirm('确定要解冻员工 <?= htmlspecialchars((string) ($account['name'] ?? $username)) ?> 吗？')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="unfreeze">
                                    <input type="hidden" name="freeze_username" value="<?= htmlspecialchars($username) ?>">
                                    <button type="submit" class="btn btn-sm btn-success btn-icon" title="解冻该员工" aria-label="解冻该员工"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg></button>
                                </form>
                                <?php endif; ?>
                                <form method="post" onsubmit="return confirm('确定要删除员工 <?= htmlspecialchars((string) ($account['name'] ?? $username)) ?> 吗？')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="delete_username" value="<?= htmlspecialchars($username) ?>">
                                    <button type="submit" class="btn btn-sm btn-danger btn-icon" title="删除该员工" aria-label="删除该员工"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg></button>
                                </form>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>



<div id="importModal" class="modal-overlay" style="display:none;">
    <div class="modal-card">
        <div class="modal-header">
            <h3>批量导入员工</h3>
            <button type="button" class="modal-close" onclick="closeImportModal()">×</button>
        </div>
        <p class="settings-help">按模板格式填写后上传 CSV 文件（UTF-8 或 Excel 导出的 GBK 均可）。<br>列：账号, 姓名, 初始密码, 权限, 含普通员工权限。<br>权限填写：普通员工 / 财务 / 管理员；"含普通员工权限"仅对财务填写：是 / 否。</p>
        <form method="post" enctype="multipart/form-data">
                <?= csrfField() ?>
            <input type="hidden" name="action" value="import">
            <div class="form-group">
                <label>CSV 文件 <span class="required">*</span></label>
                <input type="file" name="staff_csv" accept=".csv,text/csv" required>
                <p class="form-help"><a href="/admin/staff.php?download_template=1">下载导入模板</a>（不超过 2MB，重复账号会自动跳过）</p>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">开始导入</button>
                <button type="button" class="btn btn-secondary" onclick="closeImportModal()">取消</button>
            </div>
        </form>
    </div>
</div>

<div id="resetPasswordModal" class="modal-overlay" style="display:none;">
    <div class="modal-card">
        <div class="modal-header">
            <h3>重置员工密码</h3>
            <button type="button" class="modal-close" onclick="closeResetModal()">×</button>
        </div>
        <form method="post" id="resetPasswordForm" onsubmit="return confirmResetPassword()">
                <?= csrfField() ?>
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="reset_username" id="resetUsername">
            <div class="form-group">
                <label>员工</label>
                <input type="text" id="resetDisplayName" disabled>
            </div>
            <div class="form-group">
                <label>新密码 <span class="required">*</span></label>
                <input type="password" name="new_password" id="resetNewPassword" placeholder="至少 6 个字符" required minlength="6">
            </div>
            <div class="form-group">
                <label>确认密码 <span class="required">*</span></label>
                <input type="password" name="confirm_new_password" placeholder="再次输入新密码" required minlength="6">
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">确认重置</button>
                <button type="button" class="btn btn-secondary" onclick="closeResetModal()">取消</button>
            </div>
        </form>
    </div>
</div>

<script>
const roleSelect = document.querySelector('select[name="role"]');
const financeStaffAccess = document.getElementById('financeStaffAccess');
function toggleFinanceStaffAccess() {
    financeStaffAccess.style.display = roleSelect.value === 'finance' ? 'block' : 'none';
}
roleSelect.addEventListener('change', toggleFinanceStaffAccess);
toggleFinanceStaffAccess();

function openImportModal() {
    const modal = document.getElementById('importModal');
    modal.style.display = 'flex';
}
function closeImportModal() {
    document.getElementById('importModal').style.display = 'none';
}

function openResetModal(username, name) {
    const modal = document.getElementById('resetPasswordModal');
    const form = document.getElementById('resetPasswordForm');
    form.reset();
    document.getElementById('resetUsername').value = username;
    document.getElementById('resetDisplayName').value = name + '（' + username + '）';
    modal.style.display = 'flex';
    document.getElementById('resetNewPassword').focus();
}

function closeResetModal() {
    const modal = document.getElementById('resetPasswordModal');
    const form = document.getElementById('resetPasswordForm');
    form.reset();
    modal.style.display = 'none';
}

function confirmResetPassword() {
    return confirm('确定要重置这个员工的密码吗？');
}

// 员工批量操作：全选 / 计数 / 二次确认
(function () {
    const selectAll = document.getElementById('bulkSelectAll');
    const rows = document.querySelectorAll('.bulk-row');
    const countEl = document.getElementById('bulkCount');
    const bar = document.getElementById('bulkBar');

    function updateBulkState() {
        let checked = 0;
        rows.forEach(function (cb) { if (cb.checked) checked++; });
        if (countEl) countEl.textContent = checked;
        if (bar) bar.classList.toggle('has-selection', checked > 0);
        if (selectAll) {
            selectAll.checked = checked > 0 && checked === rows.length;
            selectAll.indeterminate = checked > 0 && checked < rows.length;
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            rows.forEach(function (cb) { cb.checked = selectAll.checked; });
            updateBulkState();
        });
    }
    rows.forEach(function (cb) { cb.addEventListener('change', updateBulkState); });
    updateBulkState();
})();

function confirmBulkAction(label) {
    const checked = document.querySelectorAll('.bulk-row:checked').length;
    if (!checked) {
        alert('请先勾选要操作的员工');
        return false;
    }
    return confirm('确定要批量' + label + '选中的 ' + checked + ' 名员工吗？');
}

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeResetModal();
        closeImportModal();
    }
});
</script>

<?php renderFooter(); ?>

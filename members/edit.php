<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';

$pdo = getDb();
$id = (int) ($_GET['id'] ?? 0);
$member = getMemberById($pdo, $id);

if (!$member) {
    flash('error', '会员不存在');
    redirect('/members/');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? 'update';

    if ($action === 'delete') {
        if (!isAdmin()) {
            flash('error', '您没有权限删除会员');
            redirect('/members/edit.php?id=' . $id);
        }
        logOperation($pdo, 'member_delete', 'member', $id, '删除会员：' . $member['name'], [
            'name' => $member['name'],
            'phone' => $member['phone'],
            'student_no' => $member['student_no'] ?? '',
        ]);
        $pdo->prepare('DELETE FROM points_log WHERE member_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM consumption WHERE member_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM member WHERE id = ?')->execute([$id]);
        flash('success', '会员已删除');
        redirect('/members/');
    }

    if ($action === 'toggle_status') {
        $newStatus = $member['status'] == STATUS_NORMAL ? STATUS_FROZEN : STATUS_NORMAL;
        $pdo->prepare('UPDATE member SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
        logOperation($pdo, $newStatus == STATUS_NORMAL ? 'member_unfreeze' : 'member_freeze', 'member', $id, ($newStatus == STATUS_NORMAL ? '解冻会员：' : '冻结会员：') . $member['name'], [
            'name' => $member['name'],
            'phone' => $member['phone'],
        ]);
        flash('success', $newStatus == STATUS_NORMAL ? '会员已解冻' : '会员已冻结');
        redirect('/members/edit.php?id=' . $id);
    }

    $name = trim($_POST['name'] ?? '');
    $phone = isAdmin() ? trim($_POST['phone'] ?? '') : $member['phone'];
    $studentNo = trim($_POST['student_no'] ?? '');
    $gender = (int) ($_POST['gender'] ?? 0);
    $birthday = trim($_POST['birthday'] ?? '') ?: null;
    $note = trim($_POST['note'] ?? '');

    if ($name === '') {
        $errors[] = '姓名不能为空';
    }

    if ($phone !== '' && $phone !== $member['phone'] && getMemberByPhone($pdo, $phone)) {
        $errors[] = '该手机号已被其他会员使用';
    }

    if ($studentNo !== '' && getMemberByStudentNo($pdo, $studentNo, $id)) {
        $errors[] = '该学号已被其他会员使用';
    }

    $newPoints = (int) $member['points'];
    if (isAdmin()) {
        $pointsInput = trim((string) ($_POST['points'] ?? $member['points']));
        if ($pointsInput === '' || filter_var($pointsInput, FILTER_VALIDATE_INT) === false || (int) $pointsInput < 0) {
            $errors[] = '积分必须是大于或等于 0 的整数';
        } else {
            $newPoints = (int) $pointsInput;
        }
    }

    if (empty($errors)) {
        $pointsChange = $newPoints - (int) $member['points'];
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE member SET name = ?, phone = ?, student_no = ?, gender = ?, birthday = ?, note = ?, points = ? WHERE id = ?');
            // 学号为选填：清空时存 NULL，避免 UNIQUE 约束冲突
        $stmt->execute([$name, $phone !== '' ? $phone : null, $studentNo !== '' ? $studentNo : null, $gender, $birthday, $note, $newPoints, $id]);
            if ($pointsChange !== 0) {
                addPointsLog($pdo, $id, $pointsChange, '管理员手动调整积分');
            }
            logOperation($pdo, 'member_update', 'member', $id, '编辑会员资料：' . $name, [
                'old_name' => $member['name'],
                'new_name' => $name,
                'old_phone' => $member['phone'],
                'new_phone' => $phone,
                'student_no' => $studentNo,
                'old_points' => (int) $member['points'],
                'new_points' => $newPoints,
                'note_changed' => ($member['note'] ?? '') !== $note,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = '会员信息保存失败，请重试';
        }
        if (empty($errors)) {
            flash('success', '会员信息已更新');
            redirect('/members/view.php?id=' . $id);
        }
    }
}

renderHeader('编辑会员 - Anson咖啡厅管理系统', 'members');
?>

<div class="page-header">
    <h2 class="page-title">编辑会员</h2>
    <a href="/members/view.php?id=<?= $id ?>" class="btn btn-secondary">返回详情</a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $e): ?>
            <div><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card form-card">
    <form method="post">
                <?= csrfField() ?>
        <input type="hidden" name="action" value="update">
        <div class="form-group">
            <label>手机号</label>
            <?php if (isAdmin()): ?>
                <input type="tel" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? $member['phone'] ?? '') ?>" placeholder="未填写">
            <?php else: ?>
                <input type="text" value="<?= htmlspecialchars($member['phone'] ?: '待补全，可在会员详情页补全') ?>" disabled>
            <?php endif; ?>
        </div>
        <div class="form-group">
            <label>学号</label>
            <input type="text" name="student_no" value="<?= htmlspecialchars($_POST['student_no'] ?? $member['student_no'] ?? '') ?>" placeholder="会员学号（选填）">
        </div>
        <div class="form-group">
            <label>姓名 <span class="required">*</span></label>
            <input type="text" name="name" value="<?= htmlspecialchars($_POST['name'] ?? $member['name']) ?>" required>
        </div>
        <div class="form-group">
            <label>性别</label>
            <select name="gender">
                <?php $g = $_POST['gender'] ?? $member['gender']; ?>
                <option value="0" <?= $g == 0 ? 'selected' : '' ?>>未知</option>
                <option value="1" <?= $g == 1 ? 'selected' : '' ?>>男</option>
                <option value="2" <?= $g == 2 ? 'selected' : '' ?>>女</option>
            </select>
        </div>
        <div class="form-group">
            <label>生日</label>
            <input type="date" name="birthday" value="<?= htmlspecialchars($_POST['birthday'] ?? $member['birthday'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>备注</label>
            <textarea name="note" rows="4" placeholder="可填写会员偏好、注意事项等（选填）"><?= htmlspecialchars($_POST['note'] ?? $member['note'] ?? '') ?></textarea>
        </div>
        <?php if (isAdmin()): ?>
        <div class="form-group">
            <label>积分</label>
            <input type="number" name="points" value="<?= htmlspecialchars((string) ($_POST['points'] ?? $member['points'])) ?>" min="0" step="1" required>
            <small class="text-muted">管理员可直接调整会员积分，变动会记入积分记录和操作日志。</small>
        </div>
        <?php endif; ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">保存修改</button>
        </div>
    </form>
</div>

<div class="card danger-zone">
    <h3>危险操作</h3>
    <div class="danger-actions">
        <form method="post" onsubmit="return confirm('确定要<?= $member['status'] == STATUS_NORMAL ? '冻结' : '解冻' ?> 该会员吗？')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="toggle_status">
            <button type="submit" class="btn btn-warning btn-icon" title="<?= $member['status'] == STATUS_NORMAL ? '冻结该会员' : '解冻该会员' ?>" aria-label="<?= $member['status'] == STATUS_NORMAL ? '冻结该会员' : '解冻该会员' ?>">
                <?= $member['status'] == STATUS_NORMAL ? '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>' : '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>' ?>
            </button>
        </form>
        <?php if (isAdmin()): ?>
        <form method="post" onsubmit="return confirm('确定要删除该会员吗？此操作不可恢复，将同时删除所有消费和积分记录。')">
                <?= csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="btn btn-danger btn-icon" title="删除该会员" aria-label="删除该会员"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg></button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php renderFooter(); ?>

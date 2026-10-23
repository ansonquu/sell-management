<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';

requireAdmin();

$pdo = getDb();
$staff = getCurrentStaff();
$announcementId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$announcement = null;

if ($announcementId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM announcements WHERE id = ?');
    $stmt->execute([$announcementId]);
    $announcement = $stmt->fetch() ?: null;
    if (!$announcement) {
        flash('error', '公告不存在');
        redirect('/announcements/');
    }
}

$title = (string) ($announcement['title'] ?? '');
$content = (string) ($announcement['content'] ?? '');
$category = (string) ($announcement['category'] ?? 'normal');
$status = (string) ($announcement['status'] ?? 'published');
$groupOptions = announcementGroupOptions();
$existingTargets = announcementTargets($announcement ?? []);
$targetRoles = $existingTargets['roles'];
$targetStaff = $existingTargets['staff'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $title = trim((string) ($_POST['title'] ?? ''));
    $content = trim((string) ($_POST['content'] ?? ''));
    $category = (string) ($_POST['category'] ?? 'normal');
    $status = (string) ($_POST['status'] ?? 'published');
    $targetRoles = array_values(array_intersect(array_map('strval', (array) ($_POST['target_roles'] ?? [])), array_keys($groupOptions['roles'])));
    $targetStaff = array_values(array_intersect(array_map('strval', (array) ($_POST['target_staff'] ?? [])), array_keys(getStaffAccounts())));

    if ($title === '') {
        $errors[] = '请输入公告标题';
    } elseif (strlen($title) > 360) {
        $errors[] = '公告标题过长';
    }
    if ($content === '') {
        $errors[] = '请输入公告内容';
    }
    if (!in_array($category, ['normal', 'urgent'], true)) {
        $errors[] = '公告类别无效';
    }
    if (!in_array($status, ['published', 'archived'], true)) {
        $errors[] = '公告状态无效';
    }

    if (!$errors) {
        $now = currentDateTime();
        if ($announcement) {
            $stmt = $pdo->prepare('UPDATE announcements SET title = ?, content = ?, category = ?, status = ?, target_roles = ?, target_staff = ?, revision = revision + 1, updated_at = ? WHERE id = ?');
            $stmt->execute([$title, $content, $category, $status, json_encode($targetRoles, JSON_UNESCAPED_UNICODE), json_encode($targetStaff, JSON_UNESCAPED_UNICODE), $now, $announcementId]);
            logOperation($pdo, 'announcement_update', 'announcement', $announcementId, '编辑公告：' . $title, ['category' => $category, 'status' => $status, 'target_roles' => $targetRoles, 'target_staff' => $targetStaff]);
            flash('success', '公告已更新，更新后的公告将重新显示为未读');
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO announcements (title, content, category, status, target_roles, target_staff, created_by, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$title, $content, $category, $status, json_encode($targetRoles, JSON_UNESCAPED_UNICODE), json_encode($targetStaff, JSON_UNESCAPED_UNICODE), (string) ($staff['name'] ?? $staff['username'] ?? ''), $now, $now]);
            $announcementId = (int) $pdo->lastInsertId();
            logOperation($pdo, 'announcement_create', 'announcement', $announcementId, '发布公告：' . $title, ['category' => $category, 'status' => $status, 'target_roles' => $targetRoles, 'target_staff' => $targetStaff]);
            flash('success', $status === 'published' ? '公告已发布' : '公告已保存为撤下状态');
        }
        redirect('/announcements/');
    }
}

renderHeader(($announcement ? '编辑公告' : '发布公告') . ' - Anson咖啡厅管理系统', 'announcements');
?>

<div class="page-header">
    <h2 class="page-title"><?= $announcement ? '编辑公告' : '发布公告' ?></h2>
    <a href="/announcements/" class="btn btn-secondary">返回公告列表</a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-error"><?php foreach ($errors as $error): ?><div><?= htmlspecialchars($error) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<div class="card form-card announcement-editor-card">
    <form method="post">
                <?= csrfField() ?>
        <?php if ($announcement): ?><input type="hidden" name="id" value="<?= (int) $announcement['id'] ?>"><?php endif; ?>
        <div class="form-group">
            <label for="title">公告标题 <span class="required">*</span></label>
            <input type="text" id="title" name="title" maxlength="120" required value="<?= htmlspecialchars($title) ?>">
        </div>
        <div class="form-group">
            <label for="content">公告内容 <span class="required">*</span></label>
            <div class="markdown-toolbar" role="toolbar" aria-label="Markdown 格式工具">
                <button type="button" data-md-prefix="**" data-md-suffix="**" title="粗体"><strong>B</strong></button>
                <button type="button" data-md-prefix="*" data-md-suffix="*" title="斜体"><em>I</em></button>
                <button type="button" data-md-line="## " title="标题">H2</button>
                <button type="button" data-md-line="- " title="无序列表">列表</button>
                <button type="button" data-md-line="> " title="引用">引用</button>
                <button type="button" data-md-prefix="[" data-md-suffix="](https://)" title="链接">链接</button>
                <button type="button" data-md-prefix="`" data-md-suffix="`" title="行内代码">代码</button>
            </div>
            <textarea id="content" name="content" rows="12" required data-markdown-editor><?= htmlspecialchars($content) ?></textarea>
            <p class="form-help">支持标题、粗体、斜体、列表、引用、链接和代码等 Markdown 语法。</p>
            <div class="markdown-preview-wrap">
                <div class="markdown-preview-title">实时预览</div>
                <div id="markdownPreview" class="markdown-preview markdown-body"></div>
            </div>
        </div>
        <div class="form-group">
            <label for="category">公告类别</label>
            <select id="category" name="category">
                <option value="normal" <?= $category === 'normal' ? 'selected' : '' ?>>普通公告</option>
                <option value="urgent" <?= $category === 'urgent' ? 'selected' : '' ?>>紧急公告（红色显示）</option>
            </select>
        </div>
        <div class="form-group">
            <label for="status">状态</label>
            <select id="status" name="status">
                <option value="published" <?= $status === 'published' ? 'selected' : '' ?>>发布（员工可见）</option>
                <option value="archived" <?= $status === 'archived' ? 'selected' : '' ?>>撤下（仅管理员可见）</option>
            </select>
        </div>
        <div class="form-group">
            <label>可见分组</label>
            <div class="announcement-targets">
                <div class="announcement-target-block">
                    <span class="announcement-target-title">按角色</span>
                    <div class="attendance-pos-checks">
                        <?php foreach ($groupOptions['roles'] as $roleValue => $roleLabel): ?>
                            <label class="attendance-pos-check"><input type="checkbox" name="target_roles[]" value="<?= htmlspecialchars($roleValue) ?>" <?= in_array($roleValue, $targetRoles, true) ? 'checked' : '' ?>><span><?= htmlspecialchars($roleLabel) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="announcement-target-block">
                    <span class="announcement-target-title">指定人员</span>
                    <div class="attendance-pos-checks">
                        <?php foreach (getStaffAccounts() as $staffUsername => $staffAccount): ?>
                            <label class="attendance-pos-check"><input type="checkbox" name="target_staff[]" value="<?= htmlspecialchars((string) $staffUsername) ?>" <?= in_array((string) $staffUsername, $targetStaff, true) ? 'checked' : '' ?>><span><?= htmlspecialchars((string) ($staffAccount['name'] ?? $staffUsername)) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <p class="form-help">勾选后只有对应<b>角色</b>或<b>指定人员</b>能看到该公告（两者任一命中即可见）；都不勾选 = 全员可见。已读情况只统计可见范围内的员工。管理员始终可见全部公告。</p>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $announcement ? '保存修改' : '发布公告' ?></button>
            <a href="/announcements/" class="btn btn-secondary">取消</a>
        </div>
    </form>
</div>

<script src="<?= assetUrl('/assets/js/markdown-editor.js') ?>"></script>

<?php renderFooter(); ?>

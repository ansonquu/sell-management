<?php

/**
 * 优惠券发放：从会员列表中选择（支持搜索、多选），按人发放。
 */

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/db.php';

$pdo = getDb();
$templateId = $_GET['id'] ?? '';

if (!is_numeric($templateId)) {
    flash('error', '无效的优惠券ID');
    redirect('/coupons/index.php');
}

$stmt = $pdo->prepare('SELECT * FROM coupon_templates WHERE id = ?');
$stmt->execute([(int) $templateId]);
$template = $stmt->fetch();

if (!$template) {
    flash('error', '优惠券模板不存在');
    redirect('/coupons/index.php');
}

$errors = [];

/* ---------- 会员列表（支持搜索） ---------- */
$memberKeyword = trim((string) ($_GET['q'] ?? ''));
$memberSql = 'SELECT id, name, phone, student_no, level FROM member WHERE status = ' . STATUS_NORMAL;
$memberParams = [];
if ($memberKeyword !== '') {
    $memberSql .= ' AND (name LIKE ? OR phone LIKE ? OR student_no LIKE ?)';
    $memberParams = ["%{$memberKeyword}%", "%{$memberKeyword}%", "%{$memberKeyword}%"];
}
$memberSql .= ' ORDER BY create_time DESC LIMIT 200';
$memberStmt = $pdo->prepare($memberSql);
$memberStmt->execute($memberParams);
$members = $memberStmt->fetchAll();

/* ---------- 发放 ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $memberIds = array_values(array_unique(array_filter(
        array_map('intval', (array) ($_POST['member_ids'] ?? [])),
        static fn (int $value): bool => $value > 0
    )));
    $issueCount = (int) ($_POST['issue_count'] ?? 1);

    if ($memberIds === []) {
        $errors[] = '请至少选择一位会员';
    }
    if ($issueCount < 1 || $issueCount > 10) {
        $errors[] = '每人发放数量需在 1 ~ 10 之间';
    }

    $totalToIssue = count($memberIds) * $issueCount;
    $available = (int) $template['total_quantity'] > 0
        ? (int) $template['total_quantity'] - (int) $template['issued_quantity']
        : null;
    if (!$errors && $available !== null && $totalToIssue > $available) {
        $errors[] = '超出发行数量限制，最多还能发放 ' . max(0, $available) . ' 张';
    }

    if (!$errors) {
        $staff = getCurrentStaff();
        $operator = (string) ($staff['name'] ?? '');
        $issuedNames = [];
        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare('INSERT INTO member_coupons (template_id, member_id, code, expire_at, operator, created_at) VALUES (?, ?, ?, ?, ?, ?)');
            $expireAt = date('Y-m-d H:i:s', strtotime('+' . (int) $template['expire_days'] . ' days'));
            $nameStmt = $pdo->prepare('SELECT name FROM member WHERE id = ?');

            foreach ($memberIds as $memberId) {
                $nameStmt->execute([$memberId]);
                $memberName = (string) ($nameStmt->fetchColumn() ?: ('#' . $memberId));
                $issuedNames[] = $memberName;

                for ($i = 0; $i < $issueCount; $i++) {
                    $code = 'CP' . date('Ymd') . str_pad((string) mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
                    $insert->execute([(int) $templateId, $memberId, $code, $expireAt, $operator, currentDateTime()]);
                }
            }

            $pdo->prepare('UPDATE coupon_templates SET issued_quantity = issued_quantity + ? WHERE id = ?')
                ->execute([$totalToIssue, (int) $templateId]);

            logOperation($pdo, 'coupon_issue', 'coupon', (int) $templateId, '发放优惠券：' . $template['name'] . ' 给 ' . implode('、', $issuedNames), [
                'template_id' => (int) $templateId,
                'template_name' => $template['name'],
                'member_ids' => $memberIds,
                'member_names' => $issuedNames,
                'per_member' => $issueCount,
                'total' => $totalToIssue,
            ]);
            $pdo->commit();

            flash('success', '已向 ' . count($memberIds) . ' 位会员各发放 ' . $issueCount . ' 张《' . $template['name'] . '》（共 ' . $totalToIssue . ' 张）');
            redirect('/coupons/issue.php?id=' . (int) $templateId);
        } catch (Throwable $error) {
            $pdo->rollBack();
            $errors[] = '发放失败，请重试';
        }
    }
}

renderHeader('发放优惠券 - Anson咖啡厅管理系统', 'coupons');
?>

<div class="page-header">
    <h2 class="page-title">发放优惠券 - <?= htmlspecialchars($template['name']) ?></h2>
    <div class="page-actions">
        <a href="/coupons/index.php" class="btn btn-secondary">返回列表</a>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="grid-2">
    <div class="card">
        <h3>优惠券信息</h3>
        <div class="coupon-preview">
            <div class="coupon-preview-header">
                <span class="badge <?= $template['status'] === 'active' ? 'badge-success' : 'badge-danger' ?>">
                    <?= $template['status'] === 'active' ? '启用' : '停用' ?>
                </span>
                <span class="coupon-type"><?= $template['type'] === 'fixed' ? '代金券' : '折扣券' ?></span>
            </div>
            <h4><?= htmlspecialchars($template['name']) ?></h4>
            <div class="coupon-preview-value">
                <?php if ($template['type'] === 'fixed'): ?>
                    <span class="coupon-amount">¥<?= $template['value'] ?></span>
                <?php else: ?>
                    <span class="coupon-amount"><?= $template['value'] ?>折</span>
                <?php endif; ?>
                <?php if ($template['min_amount'] > 0): ?>
                    <span class="coupon-condition">满<?= $template['min_amount'] ?>可用</span>
                <?php endif; ?>
            </div>
            <div class="coupon-preview-meta">
                <span>有效期：<?= (int) $template['expire_days'] ?>天</span>
                <span>已发放：<?= (int) $template['issued_quantity'] ?> / <?= (int) $template['total_quantity'] > 0 ? (int) $template['total_quantity'] : '不限量' ?></span>
            </div>
        </div>
    </div>

    <div class="card">
        <h3>发放表单</h3>
        <form method="get" class="search-bar" style="margin-bottom:0.9rem">
            <input type="hidden" name="id" value="<?= (int) $template['id'] ?>">
            <input type="text" name="q" value="<?= htmlspecialchars($memberKeyword) ?>" placeholder="搜索姓名、手机号或学号...">
            <button type="submit" class="btn btn-secondary">搜索会员</button>
            <?php if ($memberKeyword !== ''): ?><a href="/coupons/issue.php?id=<?= (int) $template['id'] ?>" class="btn btn-link">清除</a><?php endif; ?>
        </form>

        <form method="post" class="form">
            <?= csrfField() ?>
            <div class="form-group">
                <label>选择会员 <span class="required">*</span>（可多选）</label>
                <div class="issue-member-list">
                    <?php if (!$members): ?>
                        <p class="text-muted" style="margin:0">没有匹配的会员</p>
                    <?php endif; ?>
                    <?php foreach ($members as $m): ?>
                        <label class="issue-member-row">
                            <input type="checkbox" name="member_ids[]" value="<?= (int) $m['id'] ?>">
                            <span class="issue-member-name"><?= htmlspecialchars((string) $m['name']) ?></span>
                            <span class="issue-member-meta"><?= htmlspecialchars((string) ($m['phone'] ?: '无手机号')) ?> · <?= htmlspecialchars((string) ($m['student_no'] ?: '无学号')) ?></span>
                            <span class="badge badge-level"><?= htmlspecialchars((string) $m['level']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <small class="text-muted">共 <?= count($members) ?> 位可选会员；每张券的有效期按模板设置自动计算。</small>
            </div>

            <div class="form-group">
                <label>每人发放数量 <span class="required">*</span></label>
                <input type="number" name="issue_count" value="1" min="1" max="10" required>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">确认发放</button>
                <a href="/coupons/index.php" class="btn btn-secondary">返回</a>
            </div>
        </form>
    </div>
</div>

<?php renderFooter(); ?>

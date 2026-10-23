<?php
$formSettings = $formSettings ?? defaultSystemSettings();
$redemption = $formSettings['redemption'] ?? ['enabled' => true, 'items' => []];
$recordFields = $formSettings['consumption_record_fields'] ?? defaultSystemSettings()['consumption_record_fields'];
$memberFields = $formSettings['member_record_fields'] ?? defaultSystemSettings()['member_record_fields'];
?>
<style>
/* 设置表单样式已统一迁移至 /assets/css/style.css（视觉主题 v2） */
</style>

<section class="settings-section" style="margin-top:0;padding-top:0;border-top:0">
    <h3>网站信息</h3>
    <p class="settings-help">网站名称会显示在导航栏和浏览器标题中；图标会显示在浏览器标签页。</p>
    <div class="form-group"><label>网站名称 <span class="required">*</span></label><input name="site_name" value="<?= htmlspecialchars((string) ($formSettings['site_name'] ?? getSiteName())) ?>" maxlength="60" required></div>
    <div class="form-group"><label>Favicon 网站图标</label><input type="file" name="favicon" accept=".png,.jpg,.jpeg,.webp,.gif,.ico,image/png,image/jpeg,image/webp,image/gif,image/x-icon"><p class="settings-help">支持 PNG、JPG、WebP、GIF、ICO，最大 2MB。建议使用正方形图片。</p></div>
    <div style="display:flex;align-items:center;gap:.75rem;margin:.75rem 0"><img src="<?= htmlspecialchars(getFaviconUrl()) ?>" alt="当前网站图标" width="40" height="40" style="object-fit:contain;border:1px solid var(--border);border-radius:.4rem;padding:.2rem"><span class="settings-help">当前图标</span></div>
    <?php if (($formSettings['favicon_path'] ?? defaultSystemSettings()['favicon_path']) !== defaultSystemSettings()['favicon_path']): ?><label class="checkbox-line"><input type="checkbox" name="remove_favicon" value="1"> 恢复默认网站图标</label><?php endif; ?>
</section>

<section class="settings-section">
    <h3>饮品与价格</h3>
    <p class="settings-help">消费登记会使用这里的饮品和单价。用 ↑ ↓ 可调整饮品顺序，消费登记页会按此顺序排列。</p>
    <div id="menuRows">
        <?php foreach ($formSettings['menu_items'] as $item): ?>
            <div class="settings-row menu-row">
                <div class="form-group"><label>饮品名称</label><input name="menu_name[]" value="<?= htmlspecialchars($item['name']) ?>" required></div>
                <div class="form-group"><label>价格（元）</label><input type="number" name="menu_price[]" value="<?= htmlspecialchars((string) $item['price']) ?>" min="0.01" step="0.01" required></div>
                <div class="row-actions">
                    <button type="button" class="btn btn-sm btn-secondary move-row" data-move="up" title="上移">↑</button>
                    <button type="button" class="btn btn-sm btn-secondary move-row" data-move="down" title="下移">↓</button>
                    <button type="button" class="btn btn-danger remove-row">删除</button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-secondary" data-add-row="menu">+ 添加饮品</button>
</section>

<section class="settings-section">
    <h3>会员等级</h3>
    <p class="settings-help">最低等级门槛必须为 0 元；折扣填写 10 表示原价，9 表示九折。</p>
    <div id="levelRows">
        <?php foreach ($formSettings['member_levels'] as $level): ?>
            <div class="settings-row level-row">
                <div class="form-group"><label>等级名称</label><input name="level_name[]" value="<?= htmlspecialchars($level['name']) ?>" required></div>
                <div class="form-group"><label>累计消费（元）</label><input type="number" name="level_min_spent[]" value="<?= htmlspecialchars((string) $level['min_spent']) ?>" min="0" step="0.01" required></div>
                <div class="form-group"><label>折扣（折）</label><input type="number" name="level_discount[]" value="<?= htmlspecialchars((string) ($level['discount'] * 10)) ?>" min="0.1" max="10" step="0.1" required></div>
                <button type="button" class="btn btn-danger remove-row">删除</button>
            </div>
        <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-secondary" data-add-row="level">+ 添加等级</button>
</section>

<section class="settings-section">
    <h3>积分规则</h3>
    <div class="form-group"><label>每消费 1 元获得积分</label><input type="number" name="points_per_yuan" value="<?= htmlspecialchars((string) $formSettings['points_per_yuan']) ?>" min="0" step="0.01" required></div>
    <label class="checkbox-line"><input type="checkbox" name="redemption_enabled" value="1" <?= !empty($redemption['enabled']) ? 'checked' : '' ?>> 开启积分兑换功能</label>
    <p class="settings-help">实物和无实物项目都在后台预设固定积分，并可自由添加或删除；用 ↑ ↓ 可调整顺序，积分兑换页会按此顺序显示。</p>
    <div id="redeemRows">
        <?php foreach (($redemption['items'] ?? []) as $item): ?>
            <div class="settings-row redeem-row">
                <?php if (trim((string) ($item['cost_product'] ?? '')) === ''): ?>
                    <div class="form-group"><label>无实物兑换名称</label><input name="redeem_name[]" value="<?= htmlspecialchars((string) ($item['name'] ?? '')) ?>" placeholder="例如：优惠券、周边礼品" required><input type="hidden" name="redeem_type[]" value="non_physical"><input type="hidden" name="redeem_cost_product[]" value=""></div>
                    <div class="form-group"><label>所需积分</label><input type="number" name="redeem_points[]" value="<?= (int) $item['points'] ?>" min="1" step="1" required></div>
                    <div class="row-actions">
                        <button type="button" class="btn btn-sm btn-secondary move-row" data-move="up" title="上移">↑</button>
                        <button type="button" class="btn btn-sm btn-secondary move-row" data-move="down" title="下移">↓</button>
                        <button type="button" class="btn btn-danger remove-row">删除</button>
                    </div>
                <?php else: ?>
                    <div class="form-group"><label>实际兑换商品</label><select name="redeem_cost_product[]"><?php foreach (($formSettings['menu_items'] ?? []) as $menuItem): $menuName = (string) $menuItem['name']; ?><option value="<?= htmlspecialchars($menuName) ?>" <?= ($item['cost_product'] ?? '') === $menuName ? 'selected' : '' ?>><?= htmlspecialchars($menuName) ?></option><?php endforeach; ?></select><input type="hidden" name="redeem_type[]" value="product"><input type="hidden" name="redeem_name[]" value=""></div>
                    <div class="form-group"><label>所需积分</label><input type="number" name="redeem_points[]" value="<?= (int) $item['points'] ?>" min="1" step="1"></div>
                    <div class="row-actions">
                        <button type="button" class="btn btn-sm btn-secondary move-row" data-move="up" title="上移">↑</button>
                        <button type="button" class="btn btn-sm btn-secondary move-row" data-move="down" title="下移">↓</button>
                        <button type="button" class="btn btn-danger remove-row">删除</button>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-secondary" data-add-row="redeem_product">+ 添加实物商品</button>
    <button type="button" class="btn btn-secondary" data-add-row="redeem_nonphysical">+ 添加无实物项目</button>
</section>

<section class="settings-section">
    <h3>支出分类</h3>
    <p class="settings-help">财务管理-支出登记会使用这里的分类。可修改名称或添加新分类；删除后历史支出记录仍保留，但会按原分类名显示。</p>
    <div id="expenseCatRows">
        <?php foreach (($formSettings['expense_categories'] ?? []) as $category): ?>
            <div class="settings-row expense-cat-row">
                <div class="form-group"><label>分类名称</label><input name="expense_cat_name[]" value="<?= htmlspecialchars((string) ($category['name'] ?? '')) ?>" required></div>
                <input type="hidden" name="expense_cat_key[]" value="<?= htmlspecialchars((string) ($category['key'] ?? '')) ?>">
                <button type="button" class="btn btn-danger remove-row">删除</button>
            </div>
        <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-secondary" data-add-row="expense_cat">+ 添加分类</button>
</section>

<section class="settings-section">
    <h3>签到职位</h3>
    <p class="settings-help">签到名单中人员的职位选项；在“签到管理”配置名单时从这些职位中选择。</p>
    <div id="positionRows">
        <?php foreach (($formSettings['attendance_positions'] ?? []) as $position): ?>
            <div class="settings-row expense-cat-row">
                <div class="form-group"><label>职位名称</label><input name="attendance_positions[]" value="<?= htmlspecialchars((string) $position) ?>" required></div>
                <button type="button" class="btn btn-danger remove-row">删除</button>
            </div>
        <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-secondary" data-add-row="attendance_position">+ 添加职位</button>
</section>

<section class="settings-section">
    <h3>会员列表显示字段</h3>
    <p class="settings-help">选择会员列表页面需要显示的字段（余额为默认显示字段）；未勾选的字段不会出现在列表中。</p>
    <div class="settings-field-grid">
        <?php foreach (getMemberRecordFieldOptions() as $field => $label): ?>
            <label class="checkbox-line">
                <input type="checkbox" name="member_record_fields[]" value="<?= htmlspecialchars($field) ?>" <?= in_array($field, $memberFields, true) ? 'checked' : '' ?>>
                <?= htmlspecialchars($label) ?>
            </label>
        <?php endforeach; ?>
    </div>
</section>

<section class="settings-section">
    <h3>消费记录显示字段</h3>
    <p class="settings-help">选择消费记录页面需要显示的字段；未勾选的字段不会出现在列表中。</p>
    <div class="settings-field-grid">
        <?php foreach (getConsumptionRecordFieldOptions() as $field => $label): ?>
            <label class="checkbox-line">
                <input type="checkbox" name="consumption_record_fields[]" value="<?= htmlspecialchars($field) ?>" <?= in_array($field, $recordFields, true) ? 'checked' : '' ?>>
                <?= htmlspecialchars($label) ?>
            </label>
        <?php endforeach; ?>
    </div>
</section>

<script>
(function () {
    const templates = {
        menu: '<div class="settings-row menu-row"><div class="form-group"><label>饮品名称</label><input name="menu_name[]" required></div><div class="form-group"><label>价格（元）</label><input type="number" name="menu_price[]" min="0.01" step="0.01" required></div><div class="row-actions"><button type="button" class="btn btn-sm btn-secondary move-row" data-move="up" title="上移">↑</button><button type="button" class="btn btn-sm btn-secondary move-row" data-move="down" title="下移">↓</button><button type="button" class="btn btn-danger remove-row">删除</button></div></div>',
        level: '<div class="settings-row level-row"><div class="form-group"><label>等级名称</label><input name="level_name[]" required></div><div class="form-group"><label>累计消费（元）</label><input type="number" name="level_min_spent[]" min="0" step="0.01" required></div><div class="form-group"><label>折扣（折）</label><input type="number" name="level_discount[]" value="10" min="0.1" max="10" step="0.1" required></div><div class="row-actions"><button type="button" class="btn btn-sm btn-secondary move-row" data-move="up" title="上移">↑</button><button type="button" class="btn btn-sm btn-secondary move-row" data-move="down" title="下移">↓</button><button type="button" class="btn btn-danger remove-row">删除</button></div></div>',
        redeem_product: <?= json_encode('<div class="settings-row redeem-row"><div class="form-group"><label>实际兑换商品</label><select name="redeem_cost_product[]">' . implode('', array_map(static fn (array $item): string => '<option value="' . htmlspecialchars((string) $item['name'], ENT_QUOTES) . '">' . htmlspecialchars((string) $item['name']) . '</option>', $formSettings['menu_items'] ?? [])) . '</select><input type="hidden" name="redeem_type[]" value="product"><input type="hidden" name="redeem_name[]" value=""></div><div class="form-group"><label>所需积分</label><input type="number" name="redeem_points[]" min="1" step="1" required></div><div class="row-actions"><button type="button" class="btn btn-sm btn-secondary move-row" data-move="up" title="上移">↑</button><button type="button" class="btn btn-sm btn-secondary move-row" data-move="down" title="下移">↓</button><button type="button" class="btn btn-danger remove-row">删除</button></div></div>', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        redeem_nonphysical: '<div class="settings-row redeem-row"><div class="form-group"><label>无实物兑换名称</label><input name="redeem_name[]" placeholder="例如：优惠券、周边礼品" required><input type="hidden" name="redeem_type[]" value="non_physical"><input type="hidden" name="redeem_cost_product[]" value=""></div><div class="form-group"><label>所需积分</label><input type="number" name="redeem_points[]" min="1" step="1" required></div><div class="row-actions"><button type="button" class="btn btn-sm btn-secondary move-row" data-move="up" title="上移">↑</button><button type="button" class="btn btn-sm btn-secondary move-row" data-move="down" title="下移">↓</button><button type="button" class="btn btn-danger remove-row">删除</button></div></div>',
        expense_cat: '<div class="settings-row expense-cat-row"><div class="form-group"><label>分类名称</label><input name="expense_cat_name[]" required></div><input type="hidden" name="expense_cat_key[]" value=""><button type="button" class="btn btn-danger remove-row">删除</button></div>',
        attendance_position: '<div class="settings-row expense-cat-row"><div class="form-group"><label>职位名称</label><input name="attendance_positions[]" required></div><button type="button" class="btn btn-danger remove-row">删除</button></div>'
    };
    const containers = {menu: document.getElementById('menuRows'), level: document.getElementById('levelRows'), redeem_product: document.getElementById('redeemRows'), redeem_nonphysical: document.getElementById('redeemRows'), expense_cat: document.getElementById('expenseCatRows'), attendance_position: document.getElementById('positionRows')};
    document.addEventListener('click', function (event) {
        const addButton = event.target.closest('[data-add-row]');
        if (addButton) {
            containers[addButton.dataset.addRow].insertAdjacentHTML('beforeend', templates[addButton.dataset.addRow]);
            return;
        }
        const moveButton = event.target.closest('.move-row');
        if (moveButton) {
            const row = moveButton.closest('.settings-row');
            if (moveButton.dataset.move === 'up' && row.previousElementSibling) {
                row.parentNode.insertBefore(row, row.previousElementSibling);
            } else if (moveButton.dataset.move === 'down' && row.nextElementSibling) {
                row.parentNode.insertBefore(row.nextElementSibling, row);
            }
            return;
        }
        const removeButton = event.target.closest('.remove-row');
        if (removeButton) removeButton.closest('.settings-row').remove();
    });
})();
</script>

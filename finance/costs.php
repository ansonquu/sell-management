<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
requireLogin();
requireFinance();

$pdo = getDb();
$staff = getCurrentStaff() ?? [];
$errors = [];
$menuItems = getMenuItems();
$menuNames = array_values(array_map(fn (array $item): string => (string) $item['name'], $menuItems));
$selectedProduct = trim((string) ($_POST['product_name'] ?? $_GET['product'] ?? ($menuNames[0] ?? '')));
if (!in_array($selectedProduct, $menuNames, true)) {
    $selectedProduct = $menuNames[0] ?? '';
}

$ingredientMap = getCostIngredientMap($pdo);
$formAction = (string) ($_POST['form_action'] ?? 'save_recipe');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyFinanceCsrf()) {
        $errors[] = '页面已过期，请刷新后重试';
    } elseif ($formAction === 'save_ingredient') {
        $ingredientId = (int) ($_POST['ingredient_id'] ?? 0);
        $name = trim((string) ($_POST['ingredient_name'] ?? ''));
        $unit = trim((string) ($_POST['ingredient_unit'] ?? ''));
        $unitPrice = round((float) ($_POST['ingredient_unit_price'] ?? 0), 4);
        if ($name === '') {
            $errors[] = '请输入成分名称';
        }
        if ($unit === '') {
            $errors[] = '请输入计价单位';
        }
        if ($unitPrice < 0) {
            $errors[] = '成分单价不能小于 0';
        }
        $duplicate = $pdo->prepare('SELECT id FROM cost_ingredients WHERE name = ? AND id != ?');
        $duplicate->execute([$name, $ingredientId]);
        if ($name !== '' && $duplicate->fetchColumn()) {
            $errors[] = '该成分已经存在，请直接编辑原记录';
        }

        if (!$errors) {
            $encrypted = encryptFinanceData(['unit' => $unit, 'unit_price' => $unitPrice]);
            if ($ingredientId > 0 && isset($ingredientMap[$ingredientId])) {
                $stmt = $pdo->prepare('UPDATE cost_ingredients SET name = ?, encrypted_details = ?, operator_username = ?, operator_name = ?, updated_at = ? WHERE id = ?');
                $stmt->execute([$name, $encrypted, $staff['username'] ?? '', $staff['name'] ?? '', currentDateTime(), $ingredientId]);
                $action = 'cost_ingredient_update';
            } else {
                $stmt = $pdo->prepare('INSERT INTO cost_ingredients (name, encrypted_details, operator_username, operator_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$name, $encrypted, $staff['username'] ?? '', $staff['name'] ?? '', currentDateTime(), currentDateTime()]);
                $ingredientId = (int) $pdo->lastInsertId();
                $action = 'cost_ingredient_create';
            }
            logOperation($pdo, $action, 'cost_ingredient', $ingredientId, '保存成本成分：' . $name, ['name' => $name, 'unit' => $unit]);
            flash('success', '成分“' . $name . '”已保存，以后配置产品时可以直接选择');
            redirect('/finance/costs.php?product=' . urlencode($selectedProduct));
        }
    } elseif ($formAction === 'delete_ingredient') {
        $ingredientId = (int) ($_POST['ingredient_id'] ?? 0);
        if (!isset($ingredientMap[$ingredientId])) {
            $errors[] = '要删除的成分不存在';
        } else {
            $ingredientName = (string) $ingredientMap[$ingredientId]['name'];
            $stmt = $pdo->prepare('DELETE FROM cost_ingredients WHERE id = ?');
            $stmt->execute([$ingredientId]);
            logOperation($pdo, 'cost_ingredient_delete', 'cost_ingredient', $ingredientId, '删除成本成分：' . $ingredientName, ['name' => $ingredientName]);
            flash('success', '成分“' . $ingredientName . '”已删除；已经保存的产品配方和历史订单不受影响');
            redirect('/finance/costs.php?product=' . urlencode($selectedProduct));
        }
    } else {
        if (!in_array($selectedProduct, $menuNames, true)) {
            $errors[] = '请选择系统菜单中存在的产品';
        }
        $ingredientIds = $_POST['component_ingredient_id'] ?? [];
        $quantities = $_POST['component_quantity'] ?? [];
        $components = [];
        $unitCost = 0.0;
        $usedIngredientIds = [];
        foreach ($ingredientIds as $index => $rawIngredientId) {
            $ingredientId = (int) $rawIngredientId;
            $quantity = round((float) ($quantities[$index] ?? 0), 4);
            if (!isset($ingredientMap[$ingredientId])) {
                $errors[] = '请选择成分库中存在的成分';
                continue;
            }
            $ingredient = $ingredientMap[$ingredientId];
            $name = (string) $ingredient['name'];
            if (isset($usedIngredientIds[$ingredientId])) {
                $errors[] = '成分“' . $name . '”不能重复选择';
                continue;
            }
            if ($quantity <= 0) {
                $errors[] = $name . '的单杯用量必须大于 0';
                continue;
            }
            $usedIngredientIds[$ingredientId] = true;
            $unitPrice = (float) $ingredient['unit_price'];
            $subtotal = round($quantity * $unitPrice, 4);
            $components[] = [
                'ingredient_id' => $ingredientId,
                'name' => $name,
                'quantity' => $quantity,
                'unit' => (string) $ingredient['unit'],
                'unit_price' => $unitPrice,
                'subtotal' => $subtotal,
            ];
            $unitCost += $subtotal;
        }
        $unitCost = round($unitCost, 4);
        if (!$components) {
            $errors[] = $ingredientMap ? '请至少选择一项产品成分' : '请先在成分库中添加成分';
        }

        if (!$errors) {
            try {
                $encrypted = encryptFinanceData(['components' => $components]);
                $existing = getProductCostRecipe($pdo, $selectedProduct);
                if ($existing) {
                    $stmt = $pdo->prepare('UPDATE product_costs SET unit_cost = ?, encrypted_components = ?, operator_username = ?, operator_name = ?, updated_at = ? WHERE id = ?');
                    $stmt->execute([$unitCost, $encrypted, $staff['username'] ?? '', $staff['name'] ?? '', currentDateTime(), (int) $existing['id']]);
                    $recipeId = (int) $existing['id'];
                    $action = 'product_cost_update';
                } else {
                    $stmt = $pdo->prepare('INSERT INTO product_costs (product_name, unit_cost, encrypted_components, operator_username, operator_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$selectedProduct, $unitCost, $encrypted, $staff['username'] ?? '', $staff['name'] ?? '', currentDateTime(), currentDateTime()]);
                    $recipeId = (int) $pdo->lastInsertId();
                    $action = 'product_cost_create';
                }
                logOperation($pdo, $action, 'product_cost', $recipeId, '保存产品成本：' . $selectedProduct . '，单杯成本 ' . formatMoney($unitCost), ['product_name' => $selectedProduct, 'unit_cost' => $unitCost, 'component_count' => count($components)]);
                flash('success', $selectedProduct . '的成本配方已保存，单杯成本 ' . formatMoney($unitCost));
                redirect('/finance/costs.php?product=' . urlencode($selectedProduct));
            } catch (Throwable $e) {
                $errors[] = '成本配方保存失败：' . $e->getMessage();
            }
        }
    }
}

$ingredients = getCostIngredients($pdo);
$ingredientMap = [];
$ingredientIdByName = [];
foreach ($ingredients as $ingredient) {
    $ingredientMap[(int) $ingredient['id']] = $ingredient;
    $ingredientIdByName[(string) $ingredient['name']] = (int) $ingredient['id'];
}

$recipe = $selectedProduct !== '' ? getProductCostRecipe($pdo, $selectedProduct) : null;
$formComponents = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $formAction === 'save_recipe') {
    foreach (($_POST['component_ingredient_id'] ?? []) as $index => $ingredientId) {
        $formComponents[] = [
            'ingredient_id' => (int) $ingredientId,
            'quantity' => (float) ($_POST['component_quantity'][$index] ?? 0),
        ];
    }
} else {
    foreach (($recipe['components'] ?? []) as $component) {
        $component['ingredient_id'] = (int) ($component['ingredient_id'] ?? ($ingredientIdByName[(string) ($component['name'] ?? '')] ?? 0));
        $formComponents[] = $component;
    }
}
if (!$formComponents) {
    $formComponents = [['ingredient_id' => 0, 'quantity' => 1]];
}

$editingIngredientId = (int) ($_GET['edit_ingredient'] ?? ($_POST['ingredient_id'] ?? 0));
$editingIngredient = $ingredientMap[$editingIngredientId] ?? null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $formAction === 'save_ingredient' && $errors) {
    $editingIngredient = [
        'id' => $editingIngredientId,
        'name' => (string) ($_POST['ingredient_name'] ?? ''),
        'unit' => (string) ($_POST['ingredient_unit'] ?? ''),
        'unit_price' => (float) ($_POST['ingredient_unit_price'] ?? 0),
    ];
}
$recipes = $pdo->query('SELECT product_name, unit_cost, operator_name, updated_at FROM product_costs ORDER BY product_name')->fetchAll();

function renderCostIngredientOptions(array $ingredients, int $selectedId): void
{
    echo '<option value="">请选择成分</option>';
    foreach ($ingredients as $ingredient) {
        $id = (int) $ingredient['id'];
        $label = (string) $ingredient['name'] . '（' . formatMoney((float) $ingredient['unit_price']) . '/' . (string) $ingredient['unit'] . '）';
        echo '<option value="' . $id . '" data-price="' . htmlspecialchars((string) $ingredient['unit_price']) . '" data-unit="' . htmlspecialchars((string) $ingredient['unit']) . '"' . ($selectedId === $id ? ' selected' : '') . '>' . htmlspecialchars($label) . '</option>';
    }
}

renderHeader('成本记录 - 财务管理', 'finance');
?>
<div class="page-header"><h2 class="page-title">成本记录</h2></div>
<?php renderFinanceTabs('costs'); ?>
<?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $error): ?><div><?= htmlspecialchars($error) ?></div><?php endforeach; ?></div><?php endif; ?>

<div class="grid-2">
<div class="card form-card"><h3><?= $editingIngredient ? '编辑成分' : '提前设置成分' ?></h3>
<p class="text-muted">成分只需设置一次。以后为不同产品配置成本时直接选择并填写用量。</p>
<form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeCsrfToken()) ?>"><input type="hidden" name="form_action" value="save_ingredient"><input type="hidden" name="product_name" value="<?= htmlspecialchars($selectedProduct) ?>"><input type="hidden" name="ingredient_id" value="<?= (int) ($editingIngredient['id'] ?? 0) ?>">
<div class="form-group"><label>成分名称 <span class="required">*</span></label><input name="ingredient_name" required value="<?= htmlspecialchars((string) ($editingIngredient['name'] ?? '')) ?>" placeholder="例如：咖啡豆"></div>
<div class="form-group"><label>计价单位 <span class="required">*</span></label><input name="ingredient_unit" required value="<?= htmlspecialchars((string) ($editingIngredient['unit'] ?? '克')) ?>" placeholder="克、毫升、个、份"></div>
<div class="form-group"><label>单价（元/计价单位） <span class="required">*</span></label><input type="number" name="ingredient_unit_price" min="0" step="0.0001" required value="<?= htmlspecialchars((string) ($editingIngredient['unit_price'] ?? 0)) ?>"></div>
<button class="btn btn-primary" type="submit"><?= $editingIngredient ? '保存修改' : '添加到成分库' ?></button><?php if ($editingIngredient): ?> <a class="btn btn-secondary" href="/finance/costs.php?product=<?= urlencode($selectedProduct) ?>">取消编辑</a><?php endif; ?>
</form></div>
<div class="card"><h3>成分库</h3><table class="table"><thead><tr><th>成分</th><th>单位</th><th>单价</th><th>操作</th></tr></thead><tbody><?php if (!$ingredients): ?><tr><td colspan="4" class="text-center text-muted">请先添加常用成分</td></tr><?php endif; ?><?php foreach ($ingredients as $ingredient): ?><tr><td><?= htmlspecialchars($ingredient['name']) ?></td><td><?= htmlspecialchars($ingredient['unit']) ?></td><td><?= formatMoney((float) $ingredient['unit_price']) ?>/<?= htmlspecialchars($ingredient['unit']) ?></td><td><a class="btn btn-sm btn-secondary" href="/finance/costs.php?product=<?= urlencode($selectedProduct) ?>&edit_ingredient=<?= (int) $ingredient['id'] ?>">编辑</a> <form method="post" style="display:inline" onsubmit="return confirm('删除后不能再选择该成分，确定删除吗？')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeCsrfToken()) ?>"><input type="hidden" name="form_action" value="delete_ingredient"><input type="hidden" name="product_name" value="<?= htmlspecialchars($selectedProduct) ?>"><input type="hidden" name="ingredient_id" value="<?= (int) $ingredient['id'] ?>"><button class="btn btn-sm btn-danger" type="submit">删除</button></form></td></tr><?php endforeach; ?></tbody></table></div>
</div>

<div class="card form-card"><h3>产品成本配方</h3>
<?php if (!$menuItems): ?><p class="text-muted">请先在系统设置中添加产品。</p><?php elseif (!$ingredients): ?><p class="text-muted">请先在上方成分库中添加成分，再配置产品成本。</p><?php else: ?><form method="post" id="costForm"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeCsrfToken()) ?>"><input type="hidden" name="form_action" value="save_recipe">
<div class="form-group"><label>产品 <span class="required">*</span></label><select name="product_name" id="productSelect" required><?php foreach ($menuItems as $item): ?><option value="<?= htmlspecialchars($item['name']) ?>" <?= $selectedProduct === $item['name'] ? 'selected' : '' ?>><?= htmlspecialchars($item['name']) ?>（售价 <?= formatMoney((float) $item['price']) ?>）</option><?php endforeach; ?></select></div>
<div id="componentRows" class="cost-component-list"><?php foreach ($formComponents as $component): $selectedIngredientId = (int) ($component['ingredient_id'] ?? 0); ?><div class="cost-component-row"><div class="form-group"><label>选择成分</label><select name="component_ingredient_id[]" required><?php renderCostIngredientOptions($ingredients, $selectedIngredientId); ?></select></div><div class="form-group"><label>单杯用量</label><input type="number" name="component_quantity[]" value="<?= htmlspecialchars((string) ($component['quantity'] ?? 1)) ?>" min="0.0001" step="0.0001" required></div><div class="form-group"><label>已设单价</label><div class="ingredient-price text-muted">-</div></div><button type="button" class="btn btn-danger remove-component">删除</button></div><?php endforeach; ?></div>
<button type="button" class="btn btn-secondary" id="addComponent">+ 选择更多成分</button><div class="cost-total-box">单杯成本合计：<strong id="unitCostPreview"><?= formatMoney((float) ($recipe['unit_cost'] ?? 0)) ?></strong></div><button class="btn btn-primary" type="submit">保存成本配方</button>
</form><?php endif; ?></div>

<div class="card"><h3>已设置产品成本</h3><table class="table"><thead><tr><th>产品</th><th>单杯成本</th><th>维护人</th><th>更新时间</th><th>操作</th></tr></thead><tbody><?php if (!$recipes): ?><tr><td colspan="5" class="text-center text-muted">暂未设置成本配方</td></tr><?php endif; ?><?php foreach ($recipes as $row): ?><tr><td><?= htmlspecialchars($row['product_name']) ?></td><td><?= formatMoney((float) $row['unit_cost']) ?></td><td><?= htmlspecialchars($row['operator_name'] ?: '-') ?></td><td><?= formatDateTime($row['updated_at']) ?></td><td><a class="btn btn-sm btn-secondary" href="/finance/costs.php?product=<?= urlencode($row['product_name']) ?>">编辑配方</a></td></tr><?php endforeach; ?></tbody></table></div>
<template id="componentRowTemplate"><div class="cost-component-row"><div class="form-group"><label>选择成分</label><select name="component_ingredient_id[]" required><?php renderCostIngredientOptions($ingredients, 0); ?></select></div><div class="form-group"><label>单杯用量</label><input type="number" name="component_quantity[]" value="1" min="0.0001" step="0.0001" required></div><div class="form-group"><label>已设单价</label><div class="ingredient-price text-muted">-</div></div><button type="button" class="btn btn-danger remove-component">删除</button></div></template>
<script>
const componentRows=document.getElementById('componentRows');
function updateCostTotal(){let total=0;if(!componentRows)return;componentRows.querySelectorAll('.cost-component-row').forEach(row=>{const select=row.querySelector('[name="component_ingredient_id[]"]');const option=select.options[select.selectedIndex];const quantity=parseFloat(row.querySelector('[name="component_quantity[]"]').value)||0;const price=parseFloat(option?.dataset.price)||0;const unit=option?.dataset.unit||'';total+=quantity*price;row.querySelector('.ingredient-price').textContent=option?.value?`¥${price.toFixed(4)}/${unit}`:'-';});document.getElementById('unitCostPreview').textContent='¥'+total.toFixed(4);}
if(componentRows){componentRows.addEventListener('input',updateCostTotal);componentRows.addEventListener('change',updateCostTotal);componentRows.addEventListener('click',event=>{if(event.target.closest('.remove-component')){event.target.closest('.cost-component-row').remove();updateCostTotal();}});document.getElementById('addComponent').addEventListener('click',()=>{componentRows.appendChild(document.getElementById('componentRowTemplate').content.cloneNode(true));updateCostTotal();});document.getElementById('productSelect').addEventListener('change',event=>{location.href='/finance/costs.php?product='+encodeURIComponent(event.target.value);});updateCostTotal();}
</script>
<?php renderFooter(); ?>

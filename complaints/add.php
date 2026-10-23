<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
requireLogin();
requireRegularStaffPermission();

$pdo = getDb();
$consumptionId = (int) ($_GET['consumption_id'] ?? $_POST['consumption_id'] ?? 0);
$stmt = $pdo->prepare("SELECT c.*, m.name AS member_name, m.phone FROM consumption c LEFT JOIN member m ON c.member_id = m.id WHERE c.id = ? AND c.record_type = 'sale' AND c.refunded_at IS NULL AND c.exchanged_at IS NULL");
$stmt->execute([$consumptionId]);
$consumption = $stmt->fetch();
if (!$consumption) {
    flash('error', '原消费记录不存在或不能发起客诉');
    redirect('/consumption/');
}

$originalItems = parseConsumptionItems($consumption['items'] ?? '');
$errors = [];
$resolutionType = (string) ($_POST['resolution_type'] ?? 'full');
$hasTicket = ($_POST['has_ticket'] ?? '0') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyFinanceCsrf()) {
        $errors[] = '页面已过期，请刷新后重试';
    }
    if (!in_array($resolutionType, ['full', 'partial', 'exchange'], true)) {
        $errors[] = '请选择有效的补偿方式';
    }
    $complaintReason = trim((string) ($_POST['complaint_reason'] ?? ''));
    $handlingNote = trim((string) ($_POST['handling_note'] ?? ''));
    $ticketNumber = trim((string) ($_POST['ticket_number'] ?? ''));
    if ($complaintReason === '') {
        $errors[] = '请填写客诉内容';
    }
    if ($hasTicket && $ticketNumber === '') {
        $errors[] = '选择取号后必须填写号牌号码';
    }

    $redoItems = [];
    if ($resolutionType === 'full') {
        $redoItems = $originalItems;
    } elseif ($resolutionType === 'partial') {
        $quantities = $_POST['redo_quantity'] ?? [];
        foreach (array_values($originalItems) as $index => $maxQuantity) {
            $quantity = max(0, (int) ($quantities[$index] ?? 0));
            if ($quantity > (int) $maxQuantity) {
                $errors[] = '部分重做数量不能超过原订单数量';
                break;
            }
            if ($quantity > 0) {
                $name = array_keys($originalItems)[$index];
                $redoItems[$name] = $quantity;
            }
        }
        if (!$redoItems) {
            $errors[] = '部分重做至少需要选择一件原订单商品';
        }
    }

    // 换货：填写换入的商品与金额，系统自动计算差价
    $exchangeItems = '';
    $exchangeAmount = 0.0;
    $exchangePayment = 'cash';
    $difference = 0.0;
    if ($resolutionType === 'exchange') {
        $exchangeItems = trim((string) ($_POST['exchange_items'] ?? ''));
        $exchangeAmount = round((float) ($_POST['exchange_amount'] ?? 0), 2);
        $exchangePayment = (string) ($_POST['exchange_payment'] ?? 'cash');
        if (!in_array($exchangePayment, ['cash', 'wechat', 'alipay'], true)) {
            $exchangePayment = 'cash';
        }
        if ($exchangeItems === '') {
            $errors[] = '请填写换货后的商品';
        }
        if ($exchangeAmount < 0) {
            $errors[] = '换货商品金额不能为负数';
        }
        $difference = round($exchangeAmount - (float) $consumption['amount'], 2);
    }

    if ($resolutionType !== 'exchange' && !$originalItems) {
        $errors[] = '原消费记录没有可识别的商品，无法创建重做单';
    }

    if (!$errors) {
        $staff = getCurrentStaff() ?? [];
        $operatorName = (string) ($staff['name'] ?? '');
        $redoText = $resolutionType === 'exchange' ? $exchangeItems : formatConsumptionItems($redoItems);
        $pdo->beginTransaction();
        try {
            $encryptedDetails = encryptFinanceData([
                'complaint_reason' => $complaintReason,
                'handling_note' => $handlingNote,
            ]);
            $stmt = $pdo->prepare('INSERT INTO complaint_records (consumption_id, resolution_type, redo_items, exchange_amount, difference_amount, has_ticket, ticket_number, encrypted_details, operator_username, operator_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$consumptionId, $resolutionType, $redoText, $resolutionType === 'exchange' ? $exchangeAmount : 0, $resolutionType === 'exchange' ? $difference : 0, $hasTicket ? 1 : 0, $hasTicket ? $ticketNumber : '', $encryptedDetails, $staff['username'] ?? '', $operatorName, currentDateTime()]);
            $complaintId = (int) $pdo->lastInsertId();

            if ($resolutionType === 'exchange') {
                // 换货：补差价计入营业额；退差价登记为支出；等价换货生成 0 金额留痕记录
                $insertConsumption = $pdo->prepare("INSERT INTO consumption (member_id, amount, points_earned, items, note, operator, payment_method, ticket_number, record_type, complaint_id, cost_amount, net_income, prep_status, create_time) VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?, ?, 0, ?, 'pending', ?)");
                if ($difference > 0.009) {
                    $insertConsumption->execute([
                        (int) $consumption['member_id'], $difference, '[换货] ' . $exchangeItems,
                        '原消费 #' . $consumptionId . '，顾客补差价', $operatorName, $exchangePayment,
                        $hasTicket ? $ticketNumber : '', 'sale', $complaintId, $difference, currentDateTime(),
                    ]);
                } else {
                    $note = '原消费 #' . $consumptionId . ($difference < -0.009 ? '，退还差价 ' . formatMoney(-$difference) : '，等价换货');
                    $insertConsumption->execute([
                        (int) $consumption['member_id'], 0, '[换货] ' . $exchangeItems,
                        $note, $operatorName, $exchangePayment,
                        $hasTicket ? $ticketNumber : '', 'exchange', $complaintId, 0, currentDateTime(),
                    ]);
                }
                $redoConsumptionId = (int) $pdo->lastInsertId();

                if ($difference < -0.009) {
                    // 退还差价：登记为资金支出（优先使用专用分类，未配置则归入其他支出）
                    $refundCategory = array_key_exists('exchange_refund', financeExpenseCategories()) ? 'exchange_refund' : 'other';
                    $encryptedPayload = encryptFinanceData(['note' => '换货退差价（原消费 #' . $consumptionId . '）']);
                    $stmt = $pdo->prepare("INSERT INTO finance_expenses (type, category, amount, payment_account, occurred_at, encrypted_payload, status, operator_username, operator_name, created_at) VALUES ('expense', ?, ?, ?, ?, ?, 'active', ?, ?, ?)");
                    $stmt->execute([$refundCategory, -$difference, $exchangePayment, currentDateTime(), $encryptedPayload, $staff['username'] ?? '', $operatorName, currentDateTime()]);
                }

                $pdo->prepare('UPDATE complaint_records SET redo_consumption_id = ? WHERE id = ?')->execute([$redoConsumptionId, $complaintId]);
                $pdo->prepare('UPDATE consumption SET exchanged_at = ? WHERE id = ?')->execute([currentDateTime(), $consumptionId]);

                logOperation($pdo, 'complaint_exchange', 'complaint', $complaintId, '换货处理：原消费 #' . $consumptionId . ' → ' . $exchangeItems . '，差价 ' . formatMoney($difference), [
                    'original_consumption_id' => $consumptionId,
                    'exchange_consumption_id' => $redoConsumptionId,
                    'resolution_type' => 'exchange',
                    'exchange_items' => $exchangeItems,
                    'exchange_amount' => $exchangeAmount,
                    'difference_amount' => $difference,
                ]);
                $pdo->commit();
                flash('success', '换货处理完成：' . ($difference > 0.009
                    ? '已收补差价 ' . formatMoney($difference)
                    : ($difference < -0.009 ? '已退还差价 ' . formatMoney(-$difference) : '等价换货，无差价')));
                redirect('/consumption/');
            }

            $stmt = $pdo->prepare("INSERT INTO consumption (member_id, amount, points_earned, items, note, operator, payment_method, ticket_number, record_type, complaint_id, cost_amount, net_income, prep_status, create_time) VALUES (?, 0, 0, ?, ?, ?, 'complaint', ?, 'complaint_redo', ?, 0, 0, 'pending', ?)");
            $stmt->execute([(int) $consumption['member_id'], '[客诉重做] ' . $redoText, '原消费记录 #' . $consumptionId, $operatorName, $hasTicket ? $ticketNumber : '', $complaintId, currentDateTime()]);
            $redoConsumptionId = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE complaint_records SET redo_consumption_id = ? WHERE id = ?')->execute([$redoConsumptionId, $complaintId]);

            logOperation($pdo, 'complaint_create', 'complaint', $complaintId, '处理客诉：' . complaintResolutionLabel($resolutionType) . '，生成补偿消费记录 #' . $redoConsumptionId, [
                'original_consumption_id' => $consumptionId,
                'redo_consumption_id' => $redoConsumptionId,
                'resolution_type' => $resolutionType,
                'redo_items' => $redoText,
                'ticket_number' => $hasTicket ? $ticketNumber : '',
            ]);
            $pdo->commit();
            flash('success', '客诉处理完成，已生成金额为 0 的重做消费记录 #' . $redoConsumptionId);
            redirect('/consumption/');
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = '客诉处理失败：' . $e->getMessage();
        }
    }
}

renderHeader('客诉处理 - Anson咖啡厅管理系统', 'records');
?>
<div class="page-header"><h2 class="page-title">客诉处理</h2><a class="btn btn-secondary" href="/consumption/">返回消费记录</a></div>
<?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $error): ?><div><?= htmlspecialchars($error) ?></div><?php endforeach; ?></div><?php endif; ?>
<div class="grid-2">
<div class="card"><h3>原消费记录 #<?= (int) $consumption['id'] ?></h3><table class="table"><tbody><tr><th>时间</th><td><?= formatDateTime($consumption['create_time']) ?></td></tr><tr><th>顾客</th><td><?= htmlspecialchars($consumption['member_name'] ?: '散客') ?></td></tr><tr><th>原商品</th><td><?= htmlspecialchars($consumption['items'] ?: '-') ?></td></tr><tr><th>原金额</th><td><?= formatMoney((float) $consumption['amount']) ?></td></tr><tr><th>原号牌</th><td><?= htmlspecialchars($consumption['ticket_number'] ?: '-') ?></td></tr></tbody></table></div>
<div class="card form-card"><h3>处理方案</h3><form method="post" id="complaintForm"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(financeCsrfToken()) ?>"><input type="hidden" name="consumption_id" value="<?= (int) $consumptionId ?>">
<div class="form-group"><label>客诉内容 <span class="required">*</span></label><textarea name="complaint_reason" rows="3" required placeholder="内容会加密保存"><?= htmlspecialchars($_POST['complaint_reason'] ?? '') ?></textarea></div>
<div class="form-group"><label>补偿方式 <span class="required">*</span></label><div class="radio-group"><label class="radio-item"><input type="radio" name="resolution_type" value="full" <?= $resolutionType === 'full' ? 'checked' : '' ?> onchange="toggleResolution()"><span>全部重做</span></label><label class="radio-item"><input type="radio" name="resolution_type" value="partial" <?= $resolutionType === 'partial' ? 'checked' : '' ?> onchange="toggleResolution()"><span>部分重做</span></label><label class="radio-item"><input type="radio" name="resolution_type" value="exchange" <?= $resolutionType === 'exchange' ? 'checked' : '' ?> onchange="toggleResolution()"><span>换货</span></label></div></div>
<div id="partialItems" class="form-group"><label>选择重做商品及数量</label><div class="complaint-item-list"><?php foreach ($originalItems as $name => $maxQuantity): $index = array_search($name, array_keys($originalItems), true); ?><div class="complaint-item-row"><span><?= htmlspecialchars($name) ?>（原数量 <?= (int) $maxQuantity ?>）</span><input type="number" name="redo_quantity[<?= (int) $index ?>]" min="0" max="<?= (int) $maxQuantity ?>" value="<?= (int) ($_POST['redo_quantity'][$index] ?? 0) ?>"></div><?php endforeach; ?></div></div>
<div id="exchangeFields" class="form-group" style="display:none">
<label>换货后商品 <span class="required">*</span></label>
<input name="exchange_items" maxlength="120" value="<?= htmlspecialchars((string) ($_POST['exchange_items'] ?? '')) ?>" placeholder="例如：拿铁 x2、可颂 x1">
<div class="form-group" style="margin-top:0.6rem"><label>换货商品金额（元） <span class="required">*</span></label><input type="number" name="exchange_amount" id="exchangeAmount" step="0.01" min="0" value="<?= htmlspecialchars((string) ($_POST['exchange_amount'] ?? '')) ?>" oninput="updateExchangeDiff()" placeholder="新商品总价"></div>
<div class="form-group" style="margin-top:0.6rem"><label>收款 / 退款方式</label><select name="exchange_payment"><option value="cash">现金</option><option value="wechat">微信</option><option value="alipay">支付宝</option></select></div>
<p class="form-help">原消费金额 <?= formatMoney((float) $consumption['amount']) ?>；<span id="exchangeDiff">等价换货，无差价</span></p>
</div>
<div class="form-group"><label>是否重新取号</label><div class="radio-group"><label class="radio-item"><input type="radio" name="has_ticket" value="0" <?= !$hasTicket ? 'checked' : '' ?> onchange="toggleTicket()"><span>不取号</span></label><label class="radio-item"><input type="radio" name="has_ticket" value="1" <?= $hasTicket ? 'checked' : '' ?> onchange="toggleTicket()"><span>重新取号</span></label></div></div>
<div id="ticketInput" class="form-group"><label>号牌号码 <span class="required">*</span></label><input name="ticket_number" maxlength="20" value="<?= htmlspecialchars($_POST['ticket_number'] ?? '') ?>" placeholder="例如：A08、12号"></div>
<div class="form-group"><label>处理备注</label><textarea name="handling_note" rows="2" placeholder="内容会加密保存"><?= htmlspecialchars($_POST['handling_note'] ?? '') ?></textarea></div>
<button class="btn btn-primary" type="submit">确认处理</button>
</form></div></div>
<script>
const ORIGINAL_AMOUNT = <?= json_encode(round((float) $consumption['amount'], 2)) ?>;
function toggleResolution(){const checked=document.querySelector('input[name="resolution_type"]:checked');const value=checked?checked.value:'full';const partial=document.getElementById('partialItems');const exchange=document.getElementById('exchangeFields');if(partial)partial.style.display=value==='partial'?'block':'none';if(exchange)exchange.style.display=value==='exchange'?'block':'none';updateExchangeDiff();}
function updateExchangeDiff(){const input=document.getElementById('exchangeAmount');const target=document.getElementById('exchangeDiff');if(!input||!target)return;const amount=parseFloat(input.value||'0');if(isNaN(amount))return;const diff=Math.round((amount-ORIGINAL_AMOUNT)*100)/100;if(diff>0.009){target.textContent='顾客需补差价 '+diff.toFixed(2)+' 元（计入营业额）';target.className='text-danger';}else if(diff<-0.009){target.textContent='需退还差价 '+Math.abs(diff).toFixed(2)+' 元（登记为支出）';target.className='text-danger';}else{target.textContent='等价换货，无差价';target.className='';}}
function toggleTicket(){const ticket=document.querySelector('input[name="has_ticket"]:checked').value==='1';const section=document.getElementById('ticketInput');section.style.display=ticket?'block':'none';section.querySelector('input').required=ticket;}
toggleResolution();toggleTicket();
</script>

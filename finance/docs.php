<?php

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/layout.php';
requireLogin();
requireFinance();

renderHeader('财务文档 - 财务管理', 'finance');
?>
<div class="page-header"><h2 class="page-title">财务文档</h2><button class="btn btn-secondary" type="button" onclick="window.print()">打印文档</button></div>
<?php renderFinanceTabs('docs'); ?>

<div class="card finance-docs-intro">
    <h3>文档用途与权限</h3>
    <p>本文档说明本系统财务模块的数据口径、计算方式、操作流程和专业名词。仅管理员和“财务”职位可以访问；普通员工不能查看。</p>
    <div class="alert alert-warning"><strong>重要：</strong>本系统提供门店经营管理数据，不代替持证会计人员出具的法定会计账簿、纳税申报表或审计报告。</div>
</div>

<div class="card finance-docs-section">
    <h3>一、系统财务模块</h3>
    <table class="table"><thead><tr><th>模块</th><th>用途</th><th>主要数据来源</th></tr></thead><tbody>
        <tr><td>财务看板</td><td>查看营业收入、商品成本、净收入、经营支出、经营结余和会员储值负债。</td><td>消费、积分兑换、充值、退款、支出及成本记录。</td></tr>
        <tr><td>收支流水</td><td>按时间查看销售、充值资金流入、经营支出和退款，并可导出 CSV。</td><td>系统内所有有效业务登记。</td></tr>
        <tr><td>支出管理</td><td>登记原材料、耗材、房租、水电、工资、设备、营销、税费等支出。</td><td>财务人员手工登记。</td></tr>
        <tr><td>资金账户</td><td>查看各账户估算余额，并在现金、微信、支付宝、银行卡及已有账户间划转。</td><td>收款、充值、支出、退款和划转记录。</td></tr>
        <tr><td>成本记录</td><td>先维护可复用的成分库，再为产品选择成分并填写单杯用量，计算单杯成本。</td><td>财务人员维护的成分单价和产品成本配方。</td></tr>
        <tr><td>经营报表</td><td>查看支付渠道、支出分类、会员与散客、商品排行及月度汇总。</td><td>消费、成本、支出及退款记录。</td></tr>
        <tr><td>每日上报</td><td>对比人工实报金额和系统消费登记金额，发现漏记、错记或资金差异。</td><td>员工实报与系统自动汇总。</td></tr>
    </tbody></table>
</div>

<div class="card finance-docs-section">
    <h3>二、核心计算公式</h3>
    <div class="finance-formula-grid">
        <div class="finance-formula"><strong>单项成分成本</strong><span>单杯用量 × 成分单价</span></div>
        <div class="finance-formula"><strong>单杯商品成本</strong><span>该产品全部成分成本之和</span></div>
        <div class="finance-formula"><strong>订单商品成本</strong><span>各商品单杯成本 × 售出数量之和</span></div>
        <div class="finance-formula"><strong>实付金额</strong><span>商品原价 − 会员折扣 − 优惠券减免</span></div>
        <div class="finance-formula"><strong>净收入</strong><span>营业收入 − 销售商品成本 − 积分兑换商品成本</span></div>
        <div class="finance-formula"><strong>经营结余</strong><span>净收入 − 已登记经营支出</span></div>
        <div class="finance-formula"><strong>每日上报差异</strong><span>人工实报总额 − 系统营业收入</span></div>
        <div class="finance-formula"><strong>账户估算余额</strong><span>资金流入 − 支出 − 退款 ± 资金划转</span></div>
    </div>
    <p class="text-muted">示例：某饮品实付 ¥18.00，固化商品成本 ¥6.50，则该笔销售净收入为 ¥11.50。若当日另有经营支出 ¥4.00，则对应经营结余为 ¥7.50。</p>
</div>

<div class="card finance-docs-section">
    <h3>三、专业名词解释</h3>
    <table class="table"><thead><tr><th>名词</th><th>在本系统中的含义</th></tr></thead><tbody>
        <tr><td>营业收入</td><td>正常消费实付金额扣除已退金额。余额消费同样属于营业收入。</td></tr>
        <tr><td>资金流入</td><td>实际进入某个资金账户的金额。资金流入不一定是营业收入。</td></tr>
        <tr><td>会员充值</td><td>顾客预先存入会员余额的资金，是资金流入，但充值当时不计营业收入。</td></tr>
        <tr><td>储值负债</td><td>所有会员尚未消费的余额总和，代表门店未来需要向会员提供商品或退款的义务。</td></tr>
        <tr><td>余额消费</td><td>会员使用此前充值的余额付款。此时确认营业收入，但不会产生新的现金、微信或支付宝流入。</td></tr>
        <tr><td>商品成本</td><td>制作已售或积分兑换商品直接消耗的成分成本，例如咖啡豆、牛奶、糖浆和杯具。</td></tr>
        <tr><td>成本配方</td><td>某个产品的成分、单杯用量、单位、成分单价及单杯成本合计。</td></tr>
        <tr><td>成本快照</td><td>消费或积分兑换发生时保存的商品成本。之后修改成本配方不会改变历史记录。</td></tr>
        <tr><td>净收入</td><td>营业收入扣除销售商品成本和积分兑换商品成本后的金额，不等同于最终会计净利润。</td></tr>
        <tr><td>经营支出</td><td>门店经营产生并已登记的费用，如采购、房租、水电、工资、设备和营销费用。</td></tr>
        <tr><td>经营结余</td><td>净收入扣除经营支出后的门店经营分析值，未必包含折旧、税务调整等会计项目。</td></tr>
        <tr><td>客单价</td><td>所选期间营业收入 ÷ 有效正常订单数。</td></tr>
        <tr><td>优惠金额</td><td>会员折扣或优惠券减少的应收金额，不是独立资金支出。</td></tr>
        <tr><td>退款</td><td>向顾客退回原交易金额，并同步回退会员余额、积分、累计消费或充值余额。</td></tr>
        <tr><td>冲正</td><td>不删除原记录，而是用一条相反记录纠正错误，确保业务过程可以追溯。</td></tr>
        <tr><td>资金划转</td><td>同一门店不同资金账户间移动资金，不属于收入或支出，不改变利润。</td></tr>
        <tr><td>期初余额</td><td>账户开始纳入系统管理时已经存在的余额。</td></tr>
        <tr><td>估算余额</td><td>根据系统登记推算的账户余额，应定期与真实账户或现金盘点结果核对。</td></tr>
        <tr><td>系统金额</td><td>系统根据当天正常消费扣除退款后自动计算的营业收入。</td></tr>
        <tr><td>实报金额</td><td>员工根据实际收款、交班或盘点填写的金额。</td></tr>
        <tr><td>上报差异</td><td>实报金额与系统金额的差值。正数表示实报较多，负数表示实报较少。</td></tr>
        <tr><td>客诉补偿单</td><td>因客诉重做而生成的金额和积分均为 0 的消费记录，不计营业收入和有效订单数。</td></tr>
    </tbody></table>
</div>

<div class="card finance-docs-section">
    <h3>四、业务数据口径</h3>
    <ul class="finance-doc-list">
        <li>只有 <strong>正常消费</strong> 会进入营业收入和有效订单统计；积分兑换不增加收入或订单数，但实际兑换商品会进入商品成本并扣减净收入。</li>
        <li>饮品部分退款会从订单营业收入中扣除已退金额；整笔退完后不再计入有效营业收入。原交易和退款记录仍会保留。</li>
        <li>客诉重做记录金额和积分为 0，不进入营业收入、成本及订单数。</li>
        <li>会员充值只计资金流入及储值负债；会员实际消费时才确认营业收入。</li>
        <li>资金划转只改变账户余额分布，不改变总资金、收入、支出或利润。</li>
        <li>商品成本采用消费或积分兑换发生时的配方成本快照，保证历史报表不会随当前配方变化。</li>
        <li>历史银行卡交易继续保留，但系统不再允许新消费或新充值使用银行卡支付。</li>
    </ul>
</div>

<div class="card finance-docs-section">
    <h3>五、标准操作流程</h3>
    <ol class="finance-doc-list">
        <li><strong>建立成本：</strong>先在“成本记录”中设置成分库，再为每个菜单产品选择成分并填写用量。</li>
        <li><strong>日常结算：</strong>员工登记消费或进行积分兑换；后台配置的实物及无实物项目使用固定积分，兑换页额外提供的“其它”需现场填写备注和积分，系统自动保存积分、商品成本及净收入影响。</li>
        <li><strong>登记支出：</strong>财务及时登记采购及经营费用，错误记录使用冲正，不直接删除。</li>
        <li><strong>每日上报：</strong>员工提交各渠道实报金额，财务检查与系统金额的差异及说明。</li>
        <li><strong>资金管理：</strong>实际发生账户间转款时登记资金划转，确保估算余额与资金位置一致。</li>
        <li><strong>退款处理：</strong>在“制作总览”对指定饮品发起逐杯退款；在“消费记录”或“退款记录”页可退还订单剩余金额。退款原因加密保存；现金、微信、支付宝需同时在原收款渠道完成实际退款。</li>
        <li><strong>定期复核：</strong>财务按日或按月查看经营报表，核对收入、成本、支出、储值负债和账户余额。</li>
    </ol>
</div>

<div class="card finance-docs-section">
    <h3>六、权限与数据安全</h3>
    <table class="table"><thead><tr><th>职位</th><th>财务权限</th></tr></thead><tbody>
        <tr><td>管理员</td><td>拥有全部财务、普通员工和管理后台权限。</td></tr>
        <tr><td>财务</td><td>可访问全部财务模块和本文档；是否拥有普通员工权限由管理员创建账号时决定。</td></tr>
        <tr><td>普通员工</td><td>不能访问财务管理和本文档，但可以使用每日上报。</td></tr>
    </tbody></table>
    <p>账户资料、成本成分及单价、支出敏感详情、退款原因、客诉内容、每日上报备注和资金划转备注采用 AES-256-GCM 加密存储。</p>
    <p class="text-danger"><strong>密钥要求：</strong>必须安全备份 <code>data/finance.key</code> 或部署所使用的 <code>FINANCE_ENCRYPTION_KEY</code>。密钥丢失后，加密字段无法恢复。</p>
</div>

<?php renderFooter(); ?>

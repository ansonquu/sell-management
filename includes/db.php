<?php

require_once __DIR__ . '/../config.php';

class JsonPDOStatement extends PDOStatement
{
    private JsonPDO $jsonPdo;

    protected function __construct(JsonPDO $jsonPdo)
    {
        $this->jsonPdo = $jsonPdo;
    }

    public function execute(?array $params = null): bool
    {
        $result = $params === null ? parent::execute() : parent::execute($params);

        if ($result && $this->jsonPdo->isMutation($this->queryString)) {
            $this->jsonPdo->markChanged();
        }

        return $result;
    }
}

class JsonPDO extends PDO
{
    private string $jsonPath;
    private bool $booting = true;
    private bool $loaded = false;
    private bool $dirty = false;
    private array $tables = [
        'member',
        'consumption',
        'points_log',
        'recharge',
        'coupon_templates',
        'member_coupons',
        'operation_logs',
        'announcements',
        'announcement_reads',
        'finance_expenses',
        'finance_accounts',
        'finance_refunds',
        'daily_income_reports',
        'complaint_records',
        'finance_transfers',
        'product_costs',
        'cost_ingredients',
        'staff_notifications',
        'staff_feedback',
        'attendance_records',
    ];

    public function __construct(string $jsonPath)
    {
        $this->jsonPath = $jsonPath;
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [JsonPDOStatement::class, [$this]]);
    }

    public function exec(string $statement): int|false
    {
        $result = parent::exec($statement);

        if ($result !== false && $this->isMutation($statement)) {
            $this->markChanged();
        }

        return $result;
    }

    public function commit(): bool
    {
        $result = parent::commit();
        if ($result && $this->dirty) {
            $this->dirty = false;
            $this->persistJsonData();
        }
        return $result;
    }

    public function rollBack(): bool
    {
        $result = parent::rollBack();
        $this->dirty = false;
        return $result;
    }

    public function markChanged(): void
    {
        if ($this->booting) {
            return;
        }

        if ($this->inTransaction()) {
            $this->dirty = true;
            return;
        }

        $this->persistJsonData();
    }

    public function finishBooting(): void
    {
        $this->booting = false;
    }

    public function isMutation(string $sql): bool
    {
        if ($this->booting) {
            return false;
        }

        $verb = strtoupper(strtok(ltrim($sql), " \t\r\n"));
        return in_array($verb, ['INSERT', 'UPDATE', 'DELETE', 'REPLACE'], true);
    }

    public function loadJsonData(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        $data = $this->readJsonData();
        foreach ($this->tables as $table) {
            parent::exec('DELETE FROM ' . $table);
            foreach (($data[$table] ?? []) as $row) {
                $this->insertRow($table, $row);
            }
        }
    }

    public function persistJsonData(): void
    {
        if ($this->booting) {
            return;
        }

        $dir = dirname($this->jsonPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $data = [
            '_meta' => [
                'storage' => 'json',
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        ];

        foreach ($this->tables as $table) {
            $data[$table] = parent::query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
        }

        file_put_contents(
            $this->jsonPath,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    private function readJsonData(): array
    {
        if (file_exists($this->jsonPath)) {
            $content = file_get_contents($this->jsonPath);
            $data = json_decode($content ?: '', true);
            if (is_array($data)) {
                return $data;
            }

        }

        $legacy = ['_meta' => [
            'storage' => 'json',
            'updated_at' => date('Y-m-d H:i:s'),
        ]];
        foreach ($this->tables as $table) {
            $legacy[$table] = [];
        }
        $dir = dirname($this->jsonPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            $this->jsonPath,
            json_encode($legacy, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );

        return $legacy;
    }

    private function insertRow(string $table, array $row): void
    {
        $columns = $this->tableColumns($table);
        $row = array_intersect_key($row, array_flip($columns));
        if (!$row) {
            return;
        }

        $names = array_keys($row);
        $quotedNames = array_map(fn ($name) => '"' . str_replace('"', '""', $name) . '"', $names);
        $placeholders = array_fill(0, count($names), '?');

        $stmt = parent::prepare(
            'INSERT OR REPLACE INTO ' . $table .
            ' (' . implode(', ', $quotedNames) . ')' .
            ' VALUES (' . implode(', ', $placeholders) . ')'
        );
        $stmt->execute(array_values($row));
    }

    private function tableColumns(string $table): array
    {
        $columns = [];
        foreach (parent::query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[] = $column['name'];
        }
        return $columns;
    }
}

function getDb(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $jsonPath = defined('JSON_DB_PATH') ? JSON_DB_PATH : __DIR__ . '/../data/database.json';
        $dir = dirname($jsonPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $pdo = new JsonPDO($jsonPath);
        initDatabase($pdo);
        $pdo->loadJsonData();
        migrateDatabase($pdo);
        $pdo->finishBooting();
        // 只读请求（例如大屏轮询）不可把旧快照写回 JSON，否则可能覆盖并发登记的订单。
    }
    return $pdo;
}

function initDatabase(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS member (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            phone VARCHAR(20) UNIQUE,
            student_no VARCHAR(30) DEFAULT '',
            name VARCHAR(50) NOT NULL,
            gender TINYINT DEFAULT 0,
            birthday DATE,
            note TEXT DEFAULT '',
            level VARCHAR(20) DEFAULT '普通会员',
            points INTEGER DEFAULT 0,
            balance DECIMAL(10,2) DEFAULT 0,
            total_spent DECIMAL(10,2) DEFAULT 0,
            status TINYINT DEFAULT 1,
            create_time DATETIME DEFAULT (datetime('now', '+8 hours'))
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS consumption (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id INTEGER DEFAULT 0,
            amount DECIMAL(10,2) NOT NULL,
            points_earned INTEGER DEFAULT 0,
            points_spent INTEGER NOT NULL DEFAULT 0,
            items VARCHAR(255),
            note VARCHAR(255),
            operator VARCHAR(50) DEFAULT '',
            payment_method VARCHAR(20) DEFAULT 'balance',
            coupon_id INTEGER DEFAULT NULL,
            coupon_discount DECIMAL(10,2) DEFAULT 0,
            ticket_number VARCHAR(20) DEFAULT '',
            refunded_at DATETIME DEFAULT NULL,
            refunded_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            exchanged_at DATETIME DEFAULT NULL,
            picked_up_at DATETIME DEFAULT NULL,
            picked_up_by VARCHAR(50) DEFAULT '',
            record_type VARCHAR(30) NOT NULL DEFAULT 'sale',
            complaint_id INTEGER DEFAULT NULL,
            cost_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            net_income DECIMAL(12,2) DEFAULT NULL,
            prep_status VARCHAR(12) NOT NULL DEFAULT 'done',
            prep_items TEXT DEFAULT NULL,
            prepared_at DATETIME DEFAULT NULL,
            prepared_by VARCHAR(50) DEFAULT '',
            create_time DATETIME DEFAULT (datetime('now', '+8 hours'))
        )
    ");

    // 学号为选填：仅对非空学号强制唯一，空值可重复
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_member_student_no ON member(student_no) WHERE student_no IS NOT NULL AND student_no <> ''");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS points_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id INTEGER NOT NULL,
            change_amount INTEGER NOT NULL,
            reason VARCHAR(100),
            create_time DATETIME DEFAULT (datetime('now', '+8 hours')),
            FOREIGN KEY (member_id) REFERENCES member(id)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS recharge (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id INTEGER NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            balance_after DECIMAL(10,2) NOT NULL,
            note VARCHAR(255),
            payment_method VARCHAR(30) DEFAULT 'cash',
            operator VARCHAR(50) DEFAULT '',
            refunded_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            refunded_at DATETIME DEFAULT NULL,
            create_time DATETIME DEFAULT (datetime('now', '+8 hours')),
            FOREIGN KEY (member_id) REFERENCES member(id)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS coupon_templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(100) NOT NULL,
            type VARCHAR(20) NOT NULL DEFAULT 'fixed',
            value DECIMAL(10,2) NOT NULL DEFAULT 0,
            min_amount DECIMAL(10,2) DEFAULT 0,
            total_quantity INTEGER DEFAULT 0,
            issued_quantity INTEGER DEFAULT 0,
            expire_days INTEGER DEFAULT 7,
            status VARCHAR(20) DEFAULT 'active',
            operator VARCHAR(50) DEFAULT '',
            created_at DATETIME DEFAULT (datetime('now', '+8 hours'))
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS member_coupons (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            template_id INTEGER NOT NULL,
            member_id INTEGER NOT NULL,
            code VARCHAR(50) NOT NULL UNIQUE,
            status VARCHAR(20) DEFAULT 'unused',
            expire_at DATETIME,
            used_at DATETIME,
            used_amount DECIMAL(10,2) DEFAULT 0,
            operator VARCHAR(50) DEFAULT '',
            created_at DATETIME DEFAULT (datetime('now', '+8 hours')),
            FOREIGN KEY (template_id) REFERENCES coupon_templates(id),
            FOREIGN KEY (member_id) REFERENCES member(id)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS operation_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_username VARCHAR(50) DEFAULT '',
            actor_name VARCHAR(50) DEFAULT '',
            actor_role VARCHAR(20) DEFAULT '',
            action VARCHAR(50) NOT NULL,
            target_type VARCHAR(50) DEFAULT '',
            target_id INTEGER DEFAULT NULL,
            description TEXT NOT NULL,
            meta TEXT DEFAULT '',
            created_at DATETIME DEFAULT (datetime('now', '+8 hours'))
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS announcements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title VARCHAR(120) NOT NULL,
            content TEXT NOT NULL,
            category VARCHAR(20) NOT NULL DEFAULT 'normal',
            status VARCHAR(20) NOT NULL DEFAULT 'published',
            target_roles TEXT DEFAULT '',
            target_positions TEXT DEFAULT '',
            target_staff TEXT DEFAULT '',
            revision INTEGER NOT NULL DEFAULT 1,
            created_by VARCHAR(50) DEFAULT '',
            created_at DATETIME DEFAULT (datetime('now', '+8 hours')),
            updated_at DATETIME DEFAULT (datetime('now', '+8 hours'))
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS announcement_reads (
            announcement_id INTEGER NOT NULL,
            staff_username VARCHAR(50) NOT NULL,
            read_at DATETIME NOT NULL,
            read_revision INTEGER NOT NULL DEFAULT 1,
            PRIMARY KEY (announcement_id, staff_username),
            FOREIGN KEY (announcement_id) REFERENCES announcements(id)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS finance_expenses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type VARCHAR(20) NOT NULL DEFAULT 'expense',
            category VARCHAR(40) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            payment_account VARCHAR(30) NOT NULL DEFAULT 'cash',
            occurred_at DATETIME NOT NULL,
            encrypted_payload TEXT DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            reversed_at DATETIME DEFAULT NULL,
            reversed_by VARCHAR(50) DEFAULT '',
            operator_username VARCHAR(50) DEFAULT '',
            operator_name VARCHAR(50) DEFAULT '',
            created_at DATETIME DEFAULT (datetime('now', '+8 hours'))
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS finance_accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(60) NOT NULL,
            type VARCHAR(30) NOT NULL DEFAULT 'other',
            opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
            encrypted_details TEXT DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            operator_username VARCHAR(50) DEFAULT '',
            created_at DATETIME DEFAULT (datetime('now', '+8 hours'))
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS finance_refunds (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_type VARCHAR(20) NOT NULL,
            source_id INTEGER NOT NULL,
            item_index INTEGER DEFAULT NULL,
            item_name VARCHAR(255) DEFAULT '',
            amount DECIMAL(12,2) NOT NULL,
            payment_method VARCHAR(30) DEFAULT '',
            encrypted_reason TEXT DEFAULT '',
            operator_username VARCHAR(50) DEFAULT '',
            operator_name VARCHAR(50) DEFAULT '',
            created_at DATETIME DEFAULT (datetime('now', '+8 hours'))
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS daily_income_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_date DATE NOT NULL,
            cash_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            wechat_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            alipay_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            bank_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            balance_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            other_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            reported_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            system_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            difference_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            system_snapshot TEXT DEFAULT '',
            encrypted_note TEXT DEFAULT '',
            operator_username VARCHAR(50) NOT NULL,
            operator_name VARCHAR(50) DEFAULT '',
            is_reversal INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT (datetime('now', '+8 hours')),
            updated_at DATETIME DEFAULT (datetime('now', '+8 hours')),
            UNIQUE(report_date, operator_username)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS complaint_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            consumption_id INTEGER NOT NULL,
            redo_consumption_id INTEGER DEFAULT NULL,
            resolution_type VARCHAR(20) NOT NULL,
            redo_items VARCHAR(255) NOT NULL,
            exchange_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            difference_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            has_ticket TINYINT NOT NULL DEFAULT 0,
            ticket_number VARCHAR(20) DEFAULT '',
            encrypted_details TEXT DEFAULT '',
            operator_username VARCHAR(50) DEFAULT '',
            operator_name VARCHAR(50) DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'completed',
            created_at DATETIME DEFAULT (datetime('now', '+8 hours')),
            FOREIGN KEY (consumption_id) REFERENCES consumption(id)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS finance_transfers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            from_account VARCHAR(30) NOT NULL,
            to_account VARCHAR(30) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            occurred_at DATETIME NOT NULL,
            encrypted_note TEXT DEFAULT '',
            operator_username VARCHAR(50) DEFAULT '',
            operator_name VARCHAR(50) DEFAULT '',
            created_at DATETIME DEFAULT (datetime('now', '+8 hours'))
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS product_costs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_name VARCHAR(100) NOT NULL UNIQUE,
            unit_cost DECIMAL(12,4) NOT NULL DEFAULT 0,
            encrypted_components TEXT DEFAULT '',
            operator_username VARCHAR(50) DEFAULT '',
            operator_name VARCHAR(50) DEFAULT '',
            created_at DATETIME DEFAULT (datetime('now', '+8 hours')),
            updated_at DATETIME DEFAULT (datetime('now', '+8 hours'))
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cost_ingredients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(100) NOT NULL UNIQUE,
            encrypted_details TEXT DEFAULT '',
            operator_username VARCHAR(50) DEFAULT '',
            operator_name VARCHAR(50) DEFAULT '',
            created_at DATETIME DEFAULT (datetime('now', '+8 hours')),
            updated_at DATETIME DEFAULT (datetime('now', '+8 hours'))
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS staff_notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            recipient_username VARCHAR(50) NOT NULL,
            title VARCHAR(120) NOT NULL,
            content TEXT NOT NULL DEFAULT '',
            link VARCHAR(255) NOT NULL DEFAULT '',
            is_read TINYINT NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT (datetime('now', '+8 hours'))
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS staff_feedback (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sender_username VARCHAR(50) NOT NULL,
            sender_name VARCHAR(50) NOT NULL DEFAULT '',
            content TEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            reply_content TEXT NOT NULL DEFAULT '',
            replied_by VARCHAR(50) NOT NULL DEFAULT '',
            replied_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT (datetime('now', '+8 hours'))
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS attendance_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            record_date DATE NOT NULL,
            shift VARCHAR(20) NOT NULL,
            leader_username VARCHAR(50) NOT NULL,
            leader_name VARCHAR(50) NOT NULL DEFAULT '',
            details TEXT NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            confirmed_by VARCHAR(50) NOT NULL DEFAULT '',
            confirmed_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT (datetime('now', '+8 hours')),
            updated_at DATETIME DEFAULT (datetime('now', '+8 hours')),
            UNIQUE(record_date, shift)
        )
    ");
}

function migrateDatabase(PDO $pdo): void
{
    $expenseColumns = $pdo->query('PRAGMA table_info(finance_expenses)')->fetchAll();
    $expenseColumnNames = array_column($expenseColumns, 'name');
    if (!in_array('type', $expenseColumnNames, true)) {
        $pdo->exec("ALTER TABLE finance_expenses ADD COLUMN type VARCHAR(20) NOT NULL DEFAULT 'expense'");
    }

    $columns = $pdo->query('PRAGMA table_info(member)')->fetchAll();
    $columnNames = array_column($columns, 'name');

    if (!in_array('student_no', $columnNames, true)) {
        $pdo->exec("ALTER TABLE member ADD COLUMN student_no VARCHAR(30) DEFAULT ''");
    }

    if (!in_array('balance', $columnNames, true)) {
        $pdo->exec("ALTER TABLE member ADD COLUMN balance DECIMAL(10,2) DEFAULT 0");
    }

    if (!in_array('note', $columnNames, true)) {
        $pdo->exec("ALTER TABLE member ADD COLUMN note TEXT DEFAULT ''");
    }

    $consumptionColumns = $pdo->query('PRAGMA table_info(consumption)')->fetchAll();
    $consumptionColumnNames = array_column($consumptionColumns, 'name');

    if (!in_array('operator', $consumptionColumnNames, true)) {
        $pdo->exec("ALTER TABLE consumption ADD COLUMN operator VARCHAR(50) DEFAULT ''");
    }

    if (!in_array('payment_method', $consumptionColumnNames, true)) {
        $pdo->exec("ALTER TABLE consumption ADD COLUMN payment_method VARCHAR(20) DEFAULT 'balance'");
    }

    if (!in_array('coupon_id', $consumptionColumnNames, true)) {
        $pdo->exec("ALTER TABLE consumption ADD COLUMN coupon_id INTEGER DEFAULT NULL");
    }

    if (!in_array('coupon_discount', $consumptionColumnNames, true)) {
        $pdo->exec("ALTER TABLE consumption ADD COLUMN coupon_discount DECIMAL(10,2) DEFAULT 0");
    }

    if (!in_array('ticket_number', $consumptionColumnNames, true)) {
        $pdo->exec("ALTER TABLE consumption ADD COLUMN ticket_number VARCHAR(20) DEFAULT ''");
    }
    if (!in_array('refunded_at', $consumptionColumnNames, true)) {
        $pdo->exec("ALTER TABLE consumption ADD COLUMN refunded_at DATETIME DEFAULT NULL");
    }
    if (!in_array('refunded_amount', $consumptionColumnNames, true)) {
        $pdo->exec('ALTER TABLE consumption ADD COLUMN refunded_amount DECIMAL(12,2) NOT NULL DEFAULT 0');
    }
    if (!in_array('record_type', $consumptionColumnNames, true)) {
        $pdo->exec("ALTER TABLE consumption ADD COLUMN record_type VARCHAR(30) NOT NULL DEFAULT 'sale'");
    }
    if (!in_array('complaint_id', $consumptionColumnNames, true)) {
        $pdo->exec("ALTER TABLE consumption ADD COLUMN complaint_id INTEGER DEFAULT NULL");
    }
    if (!in_array('cost_amount', $consumptionColumnNames, true)) {
        $pdo->exec("ALTER TABLE consumption ADD COLUMN cost_amount DECIMAL(12,2) NOT NULL DEFAULT 0");
    }
    if (!in_array('net_income', $consumptionColumnNames, true)) {
        $pdo->exec("ALTER TABLE consumption ADD COLUMN net_income DECIMAL(12,2) DEFAULT NULL");
    }
    if (!in_array('points_spent', $consumptionColumnNames, true)) {
        $pdo->exec("ALTER TABLE consumption ADD COLUMN points_spent INTEGER NOT NULL DEFAULT 0");
    }
    if (!in_array('prep_status', $consumptionColumnNames, true)) {
        $pdo->exec("ALTER TABLE consumption ADD COLUMN prep_status VARCHAR(12) NOT NULL DEFAULT 'done'");
    }
    if (!in_array('prep_items', $consumptionColumnNames, true)) {
        $pdo->exec('ALTER TABLE consumption ADD COLUMN prep_items TEXT DEFAULT NULL');
    }
    if (!in_array('prepared_at', $consumptionColumnNames, true)) {
        $pdo->exec('ALTER TABLE consumption ADD COLUMN prepared_at DATETIME DEFAULT NULL');
    }
    if (!in_array('prepared_by', $consumptionColumnNames, true)) {
        $pdo->exec("ALTER TABLE consumption ADD COLUMN prepared_by VARCHAR(50) DEFAULT ''");
    }
    $pdo->exec("UPDATE consumption SET net_income = amount - COALESCE(cost_amount, 0) WHERE net_income IS NULL");

    $refundColumns = array_column($pdo->query('PRAGMA table_info(finance_refunds)')->fetchAll(), 'name');
    if (!in_array('item_index', $refundColumns, true)) {
        $pdo->exec('ALTER TABLE finance_refunds ADD COLUMN item_index INTEGER DEFAULT NULL');
    }
    if (!in_array('item_name', $refundColumns, true)) {
        $pdo->exec("ALTER TABLE finance_refunds ADD COLUMN item_name VARCHAR(255) DEFAULT ''");
    }

    $rechargeColumns = $pdo->query('PRAGMA table_info(recharge)')->fetchAll();
    $rechargeColumnNames = array_column($rechargeColumns, 'name');
    if (!in_array('payment_method', $rechargeColumnNames, true)) {
        $pdo->exec("ALTER TABLE recharge ADD COLUMN payment_method VARCHAR(30) DEFAULT 'cash'");
    }
    if (!in_array('operator', $rechargeColumnNames, true)) {
        $pdo->exec("ALTER TABLE recharge ADD COLUMN operator VARCHAR(50) DEFAULT ''");
    }
    if (!in_array('refunded_at', $rechargeColumnNames, true)) {
        $pdo->exec("ALTER TABLE recharge ADD COLUMN refunded_at DATETIME DEFAULT NULL");
    }

    $couponTemplateColumns = $pdo->query('PRAGMA table_info(coupon_templates)')->fetchAll();
    $couponTemplateColumnNames = array_column($couponTemplateColumns, 'name');
    if (!in_array('operator', $couponTemplateColumnNames, true)) {
        $pdo->exec("ALTER TABLE coupon_templates ADD COLUMN operator VARCHAR(50) DEFAULT ''");
    }

    $memberCouponColumns = $pdo->query('PRAGMA table_info(member_coupons)')->fetchAll();
    $memberCouponColumnNames = array_column($memberCouponColumns, 'name');
    if (!in_array('operator', $memberCouponColumnNames, true)) {
        $pdo->exec("ALTER TABLE member_coupons ADD COLUMN operator VARCHAR(50) DEFAULT ''");
    }

    // 兼容旧版消费记录：这些信息此前已写入操作日志，但没有保存到消费表。
    $legacyLogs = $pdo->query("
        SELECT target_id, actor_name, meta
        FROM operation_logs
        WHERE action = 'consumption_create' AND target_type = 'consumption' AND target_id IS NOT NULL
    ")->fetchAll();
    $backfill = $pdo->prepare("
        UPDATE consumption
        SET operator = CASE WHEN operator = '' THEN ? ELSE operator END,
            ticket_number = CASE WHEN ticket_number = '' THEN ? ELSE ticket_number END
        WHERE id = ?
    ");
    foreach ($legacyLogs as $log) {
        $meta = json_decode((string) ($log['meta'] ?? ''), true);
        $ticketNumber = is_array($meta) ? trim((string) ($meta['ticket_number'] ?? '')) : '';
        $backfill->execute([
            trim((string) ($log['actor_name'] ?? '')),
            $ticketNumber,
            (int) $log['target_id'],
        ]);
    }

    $announcementColumns = $pdo->query('PRAGMA table_info(announcements)')->fetchAll();
    if (!in_array('category', array_column($announcementColumns, 'name'), true)) {
        $pdo->exec("ALTER TABLE announcements ADD COLUMN category VARCHAR(20) NOT NULL DEFAULT 'normal'");
    }
    if (!in_array('revision', array_column($announcementColumns, 'name'), true)) {
        $pdo->exec('ALTER TABLE announcements ADD COLUMN revision INTEGER NOT NULL DEFAULT 1');
    }
    if (!in_array('target_roles', array_column($announcementColumns, 'name'), true)) {
        $pdo->exec("ALTER TABLE announcements ADD COLUMN target_roles TEXT DEFAULT ''");
    }
    if (!in_array('target_positions', array_column($announcementColumns, 'name'), true)) {
        $pdo->exec("ALTER TABLE announcements ADD COLUMN target_positions TEXT DEFAULT ''");
    }

    $exchangeConsumptionColumns = $pdo->query('PRAGMA table_info(consumption)')->fetchAll();
    if (!in_array('exchanged_at', array_column($exchangeConsumptionColumns, 'name'), true)) {
        $pdo->exec('ALTER TABLE consumption ADD COLUMN exchanged_at DATETIME DEFAULT NULL');
    }

    $announcementColumns = $pdo->query('PRAGMA table_info(announcements)')->fetchAll();
    if (!in_array('target_staff', array_column($announcementColumns, 'name'), true)) {
        $pdo->exec("ALTER TABLE announcements ADD COLUMN target_staff TEXT DEFAULT ''");
    }

    $pickedColumns = array_column($pdo->query('PRAGMA table_info(consumption)')->fetchAll(), 'name');
    if (!in_array('picked_up_at', $pickedColumns, true)) {
        $pdo->exec('ALTER TABLE consumption ADD COLUMN picked_up_at DATETIME DEFAULT NULL');
    }
    if (!in_array('picked_up_by', $pickedColumns, true)) {
        $pdo->exec("ALTER TABLE consumption ADD COLUMN picked_up_by VARCHAR(50) DEFAULT ''");
    }

    $complaintColumns = $pdo->query('PRAGMA table_info(complaint_records)')->fetchAll();
    if (!in_array('exchange_amount', array_column($complaintColumns, 'name'), true)) {
        $pdo->exec('ALTER TABLE complaint_records ADD COLUMN exchange_amount DECIMAL(12,2) NOT NULL DEFAULT 0');
    }
    if (!in_array('difference_amount', array_column($complaintColumns, 'name'), true)) {
        $pdo->exec('ALTER TABLE complaint_records ADD COLUMN difference_amount DECIMAL(12,2) NOT NULL DEFAULT 0');
    }

    $rechargeColumns = $pdo->query('PRAGMA table_info(recharge)')->fetchAll();
    if (!in_array('refunded_amount', array_column($rechargeColumns, 'name'), true)) {
        $pdo->exec('ALTER TABLE recharge ADD COLUMN refunded_amount DECIMAL(12,2) NOT NULL DEFAULT 0');
    }

    $dailyReportColumns = $pdo->query('PRAGMA table_info(daily_income_reports)')->fetchAll();
    if (!in_array('is_reversal', array_column($dailyReportColumns, 'name'), true)) {
        $pdo->exec('ALTER TABLE daily_income_reports ADD COLUMN is_reversal INTEGER NOT NULL DEFAULT 0');
    }

    $announcementReadColumns = $pdo->query('PRAGMA table_info(announcement_reads)')->fetchAll();
    if (!in_array('read_revision', array_column($announcementReadColumns, 'name'), true)) {
        $pdo->exec('ALTER TABLE announcement_reads ADD COLUMN read_revision INTEGER NOT NULL DEFAULT 1');
    }
}

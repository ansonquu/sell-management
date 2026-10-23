<?php

/**
 * 当日负责人签到记录：班次（中午/晚上）、到岗/未到人员、未到状态（迟到/未到/请假/无需到岗）。
 */

function attendanceShiftLabel(string $shift): string
{
    return $shift === 'evening' ? '晚上' : '中午';
}

function attendanceShiftOptions(): array
{
    return ['noon' => '中午', 'evening' => '晚上'];
}

function attendanceAbsentStatusLabel(string $status): string
{
    return match ($status) {
        'late' => '迟到',
        'leave' => '请假',
        'no_need' => '无需到岗',
        default => '未到',
    };
}

function attendanceAbsentStatusOptions(): array
{
    return ['late' => '迟到', 'absent' => '未到', 'leave' => '请假', 'no_need' => '无需到岗'];
}

/** 签到名单条目：{name: 姓名, positions: [职位...]}。兼容旧的纯姓名/单职位格式。 */
function getAttendanceRosterEntries(): array
{
    $settings = getSystemSettings();
    $configured = $settings['attendance_roster'] ?? null;
    $entries = [];
    if (is_array($configured)) {
        foreach ($configured as $item) {
            if (is_string($item)) {
                $name = trim($item);
                if ($name !== '') {
                    $entries[] = ['name' => $name, 'positions' => []];
                }
            } elseif (is_array($item)) {
                $name = trim((string) ($item['name'] ?? ''));
                if ($name !== '') {
                    $entries[] = ['name' => $name, 'positions' => normalizeAttendancePositions($item)];
                }
            }
        }
    }
    if ($entries) {
        return $entries;
    }
    // 未配置：默认所有员工（含管理员）
    foreach (getStaffAccounts() as $username => $account) {
        $entries[] = [
            'name' => (string) (($account['name'] ?? '') ?: $username),
            'positions' => [],
        ];
    }
    return $entries;
}

/** 兼容解析条目的职位：positions 数组 或 旧 position 字符串。 */
function normalizeAttendancePositions(array $entry): array
{
    if (isset($entry['positions']) && is_array($entry['positions'])) {
        $positions = [];
        foreach ($entry['positions'] as $position) {
            $position = trim((string) $position);
            if ($position !== '') {
                $positions[] = $position;
            }
        }
    } elseif (isset($entry['position']) && trim((string) $entry['position']) !== '') {
        $positions = [trim((string) $entry['position'])];
    } else {
        $positions = [];
    }
    return array_values(array_unique($positions));
}

/** 签到名单姓名列表（去重）。 */
function getAttendanceRoster(): array
{
    return array_values(array_unique(array_map(static fn (array $entry): string => (string) ($entry['name'] ?? ''), getAttendanceRosterEntries())));
}

/** 姓名 => 职位数组 映射。 */
function getAttendanceRosterPositionMap(): array
{
    $map = [];
    foreach (getAttendanceRosterEntries() as $entry) {
        $map[(string) ($entry['name'] ?? '')] = (array) ($entry['positions'] ?? []);
    }
    return $map;
}

function saveAttendanceRoster(array $entries): void
{
    $normalized = [];
    $seen = [];
    foreach ($entries as $item) {
        if (is_string($item)) {
            $name = trim($item);
            $positions = [];
        } elseif (is_array($item)) {
            $name = trim((string) ($item['name'] ?? ''));
            $positions = normalizeAttendancePositions($item);
        } else {
            continue;
        }
        if ($name === '' || isset($seen[$name])) {
            continue;
        }
        $seen[$name] = true;
        $normalized[] = ['name' => $name, 'positions' => $positions];
    }
    $settings = getSystemSettings();
    $settings['attendance_roster'] = $normalized;
    saveSystemSettings($settings);
}

/** 签到职位选项（系统设置中配置）。 */
function getAttendancePositions(): array
{
    return array_values((array) (getSystemSettings()['attendance_positions'] ?? []));
}

/** 职位多选复选框 HTML。$fieldName 为表单字段名（如 staff_position[user]）。 */
function attendancePositionCheckboxes(string $fieldName, array $current): string
{
    $positions = getAttendancePositions();
    $current = array_values(array_unique(array_filter(array_map('trim', $current), 'strlen')));
    $html = '<div class="attendance-pos-checks">';
    if (!$positions) {
        $html .= '<span class="text-muted">（请先在系统设置中配置签到职位）</span>';
    } else {
        foreach ($positions as $position) {
            $checked = in_array((string) $position, $current, true) ? ' checked' : '';
            $html .= '<label class="attendance-pos-check"><input type="checkbox" name="' . htmlspecialchars($fieldName) . '[]" value="' . htmlspecialchars((string) $position) . '"' . $checked . '><span>' . htmlspecialchars((string) $position) . '</span></label>';
        }
    }
    $html .= '</div>';
    return $html;
}

/** 职位选项（供前端 JS 生成多选框）。 */
function attendancePositionOptionsForJs(): array
{
    return array_map(static fn (string $position): array => ['value' => $position, 'label' => $position], getAttendancePositions());
}

/** 现有员工（含管理员）列表，供名单配置选择。 */
function attendanceStaffOptions(): array
{
    $options = [];
    foreach (getStaffAccounts() as $username => $account) {
        $options[$username] = [
            'name' => (string) (($account['name'] ?? '') ?: $username),
            'role' => (string) ($account['role'] ?? 'staff'),
        ];
    }
    return $options;
}

function getAttendanceRecord(string $date, string $shift): ?array
{
    $stmt = getDb()->prepare('SELECT * FROM attendance_records WHERE record_date = ? AND shift = ?');
    $stmt->execute([$date, $shift]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getAttendanceRecordById(int $id): ?array
{
    $stmt = getDb()->prepare('SELECT * FROM attendance_records WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** 保存签到记录（同日期同班次则更新，否则新增），返回记录 id。 */
function saveAttendanceRecord(string $date, string $shift, array $arrived, array $absent, string $leaderUsername, string $leaderName): int
{
    $details = json_encode([
        'arrived' => array_values(array_unique($arrived)),
        'absent' => $absent,
    ], JSON_UNESCAPED_UNICODE);

    $pdo = getDb();
    $now = currentDateTime();

    // 原子 UPSERT：同一日期 + 班次重复保存时直接更新；
    // 并发写入也不会因 UNIQUE(record_date, shift) 冲突而抛出异常。
    $stmt = $pdo->prepare("
        INSERT INTO attendance_records (record_date, shift, leader_username, leader_name, details, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, 'pending', ?, ?)
        ON CONFLICT(record_date, shift) DO UPDATE SET
            leader_username = excluded.leader_username,
            leader_name = excluded.leader_name,
            details = excluded.details,
            updated_at = excluded.updated_at
    ");
    $stmt->execute([$date, $shift, $leaderUsername, $leaderName, $details, $now, $now]);

    $id = (int) $pdo->lastInsertId();
    if ($id <= 0) {
        // UPDATE 分支不会返回自增 id，回查一次保证调用方拿到正确 id
        $row = getAttendanceRecord($date, $shift);
        $id = (int) ($row['id'] ?? 0);
    }

    return $id;
}

function getAttendanceRecords(string $date = '', int $limit = 100): array
{
    $limit = max(1, min(500, $limit));
    if ($date !== '') {
        $stmt = getDb()->prepare('SELECT * FROM attendance_records WHERE record_date = ? ORDER BY shift ASC, id ASC LIMIT ' . $limit);
        $stmt->execute([$date]);
        return $stmt->fetchAll();
    }
    return getDb()->query('SELECT * FROM attendance_records ORDER BY record_date DESC, shift ASC, id ASC LIMIT ' . $limit)->fetchAll();
}

function confirmAttendanceRecord(int $id): bool
{
    $admin = getCurrentStaff() ?? [];
    $stmt = getDb()->prepare("UPDATE attendance_records SET status = 'confirmed', confirmed_by = ?, confirmed_at = ? WHERE id = ? AND status = 'pending'");
    $stmt->execute([(string) ($admin['username'] ?? ''), currentDateTime(), $id]);
    return $stmt->rowCount() > 0;
}

function attendanceRecordDetails(array $record): array
{
    $details = json_decode((string) ($record['details'] ?? ''), true);
    return is_array($details) ? $details : ['arrived' => [], 'absent' => []];
}

/** 未到者状态映射：arrived 之外且不在 absent 中的人默认未到。 */
function normalizeAbsentStatus(array $details, string $username): string
{
    $absent = $details['absent'] ?? [];
    return (string) ($absent[$username] ?? 'absent');
}

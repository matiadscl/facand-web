<?php
declare(strict_types=1);

function getDB(): SQLite3 {
    static $db = null;
    if ($db === null) {
        $db = new SQLite3('/home/coder/facand/facand.db');
        $db->enableExceptions(true);
        $db->busyTimeout(5000);
    }
    return $db;
}

function queryAll(string $sql, array $params = []): array {
    $db = getDB();
    $stmt = $db->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue(is_int($key) ? $key + 1 : $key, $val);
    }
    $result = $stmt->execute();
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    return $rows;
}

function queryOne(string $sql, array $params = []): ?array {
    $rows = queryAll($sql, $params);
    return $rows[0] ?? null;
}

function queryScalar(string $sql, array $params = []) {
    $row = queryOne($sql, $params);
    return $row ? reset($row) : null;
}

function execute(string $sql, array $params = []): void {
    $db = getDB();
    $stmt = $db->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue(is_int($key) ? $key + 1 : $key, $val);
    }
    $stmt->execute();
}

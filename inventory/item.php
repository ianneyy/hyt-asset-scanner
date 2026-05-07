<?php
header('Content-Type: application/json; charset=utf-8');

date_default_timezone_set('Asia/Manila');
function resolveDbFilePath(): string
{
    $isVercel = getenv('VERCEL') !== false || getenv('NOW_REGION') !== false;
    if ($isVercel) {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ims_inventory.db';
    }

    return dirname(__DIR__) . '/inventory.db';
}

$dbFile = resolveDbFilePath();
$db = null;
$usePdo = false;

if (class_exists('SQLite3')) {
    $db = new SQLite3($dbFile);
    $db->exec('CREATE TABLE IF NOT EXISTS inventory_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        item_code TEXT NOT NULL UNIQUE,
        item_name TEXT NOT NULL,
        quantity INTEGER NOT NULL DEFAULT 0,
        updated_at TEXT NOT NULL
    )');
} elseif (class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE IF NOT EXISTS inventory_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        item_code TEXT NOT NULL UNIQUE,
        item_name TEXT NOT NULL,
        quantity INTEGER NOT NULL DEFAULT 0,
        updated_at TEXT NOT NULL
    )');
    $usePdo = true;
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'SQLite support is not available.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST' && $method !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Use POST or GET method.',
        'example' => 'POST /inventory/item.php?id=TV001'
    ]);
    exit;
}

$itemCode = trim($_POST['id'] ?? $_GET['id'] ?? '');
$normalizedItemCode = strtoupper($itemCode);

if ($method === 'GET' && $normalizedItemCode !== '') {
    header('Content-Type: text/html; charset=utf-8');
    header('Location: /?pending_id=' . rawurlencode($normalizedItemCode));
    exit;
}

if ($itemCode === '') {
    $rows = [];
    if ($usePdo) {
        $result = $db->query('SELECT item_code, item_name, quantity, updated_at FROM inventory_items ORDER BY updated_at DESC, id DESC');
        foreach ($result as $row) {
            $rows[] = $row;
        }
    } else {
        $result = $db->query('SELECT item_code, item_name, quantity, updated_at FROM inventory_items ORDER BY updated_at DESC, id DESC');
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
    }
    echo json_encode(['success' => true, 'method' => $method, 'items' => $rows]);
    exit;
}

$itemMap = [
    'TV001' => 'TV',
    'LAP001' => 'Laptop',
    'PRN001' => 'Printer',
    'RTR001' => 'Router',
];

$itemName = $itemMap[$normalizedItemCode] ?? $normalizedItemCode;
$updatedAt = date('Y-m-d H:i:s');

if ($usePdo) {
    $stmt = $db->prepare('INSERT INTO inventory_items (item_code, item_name, quantity, updated_at)
        VALUES (:item_code, :item_name, 1, :updated_at)
        ON CONFLICT(item_code) DO UPDATE SET
            quantity = quantity + 1,
            updated_at = excluded.updated_at,
            item_name = excluded.item_name');
    $stmt->execute([
        ':item_code' => $normalizedItemCode,
        ':item_name' => $itemName,
        ':updated_at' => $updatedAt
    ]);

    $rows = [];
    $result = $db->query('SELECT item_code, item_name, quantity, updated_at FROM inventory_items ORDER BY updated_at DESC, id DESC');
    foreach ($result as $row) {
        $rows[] = $row;
    }
} else {
    $stmt = $db->prepare('INSERT INTO inventory_items (item_code, item_name, quantity, updated_at)
        VALUES (:item_code, :item_name, 1, :updated_at)
        ON CONFLICT(item_code) DO UPDATE SET
            quantity = quantity + 1,
            updated_at = excluded.updated_at,
            item_name = excluded.item_name');
    $stmt->bindValue(':item_code', $normalizedItemCode, SQLITE3_TEXT);
    $stmt->bindValue(':item_name', $itemName, SQLITE3_TEXT);
    $stmt->bindValue(':updated_at', $updatedAt, SQLITE3_TEXT);
    $stmt->execute();

    $rows = [];
    $result = $db->query('SELECT item_code, item_name, quantity, updated_at FROM inventory_items ORDER BY updated_at DESC, id DESC');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
}

echo json_encode([
    'success' => true,
    'method' => $method,
    'item' => [
        'item_code' => $normalizedItemCode,
        'item_name' => $itemName,
        'updated_at' => $updatedAt
    ],
    'items' => $rows
]);
exit;

<?php
// SQLite setup and AJAX API handler for scanned inventory items.
$dbFile = __DIR__ . '/inventory.db';
$db = null;
$usePdo = false;

if (class_exists('SQLite3')) {
    $db = new SQLite3($dbFile);
    $db->exec('CREATE TABLE IF NOT EXISTS scanned_items (id INTEGER PRIMARY KEY AUTOINCREMENT, item_name TEXT NOT NULL, scanned_at TEXT NOT NULL)');
} elseif (class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE IF NOT EXISTS scanned_items (id INTEGER PRIMARY KEY AUTOINCREMENT, item_name TEXT NOT NULL, scanned_at TEXT NOT NULL)');
    $usePdo = true;
} else {
    die('SQLite support is not available. Please enable SQLite3 or PDO SQLite in PHP.');
}

// Handle AJAX actions before rendering HTML.
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    if ($_GET['action'] === 'save') {
        $itemName = trim($_POST['item_name'] ?? $_GET['item_name'] ?? '');
        if ($itemName === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Item name is required.']);
            exit;
        }

        $scannedAt = date('Y-m-d H:i:s');
        if ($usePdo) {
            $stmt = $db->prepare('INSERT INTO scanned_items (item_name, scanned_at) VALUES (:item_name, :scanned_at)');
            $stmt->execute([':item_name' => $itemName, ':scanned_at' => $scannedAt]);
        } else {
            $stmt = $db->prepare('INSERT INTO scanned_items (item_name, scanned_at) VALUES (:item_name, :scanned_at)');
            $stmt->bindValue(':item_name', $itemName, SQLITE3_TEXT);
            $stmt->bindValue(':scanned_at', $scannedAt, SQLITE3_TEXT);
            $stmt->execute();
        }

        $items = [];
        if ($usePdo) {
            $result = $db->query('SELECT item_name, scanned_at FROM scanned_items ORDER BY id DESC LIMIT 50');
            foreach ($result as $row) {
                $items[] = $row;
            }
        } else {
            $result = $db->query('SELECT item_name, scanned_at FROM scanned_items ORDER BY id DESC LIMIT 50');
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $items[] = $row;
            }
        }

        echo json_encode(['success' => true, 'item' => ['item_name' => $itemName, 'scanned_at' => $scannedAt], 'items' => $items]);
        exit;
    }

    if ($_GET['action'] === 'list') {
        $items = [];
        if ($usePdo) {
            $result = $db->query('SELECT item_name, scanned_at FROM scanned_items ORDER BY id DESC LIMIT 50');
            foreach ($result as $row) {
                $items[] = $row;
            }
        } else {
            $result = $db->query('SELECT item_name, scanned_at FROM scanned_items ORDER BY id DESC LIMIT 50');
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $items[] = $row;
            }
        }
        echo json_encode(['success' => true, 'items' => $items]);
        exit;
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

$pendingItemCode = strtoupper(trim($_GET['pending_id'] ?? ''));
if (!preg_match('/^[A-Z0-9_-]{1,50}$/', $pendingItemCode)) {
    $pendingItemCode = '';
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>QR Inventory Tracker</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#fff7ed',
                            100: '#ffedd5',
                            500: '#f97316',
                            600: '#ea580c',
                        }
                    },
                    boxShadow: {
                        glass: '0 10px 30px rgba(15, 23, 42, 0.25)',
                    }
                }
            }
        }
    </script>
    <script src="https://unpkg.com/html5-qrcode@2.3.8/minified/html5-qrcode.min.js"></script>
</head>

<body class="bg-slate-950 text-slate-100 min-h-screen">
    <div class="min-h-screen px-4 py-8 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-6xl">
            <header class="mb-8 text-center">
                <p class="text-orange-400 uppercase tracking-[0.3em] text-sm font-semibold">QR Code Inventory</p>
                <h1 class="mt-4 text-4xl sm:text-5xl font-bold tracking-tight">Simple Scan-to-Track Inventory</h1>
                <p class="mt-3 text-slate-400 max-w-2xl mx-auto">Use your camera to scan QR items and save them
                    instantly. The list updates automatically and stores history in SQLite.</p>
            </header>

            <main class="grid gap-8">
                <aside
                    class="space-y-6 rounded-3xl border border-white/10 bg-white/5 p-6 shadow-glass backdrop-blur-xl">
                    <div class="rounded-3xl border border-orange-500/20 bg-slate-900/80 p-5">
                        <h2 class="text-2xl font-semibold">Recent Scans</h2>
                        <p class="mt-2 text-slate-400">History of items scanned during previous sessions.</p>
                    </div>

                    <div class="overflow-hidden rounded-3xl border border-white/10 bg-slate-900/80">
                        <table class="min-w-full divide-y divide-slate-800 text-left text-sm">
                            <thead class="bg-slate-950/90 text-slate-300">
                                <tr>
                                    
                                    <th class="px-4 py-3 font-medium">Item</th>
                                    <th class="px-4 py-3 font-medium">Qty</th>
                                    <th class="px-4 py-3 font-medium">Updated</th>
                                </tr>
                            </thead>
                            <tbody id="scanList" class="divide-y divide-slate-800 bg-slate-900 text-slate-200"></tbody>
                        </table>
                    </div>
                </aside>
            </main>
        </div>
    </div>

    <div id="scanModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/80 px-4 py-8">
        <div class="w-full max-w-xl rounded-[2rem] border border-orange-500/25 bg-slate-900/95 p-8 text-center shadow-glass transition duration-300 ease-out transform opacity-0 scale-95"
            data-modal-content>
            <p id="modalLabel" class="text-sm uppercase tracking-[0.3em] text-orange-400">Scan Success</p>
            <h2 id="modalItem" class="mt-5 text-4xl font-bold text-white">Item Name</h2>
            <p id="modalTime" class="mt-3 text-slate-300">Scanned at 10:30 AM</p>
            <div id="modalActions" class="mt-8 flex items-center justify-center gap-3">
                <button id="saveModal"
                    class="inline-flex items-center justify-center rounded-full bg-emerald-500 px-6 py-3 text-sm font-semibold text-slate-950 transition hover:bg-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-500/70">Save</button>
                <button id="cancelModal"
                    class="inline-flex items-center justify-center rounded-full bg-slate-600 px-6 py-3 text-sm font-semibold text-white transition hover:bg-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-400/70">Cancel</button>
                <button id="closeModal"
                    class="hidden inline-flex items-center justify-center rounded-full bg-orange-500 px-6 py-3 text-sm font-semibold text-slate-950 transition hover:bg-orange-400 focus:outline-none focus:ring-2 focus:ring-orange-500/70">Close</button>
            </div>
        </div>
    </div>

    <script>
        const pendingItemCodeFromUrl = <?php echo json_encode($pendingItemCode, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const scanButton = document.getElementById('scanButton');
        const itemCodeInput = document.getElementById('itemCodeInput');
        const scanList = document.getElementById('scanList');
        const scanModal = document.getElementById('scanModal');
        const modalLabel = document.getElementById('modalLabel');
        const modalItem = document.getElementById('modalItem');
        const modalTime = document.getElementById('modalTime');
        const saveModal = document.getElementById('saveModal');
        const cancelModal = document.getElementById('cancelModal');
        const closeModal = document.getElementById('closeModal');
        const modalContent = document.querySelector('[data-modal-content]');
        const scanStatus = document.getElementById('scanStatus');
        let pendingItemCode = pendingItemCodeFromUrl || '';

        function setScanStatus(message, type = 'info') {
            if (!scanStatus) return;
            scanStatus.textContent = message;
            scanStatus.classList.remove('text-slate-400', 'text-orange-300', 'text-red-400', 'text-emerald-400');
            if (type === 'error') {
                scanStatus.classList.add('text-red-400');
                return;
            }
            if (type === 'success') {
                scanStatus.classList.add('text-emerald-400');
                return;
            }
            if (type === 'warn') {
                scanStatus.classList.add('text-orange-300');
                return;
            }
            scanStatus.classList.add('text-slate-400');
        }

        function formatTime(dateString) {
            const date = new Date(dateString.replace(' ', 'T'));
            return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }

        function openModal(item, scannedAt) {
            modalItem.textContent = item;
            modalTime.textContent = `Scanned at ${formatTime(scannedAt)}`;
            scanModal.classList.remove('hidden');
            requestAnimationFrame(() => {
                modalContent.classList.remove('opacity-0', 'scale-95');
                modalContent.classList.add('opacity-100', 'scale-100');
            });
        }

        function openSuccessModal(item, scannedAt) {
            modalLabel.textContent = 'Scan Success';
            saveModal.classList.add('hidden');
            cancelModal.classList.add('hidden');
            closeModal.classList.remove('hidden');
            openModal(item, scannedAt);
        }

        function openConfirmModal(itemCode) {
            modalLabel.textContent = 'Pending Item';
            modalItem.textContent = itemCode;
            modalTime.textContent = 'Save this item to local database?';
            saveModal.classList.remove('hidden');
            cancelModal.classList.remove('hidden');
            closeModal.classList.add('hidden');
            scanModal.classList.remove('hidden');
            requestAnimationFrame(() => {
                modalContent.classList.remove('opacity-0', 'scale-95');
                modalContent.classList.add('opacity-100', 'scale-100');
            });
        }

        function clearPendingItemFromUrl() {
            const url = new URL(window.location.href);
            if (url.searchParams.has('pending_id')) {
                url.searchParams.delete('pending_id');
                window.history.replaceState({}, '', url.toString());
            }
        }

        function closeModalWindow() {
            modalContent.classList.add('opacity-0', 'scale-95');
            scanModal.classList.add('hidden');
        }

        function renderScanList(items) {
            if (!Array.isArray(items)) items = [];
            scanList.innerHTML = items.map(item => {
                return `
                    <tr class="transition hover:bg-slate-800/80">
                        <td class="px-4 py-4 font-medium text-white">${escapeHtml(item.item_name)}</td>
                        <td class="px-4 py-4 text-slate-300">${escapeHtml(item.quantity ?? 0)}</td>
                        <td class="px-4 py-4 text-slate-400">${formatTime(item.updated_at)}</td>
                    </tr>
                `;
            }).join('');
        }

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        async function loadScannedItems() {
            try {
                const response = await fetch('inventory/item.php');
                const data = await response.json();
                if (data.success) {
                    renderScanList(data.items);
                }
            } catch (error) {
                console.error('Could not load scanned items', error);
                setScanStatus('Could not load inventory list.', 'error');
            }
        }

        async function saveScannedItem(itemCode) {
            try {
                const formData = new URLSearchParams();
                formData.append('id', itemCode);
                const response = await fetch(`inventory/item.php?id=${encodeURIComponent(itemCode)}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: formData.toString(),
                });
                const data = await response.json();
                if (data.success) {
                    openSuccessModal(`${data.item.item_name} (${data.item.item_code})`, data.item.updated_at);
                    renderScanList(data.items);
                    setScanStatus(`Scanned ${data.item.item_code} successfully.`, 'success');
                    if (itemCodeInput) {
                        itemCodeInput.value = '';
                        itemCodeInput.focus();
                    }
                }
            } catch (error) {
                console.error('Could not save scan', error);
                setScanStatus('Could not submit scan.', 'error');
            }
        }

        function showSuccessEffect() {
            if (!scanButton) return;
            scanButton.classList.add('animate-pulse');
            setTimeout(() => {
                scanButton.classList.remove('animate-pulse');
            }, 700);
        }

        async function submitScan() {
            if (!itemCodeInput || !scanButton) return;
            const itemCode = itemCodeInput.value.trim().toUpperCase();
            if (!itemCode) {
                setScanStatus('Please enter an item code first.', 'warn');
                return;
            }
            scanButton.disabled = true;
            scanButton.classList.add('opacity-60', 'cursor-not-allowed');
            setScanStatus('Submitting scan...', 'warn');
            await saveScannedItem(itemCode);
            showSuccessEffect();
            scanButton.disabled = false;
            scanButton.classList.remove('opacity-60', 'cursor-not-allowed');
        }

        if (scanButton) {
            scanButton.addEventListener('click', submitScan);
        }
        if (itemCodeInput) {
            itemCodeInput.addEventListener('keydown', event => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    submitScan();
                }
            });
        }
        closeModal.addEventListener('click', closeModalWindow);
        saveModal.addEventListener('click', async () => {
            if (!pendingItemCode) {
                closeModalWindow();
                return;
            }
            saveModal.disabled = true;
            cancelModal.disabled = true;
            await saveScannedItem(pendingItemCode);
            pendingItemCode = '';
            clearPendingItemFromUrl();
            saveModal.disabled = false;
            cancelModal.disabled = false;
        });
        cancelModal.addEventListener('click', () => {
            pendingItemCode = '';
            clearPendingItemFromUrl();
            closeModalWindow();
            setScanStatus('Save cancelled.', 'warn');
        });
        scanModal.addEventListener('click', event => {
            if (event.target === scanModal) {
                closeModalWindow();
            }
        });

        if (itemCodeInput) {
            itemCodeInput.focus();
        }
        loadScannedItems();
        if (pendingItemCode) {
            setScanStatus(`Pending item ${pendingItemCode}. Choose Save or Cancel.`, 'warn');
            openConfirmModal(pendingItemCode);
        }
    </script>
</body>

</html>
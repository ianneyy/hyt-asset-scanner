<?php
// SQLite setup and AJAX API handler for scanned inventory items.
date_default_timezone_set('Asia/Manila');
function resolveDbFilePath(): string
{
    $isVercel = getenv('VERCEL') !== false || getenv('NOW_REGION') !== false;
    if ($isVercel) {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ims_inventory.db';
    }

    return __DIR__ . '/inventory.db';
}

$dbFile = resolveDbFilePath();
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
    <link href="https://cdn.jsdelivr.net/npm/daisyui@5" rel="stylesheet" type="text/css" />
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

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
</head>

<body class="bg-neutral-950 text-slate-100 min-h-screen">
    <div class="min-h-screen px-4 py-8 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-6xl">
            <header class="mb-8 text-center">
                <h1 class="mt-4 text-4xl sm:text-5xl font-bold tracking-tight">HYT Business Center IT Assets</h1>
                <p class="mt-3 text-neutral-400 max-w-2xl mx-auto">Scan QR items and save them
                    instantly. The list updates automatically and stores history.</p>
            </header>
            <div class="absolute bottom-4 right-4">
                <!-- a focusable div with tabindex is necessary to work on all browsers. role="button" is necessary for accessibility -->
                <div id="cameraScanBtn" role="button" tabindex="0" class="btn btn-lg btn-circle btn-primary"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-scan-qr-code-icon lucide-scan-qr-code">
                        <path d="M17 12v4a1 1 0 0 1-1 1h-4" />
                        <path d="M17 3h2a2 2 0 0 1 2 2v2" />
                        <path d="M17 8V7" />
                        <path d="M21 17v2a2 2 0 0 1-2 2h-2" />
                        <path d="M3 7V5a2 2 0 0 1 2-2h2" />
                        <path d="M7 17h.01" />
                        <path d="M7 21H5a2 2 0 0 1-2-2v-2" />
                        <rect x="7" y="7" width="5" height="5" rx="1" />
                    </svg></div>


            </div>

            <main class="w-full">
                <aside class="w-full rounded-3xl lg:border border-neutral-800 bg-neutral-950 p-0 lg:p-6 shadow-glass">
                    <div class="space-y-5">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <input type="text" placeholder="Filter assets..."
                                class="w-full rounded-xl border border-neutral-800 bg-neutral-950 px-4 py-2.5 text-sm text-neutral-200 placeholder:text-neutral-500 outline-none sm:max-w-md" />
                            <!-- <button type="button"
                                class="inline-flex items-center justify-center gap-2 rounded-xl border border-neutral-800 bg-neutral-900 px-4 py-2.5 text-sm font-medium text-neutral-200">
                                Columns
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-trash-icon lucide-trash">
                                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" />
                                    <path d="M3 6h18" />
                                    <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                </svg>
                            </button> -->
                        </div>

                        <div class="overflow-hidden rounded-2xl border border-neutral-800 bg-neutral-950">
                            <table class="min-w-full text-left text-sm">
                                <thead class="border-b border-neutral-800 text-neutral-300">
                                    <tr>
                                        <th class="w-15 px-3 py-3">
                                            <input
                                                type="checkbox"

                                                class="checkbox border-neutral-100 rounded-md bg-neutral-900 checked:border-neutral-900 checked:bg-neutral-200 checked:text-neutral-900 p-1 checkbox-sm" />
                                        </th>
                                        <th class="px-3 py-3 font-medium">Name</th>
                                        <th class="px-3 py-3 font-medium">Qty</th>
                                        <th class="px-3 py-3 font-medium">Updated</th>
                                        <th class="w-12 px-3 py-3"></th>
                                    </tr>
                                </thead>
                                <tbody id="scanList" class="divide-y divide-neutral-800 text-neutral-100">

                                </tbody>
                            </table>
                        </div>

                        <div class="flex flex-col gap-3 text-sm text-neutral-400 sm:flex-row sm:items-center sm:justify-between">
                            <p>0 of 5 row(s) selected.</p>
                            <div class="flex items-center gap-2">
                                <button type="button"
                                    class="rounded-xl border border-neutral-800 bg-neutral-900 px-4 py-2 text-neutral-500">Previous</button>
                                <button type="button"
                                    class="rounded-xl border border-neutral-800 bg-neutral-900 px-4 py-2 text-neutral-500">Next</button>
                            </div>
                        </div>
                    </div>
                </aside>
            </main>
        </div>
    </div>

    <div id="scanModal" class="fixed inset-0 z-50 hidden  items-center justify-center bg-neutral-900/80 px-4 py-8">
        <div class="w-full max-w-xl rounded-2xl border border-neutral-800 bg-neutral-900 p-8 text-center shadow-glass transition duration-300 ease-out transform scale-95"
            data-modal-content>
            <p id="modalLabel" class="text-sm uppercase tracking-[0.3em] text-neutral-400">Scan Success!</p>
            <h2 id="modalItem" class="mt-5 text-4xl font-bold text-white">Item Name</h2>
            <p id="modalTime" class="mt-3 text-slate-300">Scanned at 10:30 AM</p>
            <div id="modalActions" class="mt-8 flex items-center justify-center gap-3">

                <button id="cancelModal"
                    class="inline-flex items-center justify-center rounded-full  px-6 py-3 text-sm font-semibold text-neutral-400 transition focus:outline-none focus:ring-2 focus:ring-slate-400/70">Cancel</button>
                <button id="saveModal"
                    class="inline-flex items-center justify-center rounded-full bg-neutral-200 px-6 py-3 text-sm font-semibold text-slate-950 transition hover:bg-neutral-300 focus:outline-none focus:ring-2 focus:ring-emerald-500/70">Save</button>
                <button id="closeModal"
                    class="hidden inline-flex items-center justify-center rounded-full bg-neutral-200 px-6 py-3 text-sm font-semibold text-neutral-900 transition hover:bg-neutral-300 focus:outline-none focus:ring-2 focus:ring-neutral-200/70">Close</button>
            </div>
        </div>
    </div>

    <div id="cameraModal" class="fixed inset-0 z-50 hidden flex items-center justify-center bg-neutral-900/80 px-4 py-8">
        <div class="w-full max-w-xl rounded-2xl border border-neutral-800 bg-neutral-950 p-5 shadow-glass">
            <div class="flex items-center justify-between gap-3 border-b border-neutral-800 pb-3">
                <div>
                    <p class="text-xs uppercase tracking-[0.3em] text-neutral-400">Camera Scanner</p>
                    <p class="mt-1 text-sm text-neutral-300">Point your camera at a QR code.</p>
                </div>
                <button id="closeCameraModal" type="button"
                    class="inline-flex items-center justify-center rounded-xl border border-neutral-800 bg-neutral-900 px-3 py-2 text-sm font-medium text-neutral-200 hover:bg-neutral-800">
                    Close
                </button>
            </div>
            <div class="pt-4">
                <div class="overflow-hidden rounded-2xl border border-neutral-800 bg-neutral-950">
                    <video id="cameraVideo" class="h-[60vh] w-full bg-neutral-950 object-cover sm:h-[420px]" autoplay
                        playsinline muted></video>
                </div>
                <p id="cameraHint" class="mt-3 text-sm text-neutral-400"></p>
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
        const cameraScanBtn = document.getElementById('cameraScanBtn');
        const cameraModal = document.getElementById('cameraModal');
        const closeCameraModal = document.getElementById('closeCameraModal');
        const cameraHint = document.getElementById('cameraHint');
        const cameraVideo = document.getElementById('cameraVideo');
        let cameraStream = null;
        let barcodeDetector = null;
        let scanRafId = null;
        let isScanning = false;
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
            return date.toLocaleTimeString([], {
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        function openModal(item, scannedAt) {
            modalItem.textContent = item;
            modalTime.textContent = `Scanned at ${formatTime(scannedAt)}`;
            scanModal.classList.remove('hidden');
            scanModal.classList.add('flex');
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

        async function stopCameraScanner() {
            isScanning = false;

            if (scanRafId) {
                cancelAnimationFrame(scanRafId);
                scanRafId = null;
            }

            if (cameraVideo) {
                cameraVideo.pause();
                cameraVideo.srcObject = null;
            }

            if (cameraStream) {
                for (const track of cameraStream.getTracks()) {
                    track.stop();
                }
                cameraStream = null;
            }
        }

        async function scanLoop() {
            if (!isScanning || !barcodeDetector || !cameraVideo) return;
            if (cameraVideo.readyState < 2) {
                scanRafId = requestAnimationFrame(scanLoop);
                return;
            }

            try {
                const barcodes = await barcodeDetector.detect(cameraVideo);
                const first = barcodes && barcodes[0];
                const rawValue = first && (first.rawValue || first.data);
                const code = String(rawValue || '').trim();
                if (code) {
                    await stopCameraScanner();
                    if (cameraModal) cameraModal.classList.add('hidden');
                    window.location.href = `inventory/item.php?id=${encodeURIComponent(code)}`;
                    return;
                }
            } catch (e) {
                // ignore detect errors and keep scanning
            }

            scanRafId = requestAnimationFrame(scanLoop);
        }

        async function openCameraScanner() {
            if (!cameraModal) return;
            cameraModal.classList.remove('hidden');
            if (cameraHint) cameraHint.textContent = '';

            try {
                if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                    if (cameraHint) cameraHint.textContent = 'Camera is not supported on this browser.';
                    return;
                }

                await stopCameraScanner();

                cameraStream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: {
                            ideal: 'environment'
                        }
                    },
                    audio: false
                });

                if (!cameraVideo) {
                    if (cameraHint) cameraHint.textContent = 'Video element not found.';
                    return;
                }

                cameraVideo.srcObject = cameraStream;
                await cameraVideo.play();

                if ('BarcodeDetector' in window) {
                    try {
                        barcodeDetector = new BarcodeDetector({
                            formats: ['qr_code']
                        });
                        isScanning = true;
                        scanRafId = requestAnimationFrame(scanLoop);
                        if (cameraHint) cameraHint.textContent = 'Scanning...';
                    } catch (e) {
                        barcodeDetector = null;
                        if (cameraHint) cameraHint.textContent = 'Camera is open. QR scanning not supported on this browser.';
                    }
                } else {
                    if (cameraHint) cameraHint.textContent = 'Camera is open. QR scanning not supported on this browser.';
                }
            } catch (error) {
                console.error('Camera error:', error);
                if (cameraHint) cameraHint.textContent = 'Camera access blocked or unavailable. Please allow camera permission.';
            }
        }

        async function closeCameraScanner() {
            await stopCameraScanner();
            if (cameraModal) {
                cameraModal.classList.add('hidden');
            }
        }

        function renderScanList(items) {
            if (!Array.isArray(items)) items = [];
            scanList.innerHTML = items.map(item => {
                return `
                    <tr class="transition hover:bg-neutral-900/80">
                     <td class="px-3 py-3"> <input
                                                type="checkbox"
                                                
                                                class="checkbox border-indigo-600 bg-neutral-900 checked:border-neutral-900 checked:bg-neutral-200 rounded-md  checked:text-neutral-900 p-1 checkbox-sm" /></td>
                        <td class="px-4 py-4 font-medium text-white">${escapeHtml(item.item_name)}</td>
                        <td class="px-4 py-4 text-slate-300">${escapeHtml(item.quantity ?? 0)}</td>
                        <td class="px-4 py-4 text-neutral-400">${formatTime(item.updated_at)}</td>
                         <td class="px-3 py-3">
                                            <button type="button" class="text-neutral-400 hover:text-neutral-200">
                                               <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-trash-icon lucide-trash"><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>   
                                            </button>
                                        </td>
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
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: formData.toString(),
                });
                const data = await response.json();
                if (data.success) {
                    const itemName = String(data.item.item_name || '').trim();
                    const itemCode = String(data.item.item_code || '').trim();
                    const modalTitle = itemName && itemCode && itemName.toUpperCase() !== itemCode.toUpperCase() ?
                        `${itemName} - ${itemCode}` :
                        (itemName || itemCode);
                    openSuccessModal(modalTitle, data.item.updated_at);
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
        if (cameraScanBtn) {
            cameraScanBtn.addEventListener('click', openCameraScanner);
            cameraScanBtn.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    openCameraScanner();
                }
            });
        }
        if (closeCameraModal) {
            closeCameraModal.addEventListener('click', closeCameraScanner);
        }
        if (cameraModal) {
            cameraModal.addEventListener('click', (event) => {
                if (event.target === cameraModal) {
                    closeCameraScanner();
                }
            });
        }
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
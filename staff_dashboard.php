<?php
session_start();
include("config.php");
include(__DIR__ . '/phpqrcode/qrlib.php'); 

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'staff') {
    header("Location: index.php");
    exit();
}

$error = "";
$success = "";
$user_id = $_SESSION['id'];


// HANDLE ADD ITEM

if (isset($_POST['add_item'])) {
    $item_name = trim($_POST['item_name']);
    $description = trim($_POST['description']);
    $price = (float)$_POST['price'];
    $cost_price = $price;
    $image_path = '';

    // Image upload
    if (isset($_FILES['item_image']) && $_FILES['item_image']['error'] == 0) {
        $target_dir = "item_images/";
        $original_filename = basename($_FILES["item_image"]["name"]);
        $imageFileType = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));
        $safe_name = preg_replace('/[^a-z0-9]+/i', '_', $item_name);
        $new_filename = $safe_name . '_' . time() . '.' . $imageFileType;
        $target_file = $target_dir . $new_filename;

        if (!in_array($imageFileType, ['jpg', 'png', 'jpeg', 'gif'])) {
            $error = "Only JPG, JPEG, PNG & GIF allowed.";
        } elseif (!move_uploaded_file($_FILES["item_image"]["tmp_name"], $target_file)) {
            $error = "Error uploading file.";
        } else {
            $image_path = $new_filename;
        }
    }

    if (empty($error)) {
        $check_stmt = $conn->prepare("SELECT id FROM items WHERE item_name = ?");
        $check_stmt->bind_param("s", $item_name);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            $error = "Item name already exists.";
        } else {
            $default_markup = 0.00;
            $stmt = $conn->prepare("INSERT INTO items (item_name, description, price, cost_price, submitted_by, image_path, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
            $stmt->bind_param("ssddis", $item_name, $description, $price, $cost_price, $user_id, $image_path);
            if ($stmt->execute()) {
                $item_id = $stmt->insert_id;

                // Generate QR
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                $host = $_SERVER['HTTP_HOST'];
                $uri = dirname($_SERVER['PHP_SELF']);
                $base_url = $protocol . "://" . $host . rtrim($uri, '/'); 
                $item_url = $base_url . '/items.php?id=' . $item_id;
                $item_url = 'http://192.168.254.193/FinalsWeb/items.php?id=' . $item_id; 
                $qr_folder = 'qrcodes/'; 
                if (!is_dir($qr_folder)) { mkdir($qr_folder, 0777, true); }
                $safe_item_name = preg_replace('/[^a-z0-9]+/i', '_', $item_name);
                $qr_file_name = $safe_item_name . '_' . $item_id . '.png';
                $qr_file_path = $qr_folder . $qr_file_name;

                QRcode::png($item_url, $qr_file_path, 'L', 4, 2);
                $conn->query("UPDATE items SET qr_code_path = '{$qr_file_name}' WHERE id = {$item_id}");

                // Notify admins
                $admins = $conn->query("SELECT id FROM users WHERE role='admin'");
                while($admin = $admins->fetch_assoc()) {
                    $stmt2 = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                    $msg = "New item '$item_name' submitted for admin approval.";
                    $stmt2->bind_param("is", $admin['id'], $msg);
                    $stmt2->execute();
                }

                $success = "Item submitted for admin approval!";
            } else {
                $error = "Failed to add item: " . $conn->error;
            }
        }
    }
}

// =====================
// HANDLE STOCK PROPOSAL
// =====================
if (isset($_POST['propose_stock'])) {
    $item_id = (int)$_POST['item_id'];
    $quantity = (int)$_POST['quantity'];
    $type = $_POST['stock_type'];
    // Check for existing ID or new name
    $company_id = isset($_POST['company_id']) ? (int)$_POST['company_id'] : 0; 
    $new_company_name = trim($_POST['new_company_name'] ?? '');

    // 1. Handle New Company Creation if a name is provided
    if (!empty($new_company_name)) {
        // Check if company already exists (case-insensitive check for name uniqueness)
        $check_comp_stmt = $conn->prepare("SELECT id FROM company_names WHERE name = ?");
        $check_comp_stmt->bind_param("s", $new_company_name);
        $check_comp_stmt->execute();
        $check_comp_result = $check_comp_stmt->get_result();

        if ($check_comp_result->num_rows > 0) {
            // If it exists, use its ID and clear the input to ensure logic flow
            $company_id = $check_comp_result->fetch_assoc()['id'];
            $new_company_name = ''; // Treat as existing
        } else {
            // If it doesn't exist, insert new company
            $insert_comp_stmt = $conn->prepare("INSERT INTO company_names (name) VALUES (?)");
            $insert_comp_stmt->bind_param("s", $new_company_name);
            if ($insert_comp_stmt->execute()) {
                $company_id = $insert_comp_stmt->insert_id;
            } else {
                $error = "Failed to add new company: " . $conn->error;
            }
        }
    }
    // 2. Process Transaction
if ($quantity > 0 && $company_id > 0 && empty($error)) {
    // Look up item price and markup
    $item_info_q = $conn->prepare("SELECT price, net_interest_percent FROM items WHERE id = ?");
    $item_info_q->bind_param("i", $item_id);
    $item_info_q->execute();
    $item_info = $item_info_q->get_result()->fetch_assoc();

    if ($item_info) {
        $base_price = (float)$item_info['price'];
        $markup = (float)$item_info['net_interest_percent'];
        
        $transaction_price = $base_price; // Default: Cost Price
        
        // If 'out' (sale), calculate the selling price
        if ($type === 'out') {
            // Selling Price = Base Price * (1 + Markup / 100)
            $selling_price = $base_price * (1 + ($markup / 100));
            $transaction_price = $selling_price;
        }

        // INSERT into stock_transactions, now including transaction_price
        $stmt = $conn->prepare("INSERT INTO stock_transactions (item_id, type, quantity, user_id, company_id, transaction_price, status) VALUES (?,?,?,?,?,?, 'pending')");
        // Note: The bind_param has changed to include the double 'd' for transaction_price
        $stmt->bind_param("isiiid", $item_id, $type, $quantity, $user_id, $company_id, $transaction_price);
        $stmt->execute();

        // ... rest of the notification code (keep it as is) ...
        $item_name = $conn->query("SELECT item_name FROM items WHERE id=$item_id")->fetch_assoc()['item_name'];
        $company_name_q = $conn->query("SELECT name FROM company_names WHERE id=$company_id");
        $company_name = $company_name_q->num_rows > 0 ? $company_name_q->fetch_assoc()['name'] : 'Unknown Company';
        $action = ($type === 'in') ? "Stock IN from $company_name" : "Stock OUT to $company_name";

        $admins = $conn->query("SELECT id FROM users WHERE role='admin'");
        while($admin = $admins->fetch_assoc()){
            $stmt2 = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
            $msg = "New staff proposal: $action for item '$item_name' (Qty: $quantity, Price: " . number_format($transaction_price, 2) . "), pending admin approval.";
            $stmt2->bind_param("is", $admin['id'], $msg);
            $stmt2->execute();
        }

        $success = "Stock $type proposal submitted for admin approval!";
    } else {
        $error = "Error: Could not retrieve item information.";
    }
} elseif (empty($error)) {
    $error = "Quantity must be greater than 0, and a company must be selected or provided!";
}
}

// =====================
// FETCH APPROVED ITEMS (UPDATED to include net_interest_percent)
// =====================
$items_query = "
    SELECT 
        i.id, i.item_name, i.price, i.net_interest_percent, i.qr_code_path, 
        IFNULL(SUM(CASE WHEN t.type='in' AND t.status='approved' THEN t.quantity ELSE 0 END),0) -
        IFNULL(SUM(CASE WHEN t.type='out' AND t.status='approved' THEN t.quantity ELSE 0 END),0) AS current_stock
    FROM items i
    LEFT JOIN stock_transactions t ON i.id = t.item_id
    WHERE i.status = 'approved' 
    GROUP BY i.id, i.net_interest_percent, i.item_name, i.price, i.qr_code_path 
    ORDER BY i.item_name ASC
";
$items_result = $conn->query($items_query);

// =====================
// FETCH PENDING TRANSACTIONS
// =====================
$pending_transactions_query = $conn->prepare("
    SELECT 
        t.id, i.item_name, t.type, t.quantity, t.created_at
    FROM stock_transactions t
    JOIN items i ON t.item_id = i.id
    WHERE t.user_id = ? AND t.status = 'pending'
    ORDER BY t.created_at DESC
");
$pending_transactions_query->bind_param("i", $user_id);
$pending_transactions_query->execute();
$pending_transactions_result = $pending_transactions_query->get_result();

// Re-fetch all companies for the dropdown, including newly added ones
$companies_result = $conn->query("SELECT id, name FROM company_names ORDER BY name ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Staff Dashboard</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
body { font-family: 'Inter', sans-serif; background-color: #f4f7f9; }
.modal-active { display: flex; }
</style>
</head>
<body class="p-4 sm:p-8">

<header class="flex justify-between items-center mb-8">
    <h1 class="text-3xl font-extrabold text-gray-900">Staff Inventory Dashboard</h1>
    <a href="logout.php" class="py-2 px-4 bg-red-500 hover:bg-red-600 text-white font-semibold rounded-lg shadow-md transition duration-150">Logout</a>
</header>

<?php if($error): ?><div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if($success): ?><div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<div class="mb-8 flex space-x-4">
<button onclick="openModal('addItemModal')" class="py-3 px-6 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-lg shadow-lg">Add New Item</button>
 <button onclick="openModal('pendingTransactionsModal')" class="py-3 px-6 bg-yellow-500 hover:bg-yellow-600 text-white font-bold rounded-lg shadow-lg">View My Pending Transactions</button>
</div>

<section class="bg-white p-6 rounded-xl shadow-xl">
    <h2 class="text-2xl font-bold text-gray-800 mb-4 border-b pb-2">Approved Inventory</h2>
    
    <?php if ($items_result->num_rows > 0): ?>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Price (₱)</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Stock</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">QR Code</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php while($item = $items_result->fetch_assoc()): ?>
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($item['item_name']) ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= number_format($item['price'], 2) ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-bold <?= $item['current_stock'] > 0 ? 'text-green-600' : 'text-red-600' ?>"><?= $item['current_stock'] ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        <?php if (!empty($item['qr_code_path'])): ?>
                        <a href="qrcodes/<?= htmlspecialchars($item['qr_code_path']) ?>" target="_blank" class="text-blue-600 hover:text-blue-800 font-semibold">View QR</a>
                        <?php else: ?> N/A <?php endif; ?>
                    </td>
                 <td class="px-6 py-4 whitespace-nowrap text-sm">
    <button onclick="showStockModal(<?= $item['id'] ?>,'in', <?= $item['price'] ?>, 0)" class="px-3 py-1 bg-green-500 text-white rounded-lg hover:bg-green-600 font-semibold">Stock IN</button>
    <button onclick="showStockModal(<?= $item['id'] ?>,'out', <?= $item['price'] ?>, <?= $item['net_interest_percent'] ?>)" class="px-3 py-1 bg-red-500 text-white rounded-lg hover:bg-red-600 font-semibold">Stock OUT</button>
</td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <p class="text-gray-500">No approved items found.</p>
    <?php endif; ?>
</section>

<!-- Add Item Modal -->
<div id="addItemModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-75 items-center justify-center z-50 p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg p-6">
        <h3 class="text-2xl font-bold text-gray-800 mb-6 border-b pb-2">Add New Item Proposal</h3>
        <form method="POST" action="staff_dashboard.php" enctype="multipart/form-data">
            <div class="mb-4">
                <label for="item_name" class="block text-gray-700 font-semibold mb-2">Item Name</label>
                <input type="text" id="item_name" name="item_name" required class="w-full p-3 border border-gray-300 rounded-lg">
            </div>
            <div class="mb-4">
                <label for="description" class="block text-gray-700 font-semibold mb-2">Description</label>
                <textarea id="description" name="description" rows="4" required class="w-full p-3 border border-gray-300 rounded-lg"></textarea>
            </div>
            <div class="mb-4 flex space-x-4">
                <div class="w-1/2">
                    <label for="price" class="block text-gray-700 font-semibold mb-2">Selling Price (₱)</label>
                    <input type="number" step="0.01" min="0" id="price" name="price" required class="w-full p-3 border border-gray-300 rounded-lg">
                </div>
               
            </div>
            <div class="mb-6">
                <label for="item_image" class="block text-gray-700 font-semibold mb-2">Item Image (Optional)</label>
                <input type="file" id="item_image" name="item_image" accept="image/*" class="w-full p-3 border border-gray-300 rounded-lg">
            </div>
            <div class="flex justify-end space-x-3">
                <button type="button" onclick="closeModal('addItemModal')" class="py-2 px-4 bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold rounded-lg">Cancel</button>
                <button type="submit" name="add_item" class="py-2 px-4 bg-green-600 hover:bg-green-700 text-white font-bold rounded-lg">Submit for Approval</button>
            </div>
        </form>
    </div>
</div>

<div id="stockModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-75 flex justify-center items-center p-4 z-50">

    <div class="bg-white rounded-xl shadow-2xl p-6 w-full max-w-sm">

        <h3 id="stockModalTitle" class="text-2xl font-bold text-gray-800 mb-4 text-center"></h3>

        <form id="stockForm" method="POST" action="staff_dashboard.php">

            <input type="hidden" id="modalItemId" name="item_id">

            <input type="hidden" id="modalStockType" name="stock_type">

            

                        <input type="hidden" id="modalItemPrice" value="0"> 
                        <input type="hidden" id="modalItemMarkup" value="0">

            

            <div class="mb-4">

                <label for="quantity" class="block text-gray-700 font-semibold mb-2">Quantity:</label>

                                <input type="number" id="modalQuantity" name="quantity" min="1" required 

                       class="w-full p-3 border border-gray-300 rounded-lg text-center"

                       oninput="calculateTotal()">

            </div>

            

                        <div id="modalTotalCalculation" 

                 class="text-lg font-bold my-4 p-3 rounded-lg text-center border-2" 

                 style="display: none;">

                Total: ₱0.00

            </div>

            

            <div class="mb-4">

                <label for="company_selection" class="block text-gray-700 font-semibold mb-2" id="companyLabel">Supplier/Buyer Company:</label>
                <!-- Existing Company Dropdown -->
                <div id="existingCompanyDiv">
                    <select id="modalCompanyId" name="company_id" required class="w-full p-3 border border-gray-300 rounded-lg">
                        <option value="">-- Select Company --</option>
                        <?php
                            // Use the re-fetched list
                            while($comp = $companies_result->fetch_assoc()):
                        ?>
                            <option value="<?= $comp['id'] ?>"><?= htmlspecialchars($comp['name']) ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <!-- New Company Input (Hidden by default) -->
                <div id="newCompanyDiv" class="hidden">
                    <input type="text" id="newCompanyName" name="new_company_name" placeholder="Enter new company name..." class="w-full p-3 border border-blue-400 rounded-lg">
                </div>
            </div>

            <!-- Toggle Button -->
            <button type="button" onclick="toggleCompanyInput()" id="toggleCompanyButton" class="text-sm text-blue-600 hover:text-blue-800 mb-4 font-semibold w-full text-center py-1 rounded-lg">
                + Add New Company
            </button>

            <div class="flex space-x-3">
                <button type="submit" name="propose_stock" class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-lg">Submit Proposal</button>
                <button type="button" onclick="closeStockModal()" class="py-3 px-6 bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold rounded-lg">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Pending Transactions Modal (Unchanged) -->
<div id="pendingTransactionsModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-75 items-center justify-center z-50 p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl p-6">
        <h3 class="text-2xl font-bold text-gray-800 mb-6 border-b pb-2">My Pending Stock Proposals</h3>
        <?php if ($pending_transactions_result->num_rows > 0): ?>
        <div class="overflow-x-auto max-h-96">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50 sticky top-0">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Submitted</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php while($transaction = $pending_transactions_result->fetch_assoc()): ?>
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($transaction['item_name']) ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold <?= $transaction['type'] === 'in' ? 'text-green-600' : 'text-red-600' ?>"><?= strtoupper($transaction['type']) ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= $transaction['quantity'] ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= date("M d, Y H:i", strtotime($transaction['created_at'])) ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <p class="text-gray-500">No pending stock transactions awaiting admin approval.</p>
        <?php endif; ?>
        <div class="flex justify-end mt-6">
            <button type="button" onclick="closeModal('pendingTransactionsModal')" class="py-2 px-4 bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold rounded-lg">Close</button>
        </div>
    </div>
</div>

<script>
function openModal(id) { document.getElementById(id).classList.add('modal-active'); document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.remove('modal-active'); document.getElementById(id).classList.add('hidden'); }

let isNewCompanyMode = false;

// ------------------------------------------------
// MODIFIED showStockModal FUNCTION (Now accepts itemPrice AND itemMarkup)
// ------------------------------------------------
function showStockModal(itemId, type, itemPrice, itemMarkup) {
    document.getElementById('modalItemId').value = itemId;
    document.getElementById('modalStockType').value = type;
    
    // Store the item's base price
    document.getElementById('modalItemPrice').value = itemPrice; 
    
    // NEW: Store the item's markup percentage
    document.getElementById('modalItemMarkup').value = itemMarkup; 

    // Reset quantity input
    document.getElementById('modalQuantity').value = '';
    
    // Reset to existing company mode
    isNewCompanyMode = false;
    document.getElementById('newCompanyDiv').classList.add('hidden');
    document.getElementById('existingCompanyDiv').classList.remove('hidden');
    document.getElementById('newCompanyName').value = ''; 
    document.getElementById('modalCompanyId').selectedIndex = 0;
    document.getElementById('modalCompanyId').required = true;
    document.getElementById('newCompanyName').required = false;

    const title = type === 'in' ? 'Propose Stock IN' : 'Propose Stock OUT';
    const titleColor = type === 'in' ? 'text-green-600' : 'text-red-600';
    const companyLabel = type === 'in' ? 'Select Supplier Company:' : 'Select Buyer Company:';
    const buttonText = type === 'in' ? '+ Add New Supplier' : '+ Add New Buyer';
    
    // Total calculation div styling
    const totalDiv = document.getElementById('modalTotalCalculation');
    const totalColor = type === 'in' ? 'text-green-700' : 'text-red-700';
    const totalBgColor = type === 'in' ? 'bg-green-50' : 'bg-red-50';
    
    // Clear previous classes and apply new ones for the total calculation box
    // Note: This relies on your existing Tailwind classes, adjust if needed
    totalDiv.className = 'text-lg font-bold my-4 p-3 rounded-lg text-center border-2 ' + totalColor + ' ' + totalBgColor;
    
    // Run initial calculation to set default text (0.00) and show the box
    calculateTotal(); 
    totalDiv.style.display = 'block';

    const modalTitle = document.getElementById('stockModalTitle');
    modalTitle.textContent = title;
    modalTitle.className = 'text-2xl font-bold mb-4 text-center ' + titleColor;
    document.getElementById('companyLabel').textContent = companyLabel;
    
    const toggleButton = document.getElementById('toggleCompanyButton');
    toggleButton.textContent = buttonText;

    document.getElementById('stockModal').classList.remove('hidden');
}
// ------------------------------------------------
// NEW calculateTotal FUNCTION (Performs Quantity * Price, applying markup for 'out')
// ------------------------------------------------
function calculateTotal() {
    const quantityInput = document.getElementById('modalQuantity');
    const priceInput = document.getElementById('modalItemPrice');
    const markupInput = document.getElementById('modalItemMarkup'); // NEW
    const totalDiv = document.getElementById('modalTotalCalculation');
    const stockType = document.getElementById('modalStockType').value;
    
    // Get values, default to 0 if empty/invalid
    const quantity = parseFloat(quantityInput.value) || 0;
    const basePrice = parseFloat(priceInput.value) || 0;
    const markupPercent = parseFloat(markupInput.value) || 0; // NEW
    
    let effectivePrice = basePrice;
    let totalLabel = 'Total: ';

    if (stockType === 'in') {
        totalLabel = 'Total Cost: ';
        // effectivePrice remains basePrice (cost price)
    } else if (stockType === 'out') {
        totalLabel = 'Total Sales: ';
        // Calculate Selling Price: Base Price * (1 + Markup / 100)
        effectivePrice = basePrice * (1 + (markupPercent / 100));
        
        // NEW: Display the Selling Price next to the Total
        const sellingPriceText = 'Selling Price: ₱' + effectivePrice.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        totalDiv.setAttribute('data-selling-price', sellingPriceText); 
    }
    
    const total = quantity * effectivePrice;

    // Format the number to two decimal places and add currency symbol
    const formattedTotal = total.toLocaleString('en-US', { 
        minimumFractionDigits: 2, 
        maximumFractionDigits: 2 
    });
    
    // Display the final output in the modal total div
    if (stockType === 'out') {
        totalDiv.innerHTML = `<div class="text-sm font-normal text-gray-500 mb-1">Selling Price: ₱${effectivePrice.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</div> 
                              <div>${totalLabel} ₱${formattedTotal}</div>`;
    } else {
        totalDiv.textContent = totalLabel + '₱' + formattedTotal;
    }
}

function toggleCompanyInput() {
    isNewCompanyMode = !isNewCompanyMode;

    const existingDiv = document.getElementById('existingCompanyDiv');
    const newDiv = document.getElementById('newCompanyDiv');
    const toggleButton = document.getElementById('toggleCompanyButton');
    const companyIdSelect = document.getElementById('modalCompanyId');
    const newCompanyNameInput = document.getElementById('newCompanyName');
    const stockType = document.getElementById('modalStockType').value;

    const buttonTextNew = stockType === 'in' ? 'Use Existing Supplier List' : 'Use Existing Buyer List';
    const buttonTextExisting = stockType === 'in' ? '+ Add New Supplier' : '+ Add New Buyer';


    if (isNewCompanyMode) {
        // Switch to NEW mode
        existingDiv.classList.add('hidden');
        newDiv.classList.remove('hidden');
        
        // Update required status
        companyIdSelect.required = false;
        newCompanyNameInput.required = true;
        
        // Clear previous values
        companyIdSelect.value = '';
        
        toggleButton.textContent = '- ' + buttonTextNew;
        toggleButton.classList.add('text-red-600');
        toggleButton.classList.remove('text-blue-600');

    } else {
        // Switch back to EXISTING mode
        newDiv.classList.add('hidden');
        existingDiv.classList.remove('hidden');
        
        // Update required status
        newCompanyNameInput.required = false;
        companyIdSelect.required = true;
        
        // Clear previous values
        newCompanyNameInput.value = '';
        
        toggleButton.textContent = buttonTextExisting;
        toggleButton.classList.remove('text-red-600');
        toggleButton.classList.add('text-blue-600');
    }
}


function closeStockModal() { 
    // NEW: Also hide the calculation div when closing
    document.getElementById('modalTotalCalculation').style.display = 'none';

    // Reset to default selection mode when closing
    isNewCompanyMode = false;
    document.getElementById('newCompanyDiv').classList.add('hidden');
    document.getElementById('existingCompanyDiv').classList.remove('hidden');
    document.getElementById('modalCompanyId').required = true;
    document.getElementById('newCompanyName').required = false;
    document.getElementById('stockModal').classList.add('hidden'); 
}
</script>
</body>
</html>


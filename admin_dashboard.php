<?php
session_start();
include("config.php");

// --- Admin access check ---
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Initialize error and success messages
$error = null;
$success = null;

// --- ADD STOCK TRANSACTION LOGIC (NEW SECTION) ---
if (isset($_POST['add_stock_transaction'])) {
    // 1. Sanitize and Validate Inputs
    $item_id      = (int)$_POST['item_id'];
    $type         = trim($_POST['type']); // 'in' or 'out'
    $quantity     = (int)$_POST['quantity'];
    $company_id   = (int)$_POST['company_id']; // Can be Buyer or Supplier

    // Basic validation
    if ($item_id <= 0 || !in_array($type, ['in', 'out']) || $quantity <= 0) {
        $error = "Invalid item, transaction type, or quantity.";
    } else {
        // Additional check for Stock OUT: ensure enough stock is available (for 'out' transactions)
        if ($type === 'out') {
            // Get current stock
            $stock_check_stmt = $conn->prepare("
                SELECT 
                    IFNULL(SUM(CASE WHEN type='in' AND status='approved' THEN quantity ELSE 0 END),0) -
                    IFNULL(SUM(CASE WHEN type='out' AND status='approved' THEN quantity ELSE 0 END),0) AS current_stock
                FROM stock_transactions 
                WHERE item_id = ?
            ");
            $stock_check_stmt->bind_param("i", $item_id);
            $stock_check_stmt->execute();
            $current_stock = $stock_check_stmt->get_result()->fetch_assoc()['current_stock'];
            $stock_check_stmt->close();
            
            if ($quantity > $current_stock) {
                $error = "Stock-Out failed: Only **$current_stock** units of this item are currently available. Requested: $quantity.";
            }
        }

        // If no error so far, proceed with insertion
        if ($error === null) {
            // 2. Insert the transaction as 'approved' (since admin is entering it)
            $user_id = $_SESSION['id']; 
            
            // Prepared Statement for Insertion
            $stmt = $conn->prepare("
                INSERT INTO stock_transactions (item_id, type, quantity, user_id, company_id, status) 
                VALUES (?, ?, ?, ?, ?, 'approved')
            "); 
            // Bind parameters: iisii (item_id, type, quantity, user_id, company_id)
            $stmt->bind_param("isiii", $item_id, $type, $quantity, $user_id, $company_id);
            
            if ($stmt->execute()) {
                $type_label = $type === 'in' ? 'Stock-In' : 'Stock-Out';
                $success = "**$type_label** transaction of **$quantity** units recorded and approved successfully!";
            } else {
                $error = "Failed to record stock transaction: " . $conn->error;
            }
            $stmt->close();
        }
    }
}
// --- END OF NEW STOCK TRANSACTION LOGIC ---


// --- Add Company (Buyer/Supplier) ---
if (isset($_POST['add_company'])) {
    $company_name = trim($_POST['company_name']);
    if (!empty($company_name)) {
        // 1. Check if company already exists (Prepared Statement - OK)
        $check_stmt = $conn->prepare("SELECT id FROM company_names WHERE name = ?");
        $check_stmt->bind_param("s", $company_name);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            $error = "Company '$company_name' already exists.";
        } else {
            // 2. Insert new company (Prepared Statement - OK)
            $stmt = $conn->prepare("INSERT INTO company_names (name) VALUES (?)");
            $stmt->bind_param("s", $company_name);
            if ($stmt->execute()) {
                $success = "Company **$company_name** added successfully!";
            } else {
                $error = "Failed to add company: " . $conn->error;
            }
            $stmt->close();
        }
        $check_stmt->close();
    } else {
        $error = "Company name cannot be empty.";
    }
}

// --- Delete Company (Buyer/Supplier) ---
if (isset($_POST['delete_company'])) {
    $company_id = intval($_POST['company_id']);
    
    // Check if the company is linked to any APPROVED stock transactions first (Prepared Statement - OK)
    $check_tx_stmt = $conn->prepare("SELECT COUNT(*) FROM stock_transactions WHERE company_id = ? AND status = 'approved'");
    $check_tx_stmt->bind_param("i", $company_id);
    $check_tx_stmt->execute();
    $tx_count = $check_tx_stmt->get_result()->fetch_row()[0];
    $check_tx_stmt->close();

    if ($tx_count > 0) {
        $error = "Cannot delete company. It is linked to **$tx_count** approved stock transactions.";
    } else {
        // IMPROVEMENT: Use Prepared Statement for DELETE
        $stmt = $conn->prepare("DELETE FROM company_names WHERE id = ?");
        $stmt->bind_param("i", $company_id);
        if ($stmt->execute()) {
            $success = "Company deleted successfully!";
        } else {
            $error = "Failed to delete company: " . $conn->error;
        }
        $stmt->close();
    }
}

// FETCH ALL COMPANIES FOR DISPLAY
$all_companies_result = $conn->query("SELECT id, name FROM company_names ORDER BY name ASC");

// --- MANAGE USERS ---
if (isset($_POST['delete_user'])) {
    $user_id = (int)$_POST['user_id'];
    if ($user_id != $_SESSION['id']) {
        // IMPROVEMENT: Use Prepared Statement for DELETE
        $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            $success = "User deleted successfully!";
        } else {
            $error = "Failed to delete user: " . $conn->error;
        }
        $stmt->close();
    } else {
        $error = "Cannot delete the currently logged-in admin user.";
    }
}

// --- APPROVE / REJECT NEW ITEMS ---
if (isset($_POST['approve_item'])) {
    $item_id = (int)$_POST['item_id'];
    // Prepared Statement - OK
    $stmt = $conn->prepare("UPDATE items SET status='approved' WHERE id=?");
    $stmt->bind_param("i", $item_id);
    if ($stmt->execute()) {
        $success = "Item approved successfully!";
    } else {
        $error = "Failed to approve item: " . $conn->error;
    }
    $stmt->close();
}
if (isset($_POST['reject_item'])) {
    $item_id = (int)$_POST['item_id'];
    // IMPROVEMENT: Use Prepared Statement for DELETE
    $stmt = $conn->prepare("DELETE FROM items WHERE id=?");
    $stmt->bind_param("i", $item_id);
    if ($stmt->execute()) {
        $success = "Item rejected and deleted successfully!";
    } else {
        $error = "Failed to reject item: " . $conn->error;
    }
    $stmt->close();
}


// --- APPROVE / REJECT STOCK TRANSACTIONS ---
if (isset($_POST['approve_stock'])) {
    $stock_id = (int)$_POST['stock_id'];
    // IMPROVEMENT: Use Prepared Statement for UPDATE
    $stmt = $conn->prepare("UPDATE stock_transactions SET status='approved' WHERE id=?");
    $stmt->bind_param("i", $stock_id);
    if ($stmt->execute()) {
        $success = "Stock transaction approved successfully!";
    } else {
        $error = "Failed to approve stock transaction: " . $conn->error;
    }
    $stmt->close();
}
if (isset($_POST['reject_stock'])) {
    $stock_id = (int)$_POST['stock_id'];
    // IMPROVEMENT: Use Prepared Statement for UPDATE
    $stmt = $conn->prepare("UPDATE stock_transactions SET status='rejected' WHERE id=?");
    $stmt->bind_param("i", $stock_id);
    if ($stmt->execute()) {
        $success = "Stock transaction rejected successfully!";
    } else {
        $error = "Failed to reject stock transaction: " . $conn->error;
    }
    $stmt->close();
}

// --- EDIT INVENTORY INLINE ---
if (isset($_POST['edit_inventory'])) {
    $item_id = (int)$_POST['item_id'];
    $name = trim($_POST['item_name']);
    $desc = trim($_POST['description']);
    $price = floatval($_POST['price']);
    $net_interest = floatval($_POST['net_interest_percent']); 
    
    // Prepared Statement - OK
    $stmt = $conn->prepare("UPDATE items SET item_name=?, description=?, price=?, net_interest_percent=? WHERE id=?");
    $stmt->bind_param("ssddi", $name, $desc, $price, $net_interest, $item_id);
    if ($stmt->execute()) {
        $success = "Inventory item updated successfully!";
    } else {
        $error = "Failed to update inventory item: " . $conn->error;
    }
    $stmt->close();
}

// --- DELETE INVENTORY ITEM ---
if (isset($_POST['delete_inventory'])) {
    $item_id = (int)$_POST['item_id'];

    // IMPROVEMENT: Use Prepared Statements for cascading deletes
    $delete_tx_stmt = $conn->prepare("DELETE FROM stock_transactions WHERE item_id=?");
    $delete_tx_stmt->bind_param("i", $item_id);
    $delete_tx_stmt->execute();
    $delete_tx_stmt->close();
    
    $delete_edits_stmt = $conn->prepare("DELETE FROM item_edits WHERE item_id=?");
    $delete_edits_stmt->bind_param("i", $item_id);
    $delete_edits_stmt->execute();
    $delete_edits_stmt->close();
    
    // Delete the item itself
    $delete_item_stmt = $conn->prepare("DELETE FROM items WHERE id=?");
    $delete_item_stmt->bind_param("i", $item_id);

    if ($delete_item_stmt->execute()) {
        $success = "Inventory item deleted successfully!";
    } else {
        $error = "Failed to delete item: " . $conn->error;
    }
    $delete_item_stmt->close();
}
// --- CLEAR ALL APPROVED STOCK TRANSACTIONS ---
if (isset($_POST['clear_transactions'])) {
    // Check for required confirmation from the hidden input field populated by JS
    if (isset($_POST['confirmation_phrase']) && $_POST['confirmation_phrase'] === 'CONFIRM DELETE') {

        // Using a prepared statement for DELETE all approved transactions
        $stmt = $conn->prepare("DELETE FROM stock_transactions WHERE status = 'approved'");

        if ($stmt->execute()) {
            $success = "Successfully **cleared all approved stock transaction records**! The transaction log is now empty.";
        } else {
            $error = "Failed to clear approved stock transaction records: " . $conn->error;
        }
        $stmt->close();
    } else {
        // This catches if the JS didn't pass the correct confirmation phrase
        $error = "Transaction clearing failed: You must type 'CONFIRM DELETE' to proceed.";
    }
}
// --- FETCH DATA ---
// Inventory query for table (CORRECTED to fetch latest supplier from stock_transactions)
$inventory = $conn->query("
    SELECT 
        @rownum := @rownum + 1 AS display_id, 
        i.id, 
        i.item_name, 
        i.description, 
        i.price, 
        i.net_interest_percent,
        
        -- Subquery to find the company name associated with the LATEST approved Stock IN
        (
            SELECT cn.name 
            FROM stock_transactions st
            JOIN company_names cn ON st.company_id = cn.id
            WHERE st.item_id = i.id 
            AND st.type = 'in' 
            AND st.status = 'approved'
            ORDER BY st.created_at DESC 
            LIMIT 1
        ) AS supplier_name,
        
        IFNULL(SUM(CASE WHEN t.type='in' AND t.status='approved' THEN t.quantity ELSE 0 END),0) -
        IFNULL(SUM(CASE WHEN t.type='out' AND t.status='approved' THEN t.quantity ELSE 0 END),0) AS current_stock
        
    FROM items i
    LEFT JOIN stock_transactions t ON i.id = t.item_id
    CROSS JOIN (SELECT @rownum := 0) r
    WHERE i.status='approved'
    GROUP BY i.id, i.item_name, i.description, i.price, i.net_interest_percent
    ORDER BY i.item_name ASC
");
// Separate items grid data (so we don't consume $inventory)
// NOTE: Must clone/re-run query if needed in multiple loops/sections.
$items_grid_result = $conn->query("SELECT id, item_name, price FROM items WHERE status='approved' ORDER BY item_name ASC");

// PENDING ITEMS QUERY: 
$pending_items = $conn->query("
    SELECT i.id, i.item_name, i.description, i.price, u.username AS submitted_by
    FROM items i
    LEFT JOIN users u ON i.submitted_by=u.id
    WHERE i.status='pending'
    ORDER BY i.id ASC
");


$pending_stock = $conn->query("
    SELECT t.id, i.item_name, t.type, t.quantity, u.username AS staff_name
    FROM stock_transactions t
    LEFT JOIN items i ON t.item_id=i.id
    LEFT JOIN users u ON t.user_id=u.id
    WHERE t.status='pending'
    ORDER BY t.id ASC
");

$users = $conn->query("SELECT id, username, role FROM users ORDER BY id ASC");

// Reports (weekly/monthly/yearly)
$transactions = $conn->query("
    SELECT t.id, i.item_name, t.type, t.quantity, u.username AS performed_by, t.created_at
    FROM stock_transactions t
    LEFT JOIN items i ON t.item_id=i.id
    LEFT JOIN users u ON t.user_id=u.id
    WHERE t.status='approved'
    ORDER BY t.id DESC
");

// Define the base report query structure once.
$report_query = "
    SELECT i.item_name, i.price,
            SUM(CASE WHEN t.type='in' THEN t.quantity ELSE 0 END) AS total_in_qty,
            SUM(CASE WHEN t.type='out' THEN t.quantity ELSE 0 END) AS total_out_qty,
            -- Total Cost is based on item's base price
            SUM(CASE WHEN t.type='in' THEN t.quantity * i.price ELSE 0 END) AS total_cost_value,
            -- Total Sales is based on the calculated Selling Price (Price + Markup)
            SUM(CASE WHEN t.type='out' THEN t.quantity * i.price * (1 + i.net_interest_percent / 100) ELSE 0 END) AS total_sales_value
    FROM stock_transactions t
    LEFT JOIN items i ON t.item_id = i.id
    WHERE t.status='approved' AND %CONDITION%
    GROUP BY i.id, i.item_name, i.price, i.net_interest_percent -- Group by net_interest_percent for correctness
";

// Weekly Report
$weekly = $conn->query(str_replace(
    "%CONDITION%", 
    "t.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)", 
    $report_query
));

// Monthly Report
$monthly = $conn->query(str_replace(
    "%CONDITION%", 
    "MONTH(t.created_at) = MONTH(CURDATE()) AND YEAR(t.created_at) = YEAR(CURDATE())", 
    $report_query
));

// Yearly Report
$yearly = $conn->query(str_replace(
    "%CONDITION%", 
    "YEAR(t.created_at) = YEAR(CURDATE())", 
    $report_query
));

// Helper function to render reports with Tailwind styles
function renderReportTable($reportData, $title) {
    echo "<div class='mb-8 bg-white p-6 rounded-xl shadow-lg border border-gray-100'>";
    echo "<h4 class='text-2xl font-bold mb-4 text-gray-800 border-b pb-2'>$title</h4>";
    
    // Initialize monetary totals for profit calculation
    $report_total_in = 0;
    $report_total_out = 0;

    if (!$reportData || $reportData->num_rows === 0) {
        echo "<p class='text-gray-500'>No approved transactions recorded for this period.</p>";
        echo "</div>";
        return;
    }
    
    // Reset pointer to the start
    if ($reportData->num_rows > 0) {
        $reportData->data_seek(0);
    }
    
    echo "<div class='overflow-x-auto'>";
    echo "<table class='min-w-full divide-y divide-gray-200 text-sm'>";
    echo "<thead class='bg-blue-50'>";
    echo "<tr>";
    // MODIFIED HEADERS
    echo "<th class='px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider'>#</th>";
    echo "<th class='px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider'>Item</th>";
    echo "<th class='px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider'>Qty IN</th>";
    echo "<th class='px-3 py-3 text-center text-xs font-medium text-green-600 uppercase tracking-wider'>Cost (₱)</th>"; // NEW
    echo "<th class='px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider'>Qty OUT</th>";
    echo "<th class='px-3 py-3 text-center text-xs font-medium text-red-600 uppercase tracking-wider'>Sales (₱)</th>"; // NEW
    echo "</tr>";
    echo "</thead>";
    echo "<tbody class='bg-white divide-y divide-gray-200'>";
    
    $counter = 1;
    while ($row = $reportData->fetch_assoc()) {
        
        // Accumulate totals for summary
        $report_total_in += $row['total_cost_value'];
        $report_total_out += $row['total_sales_value'];
        
        echo "<tr>";
        echo "<td class='px-3 py-3 whitespace-nowrap text-sm text-gray-500'>{$counter}</td>";
        echo "<td class='px-3 py-3 whitespace-nowrap text-sm font-medium text-gray-900'>" . htmlspecialchars($row['item_name']) . "</td>";
        echo "<td class='px-3 py-3 whitespace-nowrap text-sm text-center text-green-600 font-bold'>{$row['total_in_qty']}</td>"; // CHANGED COLUMN NAME
        echo "<td class='px-3 py-3 whitespace-nowrap text-sm text-center text-green-700 font-bold'>" . number_format($row['total_cost_value'], 2) . "</td>"; // NEW COLUMN
        echo "<td class='px-3 py-3 whitespace-nowrap text-sm text-center text-red-600 font-bold'>{$row['total_out_qty']}</td>"; // CHANGED COLUMN NAME
        echo "<td class='px-3 py-3 whitespace-nowrap text-sm text-center text-red-700 font-bold'>" . number_format($row['total_sales_value'], 2) . "</td>"; // NEW COLUMN
        echo "</tr>";
        $counter++;
    }
    
    echo "</tbody>";
    echo "</table>";
    echo "</div>";
    
    
    // FINAL PROFIT/LOSS SUMMARY 
    
    $net_profit = $report_total_out - $report_total_in;
    $profit_color = $net_profit >= 0 ? 'bg-green-100 text-green-800 border-green-500' : 'bg-red-100 text-red-800 border-red-500';
    $profit_label = $net_profit >= 0 ? 'Net Profit' : 'Net Loss';

    echo "";
    echo "<div class='mt-6 p-4 rounded-lg border-l-4 $profit_color shadow-inner'>";
    echo "     <h4 class='font-bold text-lg mb-1'>Financial Summary</h4>";
    echo "     <p class='text-sm'>Total Sales Value: <span class='font-semibold'>₱" . number_format($report_total_out, 2) . "</span></p>";
    echo "     <p class='text-sm'>Total Cost Value: <span class='font-semibold'>₱" . number_format($report_total_in, 2) . "</span></p>";
    echo "     <div class='mt-2 pt-2 border-t border-gray-300'>";
    echo "         <p class='text-xl font-extrabold flex justify-between'>";
    echo "             <span>{$profit_label}:</span>";
    echo "             <span>₱" . number_format(abs($net_profit), 2) . "</span>";
    echo "         </p>";
    echo "     </div>";
    echo "</div>";
    // ===========================================
    
    echo "</div>"; // close mb-8 div
}

// Ensure the connection is closed (good practice, though PHP usually handles it)
// $conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Admin Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f4f7f9; }
        .admin-table th { background-color: #3b82f6; color: white; }
        /* simple modal backdrop */
        .modal-backdrop { background: rgba(0,0,0,0.5); }
    </style>
</head>
<body class="p-4 sm:p-8">

    <header class="flex justify-between items-center mb-8 pb-4 border-b-2 border-blue-500">
        <h1 class="text-3xl font-extrabold text-gray-900">Admin Control Center</h1>
        <a href="logout.php" class="py-2 px-4 bg-red-600 hover:bg-red-700 text-white font-semibold rounded-lg shadow-md transition duration-150">Logout</a>
    </header>

    <h2 class="text-2xl font-bold text-gray-800 mb-6">Welcome, <?= htmlspecialchars($_SESSION['username']) ?>!</h2>

    <?php if(!empty($error)): ?><div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative mb-4" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if(!empty($success)): ?><div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg relative mb-4" role="alert"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <section id="pending-approvals" class="mb-10 p-6 bg-white rounded-xl shadow-2xl">
        <h3 class="text-3xl font-extrabold text-orange-600 mb-6 border-b pb-3">🚨 Pending Approvals (Action Required)</h3>

        <div class="mb-8">
            <h4 class="text-xl font-semibold text-gray-800 mb-3">Pending Stock Transactions (<?= $pending_stock ? $pending_stock->num_rows : 0 ?>)</h4>
            <?php if ($pending_stock && $pending_stock->num_rows > 0): ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 admin-table rounded-lg overflow-hidden">
                        <thead>
                            <tr>
                                <th class="px-4 py-3 text-left text-xs uppercase tracking-wider">#</th>
                                <th class="px-4 py-3 text-left text-xs uppercase tracking-wider">Item</th>
                                <th class="px-4 py-3 text-left text-xs uppercase tracking-wider">Type</th>
                                <th class="px-4 py-3 text-left text-xs uppercase tracking-wider">Quantity</th>
                                <th class="px-4 py-3 text-left text-xs uppercase tracking-wider">Staff</th>
                                <th class="px-4 py-3 text-center text-xs uppercase tracking-wider">Action</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php $ps=1; 
                            // Ensure pointer is at start
                            if ($pending_stock->num_rows > 0) $pending_stock->data_seek(0);
                            while($stock=$pending_stock->fetch_assoc()): ?>
                            <tr>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500"><?= $ps++ ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($stock['item_name']) ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm font-bold <?= $stock['type'] === 'in' ? 'text-green-600' : 'text-red-600' ?>"><?= ucfirst($stock['type']) ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700"><?= $stock['quantity'] ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($stock['staff_name']) ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-center">
                                    <form method="POST" class="inline-block mr-2">
                                        <input type="hidden" name="stock_id" value="<?= $stock['id'] ?>">
                                        <button type="submit" name="approve_stock" class="py-1 px-3 bg-green-500 hover:bg-green-600 text-white text-xs font-semibold rounded-lg transition duration-150">Approve</button>
                                    </form>
                                    <form method="POST" class="inline-block">
                                        <input type="hidden" name="stock_id" value="<?= $stock['id'] ?>">
                                        <button type="submit" name="reject_stock" class="py-1 px-3 bg-red-500 hover:bg-red-600 text-white text-xs font-semibold rounded-lg transition duration-150">Reject</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-gray-500">No pending stock transactions.</p>
            <?php endif; ?>
        </div>

        <div>
            <h4 class="text-xl font-semibold text-gray-800 mb-3">Pending New Item Submissions (<?= $pending_items ? $pending_items->num_rows : 0 ?>)</h4>
            <?php if ($pending_items && $pending_items->num_rows > 0): ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 admin-table rounded-lg overflow-hidden">
                        <thead>
                            <tr>
                                <th class="px-4 py-3 text-left text-xs uppercase tracking-wider">#</th>
                                <th class="px-4 py-3 text-left text-xs uppercase tracking-wider">Name</th>
                                <th class="px-4 py-3 text-left text-xs uppercase tracking-wider">Price (₱)</th>
                                <th class="px-4 py-3 text-left text-xs uppercase tracking-wider">Submitted By</th>
                                <th class="px-4 py-3 text-center text-xs uppercase tracking-wider">Action</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php $p=1; 
                            // Ensure pointer is at start
                            if ($pending_items->num_rows > 0) $pending_items->data_seek(0);
                            while($item=$pending_items->fetch_assoc()): ?>
                            <tr>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500"><?= $p++ ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900" title="<?= htmlspecialchars($item['description']) ?>"><?= htmlspecialchars($item['item_name']) ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700"><?= number_format($item['price'], 2) ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($item['submitted_by']) ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-center">
                                    <form method="POST" class="inline-block mr-2">
                                        <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                        <button type="submit" name="approve_item" class="py-1 px-3 bg-green-500 hover:bg-green-600 text-white text-xs font-semibold rounded-lg transition duration-150">Approve</button>
                                    </form>
                                    <form method="POST" class="inline-block">
                                        <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                        <button type="submit" name="reject_item" class="py-1 px-3 bg-red-500 hover:bg-red-600 text-white text-xs font-semibold rounded-lg transition duration-150">Reject & Delete</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-gray-500">No new items awaiting approval.</p>
            <?php endif; ?>
        </div>

    </section>

    
        

        <div class="bg-white p-6 rounded-xl shadow-2xl">
            <h3 class="text-3xl font-extrabold text-blue-600 mb-6 border-b pb-3">📦 Current Inventory</h3>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 admin-table rounded-lg overflow-hidden">
            <thead>
                <tr>
                    <th class="px-4 py-3 text-left text-xs uppercase tracking-wider">#</th>
                   <th class="px-4 py-3 text-left text-xs uppercase tracking-wider w-1/4">Item Name</th>
                     <th class="px-4 py-3 text-left text-xs uppercase tracking-wider">Price (₱)</th>
                     <th class="px-4 py-3 text-left text-xs uppercase tracking-wider">Markup (%)</th>
                     <th class="px-4 py-3 text-left text-xs uppercase tracking-wider">Stock</th>
                     <th class="px-4 py-3 text-left text-xs uppercase tracking-wider">Supplier</th>
                     <th class="px-4 py-3 text-center text-xs uppercase tracking-wider">Action</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
<?php if ($inventory && $inventory->num_rows > 0): ?>
 <?php 
 // Ensure pointer is at start
 if ($inventory->num_rows > 0) $inventory->data_seek(0);
 while($row = $inventory->fetch_assoc()): ?>

<tr id="item-row-<?= $row['id'] ?>">
<td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 align-top"><?= $row['display_id'] ?></td>

<td class="px-4 py-3 text-sm font-semibold text-gray-900 w-1/4 truncate">
    <?= htmlspecialchars($row['item_name']) ?>
</td>
<td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 align-top">
    ₱<?= number_format($row['price'], 2) ?>
</td>
                         
 <td class="px-4 py-3 whitespace-nowrap text-sm text-blue-600 font-semibold align-top">
    <?= number_format(isset($row['net_interest_percent']) ? $row['net_interest_percent'] : 0, 2) ?>%
</td>
 <td class="px-4 py-3 whitespace-nowrap text-sm font-bold <?= $row['current_stock'] > 0 ? 'text-green-600' : 'text-red-600' ?> align-top">
    <?= $row['current_stock'] ?>
</td>

<td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 align-top">
    <?= htmlspecialchars($row['supplier_name']) ?>
</td>

 <td class="px-4 py-3 whitespace-nowrap text-sm text-center align-top">
    <div class="flex flex-col space-y-1">
        <button 
            type="button" 
            onclick="toggleEditForm('<?= $row['id'] ?>')"
            class="py-1 px-3 bg-blue-500 hover:bg-blue-600 text-white text-xs font-semibold rounded-lg transition duration-150 edit-toggle-btn w-full"
            data-is-open="false"
            id="edit-btn-<?= $row['id'] ?>"
        >
            Edit
        </button>
        
        <form method="POST" onsubmit="return confirm('Are you sure you want to delete this item? This cannot be undone.');">
            <input type="hidden" name="item_id" value="<?= $row['id'] ?>">
            <button type="submit" name="delete_inventory" class="py-1 px-3 bg-red-600 hover:bg-red-700 text-white text-xs font-semibold rounded-lg transition duration-150 w-full">Delete</button>
        </form>
    </div>
</td>
                         </tr>

                                                 <tr id="edit-form-row-<?= $row['id'] ?>" class="hidden bg-gray-50">
                            <td colspan="7" class="p-4"> 
                                <form method="POST" class="space-y-3">
                                    <input type="hidden" name="item_id" value="<?= $row['id'] ?>">

                                    <div class="flex items-center space-x-2">
                                        <span class="text-gray-600 text-sm font-semibold w-24">Item Name:</span>
                                        <input type="text" name="item_name" value="<?= htmlspecialchars($row['item_name']) ?>" class="flex-grow p-2 border border-gray-300 rounded-md text-sm font-semibold" placeholder="Item Name" required>
                                    </div>
                                    
                                    <div>
                                        <span class="text-gray-600 text-sm font-semibold block mb-1">Description:</span>
                                        <textarea name="description" rows="3" class="w-full p-2 border border-gray-300 rounded-md text-xs" placeholder="Description" required><?= htmlspecialchars($row['description']) ?></textarea>
                                    </div>
                                    
                                    <div class="flex space-x-3 items-center pt-2">
                                        <span class="text-gray-600 text-sm font-semibold">Price:</span>
                                        <div class="flex items-center">
                                            <span class="p-2 border border-r-0 border-gray-300 rounded-l-md text-sm bg-gray-200">₱</span>
                                            <input type="number" name="price" step="0.01" value="<?= $row['price'] ?>" class="p-2 border border-gray-300 rounded-r-md text-sm w-32" placeholder="0.00" required>
                                        </div>

                                        <span class="text-gray-600 text-sm font-semibold ml-4">Markup (%):</span>
                                        <div class="flex items-center">
                                            <input type="number" name="net_interest_percent" step="0.01" min="0" value="<?= isset($row['net_interest_percent']) ? $row['net_interest_percent'] : 0 ?>" class="p-2 border border-gray-300 rounded-l-md text-sm w-20" placeholder="0.00" required>
                                            <span class="p-2 border border-l-0 border-gray-300 rounded-r-md text-sm bg-gray-200">%</span>
                                        </div>
                                                                            
                                        <button type="submit" name="edit_inventory" class="py-2 px-4 bg-yellow-500 hover:bg-yellow-600 text-gray-800 text-sm font-semibold rounded-lg transition duration-150">Save Edits</button>
                                        
                                        <button 
                                            type="button" 
                                            onclick="toggleEditForm('<?= $row['id'] ?>')" 
                                            class="py-2 px-4 bg-gray-300 hover:bg-gray-400 text-gray-800 text-sm font-semibold rounded-lg transition duration-150"
                                        >
                                            Cancel
                                        </button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="7" class="p-4 text-center text-gray-500">No approved inventory items found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    function toggleEditForm(itemId) {
        const formRow = document.getElementById('edit-form-row-' + itemId);
        const editButton = document.getElementById('edit-btn-' + itemId);

        if (formRow.classList.contains('hidden')) {
            // Show the form
            formRow.classList.remove('hidden');
            editButton.textContent = 'Close'; // Change button text
            editButton.classList.remove('bg-blue-500', 'hover:bg-blue-600');
            editButton.classList.add('bg-gray-400', 'hover:bg-gray-500');
            editButton.setAttribute('data-is-open', 'true');
        } else {
            // Hide the form
            formRow.classList.add('hidden');
            editButton.textContent = 'Edit'; // Change button text back
            editButton.classList.remove('bg-gray-400', 'hover:bg-gray-500');
            editButton.classList.add('bg-blue-500', 'hover:bg-blue-600');
            editButton.setAttribute('data-is-open', 'false');
        }
    }
</script>
    </section>

  

    <section id="admin-tools" class="mb-10 p-6 bg-white rounded-xl shadow-2xl">
    <h3 class="text-3xl font-extrabold text-teal-600 mb-6 border-b pb-3">⚙️ Administrative Tools</h3>
    <div class="grid md:grid-cols-2 gap-8">

        <div class="p-4 bg-gray-50 rounded-lg border border-gray-200">
            <h4 class="text-xl font-semibold text-gray-800 mb-4">Manual Stock Transaction</h4>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="add_stock_transaction" value="1">

                <div>
                    <label for="item_id" class="block text-sm font-medium text-gray-700">Inventory Item</label>
                    <select name="item_id" id="item_id" required class="w-full p-2 border border-gray-300 rounded-md">
                        <option value="">Select Item</option>
                        <?php 
                        // Ensure pointer is at start for the items list
                        if ($items_grid_result->num_rows > 0) $items_grid_result->data_seek(0);
                        while($item = $items_grid_result->fetch_assoc()): ?>
                            <option value="<?= $item['id']; ?>">
                                <?= htmlspecialchars($item['item_name']) . " (₱" . number_format($item['price'], 2) . ")"; ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div>
                    <label for="type" class="block text-sm font-medium text-gray-700">Transaction Type</label>
                    <select name="type" id="type" required class="w-full p-2 border border-gray-300 rounded-md">
                        <option value="in">Stock In (Purchase/Return)</option>
                        <option value="out">Stock Out (Sale/Adjustment)</option>
                    </select>
                </div>
                
                <div>
                    <label for="company_id" class="block text-sm font-medium text-gray-700">Supplier / Buyer</label>
                    <select name="company_id" id="company_id" required class="w-full p-2 border border-gray-300 rounded-md">
                        <option value="">Select Company</option>
                        <?php 
                        // Reset pointer for company list
                        if ($all_companies_result->num_rows > 0) $all_companies_result->data_seek(0);
                        while($company = $all_companies_result->fetch_assoc()): ?>
                            <option value="<?= $company['id']; ?>">
                                <?= htmlspecialchars($company['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div>
                    <label for="quantity" class="block text-sm font-medium text-gray-700">Quantity</label>
                    <input type="number" name="quantity" id="quantity" min="1" required class="w-full p-2 border border-gray-300 rounded-md" placeholder="Enter quantity">
                </div>
                
                <button type="submit" class="w-full py-2 px-4 bg-green-600 text-white font-semibold rounded-md hover:bg-green-700 transition duration-150">
                    Record & Approve Transaction
                </button>
            </form>
        </div>
        <div>
            <h4 class="text-xl font-semibold text-gray-800 mb-4">User Management</h4>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 admin-table rounded-lg overflow-hidden">
                    <thead>
                        <tr>
                            <th class="px-4 py-3 text-left text-xs uppercase tracking-wider">#</th>
                            <th class="px-4 py-3 text-left text-xs uppercase tracking-wider">Username</th>
                            <th class="px-4 py-3 text-left text-xs uppercase tracking-wider">Role</th>
                            <th class="px-4 py-3 text-center text-xs uppercase tracking-wider">Action</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php $u=1; 
                        // Ensure pointer is at start
                        if ($users->num_rows > 0) $users->data_seek(0);
                        while($user=$users->fetch_assoc()): ?>
                        <tr>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500"><?= $u++ ?></td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($user['username']) ?></td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm font-semibold text-blue-600"><?= $user['role'] ?></td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-center">
                                <?php if($user['id'] != $_SESSION['id']): ?>
                                <form method="POST" class="inline-block" onsubmit="return confirm('Are you sure you want to delete user <?= htmlspecialchars($user['username']) ?>?');">
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                    <button type="submit" name="delete_user" class="py-1 px-3 bg-red-600 hover:bg-red-700 text-white text-xs font-semibold rounded-lg transition duration-150">Delete</button>
                                </form>
                                <?php else: ?><span class="text-xs text-gray-400">(You)</span><?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
        </div>
</section>

<section class="bg-white p-6 rounded-xl shadow-xl mt-8">
    <h2 class="text-2xl font-bold text-gray-800 mb-4 border-b pb-2">📦 Manage Companies (Buyers & Suppliers)</h2>
    
    <form method="POST" class="flex mb-8 space-x-2">
        <input type="text" name="company_name" placeholder="Add new company (e.g., Supplier/Buyer Name)" class="p-3 border border-gray-300 rounded-lg flex-grow" required>
        <button type="submit" name="add_company" class="px-6 py-3 bg-indigo-600 text-white font-bold rounded-lg hover:bg-indigo-700 transition">Add Company</button>
    </form>
    
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">#</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Company Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php 
                $c = 1;
                // Use the result set fetched in the PHP section above
                if ($all_companies_result->num_rows > 0):
                    $all_companies_result->data_seek(0); // Reset pointer if necessary
                    while($comp = $all_companies_result->fetch_assoc()): 
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= $c++ ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($comp['name']) ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                        <form method="POST" onsubmit="return confirm('WARNING: Deleting this company might affect historical transaction records. Are you sure you want to delete <?= htmlspecialchars($comp['name']) ?>?');">
                            <input type="hidden" name="company_id" value="<?= $comp['id'] ?>">
                            <button type="submit" name="delete_company" class="px-3 py-1 bg-red-600 text-white rounded-lg text-xs hover:bg-red-700 font-semibold">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; 
                else: ?>
                <tr>
                    <td colspan="3" class="px-6 py-4 text-center text-gray-500">No companies found.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

    <section id="reports-section" class="mb-10 p-6 bg-gray-50 rounded-xl shadow-2xl">
        <h3 class="text-3xl font-extrabold text-fuchsia-600 mb-6 border-b pb-3">📈 Inventory Reports & Logs</h3>

        <div class="grid lg:grid-cols-3 gap-6 mb-8">
            <?php
            // Re-fetch reports if they were consumed by the check above
            if ($weekly) $weekly->data_seek(0);
            if ($monthly) $monthly->data_seek(0);
            if ($yearly) $yearly->data_seek(0);

            renderReportTable($weekly, "Weekly Stock Report");
            renderReportTable($monthly, "Monthly Stock Report");
            renderReportTable($yearly, "Yearly Stock Report");
            ?>
        </div>
<div class="mb-10 p-6 bg-red-100 rounded-xl shadow-inner border-2 border-red-400">
    <h3 class="text-2xl font-bold mb-3 text-red-800 flex items-center">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
        </svg>
        DANGER ZONE: Clear Approved Transactions
    </h3>
    <p class="text-sm text-red-700 mb-4">
        This action will **permanently delete ALL approved entries** from the `stock_transactions` table. This cannot be undone and will affect historical reports.
    </p>

    <form method="POST" onsubmit="return confirmClear()">
        <input type="hidden" name="clear_transactions" value="1">
        <input type="hidden" name="confirmation_phrase" id="confirmation_phrase_input" value=""> 

        <button type="submit" class="px-6 py-3 bg-red-600 text-white font-semibold rounded-lg shadow-lg hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition ease-in-out duration-150">
            Clear ALL Approved Transactions
        </button>
    </form>
</div>
        <div class="mb-8 p-6 bg-white rounded-xl shadow-lg border border-gray-100">
            <h4 class="text-2xl font-bold mb-4 text-gray-800 border-b pb-2">Approved Transaction Log</h4>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 admin-table rounded-lg overflow-hidden">
                    <thead>
                        <tr>
                            <th class="px-4 py-3 text-left text-xs uppercase tracking-wider">#</th>
                            <th class="px-4 py-3 text-left text-xs uppercase tracking-wider">Item</th>
                            <th class="px-4 py-3 text-left text-xs uppercase tracking-wider">Type</th>
                            <th class="px-4 py-3 text-left text-xs uppercase tracking-wider">Quantity</th>
                            <th class="px-4 py-3 text-left text-xs uppercase tracking-wider">Performed By</th>
                            <th class="px-4 py-3 text-left text-xs uppercase tracking-wider">Date</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php $t=1; 
                        // Ensure pointer is at start
                        if ($transactions && $transactions->num_rows > 0) $transactions->data_seek(0);

                        if ($transactions): while($tx=$transactions->fetch_assoc()): ?>
                        <tr>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500"><?= $t++ ?></td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($tx['item_name']) ?></td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm font-semibold <?= $tx['type'] === 'in' ? 'text-green-600' : 'text-red-600' ?>"><?= ucfirst($tx['type']) ?></td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700"><?= $tx['quantity'] ?></td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($tx['performed_by']) ?></td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500"><?= date("Y-m-d H:i", strtotime($tx['created_at'])) ?></td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr><td colspan="6" class="p-4 text-center text-gray-500">No approved transactions.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </section>

    <div id="qrModal" class="fixed inset-0 hidden items-center justify-center modal-backdrop z-50">
        <div class="bg-white rounded-lg p-6 max-w-sm w-full shadow-xl">
            <div class="flex justify-between items-start">
                <h3 id="qrTitle" class="text-lg font-bold text-gray-900">QR</h3>
                <button onclick="closeQrModal()" class="text-gray-500 hover:text-gray-700">&times;</button>
            </div>
            <div class="mt-4 text-center">
                <img id="qrImage" src="" alt="QR code" class="mx-auto" width="220" height="220" />
                <p id="qrLink" class="mt-3 text-sm text-gray-600 break-words"></p>
            </div>
            <div class="mt-6 text-right">
                <button onclick="closeQrModal()" class="py-1 px-3 bg-gray-200 hover:bg-gray-300 rounded">Close</button>
            </div>
        </div>
    </div>

<script>
function toggleEditForm(itemId) {
    const formRow = document.getElementById('edit-form-row-' + itemId);
    const editButton = document.getElementById('edit-btn-' + itemId);

    if (formRow.classList.contains('hidden')) {
        // Show the form
        formRow.classList.remove('hidden');
        editButton.textContent = 'Close'; // Change button text
        editButton.classList.remove('bg-blue-500', 'hover:bg-blue-600');
        editButton.classList.add('bg-gray-400', 'hover:bg-gray-500');
        editButton.setAttribute('data-is-open', 'true');
    } else {
        // Hide the form
        formRow.classList.add('hidden');
        editButton.textContent = 'Edit'; // Change button text back
        editButton.classList.remove('bg-gray-400', 'hover:bg-gray-500');
        editButton.classList.add('bg-blue-500', 'hover:bg-blue-600');
        editButton.setAttribute('data-is-open', 'false');
    }
}

function openQrModal(itemName, qrUrl) {
    document.getElementById('qrTitle').textContent = itemName;
    document.getElementById('qrImage').src = qrUrl;
    // Show decoded link (the chart api uses the encoded link; we display the decoded form)
    try {
        // try parse the chl param from the URL:
        const url = new URL(qrUrl);
        const chl = url.searchParams.get('chl');
        document.getElementById('qrLink').innerHTML = '<a href="' + decodeURIComponent(chl) + '" target="_blank" class="text-indigo-600 underline">' + decodeURIComponent(chl) + '</a>';
    } catch(e) {
        document.getElementById('qrLink').textContent = '';
    }
    document.getElementById('qrModal').classList.remove('hidden');
    document.getElementById('qrModal').classList.add('flex');
}

function closeQrModal() {
    document.getElementById('qrModal').classList.add('hidden');
    document.getElementById('qrModal').classList.remove('flex');
    document.getElementById('qrImage').src = '';
    document.getElementById('qrLink').textContent = '';
}

function confirmClear() {
    const phrase = prompt("WARNING: This will permanently delete ALL approved transaction logs. To confirm, please type 'CONFIRM DELETE' exactly:");
    if (phrase === 'CONFIRM DELETE') {
        document.getElementById('confirmation_phrase_input').value = 'CONFIRM DELETE';
        return true;
    }
    return false;
}
</script>

</body>
</html>
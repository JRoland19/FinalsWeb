<?php
session_start();
include("config.php");
$item_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$error = "";
$success = "";

if ($item_id === 0) {
    die("Item ID not specified.");
}

// NOTE: Stock handling logic, company fetches, and related error/success messages have been removed.

// --- FETCH ITEM DATA AND STOCK ---
$stmt = $conn->prepare("
    SELECT i.item_name, i.description, i.price, i.image_path,
           IFNULL(SUM(CASE WHEN t.type='in' AND t.status='approved' THEN t.quantity ELSE 0 END),0) -
           IFNULL(SUM(CASE WHEN t.type='out' AND t.status='approved' THEN t.quantity ELSE 0 END),0) AS current_stock
    FROM items i
    LEFT JOIN stock_transactions t ON i.id = t.item_id
    WHERE i.id = ? AND i.status = 'approved' 
    GROUP BY i.id
");
$stmt->bind_param("i", $item_id);
$stmt->execute();
$item_result = $stmt->get_result();

if ($item_result->num_rows === 0) {
    die("Item not found or not yet approved.");
}

$item = $item_result->fetch_assoc();
$item_price = $item['price'];
$image_path = !empty($item['image_path']) ? 'item_images/' . htmlspecialchars($item['image_path']) : 'placeholder.png'; // Use a placeholder if no image exists
$item_name = htmlspecialchars($item['item_name']);
$item_description = htmlspecialchars($item['description']);
$current_stock = (int)$item['current_stock'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $item_name ?> Details</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f4f7f9; }
        .card { max-width: 400px; }
        .stock-value { font-size: 2.5rem; }
    </style>
</head>
<body class="p-4 flex justify-center items-center min-h-screen">

    <div class="card bg-white shadow-xl rounded-xl p-6 w-full">
        
        <h1 class="text-2xl font-extrabold text-center text-gray-800 mb-4"><?= $item_name ?></h1>

        <?php if($error): ?><p class="text-red-600 font-semibold mb-4 text-center"><?= htmlspecialchars($error) ?></p><?php endif; ?>
        <?php if($success): ?><p class="text-green-600 font-semibold mb-4 text-center"><?= htmlspecialchars($success) ?></p><?php endif; ?>

        <!-- Item Image -->
        <div class="mb-6 flex justify-center">
            <img src="<?= $image_path ?>" 
                 alt="<?= $item_name ?> Image" 
                 class="w-full max-h-56 object-contain rounded-lg border border-gray-200 p-2"
                 onerror="this.onerror=null; this.src='https://placehold.co/400x200/cccccc/333333?text=No+Image';"
            >
        </div>
        
        <!-- Price and Description -->
        <div class="text-center mb-6">
            <div class="text-lg font-semibold text-gray-700">Price:</div>
            <div class="text-3xl font-bold text-blue-600">₱<?= number_format($item_price, 2) ?></div>
        </div>

        <div class="mb-6">
            <h3 class="text-xl font-semibold text-gray-800 border-b pb-2 mb-2">Description</h3>
            <p class="text-gray-600 whitespace-pre-wrap"><?= $item_description ?></p>
        </div>
        
        <!-- Total Stock Display -->
        <div class="text-center p-4 bg-gray-100 rounded-lg">
            <div class="text-lg font-semibold text-gray-700">TOTAL APPROVED STOCK:</div>
            <div class="stock-value font-extrabold <?= $current_stock > 0 ? 'text-green-600' : 'text-red-600' ?>">
                <?= $current_stock ?>
            </div>
        </div>
        
    </div>

</body>
</html>
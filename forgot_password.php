<?php
include("config.php"); // Includes your database connection

$message = "";
$reset_link = ""; // Variable to hold the defense mode link

if (isset($_POST['forgot_password'])) {
    $email = trim($_POST['email']);

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "<p class='text-red-400'>Please enter a valid email address.</p>";
    } else {
        // 1. Check if email exists
        $check_email = $conn->prepare("SELECT email FROM users WHERE email=?");
        $check_email->bind_param("s", $email);
        $check_email->execute();
        $result = $check_email->get_result();

        if ($result->num_rows === 0) {
            // User-friendly message for security: Don't tell them the email doesn't exist
            $message = "<p class='text-green-400'>If an account exists, a password reset link has been processed.</p>";
        } else {
            // 2. Generate token and expiration time
            $token = bin2hex(random_bytes(32));
            // Token expires in 1 hour
            $expires = date("Y-m-d H:i:s", time() + 3600);

            // 3. Delete old token and save new token
            // Use REPLACE INTO to simplify: it deletes old entry for this email and inserts new one
            $stmt = $conn->prepare("REPLACE INTO password_resets (email, token, expires) VALUES (?, ?, ?)");
            
            if (!$stmt) {
                $message = "<p class='text-red-400'>Database Error: Failed to prepare token statement. " . $conn->error . "</p>";
            } else {
                $stmt->bind_param("sss", $email, $token, $expires);
                
                if ($stmt->execute()) {
                    // Success! Display the defense mode link (since we cannot send mail on XAMPP)
                    $reset_url = "reset_password.php?token=" . $token . "&email=" . urlencode($email);
                    
                    // IMPORTANT: Ensure the directory path matches your setup.
                    $reset_link = 'http://localhost/FinalsWeb/' . $reset_url; 

                    $message = "<p class='text-green-400'>If an account exists, a password reset link has been sent (see below for defense mode link).</p>";
                } else {
                    $message = "<p class='text-red-400'>Database Error: Could not save reset token: " . $stmt->error . "</p>";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Forgot Password</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap');
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: #1f2937;
        }
    </style>
</head>
<body class="flex justify-center items-center min-h-screen p-4">

<div class="box bg-white/5 backdrop-blur-md border border-gray-700 p-8 rounded-2xl shadow-2xl w-full max-w-sm">
    
    <div class="text-center mb-8">
        <h2 class="text-3xl font-extrabold text-white">Reset Password</h2>
        <p class="text-sm text-gray-400">Enter your email to receive a reset link.</p>
    </div>
    
    <form method="POST" class="space-y-4">
        <div>
            <input 
                type="email" 
                name="email" 
                placeholder="Email Address" 
                required
                class="w-full p-4 border border-gray-600 rounded-xl bg-gray-700 text-white placeholder-gray-400 
                       focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-150 shadow-inner"
            >
        </div>

        <?php echo $message; ?>

        <button 
            type="submit" 
            name="forgot_password"
            class="w-full py-3.5 bg-blue-600 hover:bg-blue-500 text-white font-semibold text-lg rounded-xl shadow-lg 
                   shadow-blue-500/50 transition duration-300 transform hover:scale-[1.01]"
        >
            Process Reset Link
        </button>
        
        <button 
            type="button" 
            onclick="window.location.href='index.php'"
            class="w-full py-3.5 bg-transparent border border-gray-500 text-gray-400 hover:bg-gray-700 hover:text-white font-medium rounded-xl 
                   transition duration-200"
        >
            Back to Login
        </button>
    </form>
    
    <?php if (!empty($reset_link)): ?>
        <div class="mt-8 p-4 border border-yellow-500 bg-yellow-900/50 rounded-xl">
            <h3 class="font-bold text-lg text-yellow-300 mb-2">🔒 Reset Password (Click the Link below)</h3>
            <p class="text-sm text-yellow-100 mb-3">This link simulates the email you would receive.</p>
            <a href="<?= htmlspecialchars($reset_link) ?>" class="break-all text-sm text-yellow-100 hover:text-white underline" target="_blank">
                <?= htmlspecialchars($reset_link) ?>
            </a>
            <p class="text-xs text-yellow-400 mt-2">Reset Password Link.</p>
        </div>
    <?php endif; ?>

</div>
</body>
</html>
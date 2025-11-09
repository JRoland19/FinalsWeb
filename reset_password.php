<?php
include("config.php"); // Includes your database connection

$message = "";
$email_provided = false;
$email = "";

// 1. Check only for email in the URL (Token check removed)
if (isset($_GET['email']) && !empty($_GET['email'])) {
    $email = $_GET['email'];
    
    // Check if the email actually exists in the users table
    $check_user = $conn->prepare("SELECT email FROM users WHERE email = ?");
    $check_user->bind_param("s", $email);
    $check_user->execute();
    $result = $check_user->get_result();

    if ($result->num_rows === 1) {
        $email_provided = true; // Show the form
    } else {
        $message = "Error: The user for this reset link was not found.";
    }

} else {
    // If no email is provided in the URL
    $message = "Missing required email parameter for password reset.";
}

// 2. Handle the password form submission
// Note: We only allow submission if the email was successfully found in the GET request
if (isset($_POST['reset_password']) && $email_provided) {
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);
    
    // Check if passwords match and meet strength requirements (kept for basic security)
    if ($new_password !== $confirm_password) {
        $message = "Passwords do not match.";
    } elseif (strlen($new_password) < 8 || 
        !preg_match('/[A-Z]/', $new_password) ||
        !preg_match('/[a-z]/', $new_password) ||
        !preg_match('/[0-9]/', $new_password) ||
        !preg_match('/[^A-Za-z0-9]/', $new_password)) 
    {
        $message = "Password must be at least 8 characters long and include at least one uppercase letter, one lowercase letter, one number, and one special character.";
    } else {
        // Hashing the new password
        $hashedPassword = password_hash($new_password, PASSWORD_DEFAULT);
        
        // A. Update the user's password
        $update_user = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
        $update_user->bind_param("ss", $hashedPassword, $email);
        
        if ($update_user->execute()) {
            // Redirect to login page with success message
            header("Location: index.php?status=reset_success");
            exit();
        } else {
            $message = "Error resetting password: " . $conn->error;
        }
    }
}

// Aesthetics setup
$error_color = "bg-red-900/50 border-red-700 text-red-300";
$success_color = "bg-green-900/50 border-green-700 text-green-300";

// Determine which message to show if submission failed or token check failed
$display_class = strpos($message, 'Database Error') !== false || strpos($message, 'Error') !== false ? $error_color : $error_color; 
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Set New Password</title>
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
        <h2 class="text-3xl font-extrabold text-white">New Password</h2>
        <p class="text-sm text-gray-400">Set a strong, new password for your account.</p>
    </div>
    
    <?php if (!empty($message)): ?>
        <p class="px-4 py-3 rounded-xl text-center font-medium mb-4 <?= $display_class ?>">
            <?= $message ?>
        </p>
    <?php endif; ?>
    
    <?php if ($email_provided): ?>
        <form method="POST" class="space-y-4">
            
            <!-- New Password Field with Show/Hide Toggle -->
            <div class="relative">
                <input 
                    type="password" 
                    name="new_password" 
                    id="new_password"
                    placeholder="New Password" 
                    required
                    class="w-full p-4 border border-gray-600 rounded-xl bg-gray-700 text-white placeholder-gray-400 pr-12 
                           focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-150 shadow-inner"
                >
                <button 
                    type="button" 
                    onclick="togglePasswordVisibility('new_password')"
                    class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-white transition duration-150"
                    aria-label="Toggle new password visibility"
                >
                    <svg id="toggleIcon-new_password" class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <!-- Eye Icon (Visible) -->
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                    </svg>
                </button>
            </div>
            
            <!-- Confirm New Password Field with Show/Hide Toggle -->
            <div class="relative">
                <input 
                    type="password" 
                    name="confirm_password" 
                    id="confirm_password"
                    placeholder="Confirm New Password" 
                    required
                    class="w-full p-4 border border-gray-600 rounded-xl bg-gray-700 text-white placeholder-gray-400 pr-12
                           focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-150 shadow-inner"
                >
                <button 
                    type="button" 
                    onclick="togglePasswordVisibility('confirm_password')"
                    class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-white transition duration-150"
                    aria-label="Toggle confirm password visibility"
                >
                    <svg id="toggleIcon-confirm_password" class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <!-- Eye Icon (Visible) -->
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                    </svg>
                </button>
            </div>
            
            <button 
                type="submit" 
                name="reset_password"
                class="w-full py-3.5 bg-green-600 hover:bg-green-500 text-white font-semibold text-lg rounded-xl shadow-lg 
                       shadow-green-500/50 transition duration-300 transform hover:scale-[1.01]"
            >
                Change Password
            </button>
        </form>
    <?php endif; ?>
    
    <div class="text-center mt-6">
        <a href="index.php" class="text-blue-400 hover:text-blue-300 text-sm font-medium transition duration-200">
            Go to Login Page
        </a>
    </div>

</div>

<!-- JavaScript to handle the toggle -->
<script>
    function togglePasswordVisibility(fieldId) {
        const passwordField = document.getElementById(fieldId);
        const toggleIcon = document.getElementById('toggleIcon-' + fieldId);
        
        if (passwordField.type === 'password') {
            passwordField.type = 'text';
            // Change icon to 'eye-off' (hidden)
            toggleIcon.innerHTML = `
                <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.542-7 1.274-4.057 5.064-7 9.542-7 1.258 0 2.476.166 3.633.473z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M18.885 14.864l-4.243-4.243m0 0a3 3 0 10-4.243-4.243m4.243 4.243l-4.243 4.243m8.486-8.486L5.514 18.486"/>
            `;
        } else {
            passwordField.type = 'password';
            // Change icon back to 'eye' (visible)
            toggleIcon.innerHTML = `
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
            `;
        }
    }
</script>

</body>
</html>
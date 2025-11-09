<?php
session_start();
include("config.php");

$error = "";

// --- Redirect already logged-in users (ORIGINAL LOGIC) ---
if (isset($_SESSION['role'])) {
    $role = strtolower($_SESSION['role']);
    if ($role === 'admin') {
        header("Location: admin_dashboard.php");
        exit();
    } elseif ($role === 'staff') {
        header("Location: staff_dashboard.php");
        exit();
    }
}

// --- Handle login (SECURITY UPGRADE) ---
if (isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password']; // Get raw password

    if (empty($username) || empty($password)) {
        $error = "Please fill in all fields.";
    } else {
        // 1. Fetch user data (including the stored hash/MD5)
        $stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE username=? LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        $login_success = false;
        
        if ($user) {
            $stored_hash = $user['password'];

            // 2. CHECK: Try modern password_verify()
            if (password_verify($password, $stored_hash)) {
                $login_success = true;
                // If it succeeds, the password is already modern and secure. No update needed.

            // 3. CHECK: Fallback to old MD5 hash
            } elseif (md5($password) === $stored_hash) {
                $login_success = true;

                // *** RE-HASHING ON LOGIN (The Security Upgrade!) ***
                // The user successfully logged in with an old MD5 password.
                // Re-hash and save the new secure hash to the database for future logins.
                $new_secure_hash = password_hash($password, PASSWORD_DEFAULT);
                $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $update_stmt->bind_param("si", $new_secure_hash, $user['id']);
                $update_stmt->execute();
                $update_stmt->close();
            }
        }

        if ($login_success) {
            $role = strtolower($user['role']);
            
            // Set session
            $_SESSION['id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $role;

            // Redirect based on role
            if ($role === 'admin') {
                header("Location: admin_dashboard.php");
                exit();
            } elseif ($role === 'staff') {
                header("Location: staff_dashboard.php");
                exit();
            } else {
                $error = "Unknown role.";
            }
        } else {
            $error = "Invalid username or password.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Login</title>
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

<div class="login-box bg-white/5 backdrop-blur-md border border-gray-700 p-8 rounded-2xl shadow-2xl w-full max-w-sm">
    
    <div class="text-center mb-8">
        <div class="mx-auto h-16 w-16 rounded-full bg-blue-600 p-3 ring-4 ring-white/20 flex items-center justify-center mb-4">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
            </svg>
        </div>
        
        <h2 class="mt-4 text-3xl font-extrabold text-white">Simple Warehouse Management System</h2>
        <p class="text-sm text-gray-400">Log in to continue to your dashboard</p>
    </div>
    
    <form method="POST" class="space-y-6">
        <div>
            <label for="username" class="sr-only">Username</label>
            <input 
                type="text" 
                name="username" 
                id="username"
                placeholder="Username" 
                value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" 
                required 
                class="w-full p-4 border border-gray-600 rounded-xl bg-gray-700 text-white placeholder-gray-400 
                       focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-150 shadow-inner"
            >
        </div>
        
        <div class="relative">
            <label for="password" class="sr-only">Password</label>
            <input 
                type="password" 
                name="password" 
                id="password"
                placeholder="Password" 
                required 
                class="w-full p-4 border border-gray-600 rounded-xl bg-gray-700 text-white placeholder-gray-400 pr-12
                       focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-150 shadow-inner"
            >
            <button 
                type="button" 
                onclick="togglePasswordVisibility('password')"
                class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-white transition duration-150"
                aria-label="Toggle password visibility"
            >
                <svg id="toggleIcon-password" class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                </svg>
            </button>
        </div>
        
        <div class="text-right -mt-4">
            <a href="forgot_password.php" class="text-sm text-blue-400 hover:text-blue-300 transition duration-150">
                Forgot Password?
            </a>
        </div>

        <button 
            type="submit" 
            name="login" 
            class="w-full py-3.5 bg-blue-600 hover:bg-blue-500 text-white font-semibold text-lg rounded-xl shadow-lg 
                   shadow-blue-500/50 transition duration-300 transform hover:scale-[1.01]"
        >
            Login
        </button>
        
        <?php if ($error): ?>
            <p class="bg-red-900/50 border border-red-700 text-red-300 px-4 py-3 rounded-xl text-center font-medium mt-6 transition duration-300 ease-in-out">
                <?= htmlspecialchars($error) ?>
            </p>
        <?php endif; ?>

        <div class="text-center pt-6">
            <span class="text-gray-400 text-sm block mb-3">
                Don't have an account?
            </span>
            <button 
                type="button" 
                class="w-full py-3 bg-transparent border border-green-500 text-green-400 hover:bg-green-500 hover:text-white font-medium rounded-xl 
                       transition duration-200"
                onclick="window.location.href='signup.php'"
            >
                Sign Up
            </button>
        </div>
    </form>
</div>

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
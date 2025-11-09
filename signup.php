<?php
include("config.php");

$error = "";
$success = "";

// When the signup form is submitted
if (isset($_POST['register'])) {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    $confirm_password = trim($_POST['confirm_password']);
    $role = trim($_POST['role']);

    if (empty($username) || empty($email) || empty($password) || empty($confirm_password) || empty($role)) {
        $error = "Please fill in all fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } 
    // *** NEW PASSWORD STRENGTH VALIDATION ***
    // Requires: Min 8 chars, 1 uppercase, 1 lowercase, 1 number, 1 special character
    elseif (strlen($password) < 8 || 
        !preg_match('/[A-Z]/', $password) ||    // Check for at least one uppercase letter
        !preg_match('/[a-z]/', $password) ||    // Check for at least one lowercase letter
        !preg_match('/[0-9]/', $password) ||    // Check for at least one digit
        !preg_match('/[^A-Za-z0-9]/', $password)) // Check for at least one special character
    {
        $error = "Password must be at least 8 characters long and include at least one uppercase letter, one lowercase letter, one number, and one special character.";
    }
    // *** END NEW VALIDATION ***
    
    else {
        // Check if username OR email already exists
        $check = $conn->prepare("SELECT * FROM users WHERE username=? OR email=?");
        $check->bind_param("ss", $username, $email);
        $check->execute();
        $result = $check->get_result();

        if ($result->num_rows > 0) {
            $error = "Username or Email already taken.";
        } else {
            // Secure hashing remains in place
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT); 
            
            // Prepare statement to include the 'email' column
            $insert = $conn->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)");
            $insert->bind_param("ssss", $username, $email, $hashedPassword, $role);

            if ($insert->execute()) {
                $success = "Account created successfully! You can now log in.";
                // Clear the form fields after success
                unset($_POST);
            } else {
                $error = "Error creating account. Database error: " . $conn->error;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sign Up</title>
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

<div class="signup-box bg-white/5 backdrop-blur-md border border-gray-700 p-8 rounded-2xl shadow-2xl w-full max-w-sm">
    
    <div class="text-center mb-8">
        <h2 class="text-3xl font-extrabold text-white">Create Account</h2>
        <p class="text-sm text-gray-400">Please provide your details to register</p>
    </div>
    
    <form method="POST" class="space-y-4">
        <div>
            <input 
                type="text" 
                name="username" 
                placeholder="Username" 
                value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                required
                class="w-full p-4 border border-gray-600 rounded-xl bg-gray-700 text-white placeholder-gray-400 
                       focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-150 shadow-inner"
            >
        </div>
        
        <div>
            <input 
                type="email" 
                name="email" 
                placeholder="Email Address" 
                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                required
                class="w-full p-4 border border-gray-600 rounded-xl bg-gray-700 text-white placeholder-gray-400 
                       focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-150 shadow-inner"
            >
        </div>
        
        <!-- PASSWORD FIELD with Show/Hide Toggle -->
        <div class="relative">
            <input 
                type="password" 
                name="password" 
                id="password" 
                placeholder="Password (complex requirements below)" 
                required
                oninput="validatePasswordStrength()"
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
                    <!-- Eye Icon (Visible) -->
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                </svg>
            </button>
        </div>

        <!-- NEW: Password Strength Hints -->
        <div id="password-requirements" class="text-sm space-y-1 p-3 bg-gray-800 rounded-xl border border-gray-700">
            <p class="text-gray-300 font-semibold mb-2">Password must contain:</p>
            <ul class="space-y-1">
                <li id="req-length" class="flex items-center space-x-2 text-red-400 transition-colors duration-300">
                    <span id="icon-length">
                        <!-- Default Red X icon -->
                        <svg class="h-4 w-4 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </span>
                    <span>Minimum 8 characters</span>
                </li>
                <li id="req-uppercase" class="flex items-center space-x-2 text-red-400 transition-colors duration-300">
                    <span id="icon-uppercase">
                        <svg class="h-4 w-4 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </span>
                    <span>One uppercase letter (A-Z)</span>
                </li>
                <li id="req-lowercase" class="flex items-center space-x-2 text-red-400 transition-colors duration-300">
                    <span id="icon-lowercase">
                        <svg class="h-4 w-4 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </span>
                    <span>One lowercase letter (a-z)</span>
                </li>
                <li id="req-number" class="flex items-center space-x-2 text-red-400 transition-colors duration-300">
                    <span id="icon-number">
                        <svg class="h-4 w-4 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </span>
                    <span>One number (0-9)</span>
                </li>
                <li id="req-special" class="flex items-center space-x-2 text-red-400 transition-colors duration-300">
                    <span id="icon-special">
                        <svg class="h-4 w-4 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </span>
                    <span>One special character (!@#$...)</span>
                </li>
            </ul>
        </div>
        
        <!-- CONFIRM PASSWORD FIELD with Show/Hide Toggle -->
        <div class="relative">
            <input 
                type="password" 
                name="confirm_password" 
                id="confirm_password" 
                placeholder="Confirm Password" 
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
        
        <div>
            <select 
                name="role" 
                required
                class="w-full p-4 border border-gray-600 rounded-xl bg-gray-700 text-white placeholder-gray-400 
                       focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-150 shadow-inner appearance-none"
            >
                <option value="" class="bg-gray-700 text-gray-400">-- Select Role --</option>
                <option value="admin" class="bg-gray-700 text-white">Admin</option>
                <option value="staff" class="bg-gray-700 text-white">Staff</option>
            </select>
        </div>

        <button 
            type="submit" 
            name="register"
            class="w-full py-3.5 bg-green-600 hover:bg-green-500 text-white font-semibold text-lg rounded-xl shadow-lg 
                   shadow-green-500/50 transition duration-300 transform hover:scale-[1.01]"
        >
            Register
        </button>
        
        <button 
            type="button" 
            onclick="window.location.href='index.php'"
            class="w-full py-3.5 bg-transparent border border-blue-500 text-blue-400 hover:bg-blue-500 hover:text-white font-medium rounded-xl 
                   transition duration-200"
        >
            Back to Login
        </button>
        
        <?php if ($error): ?>
            <p class="bg-red-900/50 border border-red-700 text-red-300 px-4 py-3 rounded-xl text-center font-medium mt-6"><?= $error ?></p>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <p class="bg-green-900/50 border border-green-700 text-green-300 px-4 py-3 rounded-xl text-center font-medium mt-6"><?= $success ?></p>
        <?php endif; ?>
    </form>
</div>

<!-- JavaScript to handle the toggle and the real-time strength validation -->
<script>
    // --- Icons ---
    const iconCheck = `<svg class="h-4 w-4 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>`;
    const iconX = `<svg class="h-4 w-4 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>`;

    // --- Utility Function to Update UI for a Single Requirement ---
    function updateRequirement(reqId, condition) {
        const iconElement = document.getElementById('icon-' + reqId);
        const textElement = document.getElementById('req-' + reqId);

        if (iconElement && textElement) {
            if (condition) {
                // Set to Checkmark and Green text
                iconElement.innerHTML = iconCheck;
                textElement.classList.remove('text-red-400');
                textElement.classList.add('text-green-400');
            } else {
                // Set to X and Red text
                iconElement.innerHTML = iconX;
                textElement.classList.remove('text-green-400');
                textElement.classList.add('text-red-400');
            }
        }
    }

    // --- Main Validation Logic ---
    function validatePasswordStrength() {
        const password = document.getElementById('password').value;

        // 1. Length Check (Min 8 characters)
        const isLengthValid = password.length >= 8;
        updateRequirement('length', isLengthValid);

        // 2. Uppercase Check (Min 1 uppercase)
        const isUppercaseValid = /[A-Z]/.test(password);
        updateRequirement('uppercase', isUppercaseValid);

        // 3. Lowercase Check (Min 1 lowercase)
        const isLowercaseValid = /[a-z]/.test(password);
        updateRequirement('lowercase', isLowercaseValid);

        // 4. Number Check (Min 1 digit)
        const isNumberValid = /[0-9]/.test(password);
        updateRequirement('number', isNumberValid);

        // 5. Special Character Check (Min 1 special char)
        // Uses the same regex as the PHP validation: /[^A-Za-z0-9]/
        const isSpecialValid = /[^A-Za-z0-9]/.test(password); 
        updateRequirement('special', isSpecialValid);
    }
    
    // --- Existing Password Toggle Function ---
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
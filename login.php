<?php
session_start();
include 'db.php';

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    $normalizedRole = strtolower($_SESSION['role']);
    if ($normalizedRole === 'admin') {
        header("Location: admin.php");
    } elseif ($normalizedRole === 'seller') {
        header("Location: seller.php");
    } else {
        header("Location: coustomer.php");
    }
    exit();
}

$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Invalid form submission";
    } else {
        $email = trim($_POST['email']);
        $password = $_POST['password'];

        if (empty($email) || empty($password)) {
            $error = "Please fill in all fields";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Invalid email format";
        } else {
            $stmt = $conn->prepare("SELECT id, name, email, password, role FROM reg WHERE email = ?");
            if ($stmt) {
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows === 1) {
                    $user = $result->fetch_assoc();
                    
                    // Debugging: Check what user was found
                    error_log("User found: " . print_r($user, true));
                    
                    if (password_verify($password, $user['password'])) {
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['email'] = $user['email'];
                        $_SESSION['name'] = $user['name'];
                        $normalizedRole = strtolower($user['role']);
                        $_SESSION['role'] = $normalizedRole;

                        // Debugging: Check role before redirect
                        error_log("Login successful. Role: $normalizedRole Redirecting...");
                
                        if ($normalizedRole === 'admin') {
                            header("Location: admin.php");
                        } elseif ($normalizedRole === 'seller') {
                            header("Location: seller.php");
                        } else {
                            header("Location: coustomer.php");
                        }
                        exit();
                    } else {
                        // Debugging: Password mismatch
                        error_log("Password verification failed for: $email");
                        $error = "Invalid email or password";
                    }
                } else {
                    // Debugging: No user found
                    error_log("No user found for email: $email");
                    $error = "Invalid email or password";
                }
                $stmt->close();
            } else {
                $error = "Database error: " . $conn->error;
            }
        }
    }
}

// Generate CSRF token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SpiceBid - Login</title>
    <link href="css/reg.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        
        /* New animation styles */
        .registration-container {
            animation: slideUp 0.6s ease-out forwards;
            transform: translateY(100%);
            opacity: 0;
        }
        
        @keyframes slideUp {
            0% {
                transform: translateY(100%);
                opacity: 0;
            }
            100% {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .main-content {
            overflow: hidden; /* Prevent scrollbars during animation */
        }
        .main-content {
            background: url('image/spices/cardamom.jpg') no-repeat center center;
            background-size: cover;
            padding: 2rem;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-grow: 1;
        }
        
        .password-container {
            position: relative;
        }
        
        .password-toggle {
            position: absolute;
            right: -10px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #777;
        }
        
        .password-container input {
            padding-right: 15px;
            width: 100%;
        }
    </style>
</head>
<body>
    <header>
        <div class="logo">SpiceBid</div>
        <nav>
            <!-- Navigation links -->
        </nav>
    </header>

    <div class="main-content">
        <div class="registration-container">
            <div class="registration-header">
                <h2>Welcome Back</h2>
                <p>Login to access your account and participate in auctions</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="message error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="post" action="login.php">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" required placeholder="Enter your email address">
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="password-container">
                        <input type="password" id="password" name="password" required placeholder="Enter your password">
                        <span class="password-toggle" id="password-toggle">
                            <i class="fas fa-eye"></i>
                        </span>
                    </div>
                </div>
                
                <button type="submit" class="btn">Login</button>
            </form>

            <div class="login-prompt">
                <p>Don't have an account? <a href="reg.php">Register here</a></p>
                <p><a href="forgot_password.php">Forgot your password?</a></p>
            </div>
        </div>
    </div>

    <footer>
        <p>&copy; 2025 . Ament diljo</p>
    </footer>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const passwordField = document.getElementById('password');
            const passwordToggle = document.getElementById('password-toggle');
            const eyeIcon = passwordToggle.querySelector('i');
            
            passwordToggle.addEventListener('click', function() {
                if (passwordField.type === 'password') {
                    passwordField.type = 'text';
                    eyeIcon.classList.remove('fa-eye');
                    eyeIcon.classList.add('fa-eye-slash');
                } else {
                    passwordField.type = 'password';
                    eyeIcon.classList.remove('fa-eye-slash');
                    eyeIcon.classList.add('fa-eye');
                }
            });
        });
    </script>
</body>
</html>
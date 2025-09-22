<?php
session_start();
include 'db.php';

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is logged in and is admin
if (!isset($_SESSION['email'])) {
    header("Location: login.php");
    exit();
}

if ($_SESSION['role'] !== 'admin') {
    die("Access denied. Admin privileges required.");
}

// Check database connection
if (!$conn || $conn->connect_error) {
    die("Database connection failed: " . ($conn ? $conn->connect_error : "No connection object"));
}

// Initialize variables
$error = '';
$success = '';
$user = null;

// Get user ID from URL
$user_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

// Fetch user data
if ($user_id) {
    $stmt = $conn->prepare("SELECT * FROM reg WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    if (!$user) {
        $error = "User not found";
    }
} else {
    $error = "Invalid user ID";
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['update_user'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Invalid CSRF token");
    }
    
    $user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $role = isset($_POST['role']) ? trim($_POST['role']) : '';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (!$user_id || !$name || !$email || !$role) {
        $error = "All required fields are missing";
    } 
    // Validate passwords if either is provided
    elseif ($password || $confirm_password) {
        if ($password !== $confirm_password) {
            $error = "Passwords do not match";
        } elseif (strlen($password) < 6) {
            $error = "Password must be at least 6 characters";
        }
    }
    
    // Validate role
    $allowed_roles = ['admin', 'seller', 'user'];
    if (!in_array($role, $allowed_roles)) {
        $error = "Invalid role selected";
    }
    
    if (!$error) {
        try {
            // Update without password if password fields are empty
            if (empty($password)) {
                $stmt = $conn->prepare("UPDATE reg SET name = ?, email = ?, phone = ?, role = ? WHERE id = ?");
                $stmt->bind_param("ssssi", $name, $email, $phone, $role, $user_id);
            } 
            // Update with password if password is provided
            else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE reg SET name = ?, email = ?, phone = ?, role = ?, password = ? WHERE id = ?");
                $stmt->bind_param("sssssi", $name, $email, $phone, $role, $hashed_password, $user_id);
            }
            
            $stmt->execute();
            
            if ($stmt->affected_rows > 0) {
                $success = "User updated successfully!";
                // Refresh user data
                $stmt = $conn->prepare("SELECT * FROM reg WHERE id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $user = $result->fetch_assoc();
            } else {
                $error = "No changes made or user not found";
            }
            $stmt->close();
        } catch (Exception $e) {
            $error = "Error updating user: " . $e->getMessage();
        }
    }
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User - SpiceBid Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            padding-top: 70px;
        }
        .admin-header {
            background: #8B4513;
            color: white;
            padding: 15px 0;
            position: fixed;
            top: 0;
            width: 100%;
            z-index: 1000;
        }
        .admin-card {
            background: white;
            border-radius: 5px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .password-field {
            position: relative;
        }
        .toggle-password {
            position: absolute;
            right: 10px;
            top: 35px;
            cursor: pointer;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <!-- Admin Header -->
    <header class="admin-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1><i class="fas fa-crown"></i> SpiceBid Admin</h1>
                </div>
                <div class="text-end">
                    <p class="mb-0">Welcome, <?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></p>
                    <div class="d-flex justify-content-end mt-2">
                        <a href="admin.php?section=users" class="btn btn-light btn-sm me-2">
                            <i class="fas fa-arrow-left"></i> Back to Users
                        </a>
                        <a href="logout.php" class="btn btn-light btn-sm">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="admin-card">
                    <h3><i class="fas fa-user-edit"></i> Edit User</h3>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>
                    
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                    <?php endif; ?>
                    
                    <?php if ($user): ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                        
                        <div class="mb-3">
                            <label class="form-label">Name *</label>
                            <input type="text" class="form-control" name="name" 
                                   value="<?= htmlspecialchars($user['name']) ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Email *</label>
                            <input type="email" class="form-control" name="email" 
                                   value="<?= htmlspecialchars($user['email']) ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Phone</label>
                            <input type="text" class="form-control" name="phone" 
                                   value="<?= htmlspecialchars($user['phone']) ?>">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Role *</label>
                            <select class="form-select" name="role" required>
                                <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                <option value="seller" <?= $user['role'] === 'seller' ? 'selected' : '' ?>>Seller</option>
                                <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>User</option>
                            </select>
                        </div>
                        
                        <div class="mb-3 password-field">
                            <label class="form-label">New Password</label>
                            <input type="password" class="form-control" name="password" 
                                   placeholder="Leave blank to keep current password">
                            <i class="fas fa-eye toggle-password"></i>
                        </div>
                        
                        <div class="mb-3 password-field">
                            <label class="form-label">Confirm Password</label>
                            <input type="password" class="form-control" name="confirm_password" 
                                   placeholder="Confirm new password">
                            <i class="fas fa-eye toggle-password"></i>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Password must be at least 6 characters. 
                            Leave both password fields blank to keep current password.
                        </div>
                        
                        <button type="submit" name="update_user" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update User
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Password visibility toggle
        document.querySelectorAll('.toggle-password').forEach(function(icon) {
            icon.addEventListener('click', function() {
                const input = this.previousElementSibling;
                const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
                input.setAttribute('type', type);
                this.classList.toggle('fa-eye');
                this.classList.toggle('fa-eye-slash');
            });
        });
    </script>
</body>
</html>
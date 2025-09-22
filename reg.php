<?php
session_start();
include 'db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Invalid form submission");
    }

    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $user_type = trim($_POST['user_type'] ?? 'buyer');  // Default to buyer

    // Validation
    $errors = [];
    if (empty($name)) $errors[] = "Name is required";
    if (empty($phone)) $errors[] = "Phone number is required";
    if (empty($email)) $errors[] = "Email is required";
    if (empty($password)) $errors[] = "Password is required";
    if (!in_array($user_type, ['buyer', 'seller'])) {
        $errors[] = "Invalid user type selected";
    }
    
    // NAME VALIDATION: No numbers allowed
    if (!preg_match('/^[a-zA-Z\s]+$/', $name)) {
        $errors[] = "Name should contain only letters and spaces";
    }
    
    // PHONE VALIDATION: No alphabets allowed
    if (!preg_match('/^[\d\s\(\)\-]+$/', $phone)) {
        $errors[] = "Phone number should contain only digits, spaces, hyphens and parentheses";
    }
    
    // Remove non-digit characters for database storage
    $clean_phone = preg_replace('/\D/', '', $phone);
    if (strlen($clean_phone) < 10) {
        $errors[] = "Phone number must be at least 10 digits";
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }
    
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters";
    }

    if (empty($errors)) {
        // Check if email already exists
        $stmt = $conn->prepare("SELECT email FROM reg WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();
        
        if ($stmt->num_rows > 0) {
            $errors[] = "Email already registered";
        }
        $stmt->close();
    }

    if (empty($errors)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Map user_type to database role
        $role = ($user_type === 'seller') ? 'seller' : 'user';
        
        // Prepare INSERT statement with correct columns
        $stmt = $conn->prepare("INSERT INTO reg (name, phone, email, password, role) VALUES (?, ?, ?, ?, ?)");
        
        if ($stmt) {
            // Use cleaned phone number for database
            $stmt->bind_param("sssss", $name, $clean_phone, $email, $hashed_password, $role);
            if ($stmt->execute()) {
                // Get the auto-generated user ID
                $user_id = $stmt->insert_id;
                
                // Set session variables
                $_SESSION['user_id'] = $user_id;
                $_SESSION['role'] = $role;
                $_SESSION['email'] = $email;
                $_SESSION['name'] = $name;
                
                // Redirect based on user type
                if ($role === 'seller') {
                    header("Location: seller.php");
                } else {
                    header("Location: coustomer.php");
                }
                exit();
            } else {
                $errors[] = "Registration failed: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $errors[] = "Database error: " . $conn->error;
        }
    }
    
    if (!empty($errors)) {
        $error_message = implode("<br>", $errors);
        echo "<script>alert('" . addslashes($error_message) . "'); window.history.back();</script>";
        exit();
    }
}

// Generate CSRF token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SpiceBid - Registration</title>
    <link href="css/reg.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        
        .user-type-container {
            margin-bottom: 20px;
        }
        .user-type-options {
            display: flex;
            gap: 15px;
            margin-top: 5px;
        }
        .user-type-option {
            flex: 1;
            text-align: center;
            padding: 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .user-type-option:hover {
            border-color: #8B4513;
        }
        .user-type-option.selected {
            border-color: #8B4513;
            background-color: rgba(139, 69, 19, 0.1);
        }
        .user-type-option input[type="radio"] {
            display: none;
        }
        .user-icon {
            font-size: 24px;
            margin-bottom: 8px;
            color: #8B4513;
        }
        .seller-id-preview {
            margin-top: 10px;
            font-size: 14px;
            color: #666;
            display: none;
        }
        .main-content {
            background: url('image/spices/spices.jpg') no-repeat center center;
            background-size: cover;
            padding: 2rem;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-grow: 1;
        }
        .password-wrapper {
            position: relative;
        }
        .toggle-password {
            position: absolute;
            right: -10px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #8B4513;
        }
        .error-message {
            color: #ff0000;
            font-size: 12px;
            margin-top: 5px;
            display: none;
        }
    </style>
</head>
<body>
   <header>
        <div class="logo">SpiceBid</div>
        <nav>
            <ul>
                <li><a href="home.html"><i class="fas fa-home"></i> Home</a></li>
            </ul>
        </nav>
    </header>

    <div class="main-content">
        <div class="registration-container">
            <div class="registration-header">
                <h2>Create Your Account</h2>
                <p>Register to participate in our exclusive spice auctions</p>
            </div>
            
            <form method="post" action="" id="registrationForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                <div class="form-group">
                    <label for="name">Full Name</label>
                    <input type="text" id="name" name="name" required placeholder="Enter your full name">
                    <span id="name-error" class="error-message">Name should contain only letters and spaces</span>
                </div>
                
                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone" required placeholder="Enter your phone number">
                    <span id="phone-error" class="error-message">Phone number should contain only digits, spaces, hyphens and parentheses</span>
                </div>
                
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" required placeholder="Enter your email address">
                </div>
                
                <!-- Password Field with Toggle -->
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="password" name="password" required 
                               placeholder="Enter your password (min 8 characters)">
                        <span class="toggle-password" onclick="togglePassword()">
                            <i class="fas fa-eye"></i>
                        </span>
                    </div>
                </div>
                
                <!-- User Type Selection -->
                <div class="form-group user-type-container">
                    <label>I want to register as:</label>
                    <div class="user-type-options">
                        <label class="user-type-option" onclick="selectUserType(this)">
                            <input type="radio" name="user_type" value="buyer" checked>
                            <div class="user-icon"><i class="fas fa-shopping-cart"></i></div>
                            <div>Buyer</div>
                            <div class="small-text">Browse and bid on spices</div>
                        </label>
                        <label class="user-type-option" onclick="selectUserType(this)">
                            <input type="radio" name="user_type" value="seller">
                            <div class="user-icon"><i class="fas fa-store"></i></div>
                            <div>Seller</div>
                            <div class="small-text">List spices for auction</div>
                        </label>
                    </div>
                    <div id="sellerIdPreview" class="seller-id-preview">
                        Your Seller ID will be: <span id="sellerIdDisplay"></span>
                    </div>
                </div>
                
                <button type="submit" class="btn">Register Now</button>
            </form>
            
            <div class="login-prompt">
                <p>Already have an account? <a href="http://localhost:8080/php/login.php">Log in here</a></p>
            </div>
        </div>
    </div>
    
    <footer>
        <p>&copy; 2025 . Ament diljo</p>
    </footer>

    <script>
        // Password toggle function
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const icon = document.querySelector('.toggle-password i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        
        // Name validation function
        function validateName() {
            const nameInput = document.getElementById('name');
            const errorElement = document.getElementById('name-error');
            const name = nameInput.value.trim();
            
            // Regex: Only letters and spaces
            const nameRegex = /^[a-zA-Z\s]+$/;
            
            if (!nameRegex.test(name)) {
                errorElement.style.display = 'block';
                nameInput.style.borderColor = '#ff0000';
                return false;
            }
            
            errorElement.style.display = 'none';
            nameInput.style.borderColor = '';
            return true;
        }
        
        // Phone validation function
        function validatePhone() {
            const phoneInput = document.getElementById('phone');
            const errorElement = document.getElementById('phone-error');
            const phone = phoneInput.value.trim();
            
            // Regex: Only digits, spaces, hyphens, and parentheses
            const phoneRegex = /^[\d\s\(\)\-]+$/;
            
            if (!phoneRegex.test(phone)) {
                errorElement.style.display = 'block';
                phoneInput.style.borderColor = '#ff0000';
                return false;
            }
            
            // Additional check for minimum 10 digits
            const digitCount = phone.replace(/\D/g, '').length;
            if (digitCount < 10) {
                errorElement.textContent = "Phone number must only contain digits ";
                errorElement.style.display = 'block';
                phoneInput.style.borderColor = '#ff0000';
                return false;
            }
            
            errorElement.style.display = 'none';
            phoneInput.style.borderColor = '';
            return true;
        }
        
        // User type selection
        function selectUserType(element) {
            // Remove selected class from all options
            document.querySelectorAll('.user-type-option').forEach(opt => {
                opt.classList.remove('selected');
            });
            
            // Add selected class to clicked option
            element.classList.add('selected');
            
            // Check the radio button inside
            const radio = element.querySelector('input[type="radio"]');
            radio.checked = true;
            
            // Show/hide seller ID preview
            updateSellerIdPreview();
        }
        
        // Generate preview seller ID
        function generateSellerId(name) {
            if (!name) return '';
            const cleanName = name.replace(/[^a-zA-Z]/g, '').toUpperCase();
            const prefix = cleanName.length >= 3 ? cleanName.substring(0, 3) : 
                         (cleanName + 'XXX').substring(0, 3);
            return 'SP' + prefix + Math.floor(1000 + Math.random() * 9000);
        }
        
        // Update seller ID preview
        function updateSellerIdPreview() {
            const sellerType = document.querySelector('input[name="user_type"]:checked').value;
            const preview = document.getElementById('sellerIdPreview');
            const display = document.getElementById('sellerIdDisplay');
            
            if (sellerType === 'seller') {
                const name = document.getElementById('name').value;
                display.textContent = generateSellerId(name);
                preview.style.display = 'block';
            } else {
                preview.style.display = 'none';
            }
        }
        
        // Form submission validation
        function validateForm() {
            const isNameValid = validateName();
            const isPhoneValid = validatePhone();
            return isNameValid && isPhoneValid;
        }
        
        // Initialize selected state on page load
        document.addEventListener('DOMContentLoaded', function() {
            const checkedOption = document.querySelector('input[name="user_type"]:checked').closest('.user-type-option');
            if (checkedOption) {
                checkedOption.classList.add('selected');
            }
            
            // Add event listeners for validation
            document.getElementById('name').addEventListener('input', function() {
                validateName();
                updateSellerIdPreview();
            });
            
            document.getElementById('phone').addEventListener('input', validatePhone);
            
            // Add form submit event listener
            document.getElementById('registrationForm').addEventListener('submit', function(e) {
                if (!validateForm()) {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>
</html>
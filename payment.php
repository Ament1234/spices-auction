<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
include 'db.php';

// Check if user is logged in
if (!isset($_SESSION['email'])) {
    header("Location: login.php");
    exit();
}

// Validate auction ID
$auction_id = filter_input(INPUT_GET, 'auction_id', FILTER_VALIDATE_INT);
if (!$auction_id) {
    die("Invalid auction ID");
}

// Fetch auction details
$auction = [];
$auction_stmt = $conn->prepare("SELECT a.*, u.name AS seller_name 
                       FROM auctions a 
                       JOIN reg u ON a.seller_id = u.id 
                       WHERE a.id = ?");
$auction_stmt->bind_param("i", $auction_id);
$auction_stmt->execute();
$result = $auction_stmt->get_result();

if ($result->num_rows === 0) {
    die("Auction not found");
}
$auction = $result->fetch_assoc();

// Check if user is the winner
$winner_stmt = $conn->prepare("SELECT b.*, u.name AS bidder_name 
                              FROM bids b 
                              JOIN reg u ON b.user_id = u.id 
                              WHERE b.auction_id = ? 
                              ORDER BY b.amount DESC 
                              LIMIT 1");
$winner_stmt->bind_param("i", $auction_id);
$winner_stmt->execute();
$winner_result = $winner_stmt->get_result();

if ($winner_result->num_rows === 0) {
    die("No winner for this auction");
}
$winner = $winner_result->fetch_assoc();

if ($winner['user_id'] != $_SESSION['user_id']) {
    die("You are not the winner of this auction");
}

// Handle payment submission
$error = '';
$success = '';
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Invalid request");
    }
    
    // Get payment method
    $payment_method = trim($_POST['payment_method']);
    
    // Validate payment method
    if (empty($payment_method)) {
        $error = "Please select a payment method";
    } else {
        // Process payment (simulated)
        try {
            $conn->begin_transaction();
            
            // Record transaction
            $stmt = $conn->prepare("INSERT INTO transactions 
                                    (auction_id, user_id, amount, payment_method, status) 
                                    VALUES (?, ?, ?, ?, 'completed')");
            $stmt->bind_param("iids", $auction_id, $_SESSION['user_id'], $winner['amount'], $payment_method);
            $stmt->execute();
            
            // Update auction status
            $update_stmt = $conn->prepare("UPDATE auctions SET status = 'sold' WHERE id = ?");
            $update_stmt->bind_param("i", $auction_id);
            $update_stmt->execute();
            
            $conn->commit();
            $success = "Payment successful! Your order will be shipped soon.";
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Payment failed: " . $e->getMessage();
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
    <title>Complete Payment | SpiceBid</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --spice-primary: #8B4513;
            --spice-secondary: #D2691E;
            --spice-light: #F5DEB3;
            --spice-dark: #654321;
        }
        
        .spice-header {
            background: linear-gradient(135deg, var(--spice-primary), var(--spice-secondary));
            color: white;
            padding: 20px 0;
        }
        
        .payment-card {
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 25px;
            border: none;
        }
        
        .payment-summary {
            background-color: rgba(139, 69, 19, 0.05);
            border-radius: 10px;
            padding: 20px;
        }
        
        .price-tag {
            font-size: 2rem;
            font-weight: 700;
            color: var(--spice-primary);
        }
        
        .btn-spice {
            background: linear-gradient(135deg, var(--spice-primary), var(--spice-secondary));
            color: white;
            font-weight: 600;
            padding: 12px 24px;
            border-radius: 30px;
            transition: all 0.3s ease;
        }
        
        .btn-spice:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(139, 69, 19, 0.3);
        }
        
        .payment-option {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .payment-option:hover, .payment-option.selected {
            border-color: var(--spice-secondary);
            background-color: rgba(210, 105, 30, 0.05);
        }
        
        .payment-option input[type="radio"] {
            margin-right: 10px;
        }
        
        .qr-code {
            width: 200px;
            height: 200px;
            background-color: #fff;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin: 0 auto 20px;
        }
        
        .success-banner {
            background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
            border-left: 4px solid #4CAF50;
            border-radius: 8px;
            padding: 20px;
        }
        
        .payment-icon {
            font-size: 2rem;
            margin-right: 10px;
            color: var(--spice-primary);
        }
        
        .payment-instructions {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="spice-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1><i class="fas fa-pepper-hot"></i> SpiceBid Marketplace</h1>
                    <p class="mb-0">Complete Your Purchase</p>
                </div>
                <div class="text-end">
                    <p class="mb-0">Welcome, <?php echo htmlspecialchars($_SESSION['name'] ?? 'Customer', ENT_QUOTES, 'UTF-8'); ?></p>
                    <div class="d-flex justify-content-end mt-2">
                        <a href="coustomer.php" class="btn btn-light btn-sm me-2">
                            <i class="fas fa-arrow-left me-1"></i> Back to Auctions
                        </a>
                        <a href="logout.php" class="btn btn-light btn-sm">
                            <i class="fas fa-sign-out-alt me-1"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="container my-5">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <!-- Messages -->
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($success)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (empty($success)): ?>
                <div class="payment-card p-4 mb-4">
                    <h2 class="text-center mb-4">Complete Your Payment</h2>
                    
                    <div class="row">
                        <!-- Left Column - Order Summary -->
                        <div class="col-md-5">
                            <div class="payment-summary">
                                <h4 class="mb-4">Order Summary</h4>
                                
                                <div class="d-flex align-items-center mb-4">
                                    <?php if ($auction['image_path']): ?>
                                        <img src="<?php echo htmlspecialchars($auction['image_path']); ?>" 
                                             class="img-fluid rounded me-3" 
                                             alt="<?php echo htmlspecialchars($auction['spice_name']); ?>"
                                             style="width: 80px; height: 80px; object-fit: cover;">
                                    <?php else: ?>
                                        <div class="d-flex align-items-center justify-content-center bg-light rounded me-3" 
                                             style="width: 80px; height: 80px;">
                                            <i class="fas fa-pepper-hot fa-2x text-muted"></i>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div>
                                        <h5><?php echo htmlspecialchars($auction['spice_name']); ?></h5>
                                        <p class="mb-0">Quantity: <?php echo $auction['quantity']; ?> kg</p>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between mb-2">
                                        <span>Winning Bid:</span>
                                        <span class="fw-bold">₹<?php echo number_format($winner['amount'], 2); ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-2">
                                        <span>Shipping:</span>
                                        <span class="fw-bold">FREE</span>
                                    </div>
                                    <hr>
                                    <div class="d-flex justify-content-between">
                                        <span class="fw-bold">Total:</span>
                                        <span class="price-tag">₹<?php echo number_format($winner['amount'], 2); ?></span>
                                    </div>
                                </div>
                                
                                <div class="mt-4">
                                    <h5>Shipping To</h5>
                                    <p class="mb-1">
                                        <?php echo htmlspecialchars($_SESSION['name']); ?><br>
                                        <?php echo htmlspecialchars($_SESSION['address'] ?? 'Address not specified'); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Right Column - Payment Options -->
                        <div class="col-md-7">
                            <form method="post" id="paymentForm">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                
                                <h4 class="mb-4">Select Payment Method</h4>
                                
                                <!-- UPI QR Code Option -->
                                <div class="payment-option" onclick="selectPaymentMethod('upi')">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="payment_method" id="upi" value="UPI" required>
                                        <label class="form-check-label fw-bold" for="upi">
                                            <i class="fas fa-qrcode payment-icon"></i>Pay via UPI QR Code
                                        </label>
                                    </div>
                                    <div class="mt-3 upi-details" style="display: none;">
                                        <div class="text-center">
                                            <div class="qr-code">
                                                <!-- Generate a QR code using a service -->
                                                <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?php echo urlencode('upi://pay?pa=spicebid@upi&pn=SpiceBid%20Marketplace&am=' . $winner['amount'] . '&cu=INR'); ?>" 
                                                     alt="UPI QR Code" class="img-fluid">
                                            </div>
                                            <p class="mb-2">Scan this QR code with your UPI app</p>
                                            <p class="text-muted">(Google Pay, PhonePe, Paytm, BHIM, etc.)</p>
                                            
                                            <div class="payment-instructions">
                                                <h6>How to pay:</h6>
                                                <ol class="text-start small">
                                                    <li>Open your UPI app</li>
                                                    <li>Tap on "Scan QR Code"</li>
                                                    <li>Point your camera at this QR code</li>
                                                    <li>Confirm the payment details</li>
                                                    <li>Enter your UPI PIN to complete</li>
                                                </ol>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Google Pay Option -->
                                <div class="payment-option" onclick="selectPaymentMethod('google_pay')">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="payment_method" id="google_pay" value="Google Pay" required>
                                        <label class="form-check-label fw-bold" for="google_pay">
                                            <i class="fab fa-google payment-icon"></i>Google Pay
                                        </label>
                                    </div>
                                    <div class="mt-3 google-pay-details" style="display: none;">
                                        <p>Complete your payment using Google Pay</p>
                                        <div class="d-grid">
                                            <button type="button" class="btn btn-outline-dark btn-lg" onclick="simulateGPay()">
                                                <i class="fab fa-google-pay me-2"></i>Pay with Google Pay
                                            </button>
                                        </div>
                                        <div class="payment-instructions">
                                            <h6>How to pay with Google Pay:</h6>
                                            <ol class="text-start small">
                                                <li>Click the "Pay with Google Pay" button</li>
                                                <li>You will be redirected to Google Pay</li>
                                                <li>Confirm the payment details</li>
                                                <li>Authenticate with your PIN/pattern</li>
                                                <li>Wait for payment confirmation</li>
                                            </ol>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- PhonePe Option -->
                                <div class="payment-option" onclick="selectPaymentMethod('phonepe')">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="payment_method" id="phonepe" value="PhonePe" required>
                                        <label class="form-check-label fw-bold" for="phonepe">
                                            <i class="fas fa-mobile-alt payment-icon"></i>PhonePe
                                        </label>
                                    </div>
                                    <div class="mt-3 phonepe-details" style="display: none;">
                                        <p>Complete your payment using PhonePe</p>
                                        <div class="d-grid">
                                            <button type="button" class="btn btn-light btn-lg" style="background-color: #673AB7; color: white;" onclick="simulatePhonePe()">
                                                <i class="fas fa-bolt me-2"></i>Pay with PhonePe
                                            </button>
                                        </div>
                                        <div class="payment-instructions">
                                            <h6>How to pay with PhonePe:</h6>
                                            <ol class="text-start small">
                                                <li>Click the "Pay with PhonePe" button</li>
                                                <li>You will be redirected to PhonePe</li>
                                                <li>Confirm the payment details</li>
                                                <li>Authenticate with your UPI PIN</li>
                                                <li>Wait for payment confirmation</li>
                                            </ol>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="d-grid mt-4">
                                    <button type="submit" class="btn btn-spice btn-lg py-3">
                                        <i class="fas fa-lock me-2"></i>Confirm Payment of ₹<?php echo number_format($winner['amount'], 2); ?>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="payment-card p-5 text-center">
                    <div class="success-banner">
                        <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                        <h2 class="mb-3">Payment Successful!</h2>
                        <p class="lead mb-4">Thank you for your purchase. Your order will be shipped soon.</p>
                        
                        <div class="d-flex justify-content-center gap-3">
                            <a href="coustomer.php" class="btn btn-spice">
                                <i class="fas fa-store me-2"></i>Browse More Auctions
                            </a>
                            <a href="order_details.php?auction_id=<?php echo $auction_id; ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-receipt me-2"></i>View Order Details
                            </a>
                        </div>
                    </div>
                    
                    <div class="mt-5">
                        <h4 class="mb-3">Order Summary</h4>
                        <div class="d-flex justify-content-center">
                            <div class="text-start">
                                <p><strong>Product:</strong> <?php echo htmlspecialchars($auction['spice_name']); ?></p>
                                <p><strong>Quantity:</strong> <?php echo $auction['quantity']; ?> kg</p>
                                <p><strong>Order Total:</strong> ₹<?php echo number_format($winner['amount'], 2); ?></p>
                                <p><strong>Payment Method:</strong> <?php echo htmlspecialchars($_POST['payment_method'] ?? 'N/A'); ?></p>
                                <p><strong>Order ID:</strong> #<?php echo $auction_id; ?>-<?php echo uniqid(); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-dark text-white py-4 mt-5">
        <div class="container">
            <div class="text-center">
                <p class="mb-0">&copy; 2025 Ament/Ashish</p>
            </div>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Function to handle payment method selection
        function selectPaymentMethod(method) {
            // Select the radio button
            document.getElementById(method).checked = true;
            
            // Hide all payment details
            document.querySelectorAll('.payment-option > div:not(.form-check)').forEach(el => {
                el.style.display = 'none';
            });
            
            // Remove selected class from all options
            document.querySelectorAll('.payment-option').forEach(el => {
                el.classList.remove('selected');
            });
            
            // Show details for selected method
            if (method === 'upi') {
                document.querySelector('.upi-details').style.display = 'block';
                document.querySelector('.payment-option').classList.add('selected');
            } else if (method === 'google_pay') {
                document.querySelector('.google-pay-details').style.display = 'block';
                document.querySelectorAll('.payment-option')[1].classList.add('selected');
            } else if (method === 'phonepe') {
                document.querySelector('.phonepe-details').style.display = 'block';
                document.querySelectorAll('.payment-option')[2].classList.add('selected');
            }
        }
        
        // Add click handlers to payment options
        document.querySelectorAll('.payment-option').forEach(option => {
            option.addEventListener('click', function() {
                const input = this.querySelector('input[type="radio"]');
                selectPaymentMethod(input.id);
            });
        });
        
        // Form validation
        document.getElementById('paymentForm')?.addEventListener('submit', function(e) {
            const paymentMethod = document.querySelector('input[name="payment_method"]:checked');
            
            if (!paymentMethod) {
                alert('Please select a payment method');
                e.preventDefault();
                return false;
            }
            
            return true;
        });
        
        // Simulate payment processing
        function simulateGPay() {
            alert('Redirecting to Google Pay...\n\nIn a real application, this would redirect to the Google Pay payment gateway.');
            document.getElementById('google_pay').checked = true;
        }
        
        function simulatePhonePe() {
            alert('Redirecting to PhonePe...\n\nIn a real application, this would redirect to the PhonePe payment gateway.');
            document.getElementById('phonepe').checked = true;
        }
        
        // Auto-select UPI option on page load for better UX
        document.addEventListener('DOMContentLoaded', function() {
            selectPaymentMethod('upi');
        });
    </script>
</body>
</html>
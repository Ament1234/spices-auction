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

// Calculate time remaining
$end_time = strtotime($auction['end_date']);
$current_time = time();
$time_left = $end_time - $current_time;
if ($time_left < 0) {
    $time_text = "Auction ended";
    $auction_ended = true;
} else {
    $hours = floor($time_left / 3600);
    $minutes = floor(($time_left % 3600) / 60);
    $time_text = "{$hours}h {$minutes}m left";
    $auction_ended = false;
}

// Fetch bids for this auction
$bids = [];
$bid_stmt = $conn->prepare("SELECT b.*, u.name AS bidder_name 
                           FROM bids b 
                           JOIN reg u ON b.user_id = u.id 
                           WHERE auction_id = ? 
                           ORDER BY amount DESC");
$bid_stmt->bind_param("i", $auction_id);
$bid_stmt->execute();
$bid_result = $bid_stmt->get_result();

while ($row = $bid_result->fetch_assoc()) {
    $bids[] = $row;
}

// Determine highest bid and winner
$highest_bid = null;
$is_winner = false;
$winning_amount = 0;

if (count($bids) > 0) {
    // Find the actual highest bid
    $highest_amount = 0;
    foreach ($bids as $bid) {
        if ($bid['amount'] > $highest_amount) {
            $highest_amount = $bid['amount'];
            $highest_bid = $bid;
        }
    }
    
    if ($auction_ended && $highest_bid['user_id'] == $_SESSION['user_id']) {
        $is_winner = true;
        $winning_amount = $highest_bid['amount'];
    }
}

// Handle bid placement
$error = '';
$success = '';
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['place_bid'])) {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Invalid request");
    }

    $bid_amount = filter_input(INPUT_POST, 'bid_amount', FILTER_VALIDATE_FLOAT);
    $user_id = $_SESSION['user_id'];

    if (!$bid_amount || $bid_amount <= 0) {
        $error = "Invalid bid amount";
    } else if ($bid_amount <= $auction['current_price']) {
        $error = "Your bid must be higher than the current price";
    } else if ($auction_ended) {
        $error = "This auction has already ended";
    } else if ($bid_amount > 1000000) {
        $error = "Bid amount is too high";
    } else {
        try {
            $conn->begin_transaction();

            // Update auction current price
            $update_stmt = $conn->prepare("UPDATE auctions SET current_price = ? WHERE id = ?");
            $update_stmt->bind_param("di", $bid_amount, $auction_id);
            $update_stmt->execute();
            
            // Record the bid
            $bid_insert_stmt = $conn->prepare("INSERT INTO bids (auction_id, user_id, amount) VALUES (?, ?, ?)");
            $bid_insert_stmt->bind_param("iid", $auction_id, $user_id, $bid_amount);
            $bid_insert_stmt->execute();
            
            $conn->commit();
            $success = "Bid placed successfully!";
            
            // Refresh auction data using the original statement
            $auction_stmt->execute();
            $result = $auction_stmt->get_result();
            $auction = $result->fetch_assoc();
            
            // Refresh bids with a new statement
            $new_bid_stmt = $conn->prepare("SELECT b.*, u.name AS bidder_name 
                                       FROM bids b 
                                       JOIN reg u ON b.user_id = u.id 
                                       WHERE auction_id = ? 
                                       ORDER BY amount DESC");
            $new_bid_stmt->bind_param("i", $auction_id);
            $new_bid_stmt->execute();
            $new_bid_result = $new_bid_stmt->get_result();
            
            $bids = [];
            while ($row = $new_bid_result->fetch_assoc()) {
                $bids[] = $row;
            }
            
            // Recalculate highest bid
            if (count($bids) > 0) {
                $highest_amount = 0;
                foreach ($bids as $bid) {
                    if ($bid['amount'] > $highest_amount) {
                        $highest_amount = $bid['amount'];
                        $highest_bid = $bid;
                    }
                }
            }
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error placing bid: " . $e->getMessage();
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
    <title>Bid on <?php echo htmlspecialchars($auction['spice_name']); ?> | SpiceBid</title>
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
        
        .spice-card {
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 25px;
            border: none;
        }
        
        .auction-image {
            width: 100%;
            height: 300px;
            object-fit: cover;
            background-color: var(--spice-light);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .auction-image i {
            font-size: 5rem;
            color: rgba(139, 69, 19, 0.3);
        }
        
        .price-tag {
            font-size: 2rem;
            font-weight: 700;
            color: var(--spice-primary);
        }
        
        .time-badge {
            background: var(--spice-secondary);
            padding: 8px 15px;
            border-radius: 20px;
            font-weight: 600;
        }
        
        .seller-badge {
            background: rgba(139, 69, 19, 0.1);
            padding: 8px 15px;
            border-radius: 20px;
            font-weight: 500;
        }
        
        .stat-card {
            text-align: center;
            padding: 15px;
            border-radius: 10px;
            background: rgba(139, 69, 19, 0.05);
        }
        
        .stat-number {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--spice-primary);
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: #666;
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
        
        .bid-row {
            padding: 15px 0;
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }
        
        .bid-row:last-child {
            border-bottom: none;
        }
        
        .bid-highlight {
            background: rgba(33, 150, 243, 0.1);
            border-radius: 8px;
            margin: 0 -10px;
            padding: 15px 10px;
        }
        
        .winner-notification {
            background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
            border-left: 4px solid #4CAF50;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .winner-badge {
            background: #4CAF50;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: 600;
            display: inline-block;
            margin-bottom: 10px;
        }
        
        .winner-badge i {
            margin-right: 5px;
        }
        
        .bid-history {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .bid-form-control {
            font-size: 1.25rem;
            font-weight: 700;
            padding: 12px 15px;
            height: 50px;
        }
        
        .min-bid-indicator {
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .spice-section-title {
            position: relative;
            padding-bottom: 10px;
            margin-bottom: 20px;
            color: var(--spice-dark);
        }
        
        .spice-section-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 60px;
            height: 3px;
            background: var(--spice-secondary);
            border-radius: 3px;
        }
        
        .bid-success {
            animation: pulseSuccess 2s ease;
        }
        
        @keyframes pulseSuccess {
            0% { background-color: rgba(40, 167, 69, 0.1); }
            50% { background-color: rgba(40, 167, 69, 0.3); }
            100% { background-color: transparent; }
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
                    <p class="mb-0">Bid on premium spices from around the world</p>
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
        
        <!-- Winner Notification Banner -->
        <?php if ($is_winner): ?>
        <div class="alert alert-success d-flex align-items-center mb-4">
            <div class="me-3">
                <i class="fas fa-trophy fa-2x"></i>
            </div>
            <div>
                <h4 class="alert-heading"><i class="fas fa-crown me-2"></i>Congratulations! You won this auction!</h4>
                <p class="mb-1">You have the highest bid of ₹<?php echo number_format($winning_amount, 2); ?></p>
                <a href="payment.php?auction_id=<?php echo $auction_id; ?>" class="btn btn-light mt-2">
                    <i class="fas fa-credit-card me-2"></i>Proceed to Payment
                </a>
            </div>
        </div>
        <?php endif; ?>

        <div class="row">
            <!-- Left Column - Auction Details -->
            <div class="col-lg-8">
                <div class="spice-card mb-4">
                    <div class="d-flex flex-column flex-md-row">
                        <!-- Auction Image -->
                        <div class="col-md-6 p-0">
                            <?php if ($auction['image_path']): ?>
                                <img src="<?php echo htmlspecialchars($auction['image_path']); ?>" 
                                     class="auction-image" 
                                     alt="<?php echo htmlspecialchars($auction['spice_name']); ?>">
                            <?php else: ?>
                                <div class="auction-image">
                                    <i class="fas fa-pepper-hot"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Auction Details -->
                        <div class="col-md-6 p-4">
                            <h1 class="auction-title"><?php echo htmlspecialchars($auction['spice_name']); ?></h1>
                            
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <div class="price-tag">₹<?php echo number_format($auction['current_price'], 2); ?></div>
                                <div class="time-badge">
                                    <i class="fas fa-clock me-1"></i><?php echo $time_text; ?>
                                </div>
                            </div>
                            
                            <p class="seller-badge d-inline-block mb-3">
                                <i class="fas fa-store me-1"></i>Seller: <?php echo htmlspecialchars($auction['seller_name']); ?>
                            </p>
                            
                            <div class="mb-4">
                                <h5 class="spice-section-title">Description</h5>
                                <p class="mb-0"><?php echo nl2br(htmlspecialchars($auction['description'])); ?></p>
                            </div>
                            
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <div class="stat-card">
                                        <div class="stat-number"><?php echo $auction['quantity']; ?> kg</div>
                                        <div class="stat-label">Quantity Available</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="stat-card">
                                        <div class="stat-number">₹<?php echo number_format($auction['starting_price'], 2); ?></div>
                                        <div class="stat-label">Starting Price</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Bid Form -->
                <?php if (!$auction_ended): ?>
                <div class="spice-card p-4 mb-4">
                    <h3 class="spice-section-title">Place Your Bid</h3>
                    
                    <form method="post" class="bid-form" id="bidForm">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        
                        <div class="row align-items-center mb-4">
                            <div class="col-md-6 mb-3 mb-md-0">
                                <label class="form-label fw-bold">Current Price</label>
                                <div class="display-4 text-primary">₹<?php echo number_format($auction['current_price'], 2); ?></div>
                            </div>
                            <div class="col-md-6">
                                <label for="bid_amount" class="form-label fw-bold">Your Bid Amount (₹)</label>
                                <div class="input-group">
                                    <span class="input-group-text">₹</span>
                                    <input type="number" class="form-control form-control-lg bid-form-control" 
                                           id="bid_amount" name="bid_amount" 
                                           step="0.01" min="<?php echo $auction['current_price'] + 0.01; ?>"
                                           value="<?php echo number_format($auction['current_price'] + 1, 2); ?>"
                                           required>
                                </div>
                                <div class="min-bid-indicator mt-2">
                                    Minimum bid: ₹<?php echo number_format($auction['current_price'] + 0.01, 2); ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" name="place_bid" class="btn btn-spice btn-lg bid-btn py-3">
                                <i class="fas fa-gavel me-2"></i>Place Bid Now
                            </button>
                        </div>
                    </form>
                </div>
                <?php else: ?>
                <div class="spice-card p-4 mb-4 text-center">
                    <h3 class="text-danger mb-3"><i class="fas fa-ban me-2"></i>Auction Has Ended</h3>
                    <p class="lead">This auction is no longer accepting bids.</p>
                    <a href="coustomer.php" class="btn btn-spice">
                        <i class="fas fa-arrow-left me-2"></i>Browse Other Auctions
                    </a>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Right Column - Bidding History & Auction Information -->
            <div class="col-lg-4">
                <!-- Bidding History -->
                <div class="spice-card p-4">
                    <h3 class="spice-section-title">Bidding History</h3>
                    
                    <?php if (count($bids) > 0): ?>
                        <div class="bid-history">
                            <?php foreach ($bids as $bid): 
                                $is_user_bid = ($bid['user_id'] == $_SESSION['user_id']);
                                $is_highest = ($bid['amount'] == $highest_amount);
                            ?>
                                <div class="bid-row <?php echo $is_user_bid ? 'bid-highlight' : ''; ?> <?php echo ($is_user_bid && !empty($success)) ? 'bid-success' : ''; ?>">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="d-flex align-items-center">
                                                <strong><?php echo htmlspecialchars($bid['bidder_name']); ?></strong>
                                                <?php if ($is_user_bid): ?>
                                                    <span class="badge bg-primary ms-2">Your Bid</span>
                                                <?php endif; ?>
                                                <?php if ($is_highest && $auction_ended): ?>
                                                    <span class="badge bg-success ms-2">Winner</span>
                                                <?php elseif ($is_highest): ?>
                                                    <span class="badge bg-warning ms-2">Highest</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="small text-muted">
                                                <?php echo date('M d, Y h:i A', strtotime($bid['bid_time'])); ?>
                                            </div>
                                        </div>
                                        <div class="fw-bold text-primary">
                                            ₹<?php echo number_format($bid['amount'], 2); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-comment-slash fa-3x text-muted mb-3"></i>
                            <p class="mb-0">No bids placed yet. Be the first to bid!</p>
                        </div>
                    <?php endif; ?>
                    
                    <div class="mt-4">
                        <div class="stat-card">
                            <div class="stat-number"><?php echo count($bids); ?></div>
                            <div class="stat-label">Total Bids Placed</div>
                        </div>
                    </div>
                </div>
                
                <!-- Auction Information -->
                <div class="spice-card p-4 mt-4">
                    <h3 class="spice-section-title">Auction Information</h3>
                    
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between">
                            <span>Auction ID:</span>
                            <span class="fw-bold">#<?php echo $auction['id']; ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span>Start Date:</span>
                            <span class="fw-bold"><?php echo date('M d, Y', strtotime($auction['created_at'])); ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span>End Date:</span>
                            <span class="fw-bold"><?php echo date('M d, Y h:i A', strtotime($auction['end_date'])); ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span>Status:</span>
                            <span class="fw-bold text-<?php echo $auction_ended ? 'danger' : 'success'; ?>">
                                <?php echo $auction_ended ? 'Ended' : 'Active'; ?>
                            </span>
                        </li>
                    </ul>
                    
                    <!-- Auction Winner Information -->
                    <?php if ($auction_ended && $highest_bid): ?>
                        <div class="winner-notification mt-4">
                            <span class="winner-badge"><i class="fas fa-trophy"></i> WINNER</span>
                            <h5 class="mb-2"><?php echo htmlspecialchars($highest_bid['bidder_name']); ?></h5>
                            <p class="mb-1">Winning Bid: <strong class="text-success">₹<?php echo number_format($highest_bid['amount'], 2); ?></strong></p>
                            <p class="mb-0">Bid placed on: <?php echo date('M d, Y h:i A', strtotime($highest_bid['bid_time'])); ?></p>
                        </div>
                    <?php elseif ($auction_ended): ?>
                        <div class="alert alert-warning mt-4">
                            <h5 class="mb-2"><i class="fas fa-info-circle me-2"></i>No Winning Bids</h5>
                            <p class="mb-0">This auction ended without any bids being placed.</p>
                        </div>
                    <?php endif; ?>
                    
                    <div class="mt-4">
                        <h5>Shipping Information</h5>
                        <p class="mb-1"><i class="fas fa-shipping-fast me-2 text-primary"></i>Free shipping for orders over ₹500</p>
                        <p class="mb-1"><i class="fas fa-undo me-2 text-primary"></i>30-day return policy</p>
                        <p class="mb-0"><i class="fas fa-shield-alt me-2 text-primary"></i>Secure payment processing</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <!-- <footer class="bg-dark text-white py-5 mt-5">
        <div class="container">
            <div class="row">
                <div class="col-md-4 mb-4">
                    <h5><i class="fas fa-pepper-hot"></i> SpiceBid</h5>
                    <p class="text-muted">Discover the world's finest spices through our exclusive auctions.</p>
                    <div class="d-flex mt-3">
                        <a href="#" class="text-white me-3"><i class="fab fa-facebook fa-lg"></i></a>
                        <a href="#" class="text-white me-3"><i class="fab fa-twitter fa-lg"></i></a>
                        <a href="#" class="text-white me-3"><i class="fab fa-instagram fa-lg"></i></a>
                        <a href="#" class="text-white"><i class="fab fa-pinterest fa-lg"></i></a>
                    </div>
                </div>
                <div class="col-md-2 mb-4">
                    <h5>Marketplace</h5>
                    <ul class="list-unstyled">
                        <li><a href="#" class="text-white text-decoration-none">All Spices</a></li>
                        <li><a href="#" class="text-white text-decoration-none">Premium Spices</a></li>
                        <li><a href="#" class="text-white text-decoration-none">Spice Blends</a></li>
                        <li><a href="#" class="text-white text-decoration-none">New Arrivals</a></li>
                    </ul>
                </div>
                <div class="col-md-2 mb-4">
                    <h5>Information</h5>
                    <ul class="list-unstyled">
                        <li><a href="#" class="text-white text-decoration-none">How It Works</a></li>
                        <li><a href="#" class="text-white text-decoration-none">Spice Guide</a></li>
                        <li><a href="#" class="text-white text-decoration-none">Shipping Info</a></li>
                        <li><a href="#" class="text-white text-decoration-none">FAQ</a></li>
                    </ul>
                </div>
                <div class="col-md-4 mb-4">
                    <h5>Newsletter</h5>
                    <p class="text-muted">Subscribe to receive updates on new auctions and special offers.</p>
                    <form>
                        <div class="input-group">
                            <input type="email" class="form-control" placeholder="Your email address">
                            <button class="btn btn-primary" type="submit">Subscribe</button>
                        </div>
                    </form>
                </div>
            </div>
            <hr class="bg-secondary">
            <div class="text-center text-muted">
                <p class="mb-0">&copy; 2025 SpiceBid Marketplace. All rights reserved.</p>
            </div>
        </div>
    </footer> -->

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-update countdown timer:
function updateCountdown() {
    const timerElement = document.querySelector('.time-badge');
    if (!timerElement) return;
    
    const endTime = new Date("<?php echo $auction['end_date']; ?>").getTime();
    const now = new Date().getTime();
    const timeLeft = endTime - now;
    
    if (timeLeft < 0) {
        timerElement.innerHTML = '<i class="fas fa-clock me-1"></i>Auction ended';
        timerElement.classList.remove('bg-spice-secondary');
        timerElement.classList.add('bg-danger');
        
        const bidForm = document.querySelector('.bid-form');
        if (bidForm) {
            bidForm.innerHTML = `
                <div class="alert alert-danger text-center">
                    <i class="fas fa-ban me-2"></i> Auction has ended. Bidding is closed.
                </div>
            `;
        }
    } else {
        const hours = Math.floor(timeLeft / (1000 * 60 * 60));
        const minutes = Math.floor((timeLeft % (1000 * 60 * 60)) / (1000 * 60));
        const seconds = Math.floor((timeLeft % (60000)) / 1000);
        timerElement.innerHTML = `<i class="fas fa-clock me-1"></i>${hours.toString().padStart(2, '0')}h ${minutes.toString().padStart(2, '0')}m ${seconds.toString().padStart(2, '0')}s left`;
    }
}

// Update every second instead of every minute
setInterval(updateCountdown, 1000);
                
                // Decrement minutes
                minutes -= 1;
                if (minutes < 0) {
                    minutes = 59;
                    hours -= 1;
                }
                
                if (hours >= 0) {
                    timeBadge.innerHTML = `<i class="fas fa-clock me-1"></i>${hours}h ${minutes}m left`;
                } else {
                    timeBadge.innerHTML = '<i class="fas fa-clock me-1"></i>Auction ended';
                    timeBadge.classList.remove('bg-spice-secondary');
                    timeBadge.classList.add('bg-danger');
                    
                    // Disable bid form
                    const bidForm = document.querySelector('.bid-form');
                    if (bidForm) {
                        bidForm.innerHTML = `
                            <div class="alert alert-danger text-center">
                                <i class="fas fa-ban me-2"></i> Auction has ended. Bidding is closed.
                            </div>
                        `;
                    }
                }
        
        
        // Update every minute
        setInterval(updateCountdown, 60000);
        
        // Highlight user's bids
        document.addEventListener('DOMContentLoaded', function() {
            const userBids = document.querySelectorAll('.bid-highlight');
            userBids.forEach(bid => {
                bid.scrollIntoView({behavior: 'smooth', block: 'nearest'});
            });
            
            // Auto-focus on bid amount field
            document.getElementById('bid_amount')?.focus();
            
            // Highlight new bids after successful placement
            <?php if (!empty($success)): ?>
                const newBids = document.querySelectorAll('.bid-success');
                newBids.forEach(bid => {
                    setTimeout(() => {
                        bid.classList.remove('bid-success');
                    }, 2000);
                });
            <?php endif; ?>
        });
        
        // Form validation
        document.getElementById('bidForm')?.addEventListener('submit', function(e) {
            const bidInput = document.getElementById('bid_amount');
            const minBid = parseFloat(bidInput.min);
            const bidValue = parseFloat(bidInput.value);
            
            if (bidValue < minBid) {
                alert(`Your bid must be at least ₹${minBid.toFixed(2)}`);
                e.preventDefault();
                return false;
            }
            
            if (bidValue > 1000000) {
                alert('Maximum bid amount is ₹1,000,000');
                e.preventDefault();
                return false;
            }
            
            return true;
        });
    </script>
</body>
</html>
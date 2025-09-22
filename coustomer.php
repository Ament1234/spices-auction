<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
include 'db.php';

// Check if user is logged in
if (!isset($_SESSION['email'])) {
    header("Location: login.php");
    exit();
}

// Check database connection
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

// Initialize variables
$error = '';
$success = '';
$auctions = [];
$categories = ['All', 'Premium', 'Spice Blends', 'Whole Spices', 'Ground Spices', 'Rare Finds']; // DEFINED CATEGORIES
$selected_category = $_GET['category'] ?? 'All';
$search_query = $_GET['search'] ?? '';

// Fetch auctions from database
$sql = "SELECT a.*, u.name AS seller_name 
        FROM auctions a 
        JOIN reg u ON a.seller_id = u.id 
        WHERE a.status = 'active'";

if ($selected_category !== 'All') {
    $sql .= " AND a.category = '$selected_category'";
}

if (!empty($search_query)) {
    $search_query = $conn->real_escape_string($search_query);
    $sql .= " AND (a.spice_name LIKE '%$search_query%' OR a.description LIKE '%$search_query%')";
}

$sql .= " ORDER BY a.end_date ASC";

$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $auctions[] = $row;
    }
} else {
    $error = "Error loading auctions: " . $conn->error;
}

// Handle bid placement
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['place_bid'])) {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Invalid request");
    }

    $auction_id = filter_input(INPUT_POST, 'auction_id', FILTER_VALIDATE_INT);
    $bid_amount = filter_input(INPUT_POST, 'bid_amount', FILTER_VALIDATE_FLOAT);
    $user_id = $_SESSION['user_id'];

    if (!$auction_id || $auction_id <= 0) {
        $error = "Invalid auction";
    } else if (!$bid_amount || $bid_amount <= 0) {
        $error = "Invalid bid amount";
    } else {
        // Get auction details
        $stmt = $conn->prepare("SELECT current_price FROM auctions WHERE id = ?");
        $stmt->bind_param("i", $auction_id);
        $stmt->execute();
        $stmt->bind_result($current_price);
        $stmt->fetch();
        $stmt->close();

        if ($bid_amount <= $current_price) {
            $error = "Your bid must be higher than the current price";
        } else {
            $conn->begin_transaction();
            try {
                // Update auction current price
                $update_stmt = $conn->prepare("UPDATE auctions SET current_price = ? WHERE id = ?");
                $update_stmt->bind_param("di", $bid_amount, $auction_id);
                $update_stmt->execute();
                
                // Record the bid
                $bid_stmt = $conn->prepare("INSERT INTO bids (auction_id, user_id, amount) VALUES (?, ?, ?)");
                $bid_stmt->bind_param("iid", $auction_id, $user_id, $bid_amount);
                $bid_stmt->execute();
                
                $conn->commit();
                $success = "Bid placed successfully!";
                // Refresh page to show updated info
                header("Location: customer.php");
                exit();
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Error placing bid: " . $e->getMessage();
            }
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
    <title>SpiceBid - Marketplace</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
  <link href="css/coustomer.css" rel="stylesheet">

</head>
<body>
    <!-- Marketplace Header -->
    <header class="marketplace-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1><i class="fas fa-pepper-hot"></i> SpiceBid Marketplace</h1>
                    <p class="mb-0">Discover and bid on the world's finest spices</p>
                </div>
                <div class="text-end">
                    <p class="mb-0">Welcome, <?php echo htmlspecialchars($_SESSION['name'] ?? 'Customer', ENT_QUOTES, 'UTF-8'); ?></p>
                    <div class="d-flex justify-content-end mt-2">
                        <a href="home.html" class="btn btn-light btn-sm me-2">
                            <i class="fas fa-home"></i> Home
                        </a>
                        <a href="logout.php" class="btn btn-light btn-sm">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Stats Overview -->
    <div class="container mt-5">
        <!-- <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card text-center">
                    <div class="number"><?php echo count($auctions); ?></div>
                    <div class="label">Active Auctions</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card text-center">
                    <div class="number"><?php echo count($categories) - 1; ?></div>
                    <div class="label">Spice Categories</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card text-center">
                    <div class="number">127</div>
                    <div class="label">Active Bidders</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card text-center">
                    <div class="number">24</div>
                    <div class="label">Premium Sellers</div>
                </div>
            </div>
        </div> -->
    </div>

    <!-- Main Content -->
    <div class="container">
        <div class="row">
            <!-- Left Column - Filters -->
            <!-- <div class="col-md-3">
                <div class="filter-card">
                    <h4 class="spice-section-title"><i class="fas fa-filter spice-icon"></i> Filters</h4>
                    
                    <div class="mb-4">
                        <h5>Search Spices</h5>
                        <form method="GET" action="customer.php">
                            <div class="input-group">
                                <input type="text" class="form-control" placeholder="Saffron, vanilla, cardamom..." name="search" value="<?php echo htmlspecialchars($search_query); ?>">
                                <button class="btn btn-primary" type="submit">
                                    <i class="fas fa-search"></i>
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <div class="mb-4">
                        <h5>Categories</h5>
                        <div class="d-flex flex-wrap">
                            <?php foreach ($categories as $category): ?>
                                <a href="customer.php?category=<?php echo urlencode($category); ?>&search=<?php echo urlencode($search_query); ?>" 
                                   class="filter-btn <?php echo ($selected_category === $category) ? 'active' : ''; ?>">
                                    <?php echo $category; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div>
                        <h5>Price Range</h5>
                        <div class="range-slider mb-3">
                            <input type="range" class="form-range" min="0" max="500" step="10" id="priceRange">
                        </div>
                        <div class="d-flex justify-content-between">
                            <span>$0</span>
                            <span>$500</span>
                        </div>
                    </div>
                </div> 
                
            </div> -->
            
            <!-- Center Column - Auctions -->
            <div class="col-md-9">
                <!-- Messages -->
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
                
                <?php if (!empty($success)): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>

                <!-- Auction Grid -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h3 class="spice-section-title"><i class="fas fa-gavel spice-icon"></i> Spice Auctions</h3>
                    <!-- <div>
                        <span class="me-2">Sort by:</span>
                        <select class="form-select form-select-sm d-inline-block w-auto">
                            <option>Ending Soonest</option>
                            <option>Newest First</option>
                            <option>Price: Low to High</option>
                            <option>Price: High to Low</option>
                        </select>
                    </div> -->
                </div>

                <?php if (empty($auctions)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-gavel fa-4x text-muted mb-3"></i>
                        <h3>No Active Auctions Found</h3>
                        <p class="text-muted">Check back later for new spice auctions.</p>
                        <a href="customer.php" class="btn btn-primary mt-2">
                            <i class="fas fa-sync me-2"></i>Reset Filters
                        </a>
                    </div>
                <?php else: ?>
                    <div class="row row-cols-3 g-3">
                        <?php foreach ($auctions as $auction): 
                            $end_time = strtotime($auction['end_date']);
                            $current_time = time();
                            $time_left = $end_time - $current_time;
                            
                            // Calculate time remaining
                            if ($time_left < 0) {
                                $time_text = "Auction ended";
                            } else {
                                $hours = floor($time_left / 3600);
                                $minutes = floor(($time_left % 3600) / 60);
                                $time_text = "{$hours}h {$minutes}m left";
                            }
                            
                            // Calculate progress percentage (for demonstration)
                            $progress = min(100, max(10, rand(30, 90)));
                        ?>
                        <div class="col">
                            <div class="spice-card">
                                <!-- <?php if ($auction['featured']): ?>
                                    <div class="featured-badge">FEATURED</div>
                                <?php endif; ?> -->
                                
                                <div class="spice-image-container">
                                    <?php if ($auction['image_path']): ?>
                                        <img src="<?php echo htmlspecialchars($auction['image_path']); ?>" class="spice-image" alt="<?php echo htmlspecialchars($auction['spice_name']); ?>">
                                    <?php else: ?>
                                        <div class="d-flex align-items-center justify-content-center h-100">
                                            <i class="fas fa-pepper-hot fa-3x text-muted"></i>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <span class="category-badge badge"><?php echo htmlspecialchars($auction['category'] ?? 'Spice'); ?></span>
                                    <span class="time-remaining"><?php echo $time_text; ?></span>
                                </div>
                                
                                <div class="p-3">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h5 class="mb-0"><?php echo htmlspecialchars($auction['spice_name']); ?></h5>
                                        <div class="price-tag">₹<?php echo number_format($auction['current_price'], 2); ?></div>
                                    </div>
                                    
                                    <p class="text-muted small mb-2">
                                        <span class="seller-badge">Seller: <?php echo htmlspecialchars($auction['seller_name']); ?></span>
                                    </p>
                                    
                                    <p class="mb-3"><?php echo substr(htmlspecialchars($auction['description']), 0, 120); ?>...</p>
                                    
                                    <div class="auction-progress">
                                        <div class="progress-bar" style="width: <?php echo $progress; ?>%"></div>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between small text-muted mb-3">
                                        <span>Starting: ₹<?php echo number_format($auction['starting_price'], 2); ?></span>
                                        <span><?php echo $progress; ?>% of target</span>
                                    </div>
                                    
                                  <a href="bid.php?auction_id=<?php echo $auction['id']; ?>" class="btn bid-btn">
                                  <i class="fas fa-gavel me-2"></i>Place Bid
</a>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            

    <!-- Bid Modal -->
    <div class="modal fade" id="bidModal" tabindex="-1" aria-labelledby="bidModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="bidModalLabel">Place Your Bid</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="post" action="customer.php">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" id="auction_id" name="auction_id" value="">
                        
                        <div class="mb-3 text-center">
                            <h4 id="spiceName"></h4>
                            <p>Current Price: <span class="price-tag" id="currentPrice"></span></p>
                        </div>
                        
                        <div class="mb-3">
                            <label for="bid_amount" class="form-label">Your Bid Amount ($)</label>
                            <input type="number" class="form-control" id="bid_amount" name="bid_amount" required step="0.01" min="0">
                            <div class="form-text">Enter an amount higher than the current price</div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Recent Bids</label>
                            <div class="border rounded p-2">
                                <div class="d-flex justify-content-between mb-2">
                                    <div>
                                        <span class="fw-bold">JohnDoe</span>
                                        <span class="text-muted ms-2">2 minutes ago</span>
                                    </div>
                                    <span class="fw-bold">$24.50</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <div>
                                        <span class="fw-bold">SpiceLover</span>
                                        <span class="text-muted ms-2">15 minutes ago</span>
                                    </div>
                                    <span class="fw-bold">$23.00</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <span class="fw-bold">CookingFan</span>
                                        <span class="text-muted ms-2">1 hour ago</span>
                                    </div>
                                    <span class="fw-bold">$22.50</span>
                                </div>
                            </div>
                        </div>
                        
                        <button type="submit" name="place_bid" class="btn btn-primary w-100 py-2">
                            <i class="fas fa-gavel me-2"></i>Place Bid
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

  <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Initialize bid modal
        // ... bid modal code remains unchanged ...

        // Function to update all timers
        function updateAllTimers() {
            document.querySelectorAll('.time-remaining').forEach(el => {
                const endTime = new Date(el.getAttribute('data-end-time')).getTime();
                const now = new Date().getTime();
                const timeLeft = endTime - now;
                
                if (timeLeft < 0) {
                    el.textContent = "Auction ended";
                } else {
                    const hours = Math.floor(timeLeft / (1000 * 60 * 60));
                    const minutes = Math.floor((timeLeft % (1000 * 60 * 60)) / (1000 * 60));
                    const seconds = Math.floor((timeLeft % (1000 * 60)) / 1000);
                    
                    // Format with leading zeros
                    const formattedHours = hours.toString().padStart(2, '0');
                    const formattedMinutes = minutes.toString().padStart(2, '0');
                    const formattedSeconds = seconds.toString().padStart(2, '0');
                    
                    el.textContent = `${formattedHours}h ${formattedMinutes}m ${formattedSeconds}s left`;
                }
            });
        }
        
        // Update time counters every minute
        setInterval(() => {
            document.querySelectorAll('.time-remaining').forEach(el => {
                const timeText = el.textContent;
                if (timeText.includes('h') && timeText.includes('m')) {
                    const parts = timeText.split(' ');
                    let hours = parseInt(parts[0]);
                    let minutes = parseInt(parts[1]);
                    
                    // Decrement minutes
                    minutes -= 1;
                    if (minutes < 0) {
                        minutes = 59;
                        hours -= 1;
                    }
                    
                    if (hours >= 0) {
                        el.textContent = `${hours}h ${minutes}m left`;
                    } else {
                        el.textContent = "Auction ended";
                    }
                }
            });
        }, 60000);

        // Initialize price range slider
        const priceRange = document.getElementById('priceRange');
        if (priceRange) {
            priceRange.addEventListener('input', function() {
                // Filter functionality would go here
                console.log('Selected price range:', this.value);
            });
        }
    </script>
</body>
</html>
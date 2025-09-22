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
$auction = null;
$bids = [];
$seller = null;

// Get auction ID from URL
$auction_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

// Fetch auction data
if ($auction_id) {
    try {
        // Get auction details - FIXED: Changed 'image' to 'image_path'
        $stmt = $conn->prepare("SELECT a.*, u.name AS seller_name, u.email AS seller_email 
                               FROM auctions a 
                               JOIN reg u ON a.seller_id = u.id 
                               WHERE a.id = ?");
        $stmt->bind_param("i", $auction_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $auction = $result->fetch_assoc();
        $stmt->close();
        
        if (!$auction) {
            $error = "Auction not found";
        } else {
            // Get bids for this auction
            $stmt = $conn->prepare("SELECT b.*, u.name AS bidder_name 
                                   FROM bids b 
                                   JOIN reg u ON b.user_id = u.id 
                                   WHERE b.auction_id = ? 
                                   ORDER BY b.amount DESC");
            $stmt->bind_param("i", $auction_id);
            $stmt->execute();
            $bids_result = $stmt->get_result();
            $bids = $bids_result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    } catch (Exception $e) {
        $error = "Error fetching auction data: " . $e->getMessage();
    }
} else {
    $error = "Invalid auction ID";
}

// Handle auction deletion
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['delete_auction'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Invalid CSRF token");
    }

    $auction_id = filter_input(INPUT_POST, 'auction_id', FILTER_VALIDATE_INT);
    
    if (!$auction_id) {
        $error = "Invalid auction ID";
    } else {
        $conn->begin_transaction();
        try {
            $stmt1 = $conn->prepare("DELETE FROM bids WHERE auction_id = ?");
            $stmt1->bind_param("i", $auction_id);
            $stmt1->execute();
            
            $stmt2 = $conn->prepare("DELETE FROM auctions WHERE id = ?");
            $stmt2->bind_param("i", $auction_id);
            $stmt2->execute();
            
            $conn->commit();
            $success = "Auction deleted successfully!";
            // Redirect after deletion
            header("Location: admin.php?section=auctions");
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error deleting auction: " . $e->getMessage();
        } finally {
            if (isset($stmt1)) $stmt1->close();
            if (isset($stmt2)) $stmt2->close();
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
    <title>Auction Details - SpiceBid Admin</title>
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
        .auction-image {
            max-height: 300px;
            width: 100%;
            object-fit: cover;
            border-radius: 5px;
        }
        .bid-history {
            max-height: 400px;
            overflow-y: auto;
        }
        .status-badge {
            font-size: 1rem;
            padding: 8px 15px;
        }
        .bid-row:hover {
            background-color: #f8f9fa;
        }
        /* Added better image container styling */
        .image-container {
            height: 300px;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #f8f9fa;
            border-radius: 5px;
            overflow: hidden;
        }
        .placeholder-icon {
            font-size: 4rem;
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
                        <a href="admin.php?section=auctions" class="btn btn-light btn-sm me-2">
                            <i class="fas fa-arrow-left"></i> Back to Auctions
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
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        
        <?php if ($auction): ?>
        <div class="row">
            <!-- Auction Details -->
            <div class="col-md-8">
                <div class="admin-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2><?= htmlspecialchars($auction['spice_name']) ?></h2>
                        <span class="badge bg-<?= strtotime($auction['end_date']) < time() ? 'secondary' : 'success' ?> status-badge">
                            <?= strtotime($auction['end_date']) < time() ? 'Ended' : 'Active' ?>
                        </span>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <!-- FIXED: Changed 'image' to 'image_path' and improved image display -->
                            <?php if (!empty($auction['image_path'])): ?>
                                <div class="image-container mb-3">
                                    <img src="<?= htmlspecialchars($auction['image_path']) ?>" 
                                         alt="<?= htmlspecialchars($auction['spice_name']) ?>" 
                                         class="auction-image">
                                </div>
                            <?php else: ?>
                                <div class="image-container mb-3">
                                    <i class="fas fa-image placeholder-icon"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <h4>Description</h4>
                                <p><?= nl2br(htmlspecialchars($auction['description'])) ?></p>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <h5>Starting Price</h5>
                                        <p class="fs-4">₹<?= number_format($auction['starting_price'], 2) ?></p>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <h5>Current Price</h5>
                                        <p class="fs-4 text-success">₹<?= number_format($auction['current_price'], 2) ?></p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    
                               </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <h5>End Date</h5>
                                        <p><?= date('M d, Y', strtotime($auction['end_date'])) ?></p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <h5>Quantity Available</h5>
                                <p><?= number_format($auction['quantity']) ?> kg</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Delete Auction Form -->
                    <form method="post" class="mt-4">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="auction_id" value="<?= $auction['id'] ?>">
                        <button type="submit" name="delete_auction" class="btn btn-danger"
                                onclick="return confirm('Are you sure you want to delete this auction? This cannot be undone.')">
                            <i class="fas fa-trash"></i> Delete Auction
                        </button>
                    </form>
                </div>
                
                <!-- Seller Information -->
                <div class="admin-card">
                    <h3><i class="fas fa-user"></i> Seller Information</h3>
                    <div class="row mt-3">
                        <div class="col-md-2">
                            <div class="bg-light rounded-circle d-flex align-items-center justify-content-center" 
                                 style="width: 80px; height: 80px;">
                                <i class="fas fa-user fa-2x text-muted"></i>
                            </div>
                        </div>
                        <div class="col-md-10">
                            <h4><?= htmlspecialchars($auction['seller_name']) ?></h4>
                            <p class="mb-1"><i class="fas fa-envelope"></i> <?= htmlspecialchars($auction['seller_email']) ?></p>
                            <!-- <p><i class="fas fa-calendar"></i> Member since <?= date('M Y', strtotime($auction['reg_date'])) ?></p> -->
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Bids Section -->
            <div class="col-md-4">
                <div class="admin-card">
                    <h3><i class="fas fa-gavel"></i> Bid History</h3>
                    <p class="text-muted">Total Bids: <?= count($bids) ?></p>
                    
                    <?php if (count($bids) > 0): ?>
                    <div class="bid-history">
                        <div class="list-group">
                            <?php foreach ($bids as $bid): ?>
                            <div class="list-group-item bid-row">
                                <div class="d-flex justify-content-between">
                                    <h5 class="mb-1"><?= htmlspecialchars($bid['bidder_name']) ?></h5>
                                    <span class="text-success">₹<?= number_format($bid['amount'], 2) ?></span>
                                </div>
                                <small class="text-muted">
                                    <?= date('M d, Y h:i A', strtotime($bid['bid_time'])) ?>
                                </small>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> No bids placed on this auction yet.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
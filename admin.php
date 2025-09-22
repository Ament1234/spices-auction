<?php
session_start();
include 'db.php';

// Enable error reporting for debugging
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

// Check database connection more thoroughly
if (!$conn || $conn->connect_error) {
    die("Database connection failed: " . ($conn ? $conn->connect_error : "No connection object"));
}

// Initialize variables
$error = '';
$success = '';
$current_section = $_GET['section'] ?? 'dashboard';

// Handle user deletion
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['delete_user'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Invalid CSRF token");
    }

    $user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    
    if (!$user_id) {
        $error = "Invalid user ID";
    } else {
        $conn->begin_transaction();
        try {
            // Use prepared statements for all queries
            $stmt1 = $conn->prepare("DELETE FROM bids WHERE user_id = ?");
            $stmt1->bind_param("i", $user_id);
            $stmt1->execute();
            
            $stmt2 = $conn->prepare("DELETE FROM auctions WHERE seller_id = ?");
            $stmt2->bind_param("i", $user_id);
            $stmt2->execute();
            
            $stmt3 = $conn->prepare("DELETE FROM reg WHERE id = ?");
            $stmt3->bind_param("i", $user_id);
            $stmt3->execute();
            
            $conn->commit();
            $success = "User deleted successfully!";
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error deleting user: " . $e->getMessage();
        } finally {
            if (isset($stmt1)) $stmt1->close();
            if (isset($stmt2)) $stmt2->close();
            if (isset($stmt3)) $stmt3->close();
        }
    }
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
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error deleting auction: " . $e->getMessage();
        } finally {
            if (isset($stmt1)) $stmt1->close();
            if (isset($stmt2)) $stmt2->close();
        }
    }
}
// Handle bid deletion
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['delete_bid'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Invalid CSRF token");
    }

    $bid_id = filter_input(INPUT_POST, 'bid_id', FILTER_VALIDATE_INT);
    
    if (!$bid_id) {
        $error = "Invalid bid ID";
    } else {
        try {
            $stmt = $conn->prepare("DELETE FROM bids WHERE id = ?");
            $stmt->bind_param("i", $bid_id);
            $stmt->execute();
            
            if ($stmt->affected_rows > 0) {
                $success = "Bid deleted successfully!";
            } else {
                $error = "Bid not found or already deleted";
            }
            $stmt->close();
        } catch (Exception $e) {
            $error = "Error deleting bid: " . $e->getMessage();
        }
    }
}
// Fetch data using prepared statements
$users = [];
$auctions = [];
$bids = [];

if ($current_section === 'users' || $current_section === 'dashboard') {
    $result = $conn->query("SELECT * FROM reg");
    if ($result) {
        $users = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    } else {
        $error = "Error fetching users: " . $conn->error;
    }
}

if ($current_section === 'auctions' || $current_section === 'dashboard') {
    $result = $conn->query("SELECT a.*, u.name AS seller_name FROM auctions a JOIN reg u ON a.seller_id = u.id");
    if ($result) {
        $auctions = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    } else {
        $error = "Error fetching auctions: " . $conn->error;
    }
}

if ($current_section === 'bids' || $current_section === 'dashboard') {
    $result = $conn->query("SELECT b.*, u.name AS user_name, a.spice_name 
                           FROM bids b 
                           JOIN reg u ON b.user_id = u.id 
                           JOIN auctions a ON b.auction_id = a.id 
                           ORDER BY b.bid_time DESC");
    if ($result) {
        $bids = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    } else {
        $error = "Error fetching bids: " . $conn->error;
    }
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Close connection at the end (optional)
// $conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SpiceBid - Admin Dashboard</title>
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
        
        .sidebar {
            background: white;
            border-radius: 5px;
            padding: 15px;
            position: sticky;
            top: 80px;
        }
        
        .sidebar-item {
            display: block;
            padding: 10px;
            margin: 5px 0;
            color: #333;
            text-decoration: none;
            border-radius: 5px;
        }
        
        .sidebar-item:hover, .sidebar-item.active {
            background: rgba(139, 69, 19, 0.1);
            color: #8B4513;
        }
        
        .admin-card {
            background: white;
            border-radius: 5px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .stats-card {
            text-align: center;
            padding: 15px;
            border-radius: 5px;
            background: white;
            margin-bottom: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .stats-card .number {
            font-size: 1.5rem;
            font-weight: bold;
            color: #8B4513;
        }
        
        .admin-table th {
            background-color: #f8f9fa;
        }
        
        .badge-admin { background-color: #dc3545; }
        .badge-seller { background-color: #ffc107; color: #000; }
        .badge-user { background-color: #0d6efd; }
        
    
        .form-control:disabled {
        background-color: #e9ecef;
        opacity: 1;
        cursor: not-allowed;
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

    <div class="container mt-4">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3">
                <div class="sidebar">
                    <a href="?section=dashboard" class="sidebar-item <?= $current_section === 'dashboard' ? 'active' : '' ?>">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                    <a href="?section=users" class="sidebar-item <?= $current_section === 'users' ? 'active' : '' ?>">
                        <i class="fas fa-users"></i> Users
                    </a>
                    <a href="?section=auctions" class="sidebar-item <?= $current_section === 'auctions' ? 'active' : '' ?>">
                        <i class="fas fa-gavel"></i> Auctions
                    </a>
                    <a href="?section=bids" class="sidebar-item <?= $current_section === 'bids' ? 'active' : '' ?>">
                        <i class="fas fa-money-bill-wave"></i> Bids
                    </a>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-9">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                <?php endif; ?>
                
                <!-- Dashboard Section -->
                <?php if ($current_section === 'dashboard'): ?>
                    <div class="admin-card">
                        <h3><i class="fas fa-tachometer-alt"></i> Dashboard</h3>
                        
                        <div class="row mt-4">
                            <div class="col-md-3">
                                <div class="stats-card">
                                    <i class="fas fa-users fa-2x"></i>
                                    <div class="number"><?= count($users) ?></div>
                                    <div>Total Users</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stats-card">
                                    <i class="fas fa-gavel fa-2x"></i>
                                    <div class="number"><?= count($auctions) ?></div>
                                    <div>Active Auctions</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stats-card">
                                    <i class="fas fa-money-bill-wave fa-2x"></i>
                                    <div class="number"><?= count($bids) ?></div>
                                    <div>Total Bids</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stats-card">
                                    <i class="fas fa-store fa-2x"></i>
                                    <div class="number"><?= count(array_filter($users, fn($u) => $u['role'] === 'seller')) ?></div>
                                    <div>Sellers</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="admin-card">
                                <h4><i class="fas fa-gavel"></i> Recent Auctions</h4>
                                <div class="table-responsive">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Spice</th>
                                                <th>Price</th>
                                                <th>Seller</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach (array_slice($auctions, 0, 5) as $auction): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($auction['spice_name']) ?></td>
                                                <td>₹<?= number_format($auction['current_price'], 2) ?></td>
                                                <td><?= htmlspecialchars($auction['seller_name']) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="admin-card">
                                <h4><i class="fas fa-users"></i> Recent Users</h4>
                                <div class="table-responsive">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>Email</th>
                                                <th>Role</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach (array_slice($users, 0, 5) as $user): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($user['name']) ?></td>
                                                <td><?= htmlspecialchars($user['email']) ?></td>
                                                <td>
                                                    <span class="badge rounded-pill badge-<?= 
                                                        $user['role'] === 'admin' ? 'admin' : 
                                                        ($user['role'] === 'seller' ? 'seller' : 'user') 
                                                    ?>">
                                                        <?= ucfirst($user['role']) ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Users Section -->
                <?php if ($current_section === 'users'): ?>
                    <div class="admin-card">
                        <h3><i class="fas fa-users"></i> Manage Users</h3>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Role</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><?= $user['id'] ?></td>
                                        <td><?= htmlspecialchars($user['name']) ?></td>
                                        <td><?= htmlspecialchars($user['email']) ?></td>
                                        <td><?= htmlspecialchars($user['phone']) ?></td>
                                        <td>
                                            <span class="badge rounded-pill badge-<?= 
                                                $user['role'] === 'admin' ? 'admin' : 
                                                ($user['role'] === 'seller' ? 'seller' : 'user') 
                                            ?>">
                                                <?= ucfirst($user['role']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="adminedit.php?id=<?= $user['id'] ?>" class="btn btn-sm btn-warning">
                                                 <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" style="display:inline">
                                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                <button type="submit" name="delete_user" class="btn btn-sm btn-danger" 
                                                        onclick="return confirm('Delete this user?')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Auctions Section -->
                <?php if ($current_section === 'auctions'): ?>
                    <div class="admin-card">
                        <h3><i class="fas fa-gavel"></i> Manage Auctions</h3>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Spice</th>
                                        <th>Seller</th>
                                        <th>Price</th>
                                        <th>End Date</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($auctions as $auction): 
                                        $ended = strtotime($auction['end_date']) < time();
                                    ?>
                                    <tr>
                                        <td><?= $auction['id'] ?></td>
                                        <td><?= htmlspecialchars($auction['spice_name']) ?></td>
                                        <td><?= htmlspecialchars($auction['seller_name']) ?></td>
                                        <td>₹<?= number_format($auction['current_price'], 2) ?></td>
                                        <td><?= date('M d, Y', strtotime($auction['end_date'])) ?></td>
                                        <td>
                                            <span class="badge bg-<?= $ended ? 'secondary' : 'success' ?>">
                                                <?= $ended ? 'Ended' : 'Active' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="adminshow.php?id=<?= $auction['id'] ?>" class="btn btn-sm btn-primary">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <form method="post" style="display:inline">
                                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                <input type="hidden" name="auction_id" value="<?= $auction['id'] ?>">
                                                <button type="submit" name="delete_auction" class="btn btn-sm btn-danger" 
                                                        onclick="return confirm('Delete this auction?')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
                
     <!-- Bids Section -->
<?php if ($current_section === 'bids'): ?>
    <div class="admin-card">
        <h3><i class="fas fa-money-bill-wave"></i> Manage Bids</h3>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>User</th>
                        <th>Spice</th>
                        <th>Amount</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bids as $bid): ?>
                    <tr>
                        <td><?= $bid['id'] ?></td>
                        <td><?= htmlspecialchars($bid['user_name']) ?></td>
                        <td><?= htmlspecialchars($bid['spice_name']) ?></td>
                        <td>₹<?= number_format($bid['amount'], 2) ?></td>
                        <td><?= date('M d, Y', strtotime($bid['created_at'])) ?></td>
                        <td>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="bid_id" value="<?= $bid['id'] ?>">
                                <button type="submit" name="delete_bid" class="btn btn-sm btn-danger" 
                                        onclick="return confirm('Delete this bid?')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

    <!-- Footer -->
    <!-- <footer class="bg-dark text-white py-4 mt-5">
        <div class="container text-center">
            <p class="mb-0">&copy; 2025 SpiceBid Marketplace</p>
        </div>
    </footer> -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
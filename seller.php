<?php
date_default_timezone_set('Asia/Kolkata');
session_start();
include 'db.php';

// Check if user is logged in and has seller privileges
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

// Fetch seller's auctions from database
$seller_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT * FROM auctions WHERE seller_id = ?");
$stmt->bind_param("i", $seller_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $auctions[] = $row;
    }
} else {
    $error = "Error loading auctions: " . $conn->error;
}

// Handle form submission for new auction
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['create_auction'])) {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Invalid request");
    }

    $spice_name = trim($_POST['spice_name']);
    $description = trim($_POST['description']);
    $starting_price = filter_input(INPUT_POST, 'starting_price', FILTER_VALIDATE_FLOAT);
    $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);
    $end_date = trim($_POST['end_date']);
    
    // Validation
    if (empty($spice_name)) $error = "Spice name is required";
    if (empty($description)) $error = "Description is required";
    if (!$starting_price || $starting_price <= 0) $error = "Invalid starting price";
    if (!$quantity || $quantity <= 0) $error = "Invalid quantity";
    if (empty($end_date)) $error = "End date is required";
    
    if (empty($error)) {
        // Handle image upload
        $image_path = '';
        if (isset($_FILES['spice_image']) && $_FILES['spice_image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_name = uniqid() . '_' . basename($_FILES['spice_image']['name']);
            $target_file = $upload_dir . $file_name;
            
            // Check if image file is valid
            $imageFileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
            $valid_extensions = array('jpg', 'jpeg', 'png', 'gif');
            
            if (in_array($imageFileType, $valid_extensions)) {
                if (move_uploaded_file($_FILES['spice_image']['tmp_name'], $target_file)) {
                    $image_path = $target_file;
                } else {
                    $error = "Error uploading image";
                }
            } else {
                $error = "Invalid image format. Only JPG, JPEG, PNG & GIF are allowed.";
            }
        }
        
        if (empty($error)) {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("INSERT INTO auctions (seller_id, spice_name, description, starting_price, current_price, quantity, end_date, image_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $current_price = $starting_price;
                $stmt->bind_param("issddiss", $seller_id, $spice_name, $description, $starting_price, $current_price, $quantity, $end_date, $image_path);
                
                if ($stmt->execute()) {
                    $conn->commit();
                    $success = "Auction created successfully!";
                    // Refresh auctions list
                    header("Location: seller.php");
                    exit();
                } else {
                    throw new Exception("Create auction failed: " . $stmt->error);
                }
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Error: " . $e->getMessage();
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
    <title>SpiceBid - Seller Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
        <link href="css/seller.css" rel="stylesheet">
          <link href="css/home.css" rel="stylesheet">
          <!-- <style>
            
             .main-content {
            background: url('image/watermark.jpg') no-repeat center center;
            background-size: cover;
            padding: 2rem;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-grow: 1;
        }
          </style> -->
</head>
<body>
    <div class="main-content">
    <div class="seller-container">
        <Header>
        <!-- <div class="seller-header mb-4">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1><i class="fas fa-store"></i> SpiceBid Seller Dashboard</h1>
                    <p class="mb-0">Manage your spice auctions and track your sales</p>
                </div>
                <div class="text-end">
                    <p class="mb-0">Welcome, <?php echo htmlspecialchars($_SESSION['name'] ?? 'Seller', ENT_QUOTES, 'UTF-8'); ?></p>
                    <a href="http://localhost:8080/php/home.html" class="btn btn-light btn-sm mt-2">home</a>  
                    <a href="logout.php" class="btn btn-light btn-sm mt-2">Logout</a>
                </div>
            </div>
        </div> -->
        <!-- <header> -->
        <div class="logo"><i>SpiceBid</i></div>
        <nav>
            <div class="text-end">
                    <p class="mb-0" style="color: white;">Welcome, <?php echo htmlspecialchars($_SESSION['name'] ?? 'Seller', ENT_QUOTES, 'UTF-8'); ?></p>

            <ul>
                <li><a href="home.html" class="active"><i class="fas fa-home"></i> Home</a></li>
                <!-- <li><a href="login.php"><i class="fas fa-store"></i> Seller</a></li>
                <li><a href="coustomer.php"><i class="fas fa-users"></i> Customer</a></li> -->
                <!-- <li><a href="admin.php"><i class="fas fa-crown"></i> Admin</a></li> -->
                <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </nav>
    </header>

        <!-- Stats Overview -->
        <!-- <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="number"><?php echo count($auctions); ?></div>
                    <div class="label">Total Auctions</div>
                </div>
            </div>
        </div> -->

        <!-- Main Content -->
        <div class="row">
            <!-- Left Column - Auction List -->
            <div class="col-md-12">
                <div class="seller-card">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h3><i class="fas fa-gavel me-2"></i>Your Active Auctions</h3>
                        <button class="btn btn-spice" data-bs-toggle="modal" data-bs-target="#createAuctionModal">
                            <i class="fas fa-plus me-2"></i>New Auction
                        </button>
                    </div>
                    
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                    
                    <?php if (!empty($success)): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                    
                    <?php if (empty($auctions)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-gavel fa-4x text-muted mb-3"></i>
                            <h4>No auctions found</h4>
                            <p>You haven't created any spice auctions yet.</p>
                            <button class="btn btn-spice mt-2" data-bs-toggle="modal" data-bs-target="#createAuctionModal">
                                Create Your First Auction
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach ($auctions as $auction): 
                                $status = 'active';
                                $status_class = 'active';
                                $status_text = 'Active';
                                $status_bg = 'bg-success';
                            ?>
                            <div class="col-md-4 mb-4">
                                <div class="seller-card auction-card <?php echo $status_class; ?>">
                                    <div class="position-relative">
                                        <?php if ($auction['image_path']): ?>
                                            <img src="<?php echo htmlspecialchars($auction['image_path']); ?>" alt="<?php echo htmlspecialchars($auction['spice_name']); ?>" class="spice-image mb-3">
                                        <?php else: ?>
                                            <div class="spice-image mb-3 bg-light d-flex align-items-center justify-content-center">
                                                <i class="fas fa-pepper-hot fa-3x text-muted"></i>
                                            </div>
                                        <?php endif; ?>
                                        <span class="auction-badge <?php echo $status_bg; ?> rounded-pill"><?php echo $status_text; ?></span>
                                    </div>
                                    <h5><?php echo htmlspecialchars($auction['spice_name']); ?></h5>
                                    <p class="text-muted"><?php echo substr(htmlspecialchars($auction['description']), 0, 80); ?>...</p>
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <strong>Starting Price:</strong> ₹<?php echo number_format($auction['starting_price'], 2); ?>
                                        </div>
                                        <div>
                                            <strong>Current Bid:</strong> ₹<?php echo number_format($auction['current_price'], 2); ?>
                                        </div>
                                    </div>
                                    <div class="d-flex justify-content-between mt-3">
                                        <a href="edit.php?id=<?= $auction['id'] ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-edit me-1"></i>Edit
                                        </a>
                                        <!-- <a href="auction_details.php?id=<?php echo $auction['id']; ?>" class="btn btn-sm btn-spice">
                                            <i class="fas fa-eye me-1"></i>View Details
                                        </a> -->
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Right Column - Quick Stats -->
            <!-- <div class="col-md-4">
                <div class="seller-card mb-4">
                    <h4><i class="fas fa-chart-line me-2"></i>Sales Performance</h4>
                    <div class="mt-4">
                        <canvas id="salesChart" height="250"></canvas>
                    </div>
                </div>
                
                <div class="seller-card">
                    <h4><i class="fas fa-trophy me-2"></i>Top Performing Spices</h4>
                    <ul class="list-group mt-3">
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Saffron
                            <span class="badge bg-success rounded-pill">$1,200</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Vanilla Beans
                            <span class="badge bg-success rounded-pill">$850</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Cardamom
                            <span class="badge bg-success rounded-pill">$720</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Cinnamon
                            <span class="badge bg-success rounded-pill">$620</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Black Pepper
                            <span class="badge bg-success rounded-pill">$480</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div> -->

    <!-- Create Auction Modal -->
    <div class="modal fade" id="createAuctionModal" tabindex="-1" aria-labelledby="createAuctionModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-spice text-white">
                    <h5 class="modal-title" id="createAuctionModalLabel"><i class="fas fa-plus me-2"></i>Create New Auction</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="post" action="seller.php" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="spice_name" class="form-label">Spice Name</label>
                                <input type="text" class="form-control" id="spice_name" name="spice_name" required>
                            </div>
                            <div class="col-md-6">
                                <label for="starting_price" class="form-label">Starting Price (₹)</label>
                                <input type="number" class="form-control" id="starting_price" name="starting_price" min="1" step="0.01" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3" required></textarea>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="quantity" class="form-label">Quantity (kg)</label>
                                <input type="number" class="form-control" id="quantity" name="quantity" min="1" required>
                            </div>
                            <div class="col-md-6">
                                <label for="end_date" class="form-label">Auction End Date</label>
                                <input type="datetime-local" class="form-control" id="end_date" name="end_date" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="spice_image" class="form-label">Spice Image</label>
                            <input class="form-control" type="file" id="spice_image" name="spice_image" accept="image/*">
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" name="create_auction" class="btn btn-spice btn-lg">
                                <i class="fas fa-gavel me-2"></i>Create Auction
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Initialize sales chart
        const ctx = document.getElementById('salesChart').getContext('2d');
        const salesChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                datasets: [{
                    label: 'Monthly Revenue (₹)',
                    data: [1200, 1900, 1500, 1800, 2200, 2450],
                    borderColor: '#8B4513',
                    backgroundColor: 'rgba(139, 69, 19, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
        
        // Toggle form sections
        document.querySelectorAll('.nav-tabs .nav-link').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.tab-pane').forEach(pane => {
                    pane.classList.remove('active');
                });
                const target = document.querySelector(tab.getAttribute('data-bs-target'));
                target.classList.add('active');
            });
        });
    </script>
</body>
</html>






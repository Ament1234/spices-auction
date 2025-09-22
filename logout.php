<?php
session_start();

// Destroy session data
$_SESSION = array();
session_destroy();

// Clear session cookie if set
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Redirect to registration page (make sure this path is correct)
header("Location: reg.php");
exit();
?>

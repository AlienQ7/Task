<?php
// =========================================================
// auth.php (V2.2 - Login/Sign-up with Final Redirection and Verification Logic)
// =========================================================

// --- CRITICAL CONFIGURATION & SETUP ---
ini_set('display_errors', 0); 
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
date_default_timezone_set('Asia/Kolkata'); // Use TIMEZONE_RESET constant from config

// --- INCLUSIONS ---
require_once 'config.php';
require_once 'DbManager.php';

$dbManager = new DbManager();
$error = '';
$username_submitted = '';

// --- CORE FUNCTIONS: Session Management ---

/**
 * Creates a session record, sets the cookie, and redirects the user to the dashboard.
 * @param string $username The user's username.
 * @param DbManager $dbManager Database manager instance.
 */
function createAndSetSession($username, $dbManager) {
    // Generate a secure token
    $token = bin2hex(random_bytes(32)); 
    $expiry = time() + SESSION_TTL_SECONDS;

    // The database function call:
    if ($dbManager->createSession($token, $username, $expiry)) {
        
        // Set the session token cookie
        setcookie('session', $token, [
            'expires' => $expiry,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            // 'secure' => true, // Uncomment if using HTTPS
        ]);
        
        // SUCCESS: Redirect and stop script execution
        header('Location: index.php');
        exit; // <--- CRITICAL: Prevents the HTML form from being rendered
    } else {
        return "Failed to establish a session.";
    }
}

// =========================================================
// REQUEST HANDLER
// =========================================================

/**
 * Handles all authentication logic (checking session, processing form, redirecting).
 */
function handleAuth($dbManager) {
    global $error, $username_submitted;
    
    // 1. CHECK IF ALREADY LOGGED IN
    $sessionToken = $_COOKIE['session'] ?? null;
    if ($sessionToken && $dbManager->getUsernameFromSession($sessionToken)) {
        header('Location: index.php');
        exit;
    }

    // 2. PROCESS POST REQUEST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $action = $_POST['action'] ?? 'login';
        $username_submitted = $username; // Keep for form repopulation

        if (empty($username) || empty($password)) {
            $error = "Username and password cannot be empty.";
        } elseif ($action === 'login') {
            
            $userData = $dbManager->getUserData($username);
            
            // Check if user exists AND password verifies against the stored hash
            if ($userData && password_verify($password, $userData['hash'])) {
                // Login Success -> Create session and redirect
                $error = createAndSetSession($username, $dbManager); 
            } else {
                $error = "Invalid Commander Call Sign or Access Code.";
            }
            
        } elseif ($action === 'signup') {
            
            if (strlen($username) < 3 || strlen($password) < 5) {
                $error = "Call Sign must be at least 3 chars and Access Code at least 5 chars.";
            } elseif ($dbManager->userExists($username)) {
                $error = "Commander Call Sign already in use. Please log in.";
            } else {
                // Sign Up Success -> Create user, then create session and redirect
                $hash = password_hash($password, PASSWORD_DEFAULT);
                
                if ($dbManager->saveNewUser($username, $hash)) {
                    $error = createAndSetSession($username, $dbManager); 
                    // Success leads to exit; failure sets $error message
                } else {
                    $error = "Account creation failed due to a system error.";
                }
            }
        }
    }
    
    // 3. RENDER HTML (only if no successful redirect/exit occurred)
    echo generateAuthHtml();
}

// =========================================================
// HTML VIEW GENERATION (Displays the Login/Signup Form)
// =========================================================

function generateAuthHtml() {
    global $error, $username_submitted;
    
    $html = "<!DOCTYPE html>
<html lang=\"en\">
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <title>Dev Tasks - Access Panel</title>
    <link href=\"https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&display=swap\" rel=\"stylesheet\">
    <link rel=\"stylesheet\" href=\"style.css\">
</head>
<body>
<div class=\"container\">
    <div class=\"auth-container\">
        <h2>Dev Tasks</h2>
        
        " . ($error ? "<p class=\"error-message\">ERROR: " . htmlspecialchars($error) . "</p>" : "") . "

        <form method=\"POST\" class=\"auth-form\">
            <h3>New Dev (Sign Up)</h3>
            <input type=\"text\" name=\"username\" placeholder=\"Enter Name\" required value=\"" . htmlspecialchars($username_submitted) . "\">
            <input type=\"password\" name=\"password\" placeholder=\"Access Code\" required>
            <button type=\"submit\" name=\"action\" value=\"signup\" class=\"auth-btn signup-btn\">Establish Connection (Sign Up)</button>
        </form>
        <hr style=\"border-color:#555; margin: 20px 0;\">
        <form method=\"POST\" class=\"auth-form\">
            <h3>(Login Dev)</h3>
            <input type=\"text\" name=\"username\" placeholder=\"Enter Name\" required value=\"" . htmlspecialchars($username_submitted) . "\">
            <input type=\"password\" name=\"password\" placeholder=\"Access Code\" required>
            <button type=\"submit\" name=\"action\" value=\"login\" class=\"auth-btn\">Request Access (Login)</button>
        </form>
    </div>
</div>
</body>
</html>";
    return $html;
}

// --- EXECUTE MAIN LOGIC ---
handleAuth($dbManager);
$dbManager->close();
?>

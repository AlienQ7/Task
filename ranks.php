<?php
// =========================================================
// ranks.php (V2.5 - FIX: Define missing dependency functions)
// =========================================================

// --- DEPENDENCY LOAD (Essential for Rank Calculations) ---
require_once 'config.php';
require_once 'DbManager.php';

// Set timezone for consistency, relying on config.php
if (defined('TIMEZONE_RESET')) {
    date_default_timezone_set(TIMEZONE_RESET); 
}

// --- INITIALIZATION ---
// This line relies on config.php having been loaded!
try {
    $dbManager = new DbManager(); 
} catch (Exception $e) {
    die("Application Setup Error: " . $e->getMessage());
}

function getCurrentUser($dbManager) {
    $sessionToken = $_COOKIE['session'] ?? null;
    if (!$sessionToken) return null;

    $username = $dbManager->getUsernameFromSession($sessionToken);
    if (!$username) return null;

    $userData = $dbManager->getUserData($username);
    if (!$userData) return null;

    // Ensure rank is calculated upon retrieval
    $userData['rank'] = getRankTitle($userData['sp_points']); 
    return $userData;
}

/**
 * Determines the rank title based on SP points.
 * COPIED from index.php to resolve "undefined function" error.
 */
function getRankTitle($sp_points) {
    if (!defined('RANK_THRESHOLDS')) {
        return 'Aspiring 🚀';
    }
    // Using the constant defined in config.php
    foreach (RANK_THRESHOLDS as $rank) { 
        if ($sp_points >= $rank['sp']) {
            return $rank['title'];
        }
    }
    return 'Aspiring 🚀';
}
// ----------------------------------------------------

// --- EXECUTION START (This is line 47 now) ---
$loggedInUser = getCurrentUser($dbManager); // This call is now defined!

if (!$loggedInUser) {
    header('Location: auth.php');
    exit;
}

// Get the user's current rank for highlighting
$currentRankTitle = getRankTitle($loggedInUser['sp_points']);

// Close the DB connection (important for a clean exit)
$dbManager->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rank Thresholds - Dev Console</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ranks.css">
</head>
<body>

<div class="header-bar">
    <a href="index.php" class="back-link">
        ⟨BACK⟩
    </a>
    <div class="rank-display">
        <br>
        <span class="rank-label">CURRENT RANK:</span>
        <span class="rank-title"><?php echo htmlspecialchars($currentRankTitle); ?></span>
    </div>
</div>

<div class="container rank-container">
    <h2>Rank System Thresholds</h2>
    <p class="explanation">Ascend through the ranks by earning Diamonds (Self-Improvement Points) from daily check-ins.</p>

    <div class="rank-list">
        <?php
        // Sort ranks by SP points descending to display highest first
        $sortedRanks = RANK_THRESHOLDS;
        usort($sortedRanks, function($a, $b) {
            return $b['sp'] <=> $a['sp'];
        });

        foreach ($sortedRanks as $rank):
            $isCurrentRank = ($rank['title'] === $currentRankTitle);
        ?>

        <div class="rank-slot <?php echo $isCurrentRank ? 'current-rank' : ''; ?>">
            <div class="rank-header">
                <span class="rank-title-text"><?php echo htmlspecialchars($rank['title']); ?></span>
                <span class="sp-threshold">Requires: <?php echo number_format($rank['sp']); ?> 💎</span>
            </div>
            <div class="rank-description">
                <?php echo htmlspecialchars($rank['desc']); ?>
            </div>
        </div>

        <?php endforeach; ?>
    </div>
</div>

</body>
</html>

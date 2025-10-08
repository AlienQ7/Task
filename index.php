<?php
// =========================================================
// index.php (V2.8 - FINAL FIX: Ensures config.php is loaded first and uses const)
// =========================================================

// --- CRITICAL CONFIGURATION ---
// 1. THIS MUST BE FIRST to define DB_FILE_PATH, TIMEZONE_RESET, etc.
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
require_once 'config.php';
require_once 'DbManager.php';

// Check if TIMEZONE_RESET is defined (it should be, via config.php)
if (defined('TIMEZONE_RESET')) {
    date_default_timezone_set(TIMEZONE_RESET); 
}

// --- INITIALIZATION ---
// This line relies on config.php having been loaded!
try {
    $dbManager = new DbManager(); 
} catch (Exception $e) {
    // Catch the FATAL ERROR thrown by DbManager if config is missing
    die("Application Setup Error: " . $e->getMessage());
}

$loggedInUser = getCurrentUser($dbManager);

// If not logged in, redirect to authentication page
if (!$loggedInUser && basename($_SERVER['PHP_SELF']) !== 'auth.php') {
    header('Location: auth.php');
    exit;
}

// =========================================================
// SESSION & AUTHENTICATION MANAGEMENT
// =========================================================

/**
 * Validates session and retrieves user data.
 * @return array|null User data array or null if session is invalid.
 */
function getCurrentUser($dbManager) {
    $sessionToken = $_COOKIE['session'] ?? null;
    if (!$sessionToken) return null;

    $username = $dbManager->getUsernameFromSession($sessionToken);
    if (!$username) return null;

    $userData = $dbManager->getUserData($username);
    if (!$userData) return null;

    // --- CRITICAL FIX: Force rank calculation on every load ---
    $userData['rank'] = getRankTitle($userData['sp_points']); 
    
    // Calculate and set the current task points (Coins) upon retrieval
    $userData['task_points'] = $userData['claimed_task_points'] - $userData['total_penalty_deduction'];
    
    return $userData;
}

function handleLogout() {
    global $dbManager;
    $sessionToken = $_COOKIE['session'] ?? null;
    if ($sessionToken) {
        $dbManager->deleteSession($sessionToken);
    }
    // Use the user's SESSION_TTL_SECONDS constant
    $ttl = defined('SESSION_TTL_SECONDS') ? SESSION_TTL_SECONDS : 30 * 86400; 
    setcookie('session', '', time() - $ttl, '/'); // Expire the cookie
    header('Location: auth.php');
    exit;
}

function handleDeleteAccount($username) {
    global $dbManager;
    $dbManager->deleteUserAndData($username);
    handleLogout(); 
}

// =========================================================
// DAILY RESET & SP COLLECTION LOGIC
// =========================================================

/**
 * Checks if a new day has started and performs necessary resets/penalties.
 */
function checkDailyReset(&$user, $dbManager) {
    // Check if TIMEZONE_RESET is defined (it should be, via config.php)
    $timezone = defined('TIMEZONE_RESET') ? TIMEZONE_RESET : 'UTC';

    $now = new DateTime('now', new DateTimeZone($timezone));
    $today_midnight_ts = (clone $now)->setTime(0, 0, 0)->getTimestamp();

    if ($user['last_task_refresh'] < $today_midnight_ts) {
        
        // --- NEW FAILURE LOGIC (Protected by toggle) ---
        if ($user['is_failed_system_enabled'] == 1 && defined('DAILY_FAILURE_PENALTY')) {
            if ($user['daily_completed_count'] < $user['daily_quota']) {
                $user['failed_points']++; 
                $user['total_penalty_deduction'] += DAILY_FAILURE_PENALTY;
            }
        }
        // --- END NEW FAILURE LOGIC ---
        
        $tasksJson = $dbManager->getTasks($user['username'], 'all_tasks');
        $tasks = json_decode($tasksJson, true) ?: [];
        $updatedTasks = [];

        foreach ($tasks as $task) {
            if (($task['permanent'] ?? false) === true) {
                $task['completed'] = false;
                $task['claimed'] = false; // Reset claim status for permanent tasks
                $updatedTasks[] = $task;
            } else {
                // Keep non-permanent tasks if not yet completed/claimed
                $updatedTasks[] = $task; 
            }
        }
        
        $dbManager->saveTasks($user['username'], 'all_tasks', json_encode($updatedTasks));

        $user['daily_completed_count'] = 0; // Reset daily count
        $user['last_task_refresh'] = time(); // Update refresh time
    }
    // Always call updateUserData to ensure all changes (like penalties) are saved
    updateUserData($user, $dbManager); 
}


/**
 * Logic for collecting daily SP (Self-Improvement Points).
 */
function handleSpCollect(&$user, $dbManager) {
    header('Content-Type: application/json');
    $timezone = defined('TIMEZONE_RESET') ? TIMEZONE_RESET : 'UTC';

    $now = new DateTime('now', new DateTimeZone($timezone));
    $today_midnight_ts = (clone $now)->setTime(0, 0, 0)->getTimestamp();

    if ($user['last_sp_collect'] >= $today_midnight_ts) {
        echo json_encode(['success' => false, 'message' => 'Error: Daily 💎 already collected!']);
        return;
    }

    $reward = defined('DAILY_CHECKIN_REWARD') ? DAILY_CHECKIN_REWARD : 10;
    $user['sp_points'] += $reward;
    $user['last_sp_collect'] = time();
    $message = "💎 COLLECTED! +{$reward} 💎. Total: {$user['sp_points']}";
    
    updateUserData($user, $dbManager);
    
    echo json_encode([
        'success' => true, 
        'message' => $message, 
        'sp_points' => $user['sp_points'],
        'rank' => $user['rank']
    ]);
}

// =========================================================
// USER DATA & RANKING UTILITIES
// =========================================================

/**
 * Determines the rank title based on SP points, using the RANK_THRESHOLDS constant array.
 */
function getRankTitle($sp_points) {
    if (!defined('RANK_THRESHOLDS')) {
        return 'Aspiring 🚀';
    }
    foreach (RANK_THRESHOLDS as $rank) {
        if ($sp_points >= $rank['sp']) {
            return $rank['title'];
        }
    }
    return 'Aspiring 🚀'; 
}

/**
 * Updates user data in the database, including recalculating rank.
 */
function updateUserData(&$user, $dbManager) {
    $newRank = getRankTitle($user['sp_points']);
    $user['rank'] = $newRank; 

    // Recalculate and update the task_points field
    $user['task_points'] = $user['claimed_task_points'] - $user['total_penalty_deduction'];

    $dataToSave = [
        'rank' => $user['rank'],
        'sp_points' => $user['sp_points'],
        // task_points is a calculated field, only the components are stored:
        'claimed_task_points' => $user['claimed_task_points'], 
        'failed_points' => $user['failed_points'],                    
        'total_penalty_deduction' => $user['total_penalty_deduction'], 
        'daily_quota' => $user['daily_quota'],                        
        'is_failed_system_enabled' => $user['is_failed_system_enabled'], 
        'last_sp_collect' => $user['last_sp_collect'],
        'last_task_refresh' => $user['last_task_refresh'],
        'daily_completed_count' => $user['daily_completed_count'],
        'user_objective' => $user['user_objective']
    ];
    $dbManager->saveUserData($user['username'], $dataToSave);
}

// =========================================================
// TASK MANAGEMENT (AJAX ENDPOINT)
// =========================================================

function handleTaskActions(&$user, $dbManager) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $taskId = $_POST['id'] ?? null;
    $taskText = $_POST['text'] ?? null;
    $isPermanent = (($_POST['permanent'] ?? 'false') === 'true'); 

    $tasksJson = $dbManager->getTasks($user['username'], 'all_tasks');
    $tasks = json_decode($tasksJson, true) ?: [];
    $response = ['success' => false, 'message' => ''];
    $taskFound = false;
    $reward = defined('TASK_COMPLETION_REWARD') ? TASK_COMPLETION_REWARD : 2;

    // --- Action: Add ---
    if ($action === 'add' && !empty($taskText)) {
        $newTask = [
            'id' => uniqid(),
            'text' => htmlspecialchars($taskText),
            'completed' => false,
            'claimed' => false, 
            'permanent' => $isPermanent
        ];
        $tasks[] = $newTask;
        $response = ['success' => true, 'task' => $newTask];
    } 
    // --- Action: Toggle/Delete/Set Permanent ---
    else {
        foreach ($tasks as $key => &$task) {
            if (($task['id'] ?? null) === $taskId) {
                $taskFound = true;
                
                if ($action === 'toggle') {
                    $task['completed'] = !$task['completed'];
                    $response = ['success' => true, 'id' => $taskId, 'completed' => $task['completed']];

                    if ($task['completed']) {
                        // Task Completed: Award points
                        if (($task['claimed'] ?? false) === false || ($task['permanent'] ?? false) === true) {
                            $user['claimed_task_points'] += $reward;
                            $task['claimed'] = true; 
                            $response['points_change'] = '+'.$reward;
                        } else {
                            $response['points_change'] = '+0 (Already claimed)';
                        }
                        $user['daily_completed_count']++;
                    } else {
                        // Task Uncompleted: Only decrement daily count (points are not refunded on un-toggle)
                        if (($task['claimed'] ?? false) === true && ($task['permanent'] ?? false) !== true) {
                            // If it's a one-time task being untoggled, points should probably be refunded,
                            // but current system design only tracks penalty/claimed points, not refunds.
                        }
                        $user['daily_completed_count']--;
                        $response['points_change'] = '-0';
                    }
                    if ($user['daily_completed_count'] < 0) $user['daily_completed_count'] = 0;

                    break;
                }
                
                if ($action === 'delete') {
                    unset($tasks[$key]);
                    $tasks = array_values($tasks); 
                    $response = ['success' => true, 'id' => $taskId, 'message' => 'Task Deleted.'];
                    break;
                }

                if ($action === 'set_permanent') {
                    $task['permanent'] = $isPermanent;
                    $task['completed'] = false; 
                    $task['claimed'] = false;
                    $response = ['success' => true, 'id' => $taskId, 'permanent' => $isPermanent];
                    break;
                }
            }
        }
        if (!$taskFound && $action !== 'add') {
            $response = ['success' => false, 'message' => 'Error: Task ID not found.'];
        }
    }

    if ($response['success']) {
        $dbManager->saveTasks($user['username'], 'all_tasks', json_encode($tasks));
        updateUserData($user, $dbManager); 
        
        $response['user_data'] = [
            'tp' => $user['task_points'], 
            'sp' => $user['sp_points'],
            'failed' => $user['failed_points'], 
            'rank' => $user['rank'],
            'daily_count' => $user['daily_completed_count']
        ];
    }
    
    echo json_encode($response);
}

function handleObjectiveSave(&$user, $dbManager) {
    header('Content-Type: application/json');
    $objective = trim($_POST['objective'] ?? '');

    if (!empty($objective) || $objective === '') { 
        $user['user_objective'] = htmlspecialchars($objective);
        updateUserData($user, $dbManager);
        echo json_encode(['success' => true, 'objective' => $user['user_objective']]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Objective error.']);
    }
}

function handleQuotaSave(&$user, $dbManager) {
    header('Content-Type: application/json');
    $quota = (int)($_POST['quota'] ?? 4);
    
    if ($quota < 1) {
        $quota = 1;
    }

    $user['daily_quota'] = $quota;
    updateUserData($user, $dbManager);
    echo json_encode(['success' => true, 'quota' => $quota, 'message' => 'Daily quota saved.']);
}

function handleFailureToggle(&$user, $dbManager) {
    header('Content-Type: application/json');
    
    $user['is_failed_system_enabled'] = ($user['is_failed_system_enabled'] == 1) ? 0 : 1;
    
    updateUserData($user, $dbManager);

    $statusText = ($user['is_failed_system_enabled'] == 1) ? 'Enabled' : 'Disabled';
    echo json_encode([
        'success' => true, 
        'status' => $user['is_failed_system_enabled'],
        'status_text' => $statusText,
        'message' => "Failure System is now {$statusText}."
    ]);
}


// =========================================================
// MAIN REQUEST HANDLER & HTML VIEW (UNCHANGED LOGIC)
// =========================================================

function handleRequest(&$user, $dbManager) {
    checkDailyReset($user, $dbManager);
    
    if (isset($_GET['action'])) {
        if ($_GET['action'] === 'logout') {
            handleLogout();
        } elseif ($user && $_GET['action'] === 'delete_account') {
            handleDeleteAccount($user['username']);
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['endpoint'])) {
        $endpoint = $_POST['endpoint'];
        
        if ($endpoint === 'task_action') {
            handleTaskActions($user, $dbManager);
        } elseif ($endpoint === 'sp_collect') {
            handleSpCollect($user, $dbManager);
        } elseif ($endpoint === 'save_objective') {
            handleObjectiveSave($user, $dbManager);
        }
        elseif ($endpoint === 'save_quota') {
            handleQuotaSave($user, $dbManager);
        } elseif ($endpoint === 'toggle_failure') {
            handleFailureToggle($user, $dbManager);
        } 
        else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown endpoint.']);
        }
        exit;
    }
    
    echo generateHtml($user, $dbManager);
}

function generateHtml($user, $dbManager) {
    // ... (HTML generation function body remains largely the same, but uses constants like TASK_COMPLETION_REWARD)
    
    $tasksJson = $dbManager->getTasks($user['username'], 'all_tasks');
    $tasks = json_decode($tasksJson, true) ?: [];

    $timezone = defined('TIMEZONE_RESET') ? TIMEZONE_RESET : 'UTC';
    $now = new DateTime('now', new DateTimeZone($timezone));
    $today_midnight_ts = (clone $now)->setTime(0, 0, 0)->getTimestamp();
    $canCollectSp = $user['last_sp_collect'] < $today_midnight_ts;
    $spButtonText = $canCollectSp ? 'Collect(💎)' : 'Collected(💎):';
    
    $objectiveDisplay = ($user['user_objective'] === 'Pro max programmer xd.') ? '' : htmlspecialchars($user['user_objective']);
    
    $isFailureEnabled = ($user['is_failed_system_enabled'] == 1);
    $failureToggleText = $isFailureEnabled ? 'Disable System' : 'Enable System';
    $failureStatusText = $isFailureEnabled ? 'Enabled' : 'Disabled';


    ob_start(); // Start output buffering
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dev Console: <?php echo htmlspecialchars($user['username']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="header-bar">
    <div class="rank-display">
        <a href="ranks.php" style="text-decoration: none; color: inherit;">
            <span class="rank-label">RANK:</span>
            <span id="user-rank-title" class="rank-title"><?php echo htmlspecialchars($user['rank']); ?></span>
        </a>
    </div>
    <div class="header-menu">
        <button class="hamburger-btn" onclick="toggleMenu()">☰</button>
        
        <div id="dropdown-menu" class="menu-dropdown">
            <span class="dropdown-sp-collected">Coins(🪙): <span id="tp-display"><?php echo $user['task_points']; ?></span></span>
            <span class="dropdown-sp-collected">Diamonds(💎): <span id="sp-display-menu"><?php echo $user['sp_points']; ?></span></span>
            
            <hr style="border-color:#555;">
            
            <div class="failure-toggle-wrapper" id="failure-toggle-display">
                <span id="failure-status-text">Fail System: <?php echo $failureStatusText; ?></span>
                <button id="failure-toggle-btn" class="auth-btn" data-enabled="<?php echo $isFailureEnabled ? 'true' : 'false'; ?>" onclick="toggleFailureSystem()">
                    <?php echo $failureToggleText; ?>
                </button>
            </div>
            
            <hr style="border-color:#555;">
            <a href="shop.php">Shop (Coming Soon!)</a>
            <hr style="border-color:#555;">
            <a href="?action=logout">Log Out</a>
            <button onclick="confirmDelete()" class="delete-btn">Delete Account</button>
        </div>
    </div>
</div>

<div class="container">
    <div class="profile-container">
        <h2>Dev: <?php echo htmlspecialchars($user['username']); ?></h2>
        
        <div class="stats-line">
            Diamonds(💎): <strong id="sp-stats"><?php echo $user['sp_points']; ?></strong>
        </div>
        <div class="stats-line">
            Coins(🪙): <strong id="tp-stats"><?php echo $user['task_points']; ?></strong>
        </div>
        
        <?php if ($isFailureEnabled): ?>
        <div class="stats-line" id="failed-stat-line">
            Failed (❌): <strong id="failed-stats"><?php echo $user['failed_points']; ?></strong>
        </div>
        <?php endif; ?>
        
        <div class="stats-line">
            Daily Completed(🎯): <strong id="daily-count-stats"><?php echo $user['daily_completed_count']; ?></strong>
        </div>
        
        <?php if ($isFailureEnabled): ?>
        <div class="quota-input-container" id="quota-input-container">
            <label for="daily-quota-input" class="quota-label">Daily Quota:</label>
            <input type="number" id="daily-quota-input" min="1" value="<?php echo $user['daily_quota']; ?>" class="quota-input">
            <button onclick="saveDailyQuota()" class="auth-btn set-quota-btn">Set Quota</button>
        </div>
        <?php endif; ?>
        
        <div class="sp-btn-container">
            <button id="sp-collect-btn" onclick="collectSp()" class="auth-btn" <?php if (!$canCollectSp) echo 'disabled'; ?>>
                <?php echo $spButtonText; ?>
            </button>
        </div>
        
        <h3>Current Objective:</h3>
        <div class="objective-container">
            <input type="text" id="objective-input" placeholder="Set Your Objective" value="<?php echo $objectiveDisplay; ?>">
            <button onclick="saveObjective()">SAVE</button>
        </div>

    </div>

    <div class="task-manager">
        <h2>Task Log</h2>
        <div class="add-task-controls">
            <input type="text" id="new-task-input" placeholder="New Mission Log Entry..." onkeydown="if(event.key === 'Enter') document.getElementById('add-task-btn').click();">
            <select id="task-type-select">
                <option value="false">One-Time Mission</option>
                <option value="true">Permanent Daily Lock</option>
            </select>
            <button id="add-task-btn" onclick="addTask()" class="add-btn">ADD</button>
        </div>
        
        <div id="task-list">
            <?php foreach ($tasks as $task): ?>
                <?php echo renderTaskHtml($task); ?>
            <?php endforeach; ?>
            <?php if (empty($tasks)): ?>
                <p id="no-tasks-message">No active missions. Add a new one to begin!</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    // Use PHP to expose the necessary constants to JavaScript
    const TASK_COMPLETION_REWARD = <?php echo defined('TASK_COMPLETION_REWARD') ? TASK_COMPLETION_REWARD : 2; ?>;
    const DAILY_CHECKIN_REWARD = <?php echo defined('DAILY_CHECKIN_REWARD') ? DAILY_CHECKIN_REWARD : 10; ?>;
    
    function toggleMenu() {
        const menu = document.getElementById('dropdown-menu');
        menu.classList.toggle('show');
    }
    
    function confirmDelete() {
        if (confirm("WARNING: All data (tasks, points, progress) will be permanently deleted. Are you sure you wish to delete your account?")) {
            window.location.href = '?action=delete_account';
        }
    }

    function renderTaskHtml(task) {
        const completedClass = task.completed ? 'completed-slot' : '';
        const completedAttr = task.completed ? 'checked' : '';
        const permanentIndicator = task.permanent ? '<span class="permanent-indicator" title="Permanent Daily Task">🔒</span>' : '';
        const permanentBtnText = task.permanent ? 'Unlock' : 'Lock';
        const nextStatus = task.permanent ? 'false' : 'true';

        return `
            <div class="task-slot ${completedClass}" id="task-${task.id}" data-id="${task.id}" data-permanent="${task.permanent}" data-completed="${task.completed}">
                <input type="checkbox" class="task-checkbox" ${completedAttr} onchange="toggleTask('${task.id}')">
                <div class="task-description-wrapper">
                    ${permanentIndicator}
                    <span class="task-description ${completedAttr ? 'completed' : ''}">${task.text}</span>
                </div>
                <button class="permanent-btn" data-permanent="${task.permanent}" onclick="togglePermanent('${task.id}', ${nextStatus})">${permanentBtnText}</button>
                <button class="remove-btn" onclick="deleteTask('${task.id}')">REMOVE</button>
            </div>
        `;
    }

    function updateStatsDisplay(data) {
        document.getElementById('tp-stats').textContent = data.tp;
        document.getElementById('sp-stats').textContent = data.sp;
        document.getElementById('daily-count-stats').textContent = data.daily_count;
        document.getElementById('user-rank-title').textContent = data.rank;
        document.getElementById('tp-display').textContent = data.tp;
        document.getElementById('sp-display-menu').textContent = data.sp;
        
        const failedStats = document.getElementById('failed-stats');
        if (failedStats) {
             failedStats.textContent = data.failed; 
        }
    }

    async function postAction(data) {
        try {
            const response = await fetch('index.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(data)
            });
            return response.json();
        } catch (error) {
            console.error('Network or parsing error:', error);
            alert('A network error occurred. Check your connection.');
            return { success: false, message: 'Network Error' };
        }
    }

    async function addTask() {
        const input = document.getElementById('new-task-input');
        const text = input.value.trim();
        const permanent = document.getElementById('task-type-select').value;
        
        if (!text) return;

        const result = await postAction({ 
            endpoint: 'task_action', 
            action: 'add', 
            text: text, 
            permanent: permanent
        });

        if (result.success) {
            document.getElementById('task-list').insertAdjacentHTML('beforeend', renderTaskHtml(result.task));
            input.value = '';
            document.getElementById('no-tasks-message')?.remove();
        } else {
            alert(result.message);
        }
    }

    async function toggleTask(id) {
        const slot = document.getElementById(`task-${id}`);
        const isCompleted = slot.dataset.completed === 'true';

        const result = await postAction({ 
            endpoint: 'task_action', 
            action: 'toggle', 
            id: id 
        });

        if (result.success) {
            slot.dataset.completed = result.completed;
            slot.querySelector('.task-checkbox').checked = result.completed;
            slot.querySelector('.task-description').classList.toggle('completed', result.completed);
            slot.classList.toggle('completed-slot', result.completed);
            
            updateStatsDisplay(result.user_data);
        } else {
            slot.querySelector('.task-checkbox').checked = isCompleted;
            alert(result.message);
        }
    }

    async function deleteTask(id) {
        if (!confirm("Confirm mission abort (REMOVE)?")) return; 
        
        const result = await postAction({ 
            endpoint: 'task_action', 
            action: 'delete', 
            id: id 
        });

        if (result.success) {
            document.getElementById(`task-${id}`).remove();
            updateStatsDisplay(result.user_data);
            
            if (document.getElementById('task-list').children.length === 0) {
                 document.getElementById('task-list').innerHTML = '<p id="no-tasks-message">No active missions. Add a new one to begin!</p>';
            }
        } else {
            alert(result.message);
        }
    }

    async function togglePermanent(id, newPermanentStatus) {
        const result = await postAction({ 
            endpoint: 'task_action', 
            action: 'set_permanent', 
            id: id,
            permanent: newPermanentStatus 
        });

        if (result.success) {
            const slot = document.getElementById(`task-${id}`);
            slot.dataset.permanent = result.permanent;
            
            const indicator = slot.querySelector('.permanent-indicator');
            const button = slot.querySelector('.permanent-btn');
            
            if (result.permanent) {
                if (!indicator) { 
                    const wrapper = slot.querySelector('.task-description-wrapper');
                    wrapper.insertAdjacentHTML('afterbegin', '<span class="permanent-indicator" title="Permanent Daily Task">🔒</span>');
                }
                button.textContent = 'Unlock'; 
                button.setAttribute('data-permanent', 'true');
                button.setAttribute('onclick', `togglePermanent('${id}', false)`);
            } else {
                indicator?.remove();
                button.textContent = 'Lock'; 
                button.setAttribute('data-permanent', 'false');
                button.setAttribute('onclick', `togglePermanent('${id}', true)`);
            }
            slot.dataset.completed = 'false';
            slot.querySelector('.task-checkbox').checked = false;
            slot.querySelector('.task-description').classList.remove('completed');
            slot.classList.remove('completed-slot');
            
        } else {
            alert(result.message);
        }
    }

    async function collectSp() {
        const button = document.getElementById('sp-collect-btn');
        button.disabled = true; 

        const result = await postAction({ 
            endpoint: 'sp_collect' 
        });

        if (result.success) {
            alert(result.message);
            updateStatsDisplay({
                tp: document.getElementById('tp-stats').textContent, 
                sp: result.sp_points,
                failed: document.getElementById('failed-stats')?.textContent ?? '0', 
                rank: result.rank,
                daily_count: document.getElementById('daily-count-stats').textContent
            });
            button.textContent = 'Collected(💎):'; 
        } else {
            alert(result.message);
            button.disabled = false; 
        }
    }
    
    async function saveObjective() {
        const objective = document.getElementById('objective-input').value.trim();
        const result = await postAction({ 
            endpoint: 'save_objective', 
            objective: objective 
        });
        
        if (result.success) {
            alert('Objective saved successfully!');
            if (objective === '') {
                window.location.reload(); 
            }
        } else {
            alert(result.message);
        }
    }
    
    async function saveDailyQuota() {
        const input = document.getElementById('daily-quota-input');
        let quota = parseInt(input.value.trim(), 10);

        if (isNaN(quota) || quota < 1) {
            quota = 1;
            input.value = quota;
        }

        const result = await postAction({ 
            endpoint: 'save_quota', 
            quota: quota 
        });
        
        if (result.success) {
            alert(`Daily quota set to ${result.quota} tasks.`);
        } else {
            alert(result.message);
        }
    }
    
    async function toggleFailureSystem() {
        if (!confirm("Confirm toggle? This will affect your daily penalty logic. OK to proceed.")) return;
        
        const result = await postAction({ 
            endpoint: 'toggle_failure' 
        });

        if (result.success) {
            const isEnabled = result.status == 1;
            
            const toggleBtn = document.getElementById('failure-toggle-btn');
            const statusTextSpan = document.getElementById('failure-status-text');
            
            toggleBtn.textContent = isEnabled ? 'Disable System' : 'Enable System';
            statusTextSpan.textContent = `Fail System: ${result.status_text}`;
            
            toggleBtn.setAttribute('data-enabled', isEnabled ? 'true' : 'false');
            
            if (isEnabled) {
                 window.location.reload(); 
            } else {
                document.getElementById('quota-input-container')?.remove();
                document.getElementById('failed-stat-line')?.remove();
            }
            console.log(result.message);
            
        } else {
            alert(result.message);
        }
    }

    document.addEventListener('click', (event) => {
        const menu = document.getElementById('dropdown-menu');
        const button = document.querySelector('.hamburger-btn');
        if (menu && button && !menu.contains(event.target) && !button.contains(event.target)) {
            menu.classList.remove('show');
        }
    });

</script>
</body>
</html>
    <?php
    return ob_get_clean(); 
}

function renderTaskHtml($task) {
    $completedClass = ($task['completed'] ?? false) ? 'completed-slot' : '';
    $completedAttr = ($task['completed'] ?? false) ? 'checked' : '';
    $permanentIndicator = ($task['permanent'] ?? false) ? '<span class="permanent-indicator" title="Permanent Daily Task">🔒</span>' : '';
    $permanentBtnText = ($task['permanent'] ?? false) ? 'Unlock' : 'Lock';
    $nextStatus = ($task['permanent'] ?? false) ? 'false' : 'true';

    return '
        <div class="task-slot ' . $completedClass . '" id="task-' . ($task['id'] ?? '') . '" data-id="' . ($task['id'] ?? '') . '" data-permanent="' . (($task['permanent'] ?? false) ? 'true' : 'false') . '" data-completed="' . (($task['completed'] ?? false) ? 'true' : 'false') . '">
            <input type="checkbox" class="task-checkbox" ' . $completedAttr . ' onchange="toggleTask(\'' . ($task['id'] ?? '') . '\')">
            <div class="task-description-wrapper">
                ' . $permanentIndicator . '
                <span class="task-description ' . ($completedAttr ? 'completed' : '') . '">' . htmlspecialchars($task['text'] ?? '') . '</span>
            </div>
            <button class="permanent-btn" data-permanent="' . (($task['permanent'] ?? false) ? 'true' : 'false') . '" onclick="togglePermanent(\'' . ($task['id'] ?? '') . '\', ' . $nextStatus . ')">' . $permanentBtnText . '</button>
            <button class="remove-btn" onclick="deleteTask(\'' . ($task['id'] ?? '') . '\')">REMOVE</button>
        </div>
    ';
}

// --- EXECUTE MAIN APPLICATION LOGIC ---
if ($loggedInUser) {
    handleRequest($loggedInUser, $dbManager);
}

// Close the database connection
if (isset($dbManager)) {
    $dbManager->close();
}
?>

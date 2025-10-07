<?php
// =========================================================
// index.php (V2.0 - Diamond Branding, Fixed Toggle)
// =========================================================

// --- CRITICAL CONFIGURATION ---
// Set timezone for all date/time operations to ensure consistent daily resets
ini_set('display_errors', 1); 
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
require_once 'config.php';
require_once 'DbManager.php';
date_default_timezone_set(TIMEZONE_RESET); // Uses TIMEZONE_RESET from config.php
// --- INITIALIZATION ---
$dbManager = new DbManager();
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

    return $userData;
}

function handleLogout() {
    global $dbManager;
    $sessionToken = $_COOKIE['session'] ?? null;
    if ($sessionToken) {
        $dbManager->deleteSession($sessionToken);
    }
    setcookie('session', '', time() - 3600, '/'); // Expire the cookie
    header('Location: auth.php');
    exit;
}

function handleDeleteAccount($username) {
    global $dbManager;
    $dbManager->deleteUserAndData($username);
    handleLogout(); // Automatically logs out and redirects
}

// =========================================================
// DAILY RESET & DIAMOND COLLECTION LOGIC (Asia/Kolkata Time)
// =========================================================

/**
 * Checks if a new day has started in Kolkata time and performs necessary resets.
 */
function checkDailyReset(&$user, $dbManager) {
    $now = new DateTime('now', new DateTimeZone(TIMEZONE_RESET));
    $today_midnight_ts = (clone $now)->setTime(0, 0, 0)->getTimestamp();

    // Check if the last refresh was before today's midnight (Kolkata time)
    if ($user['last_task_refresh'] < $today_midnight_ts) {
        
        $tasksJson = $dbManager->getTasks($user['username'], 'all_tasks');
        $tasks = json_decode($tasksJson, true) ?: [];
        $updatedTasks = [];

        foreach ($tasks as $task) {
            // 1. Permanent Task Refresh Logic:
            if ($task['permanent'] === true && $task['completed'] === true) {
                // If permanent task was COMPLETED, reset its status for the new day
                $task['completed'] = false;
                $updatedTasks[] = $task;
            } elseif ($task['permanent'] === true && $task['completed'] === false) {
                 // If permanent task was NOT completed, it carries over as incomplete.
                $updatedTasks[] = $task;
            } 
            // Non-permanent tasks (whether completed or not) are discarded if they weren't done yesterday
        }
        
        // Save the refreshed tasks
        $dbManager->saveTasks($user['username'], 'all_tasks', json_encode($updatedTasks));

        // Update user stats
        $user['daily_completed_count'] = 0; // Reset daily count
        $user['last_task_refresh'] = time(); // Update refresh time
    }
}


/**
 * Logic for collecting daily Diamonds.
 */
function handleSpCollect(&$user, $dbManager) {
    header('Content-Type: application/json');
    $now = new DateTime('now', new DateTimeZone(TIMEZONE_RESET));
    $today_midnight_ts = (clone $now)->setTime(0, 0, 0)->getTimestamp();

    if ($user['last_sp_collect'] >= $today_midnight_ts) {
        // Already collected today
        echo json_encode(['success' => false, 'message' => 'Error: Daily Diamond already collected!']);
        return;
    }

    $user['sp_points'] += DAILY_DIAMOND_REWARD;
    $user['last_sp_collect'] = time();
    $message = "DIAMOND COLLECTED! +".DAILY_DIAMOND_REWARD." 💎. Total: {$user['sp_points']}";
    
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
 * Determines the rank title based on SP points.
 */
function getRankTitle($sp_points) {
    foreach (RANK_THRESHOLDS as $rank) {
        if ($sp_points >= $rank['sp']) {
            return $rank['title'];
        }
    }
    return 'Aspiring 🌱'; 
}

/**
 * Updates user data in the database, including recalculating rank.
 */
function updateUserData(&$user, $dbManager) {
    // 1. Recalculate Rank
    $newRank = getRankTitle($user['sp_points']);
    $user['rank'] = $newRank;

    // 2. Save to DB
    $dataToSave = [
        'rank' => $user['rank'],
        'sp_points' => $user['sp_points'],
        'task_points' => $user['task_points'],
        'failed_points' => $user['failed_points'],
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
    // PHP receives the status as a string 'true' or 'false'
    $isPermanent = (($_POST['permanent'] ?? 'false') === 'true'); 

    $tasksJson = $dbManager->getTasks($user['username'], 'all_tasks');
    $tasks = json_decode($tasksJson, true) ?: [];
    $response = ['success' => false, 'message' => ''];
    $taskFound = false;

    // --- Action: Add ---
    if ($action === 'add' && !empty($taskText)) {
        $newTask = [
            'id' => uniqid(),
            'text' => htmlspecialchars($taskText),
            'completed' => false,
            'permanent' => $isPermanent
        ];
        $tasks[] = $newTask;
        $response = ['success' => true, 'task' => $newTask];
    } 
    // --- Action: Toggle/Delete/Set Permanent ---
    else {
        foreach ($tasks as $key => &$task) {
            if ($task['id'] === $taskId) {
                $taskFound = true;
                
                if ($action === 'toggle') {
                    $task['completed'] = !$task['completed'];
                    $response = ['success' => true, 'id' => $taskId, 'completed' => $task['completed']];

                    // Update points and stats on completion/uncompletion
                    if ($task['completed']) {
                        $user['task_points'] += TASK_COMPLETION_REWARD;
                        $user['daily_completed_count']++;
                        $response['points_change'] = '+'.TASK_COMPLETION_REWARD;
                    } else {
                        // Deduct points if unchecking a completed task
                        $user['task_points'] -= TASK_COMPLETION_REWARD; 
                        $user['daily_completed_count']--;
                        $response['points_change'] = '-'.TASK_COMPLETION_REWARD;
                    }
                    break;
                }
                
                if ($action === 'delete') {
                    // If task was completed, we need to correct points before deletion
                    if ($task['completed']) {
                        $user['task_points'] -= TASK_COMPLETION_REWARD;
                        $user['daily_completed_count']--;
                    }
                    unset($tasks[$key]);
                    $tasks = array_values($tasks); // Re-index array
                    $response = ['success' => true, 'id' => $taskId, 'message' => 'Task Deleted.'];
                    break;
                }

                if ($action === 'set_permanent') {
                    // This handles BOTH LOCK (true) and UNLOCK (false)
                    $task['permanent'] = $isPermanent;
                    // Respond with the actual boolean status for JS to update the button
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
        // Save tasks and user data
        $dbManager->saveTasks($user['username'], 'all_tasks', json_encode($tasks));
        updateUserData($user, $dbManager); 
        $response['user_data'] = [
            'tp' => $user['task_points'],
            'sp' => $user['sp_points'],
            'rank' => $user['rank'],
            'daily_count' => $user['daily_completed_count']
        ];
    }
    
    echo json_encode($response);
}

function handleObjectiveSave(&$user, $dbManager) {
    header('Content-Type: application/json');
    $objective = trim($_POST['objective'] ?? '');

    if (!empty($objective)) {
        $user['user_objective'] = htmlspecialchars($objective);
        updateUserData($user, $dbManager);
        echo json_encode(['success' => true, 'objective' => $user['user_objective']]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Objective cannot be empty.']);
    }
}

// =========================================================
// MAIN REQUEST HANDLER
// =========================================================

/**
 * Dispatches requests to the appropriate handler (for AJAX, Logout, Delete).
 */
function handleRequest(&$user, $dbManager) {
    
    // Check for required daily actions first
    checkDailyReset($user, $dbManager);
    
    // Check for explicit GET/POST actions (Logout/Delete Account)
    if (isset($_GET['action'])) {
        if ($_GET['action'] === 'logout') {
            handleLogout();
        } elseif ($_GET['action'] === 'delete_account') {
            handleDeleteAccount($user['username']);
        }
    }

    // Check for AJAX POST requests (Task/SP/Objective Management)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['endpoint'])) {
        $endpoint = $_POST['endpoint'];
        
        if ($endpoint === 'task_action') {
            handleTaskActions($user, $dbManager);
        } elseif ($endpoint === 'sp_collect') {
            handleSpCollect($user, $dbManager);
        } elseif ($endpoint === 'save_objective') {
            handleObjectiveSave($user, $dbManager);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown endpoint.']);
        }
        // Terminate execution after AJAX response
        exit;
    }
    
    // Default: Display the main HTML page
    echo generateHtml($user, $dbManager);
}

// =========================================================
// HTML VIEW GENERATION
// =========================================================

/**
 * Generates the full HTML view for the user dashboard.
 */
function generateHtml($user, $dbManager) {
    $tasksJson = $dbManager->getTasks($user['username'], 'all_tasks');
    $tasks = json_decode($tasksJson, true) ?: [];

    // Check if Diamonds can be collected today (based on Kolkata time)
    $now = new DateTime('now', new DateTimeZone(TIMEZONE_RESET));
    $today_midnight_ts = (clone $now)->setTime(0, 0, 0)->getTimestamp();
    $canCollectSp = $user['last_sp_collect'] < $today_midnight_ts;
    // UPDATED DIAMOND BUTTON TEXT
    $spButtonText = $canCollectSp ? 'COLLECT (💎)' : 'Collected (💎)'; 

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
    <style>
        /* Embed time-specific styles for the rank title color */
        .rank-title { 
            color: <?php echo COLOR_RANK_TITLE; ?>; 
        }
    </style>
</head>
<body>

<div class="header-bar">
    <div class="rank-display">
        <span class="rank-label">RANK:</span>
        <span id="user-rank-title" class="rank-title"><?php echo htmlspecialchars($user['rank']); ?></span>
    </div>
    <div class="header-menu">
        <button class="hamburger-btn" onclick="toggleMenu()">☰</button>
        
        <div id="dropdown-menu" class="menu-dropdown">
            <span class="dropdown-sp-collected">TP: <span id="tp-display"><?php echo $user['task_points']; ?></span></span>
            <span class="dropdown-sp-collected">💎: <span id="sp-display-menu"><?php echo $user['sp_points']; ?></span></span>
            <a href="shop.php">Shop (Coming Soon!)</a>
            <hr style="border-color:#555;">
            <a href="?action=logout">Log Out</a>
            <button onclick="confirmDelete()">Delete Account</button>
        </div>
    </div>
</div>

<div class="container">
    <div class="profile-container">
        <h2>Dev: <?php echo htmlspecialchars($user['username']); ?></h2>
        <div class="stats-line">
            Total Task Points (TP): <strong id="tp-stats"><?php echo $user['task_points']; ?></strong>
        </div>
        <div class="stats-line">
            Total Diamonds (💎): <strong id="sp-stats"><?php echo $user['sp_points']; ?></strong>
        </div>
        <div class="stats-line">
            Daily Mission (🎯): <strong id="daily-count-stats"><?php echo $user['daily_completed_count']; ?></strong>
        </div>
        <div class="stats-line">
            Failed Missions (❌): <strong id="failed-stats"><?php echo $user['failed_points']; ?></strong>
        </div>
        
        <div class="sp-btn-container">
            <button id="sp-collect-btn" onclick="collectSp()" class="auth-btn" <?php if (!$canCollectSp) echo 'disabled'; ?>>
                <?php echo $spButtonText; ?>
            </button>
        </div>
        
        <h3>Current Objective:</h3>
        <div class="objective-container">
            <input type="text" id="objective-input" placeholder="Set your main focus (e.g., Learn PHP)" value="<?php echo htmlspecialchars($user['user_objective']); ?>">
            <button onclick="saveObjective()">SAVE</button>
        </div>

    </div>

    <div class="task-manager">
        <h2>Mission Log: Current Tasks</h2>
        <div style="display: flex; align-items: center; margin-bottom: 20px;">
            <input type="text" id="new-task-input" placeholder="New Mission Log Entry..." onkeydown="if(event.key === 'Enter') document.getElementById('add-task-btn').click();">
            <select id="task-type-select" style="padding: 10px; margin-right: 5px; background: #000; color: #00ff99; border: 1px solid #00ff99;">
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
    const DB_FILE_PATH = "<?php echo DB_FILE_PATH; ?>";
    const SESSION_TTL_SECONDS = <?php echo SESSION_TTL_SECONDS; ?>;
    const TASK_COMPLETION_REWARD = <?php echo TASK_COMPLETION_REWARD; ?>;
    const DAILY_DIAMOND_REWARD = <?php echo DAILY_DIAMOND_REWARD; ?>; // Updated constant

    /**
     * Toggles the Hamburger Dropdown Menu visibility.
     */
    function toggleMenu() {
        const menu = document.getElementById('dropdown-menu');
        menu.classList.toggle('show');
    }
    
    /**
     * Confirmation prompt before deleting the user's account.
     */
    function confirmDelete() {
        if (confirm("WARNING: All data (tasks, points, progress) will be permanently deleted. Are you sure you wish to delete your account?")) {
            window.location.href = '?action=delete_account';
        }
    }

    /**
     * Converts a task object into its HTML representation.
     */
    function renderTaskHtml(task) {
        const completedClass = task.completed ? 'completed-slot' : '';
        const completedAttr = task.completed ? 'checked' : '';
        const permanentIndicator = task.permanent ? '<span class="permanent-indicator" title="Permanent Daily Task">🔒</span>' : '';
        const permanentBtnText = task.permanent ? '🔓 Unlock' : '🔒 Lock'; // Updated text

        return `
            <div class="task-slot ${completedClass}" id="task-${task.id}" data-id="${task.id}" data-permanent="${task.permanent}" data-completed="${task.completed}">
                <input type="checkbox" class="task-checkbox" ${completedAttr} onchange="toggleTask('${task.id}')">
                <div class="task-description-wrapper">
                    ${permanentIndicator}
                    <span class="task-description ${completedAttr ? 'completed' : ''}">${task.text}</span>
                </div>
                <button class="permanent-btn" data-permanent="${task.permanent}" onclick="togglePermanent('${task.id}', ${!task.permanent})">${permanentBtnText}</button>
                <button class="remove-btn" onclick="deleteTask('${task.id}')">REMOVE</button> 
            </div>
        `;
    }

    /**
     * Updates the HTML display of user stats.
     */
    function updateStatsDisplay(data) {
        document.getElementById('tp-stats').textContent = data.tp;
        document.getElementById('sp-stats').textContent = data.sp;
        document.getElementById('daily-count-stats').textContent = data.daily_count;
        document.getElementById('user-rank-title').textContent = data.rank;
        document.getElementById('tp-display').textContent = data.tp;
        document.getElementById('sp-display-menu').textContent = data.sp;
    }

    // =========================================================
    // AJAX FUNCTIONS
    // =========================================================

    /**
     * Executes an asynchronous POST request to the server.
     */
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

    /**
     * Adds a new task to the list.
     */
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

    /**
     * Toggles the completion status of a task.
     */
    async function toggleTask(id) {
        const slot = document.getElementById(`task-${id}`);
        const isCompleted = slot.dataset.completed === 'true';

        const result = await postAction({ 
            endpoint: 'task_action', 
            action: 'toggle', 
            id: id 
        });

        if (result.success) {
            // Update UI elements based on response
            slot.dataset.completed = result.completed;
            slot.querySelector('.task-checkbox').checked = result.completed;
            slot.querySelector('.task-description').classList.toggle('completed', result.completed);
            slot.classList.toggle('completed-slot', result.completed);
            
            updateStatsDisplay(result.user_data);
            console.log(`Task ${id} toggled. Points change: ${result.points_change}`);
        } else {
            // Revert checkbox state if server failed
            slot.querySelector('.task-checkbox').checked = isCompleted;
            alert(result.message);
        }
    }

    /**
     * Deletes a task from the list.
     */
    async function deleteTask(id) {
        if (!confirm("Confirm mission REMOVE?")) return;
        
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

    /**
     * Toggles the permanent status of a task (Fix for Lock/Unlock).
     */
    async function togglePermanent(id, newPermanentStatus) {
        // newPermanentStatus is a boolean from the onclick handler (true/false)
        const result = await postAction({ 
            endpoint: 'task_action', 
            action: 'set_permanent', 
            id: id,
            // Convert boolean to string for PHP POST data
            permanent: newPermanentStatus ? 'true' : 'false' 
        });

        if (result.success) {
            const slot = document.getElementById(`task-${id}`);
            // PHP returns a boolean, so we convert it to string 'true'/'false' for dataset
            const isPermanent = result.permanent ? 'true' : 'false'; 
            slot.dataset.permanent = isPermanent;
            
            // Update indicator and button text
            const indicator = slot.querySelector('.permanent-indicator');
            const button = slot.querySelector('.permanent-btn');
            
            if (isPermanent === 'true') {
                if (!indicator) { // Add indicator if locking
                    const wrapper = slot.querySelector('.task-description-wrapper');
                    wrapper.insertAdjacentHTML('afterbegin', '<span class="permanent-indicator" title="Permanent Daily Task">🔒</span>');
                }
                button.textContent = '🔓 Unlock';
                button.setAttribute('data-permanent', 'true');
            } else {
                indicator?.remove();
                button.textContent = '🔒 Lock';
                button.setAttribute('data-permanent', 'false');
            }
        } else {
            alert(result.message);
        }
    }

    /**
     * Collects daily Diamonds.
     */
    async function collectSp() {
        const button = document.getElementById('sp-collect-btn');
        button.disabled = true; // Prevent double click

        const result = await postAction({ 
            endpoint: 'sp_collect' 
        });

        if (result.success) {
            alert(result.message);
            updateStatsDisplay({
                tp: document.getElementById('tp-stats').textContent, // TP is unchanged here
                sp: result.sp_points,
                rank: result.rank,
                daily_count: document.getElementById('daily-count-stats').textContent
            });
            button.textContent = 'Collected (💎)'; // Updated text
        } else {
            alert(result.message);
            button.disabled = false; // Re-enable if server failed
        }
    }
    
    /**
     * Saves the user's main objective.
     */
    async function saveObjective() {
        const objective = document.getElementById('objective-input').value.trim();
        const result = await postAction({ 
            endpoint: 'save_objective', 
            objective: objective 
        });
        
        if (result.success) {
            alert('Objective saved successfully!');
        } else {
            alert(result.message);
        }
    }

    // Close dropdown menu when clicking outside
    document.addEventListener('click', (event) => {
        const menu = document.getElementById('dropdown-menu');
        const button = document.querySelector('.hamburger-btn');
        if (!menu.contains(event.target) && !button.contains(event.target)) {
            menu.classList.remove('show');
        }
    });

</script>
</body>
</html>
    <?php
    return ob_get_clean(); // Return the buffered HTML
}

/**
 * Helper function to render a single task's HTML.
 * Used during initial page load in generateHtml().
 */
function renderTaskHtml($task) {
    $completedClass = $task['completed'] ? 'completed-slot' : '';
    $completedAttr = $task['completed'] ? 'checked' : '';
    $permanentIndicator = $task['permanent'] ? '<span class="permanent-indicator" title="Permanent Daily Task">🔒</span>' : '';
    $permanentBtnText = $task['permanent'] ? '🔓 Unlock' : '🔒 Lock';

    return '
        <div class="task-slot ' . $completedClass . '" id="task-' . $task['id'] . '" data-id="' . $task['id'] . '" data-permanent="' . ($task['permanent'] ? 'true' : 'false') . '" data-completed="' . ($task['completed'] ? 'true' : 'false') . '">
            <input type="checkbox" class="task-checkbox" ' . $completedAttr . ' onchange="toggleTask(\'' . $task['id'] . '\')">
            <div class="task-description-wrapper">
                ' . $permanentIndicator . '
                <span class="task-description ' . ($completedAttr ? 'completed' : '') . '">' . htmlspecialchars($task['text']) . '</span>
            </div>
            <button class="permanent-btn" data-permanent="' . ($task['permanent'] ? 'true' : 'false') . '" onclick="togglePermanent(\'' . $task['id'] . '\', ' . ($task['permanent'] ? 'false' : 'true') . ')">' . $permanentBtnText . '</button>
            <button class="remove-btn" onclick="deleteTask(\'' . $task['id'] . '\')">REMOVE</button>
        </div>
    ';
}

// --- EXECUTE MAIN APPLICATION LOGIC ---
if ($loggedInUser) {
    handleRequest($loggedInUser, $dbManager);
}

// Close the database connection
$dbManager->close();
?>
 

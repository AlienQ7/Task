<?php
// =========================================================
// DbManager.php (V2.4 - Schema Fix, Dependency Check, AND Error Handling Fix)
// =========================================================

class DbManager {
    private $db;

    public function __construct() {
        // --- Dependency Safety Check ---
        if (!defined('DB_FILE_PATH')) {
            throw new Exception("FATAL ERROR: DB_FILE_PATH constant not defined. Check config.php and its include order.");
        }
        
        // Use the user's constant DB_FILE_PATH
        $this->db = new SQLite3(DB_FILE_PATH);
        
        // Ensure tables exist.
        $this->createUsersTable();
        $this->createTasksTable();
        $this->createSessionsTable(); 
    }

    /**
     * Creates the user table and applies any necessary schema migrations.
     */
    private function createUsersTable() {
        $initialSchema = "
            CREATE TABLE IF NOT EXISTS users (
                username TEXT PRIMARY KEY,
                password_hash TEXT NOT NULL,
                sp_points INTEGER DEFAULT 0,
                last_sp_collect INTEGER DEFAULT 0,
                last_task_refresh INTEGER DEFAULT 0,
                rank TEXT DEFAULT 'Aspiring 🚀',
                user_objective TEXT DEFAULT 'Pro max programmer xd.',
                daily_completed_count INTEGER DEFAULT 0,
                
                claimed_task_points INTEGER DEFAULT 0,
                failed_points INTEGER DEFAULT 0,
                total_penalty_deduction INTEGER DEFAULT 0,
                daily_quota INTEGER DEFAULT 4,
                is_failed_system_enabled INTEGER DEFAULT 1
            );
        ";
        $this->db->exec($initialSchema);
        
        // Safe Migrations (Prevents "duplicate column name" warnings)
        $this->ensureColumnExists('users', 'claimed_task_points', 'INTEGER DEFAULT 0');
        $this->ensureColumnExists('users', 'failed_points', 'INTEGER DEFAULT 0');
        $this->ensureColumnExists('users', 'total_penalty_deduction', 'INTEGER DEFAULT 0');
        $this->ensureColumnExists('users', 'daily_quota', 'INTEGER DEFAULT 4');
        $this->ensureColumnExists('users', 'is_failed_system_enabled', 'INTEGER DEFAULT 1');
    }

    /**
     * Helper function to safely add a column to a table if it doesn't exist (SQLite safe).
     */
    private function ensureColumnExists($tableName, $columnName, $columnDefinition) {
        $result = $this->db->query("PRAGMA table_info({$tableName})");
        $exists = false;
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if ($row['name'] === $columnName) {
                $exists = true;
                break;
            }
        }

        if (!$exists) {
            $sql = "ALTER TABLE {$tableName} ADD COLUMN {$columnName} {$columnDefinition}";
            if (!$this->db->exec($sql)) {
                error_log("DbManager Error: Failed to add column {$columnName} to {$tableName}: " . $this->db->lastErrorMsg());
            }
        }
    }
    
    /**
     * Creates the tasks table.
     */
    private function createTasksTable() {
        $sql = "
            CREATE TABLE IF NOT EXISTS tasks (
                username TEXT NOT NULL,
                task_data TEXT NOT NULL,
                FOREIGN KEY (username) REFERENCES users(username)
            );
        ";
        // Check execution success to catch silent errors during table creation
        if (!$this->db->exec($sql)) {
             error_log("DbManager Error: Failed to create tasks table. SQLite Error: " . $this->db->lastErrorMsg());
        }
    }

    /**
     * Creates the sessions table.
     */
    private function createSessionsTable() {
        $sql = "
            CREATE TABLE IF NOT EXISTS sessions (
                token TEXT PRIMARY KEY,
                username TEXT NOT NULL,
                expires_at INTEGER NOT NULL
            );
        ";
        $this->db->exec($sql);
    }
    
    // =========================================================
    // CRUD Operations for Users
    // =========================================================
    
    public function userExists($username) {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM users WHERE username = :username');
        if (!$stmt) return false;
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $result = $stmt->execute()->fetchArray(SQLITE3_NUM);
        return $result[0] > 0;
    }

    public function createUser($username, $password_hash) {
        $sql = 'INSERT INTO users (username, password_hash) VALUES (:username, :password_hash)';
        $stmt = $this->db->prepare($sql);
        if (!$stmt) return false;
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $stmt->bindValue(':password_hash', $password_hash, SQLITE3_TEXT);
        return $stmt->execute();
    }

    public function getUserData($username) {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE username = :username');
        if (!$stmt) return null;
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        
        if ($result) {
            $result['claimed_task_points'] = $result['claimed_task_points'] ?? 0;
            $result['failed_points'] = $result['failed_points'] ?? 0;
            $result['total_penalty_deduction'] = $result['total_penalty_deduction'] ?? 0;
            $result['daily_quota'] = $result['daily_quota'] ?? 4;
            $result['is_failed_system_enabled'] = $result['is_failed_system_enabled'] ?? 1;
        }
        return $result ?: null;
    }

    public function getAllUsers() {
        $result = $this->db->query('SELECT * FROM users ORDER BY sp_points DESC');
        $users = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $row['task_points'] = ($row['claimed_task_points'] ?? 0) - ($row['total_penalty_deduction'] ?? 0);
            $users[] = $row;
        }
        return $users;
    }

    public function saveUserData($username, $data) {
        $sets = [];
        $params = [];
        foreach ($data as $key => $value) {
            $sets[] = "{$key} = :{$key}";
            $params[":{$key}"] = $value;
        }
        
        if (empty($sets)) return false;

        $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE username = :username';
        $params[':username'] = $username;
        
        $stmt = $this->db->prepare($sql);
        if (!$stmt) return false;
        foreach ($params as $key => $value) {
            $type = is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT;
            $stmt->bindValue($key, $value, $type);
        }
        return $stmt->execute();
    }

    public function deleteUserAndData($username) {
        $this->db->exec("DELETE FROM users WHERE username = '{$username}'");
        $this->db->exec("DELETE FROM tasks WHERE username = '{$username}'");
        $this->deleteSessionByUsername($username);
    }
    
    // =========================================================
    // CRUD Operations for Tasks (Location of the Fix)
    // =========================================================

    /**
     * Retrieves task data for a user.
     */
    public function getTasks($username, $taskType) {
        $stmt = $this->db->prepare('SELECT task_data FROM tasks WHERE username = :username');

        // *** CRITICAL FIX HERE: Check if the prepare() call failed ***
        if (!$stmt) {
            // Log the error and return empty data to prevent script crash
            error_log("DbManager Error in getTasks: Prepare failed. SQLite Error: " . $this->db->lastErrorMsg());
            return '[]'; // Return empty JSON array to prevent script crash
        }
        // *** END CRITICAL FIX ***
        
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $result['task_data'] ?? '[]'; 
    }

    /**
     * Saves task data (encoded as JSON string).
     */
    public function saveTasks($username, $taskType, $taskDataJson) {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM tasks WHERE username = :username');
        if (!$stmt) return false;
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $exists = $stmt->execute()->fetchArray(SQLITE3_NUM)[0] > 0;

        if ($exists) {
            $sql = 'UPDATE tasks SET task_data = :task_data WHERE username = :username';
        } else {
            $sql = 'INSERT INTO tasks (username, task_data) VALUES (:username, :task_data)';
        }

        $stmt = $this->db->prepare($sql);
        if (!$stmt) return false;
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $stmt->bindValue(':task_data', $taskDataJson, SQLITE3_TEXT);
        return $stmt->execute();
    }
    
    // =========================================================
    // Session Management
    // =========================================================

    public function createSession($username) {
        $expiresAt = time() + (defined('SESSION_TTL_SECONDS') ? SESSION_TTL_SECONDS : 30 * 86400); 
        $token = bin2hex(random_bytes(16));
        
        $sql = 'INSERT INTO sessions (token, username, expires_at) VALUES (:token, :username, :expires_at)';
        $stmt = $this->db->prepare($sql);
        if (!$stmt) return false;
        $stmt->bindValue(':token', $token, SQLITE3_TEXT);
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $stmt->bindValue(':expires_at', $expiresAt, SQLITE3_INTEGER);
        
        $stmt->execute();
        return $token;
    }

    public function getUsernameFromSession($token) {
        $this->db->exec('DELETE FROM sessions WHERE expires_at < ' . time());
        
        $stmt = $this->db->prepare('SELECT username FROM sessions WHERE token = :token');
        if (!$stmt) return null;
        $stmt->bindValue(':token', $token, SQLITE3_TEXT);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        
        return $result['username'] ?? null;
    }

    public function deleteSession($token) {
        $this->db->exec("DELETE FROM sessions WHERE token = '{$token}'");
    }
    
    public function deleteSessionByUsername($username) {
        $this->db->exec("DELETE FROM sessions WHERE username = '{$username}'");
    }

    public function close() {
        $this->db->close();
    }
}

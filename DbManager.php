<?php
// =========================================================
// DbManager.php (V2.0 - Using Modular Config)
// =========================================================

// Include the configuration constants
require_once 'config.php';

class DbManager {
    public $db;

    public function __construct() {
        // Use the constant defined in config.php
        $dbPath = DB_FILE_PATH; 
        try {
            // The SQLite3 constructor creates the file if it does not exist
            $this->db = new SQLite3($dbPath);
            $this->initializeDatabase();
            
        } catch (Exception $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }

    private function initializeDatabase() {
        // 1. Create 'users' table
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS users (
                username TEXT PRIMARY KEY,
                hash TEXT NOT NULL,
                user_objective TEXT DEFAULT 'Pro max programmer xd.',
                rank TEXT DEFAULT 'Aspiring 🌱',
                sp_points INTEGER DEFAULT 0,
                task_points INTEGER DEFAULT 0,
                failed_points INTEGER DEFAULT 0,
                last_sp_collect INTEGER DEFAULT 0,
                last_task_refresh INTEGER DEFAULT 0,
                daily_completed_count INTEGER DEFAULT 0
            )
        ");

        // 2. Create 'sessions' table
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS sessions (
                token TEXT PRIMARY KEY,
                username TEXT NOT NULL,
                expires_at INTEGER NOT NULL
            )
        ");

        // 3. Create 'tasks' table
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS tasks (
                username TEXT NOT NULL,
                type TEXT NOT NULL,
                task_data_json TEXT,
                PRIMARY KEY (username, type)
            )
        ");
    }
    
    // =================================================
    // CORE CRUD IMPLEMENTATIONS
    // =================================================

    public function userExists($username) {
        $stmt = $this->db->prepare("SELECT 1 FROM users WHERE username = :username");
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $result = $stmt->execute();
        $exists = $result->fetchArray(SQLITE3_ASSOC);
        $result->finalize();
        return (bool)$exists;
    }

    public function saveNewUser($username, $hash) {
        $stmt = $this->db->prepare("
            INSERT INTO users (username, hash, user_objective) 
            VALUES (:username, :hash, 'Pro max programmer xd.')
        ");
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $stmt->bindValue(':hash', $hash, SQLITE3_TEXT);
        return $stmt->execute() !== false;
    }

    public function getUserData($username) {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE username = :username");
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $result = $stmt->execute();
        $data = $result->fetchArray(SQLITE3_ASSOC);
        $result->finalize();
        return $data;
    }

    public function saveUserData($username, $userData) {
        $fields = [
            'rank', 'sp_points', 'task_points', 'failed_points', 
            'last_sp_collect', 'last_task_refresh', 'daily_completed_count', 'user_objective' 
        ];
        
        $setClauses = [];
        $bindValues = [':username' => $username];
        foreach ($fields as $field) {
            if (isset($userData[$field])) {
                $setClauses[] = "$field = :$field";
                $bindValues[":$field"] = $userData[$field];
            }
        }
        if (empty($setClauses)) return true;

        $sql = "UPDATE users SET " . implode(', ', $setClauses) . " WHERE username = :username";
        
        $stmt = $this->db->prepare($sql);
        foreach ($bindValues as $key => $value) {
            // Determine type for binding
            $type = (strpos($key, 'points') !== false || strpos($key, 'last_') !== false || strpos($key, 'daily_') !== false) 
                    ? SQLITE3_INTEGER : SQLITE3_TEXT;
            $stmt->bindValue($key, $value, $type);
        }
        return $stmt->execute() !== false;
    }

    public function createSession($token, $username, $expiry) {
        // Delete any existing session for this user for security
        $this->db->exec("DELETE FROM sessions WHERE username = '{$username}'"); 
        
        $stmt = $this->db->prepare("
            INSERT INTO sessions (token, username, expires_at) 
            VALUES (:token, :username, :expiry)
        ");
        $stmt->bindValue(':token', $token, SQLITE3_TEXT);
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $stmt->bindValue(':expiry', $expiry, SQLITE3_INTEGER);
        return $stmt->execute() !== false;
    }

    public function deleteSession($token) {
        $stmt = $this->db->prepare("DELETE FROM sessions WHERE token = :token");
        $stmt->bindValue(':token', $token, SQLITE3_TEXT);
        return $stmt->execute() !== false;
    }

    public function getUsernameFromSession($token) {
        $now = time();
        $stmt = $this->db->prepare("SELECT username FROM sessions WHERE token = :token AND expires_at > :now");
        $stmt->bindValue(':token', $token, SQLITE3_TEXT);
        $stmt->bindValue(':now', $now, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $data = $result->fetchArray(SQLITE3_ASSOC);
        $result->finalize();
        return $data['username'] ?? null;
    }

    public function saveTasks($username, $type, $json) {
        $sql = "INSERT OR REPLACE INTO tasks (username, type, task_data_json) VALUES (:username, :type, :json)";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $stmt->bindValue(':type', $type, SQLITE3_TEXT);
        $stmt->bindValue(':json', $json, SQLITE3_TEXT);
        return $stmt->execute() !== false;
    }

    public function getTasks($username, $type) {
        $stmt = $this->db->prepare("SELECT task_data_json FROM tasks WHERE username = :username AND type = :type");
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $stmt->bindValue(':type', $type, SQLITE3_TEXT);
        $result = $stmt->execute();
        $data = $result->fetchArray(SQLITE3_ASSOC);
        $result->finalize();
        return $data['task_data_json'] ?? '[]';
    }
    
    public function deleteUserAndData($username) {
        // Use prepared statements for safer deletion
        $stmt1 = $this->db->prepare("DELETE FROM users WHERE username = :username");
        $stmt2 = $this->db->prepare("DELETE FROM sessions WHERE username = :username");
        $stmt3 = $this->db->prepare("DELETE FROM tasks WHERE username = :username");
        
        $stmt1->bindValue(':username', $username, SQLITE3_TEXT);
        $stmt2->bindValue(':username', $username, SQLITE3_TEXT);
        $stmt3->bindValue(':username', $username, SQLITE3_TEXT);
        
        $stmt1->execute();
        $stmt2->execute();
        $stmt3->execute();
    }

    public function close() {
        if ($this->db) {
            $this->db->close();
        }
    }
}

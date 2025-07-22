<?php
date_default_timezone_set('Asia/Tehran');

require __DIR__ . '/../config.php';

class Database {
    private static $instances = [];
    private $connection;
    private $name;

    private function __construct($host, $user, $pass, $dbname) {
        $this->name = $dbname;
        $this->connect($host, $user, $pass, $dbname);
    }

    public static function getInstance($host, $user, $pass, $dbname) {
        $key = "$host:$dbname";
        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = new self($host, $user, $pass, $dbname);
        }
        return self::$instances[$key];
    }

    private function connect($host, $user, $pass, $dbname) {
        try {
            $this->connection = new mysqli($host, $user, $pass, $dbname);
            if ($this->connection->connect_error) {
                throw new Exception("DB connection failed: " . $this->connection->connect_error);
            }
            $this->connection->set_charset("utf8mb4");
        } catch (Exception $e) {
            $this->logError($e->getMessage());
            exit;
        }
    }

    public function getConnection() {
        return $this->connection;
    }

    public function logError($message) {
        file_put_contents('logs.txt', date('Y-m-d H:i:s') . " - [$this->name] $message\n", FILE_APPEND);
    }

    public function __destruct() {
        $this->connection->close();
    }
}

class Notification {
    private $apiURL;
    private $dbBot;
    private const HEADERS = ["Content-Type: application/json"];

    public function __construct($apiURL, $dbBot) {
        $this->apiURL = $apiURL;
        $this->dbBot = $dbBot;
    }

    public function sendMessage($chat_id, $message) {
        $parameters = [
            'chat_id' => $chat_id,
            'text' => $message
        ];
        $method = 'sendMessage';
        $result = $this->sendRequest($method, $parameters);
        
        if (!$result) {
            $this->logError("Failed to send message to chat_id $chat_id: $message");
        }
        return $result;
    }

    public function sendInlineKeyboard($chat_id, $message, $keyboard) {
        $parameters = [
            'chat_id' => $chat_id,
            'text' => $message,
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ];
        $method = 'sendMessage';
        $result = $this->sendRequest($method, $parameters);
        
        if (!$result) {
            $this->logError("Failed to send inline keyboard to chat_id $chat_id: $message");
        }
        return $result;
    }

    private function sendRequest($method, $parameters) {
        try {
            $url = $this->apiURL . $method;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POSTFIELDS => json_encode($parameters),
                CURLOPT_HTTPHEADER => self::HEADERS,
                CURLOPT_RETURNTRANSFER => true
            ]);
            
            $response = curl_exec($ch);
            if (curl_errno($ch)) {
                throw new Exception("cURL error: " . curl_error($ch));
            }
            curl_close($ch);
            
            $result = json_decode($response, true);
            $this->updateMessageId($result, $parameters);
            return $result;
        } catch (Exception $e) {
            $this->logError($e->getMessage());
            return false;
        }
    }

    private function updateMessageId($result, $parameters) {
        if (isset($result['result']['message_id']) && isset($parameters['chat_id'])) {
            $messageId = $result['result']['message_id'];
            $userId = $parameters['chat_id'];
            
            $stmt = $this->dbBot->prepare("UPDATE user_states SET message_id = ? WHERE user_id = ?");
            if ($stmt) {
                $stmt->bind_param("ii", $messageId, $userId);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    private function logError($message) {
        file_put_contents('logs.txt', date('Y-m-d H:i:s') . " - Notification: $message\n", FILE_APPEND);
    }
}

class PanelManager {
    private $dbMarzban;
    private $dbBot;
    private $notification;
   # private $languages;
    private $allowedUsers;
    private const INFINITY = '♾️';

    public function __construct($dbMarzban, $dbBot, $notification,/* $languages,*/ $allowedUsers) {
        $this->dbMarzban = $dbMarzban;
        $this->dbBot = $dbBot;
        $this->notification = $notification;
       # $this->languages = $languages;
        $this->allowedUsers = $allowedUsers;
    }

    private function getLang($userId) {
        $langCode = 'en';
    
        $stmt = $this->dbBot->prepare("SELECT lang FROM user_states WHERE user_id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $userId);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    if (in_array($row['lang'], ['fa', 'en', 'ru'])) {
                        $langCode = $row['lang'];
                    }
                }
            } else {
                $this->dbBot->logError("Error executing statement: " . $stmt->error);
            }
            $stmt->close();
        } else {
            $this->dbBot->logError("Error preparing statement: " . $this->dbBot->error);
        }
    
        $languageFile = dirname(__DIR__) . "/app/language/{$langCode}.php";
    
        if (file_exists($languageFile)) {
            $language = include $languageFile;
            return $language;
        }
    
        return include dirname(__DIR__) . "/app/language/en.php";
    }

    private function fetchTelegramId($adminId) {
        $stmt = $this->dbMarzban->prepare("SELECT telegram_id FROM admins WHERE id = ?");
        $stmt->bind_param("i", $adminId);
        $stmt->execute();
        $result = $stmt->get_result();
        $telegramId = $result->num_rows > 0 ? $result->fetch_assoc()['telegram_id'] : null;
        $stmt->close();
        return $telegramId;
    }

    private function getAdminInfo($adminId) {
        try {
            $adminData = $this->fetchAdminData($adminId);
            if (!$adminData) return false;
    
            $trafficData = $this->calculateTraffic($adminId);
            $settings = $this->fetchSettings($adminId);
            $userStats = $this->fetchUserStats($adminId);
    
            return $this->formatAdminInfo($adminId, $adminData, $trafficData, $settings, $userStats);
        } catch (Exception $e) {
            $this->dbMarzban->logError($e->getMessage());
            return false;
        }
    }

    private function fetchAdminData($adminId) {
        $stmt = $this->dbMarzban->prepare("SELECT username FROM admins WHERE id = ?");
        $stmt->bind_param("i", $adminId);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->num_rows > 0 ? $result->fetch_assoc() : false;
        $stmt->close();
        return $data;
    }

    private function calculateTraffic($adminId) {
        $stmtSettings = $this->dbBot->prepare("SELECT calculate_volume FROM admin_settings WHERE admin_id = ?");
        $stmtSettings->bind_param("i", $adminId);
        $stmtSettings->execute();
        $settingsResult = $stmtSettings->get_result();
        $settings = $settingsResult->fetch_assoc();
        $stmtSettings->close();
    
        $calculateVolume = $settings['calculate_volume'] ?? 'used_traffic';
    
        if ($calculateVolume === 'used_traffic') {
            $stmt = $this->dbMarzban->prepare("
                SELECT (
                    IFNULL((SELECT SUM(users.used_traffic) FROM users WHERE users.admin_id = admins.id), 0) +
                    IFNULL((SELECT SUM(user_usage_logs.used_traffic_at_reset) FROM user_usage_logs 
                            WHERE user_usage_logs.user_id IN (SELECT id FROM users WHERE users.admin_id = admins.id)), 0) +
                    IFNULL((SELECT SUM(user_deletions.used_traffic) + SUM(user_deletions.reseted_usage) 
                            FROM user_deletions WHERE user_deletions.admin_id = admins.id), 0)
                ) / 1073741824 AS used_traffic_gb
                FROM admins WHERE admins.id = ?");
            $stmt->bind_param("i", $adminId);
        } else { 
            $stmt = $this->dbMarzban->prepare("
                SELECT (
                    IFNULL((SELECT SUM(
                        CASE 
                            WHEN users.data_limit IS NOT NULL THEN users.data_limit 
                            ELSE users.used_traffic 
                        END
                    ) FROM users WHERE users.admin_id = admins.id), 0) +
                    IFNULL((SELECT SUM(user_usage_logs.used_traffic_at_reset) FROM user_usage_logs 
                            WHERE user_usage_logs.user_id IN (SELECT id FROM users WHERE users.admin_id = admins.id)), 0) +
                    IFNULL((SELECT SUM(user_deletions.reseted_usage) FROM user_deletions WHERE user_deletions.admin_id = admins.id), 0)
                ) / 1073741824 AS created_traffic_gb
                FROM admins WHERE admins.id = ?");
            $stmt->bind_param("i", $adminId);
        }
    
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();
    
        return $data;
    }

    private function fetchSettings($adminId) {
        $stmt = $this->dbBot->prepare("SELECT total_traffic, expiry_date, status, user_limit, calculate_volume, hashed_password_before, 
                                      last_traffic_notification, last_expiry_notification 
                                      FROM admin_settings WHERE admin_id = ?");
        $stmt->bind_param("i", $adminId);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();
        return $data;
    }

    private function fetchUserStats($adminId) {
        $stmt = $this->dbMarzban->prepare("
            SELECT COUNT(*) AS total_users,
                   SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_users,
                   SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) AS expired_users,
                   SUM(CASE WHEN TIMESTAMPDIFF(MINUTE, online_at, NOW()) <= 5 THEN 1 ELSE 0 END) AS online_users
            FROM users WHERE admin_id = ?");
        $stmt->bind_param("i", $adminId);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();
        return $data;
    }

    private function formatAdminInfo($adminId, $admin, $traffic, $settings, $userStats) {
        $calculateVolume = $settings['calculate_volume'] ?? 'used_traffic';
    
        if ($calculateVolume === 'used_traffic') {
            $usedTraffic = round($traffic['used_traffic_gb'] ?? 0, 2);
        } else {
            $usedTraffic = round($traffic['created_traffic_gb'] ?? 0, 2);
        }
    
        $totalTraffic = $settings['total_traffic'] > 0 ? round($settings['total_traffic'] / 1073741824, 2) : self::INFINITY;
        $remainingTraffic = $totalTraffic !== self::INFINITY ? round($totalTraffic - $usedTraffic, 2) : self::INFINITY;
        
        $expiryDate = $settings['expiry_date'] ?? self::INFINITY;
        $daysLeft = $expiryDate !== self::INFINITY ? ceil((strtotime($expiryDate) - time()) / 86400) : self::INFINITY;
        
        $userLimit = $settings['user_limit'] ?? 0;
        
        return [
            'username' => $admin['username'],
            'userid' => $adminId,
            'usedTraffic' => $usedTraffic,
            'totalTraffic' => $totalTraffic,
            'remainingTraffic' => $remainingTraffic,
            'expiryDate' => $expiryDate,
            'daysLeft' => $daysLeft,
            'status' => $settings['status'] ?? 'active',
            'hashed_password_before' => $settings['hashed_password_before'] ?? null, 
            'last_traffic_notification' => $settings['last_traffic_notification'] ?? null,
            'last_expiry_notification' => $settings['last_expiry_notification'] ?? null,
            'userStats' => $userStats 
        ];
    }

    public function gettingadmininfo($adminId) {
        $stmtAdmin = $this->dbMarzban->prepare("SELECT username FROM admins WHERE id = ?");
        $stmtAdmin->bind_param("i", $adminId);
        $stmtAdmin->execute();
        $adminResult = $stmtAdmin->get_result();
        if ($adminResult->num_rows === 0) {
            $stmtAdmin->close();
            return false;
        }
        $admin = $adminResult->fetch_assoc();
        $adminUsername = $admin['username'];
        $stmtAdmin->close();
    
        $stmtSettings = $this->dbBot->prepare("SELECT total_traffic, expiry_date, status, user_limit, calculate_volume FROM admin_settings WHERE admin_id = ?");
        $stmtSettings->bind_param("i", $adminId);
        $stmtSettings->execute();
        $settingsResult = $stmtSettings->get_result();
        $settings = $settingsResult->fetch_assoc() ?: [];
        $stmtSettings->close();
    
        $calculateVolume = $settings['calculate_volume'] ?? 'used_traffic';
    
        if ($calculateVolume === 'used_traffic') {
            $stmtTraffic = $this->dbMarzban->prepare("
                SELECT admins.username, 
                (
                    IFNULL((SELECT SUM(users.used_traffic) FROM users WHERE users.admin_id = admins.id), 0) +
                    IFNULL((SELECT SUM(user_usage_logs.used_traffic_at_reset) FROM user_usage_logs 
                            WHERE user_usage_logs.user_id IN (SELECT id FROM users WHERE users.admin_id = admins.id)), 0) +
                    IFNULL((SELECT SUM(user_deletions.used_traffic) + SUM(user_deletions.reseted_usage) 
                            FROM user_deletions WHERE user_deletions.admin_id = admins.id), 0)
                ) / 1073741824 AS used_traffic_gb
                FROM admins
                WHERE admins.id = ?
                GROUP BY admins.username, admins.id");
        } else {
            $stmtTraffic = $this->dbMarzban->prepare("
            SELECT admins.username, 
            (
                (
                    SELECT IFNULL(SUM(
                        CASE 
                            WHEN users.data_limit IS NOT NULL THEN users.data_limit 
                            ELSE users.used_traffic 
                        END
                    ), 0)
                    FROM users
                    WHERE users.admin_id = admins.id
                )
                +
                (
                    SELECT IFNULL(SUM(user_usage_logs.used_traffic_at_reset), 0)
                    FROM user_usage_logs
                    WHERE user_usage_logs.user_id IN (
                        SELECT id FROM users WHERE users.admin_id = admins.id
                    )
                )
                +
                (
                    SELECT IFNULL(SUM(user_deletions.reseted_usage), 0)
                    FROM user_deletions
                    WHERE user_deletions.admin_id = admins.id
                )
            ) / 1073741824 AS created_traffic_gb
            FROM admins
            WHERE admins.id = ?
            GROUP BY admins.username, admins.id;
                ");
        }
    
        $stmtTraffic->bind_param("i", $adminId);
        $stmtTraffic->execute();
        $trafficResult = $stmtTraffic->get_result();
        $trafficData = $trafficResult->fetch_assoc() ?: [];
        $stmtTraffic->close();
    
        $usedTraffic = isset($trafficData['used_traffic_gb']) ? round($trafficData['used_traffic_gb'], 2) : (isset($trafficData['created_traffic_gb']) ? round($trafficData['created_traffic_gb'], 2) : 0);
        $totalTraffic = isset($settings['total_traffic']) && $settings['total_traffic'] > 0 ? round($settings['total_traffic'] / 1073741824, 2) : self::INFINITY;
        $remainingTraffic = $totalTraffic !== self::INFINITY ? round($totalTraffic - $usedTraffic, 2) : self::INFINITY;
    
        $expiryDate = $settings['expiry_date'] ?? self::INFINITY;
        $daysLeft = $expiryDate !== self::INFINITY ? ceil((strtotime($expiryDate) - time()) / 86400) : self::INFINITY;
    
        $statusArray = isset($settings['status']) ? json_decode($settings['status'], true) : ['time' => 'active', 'data' => 'active', 'users' => 'active'];
        $status = $statusArray['users'] ?? 'active';
    
        $stmtUserStats = $this->dbMarzban->prepare("
            SELECT
                COUNT(*) AS total_users,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_users,
                SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) AS expired_users,
                SUM(CASE WHEN TIMESTAMPDIFF(MINUTE, NOW(), online_at) <= 5 THEN 1 ELSE 0 END) AS online_users
            FROM users
            WHERE admin_id = ?");
        $stmtUserStats->bind_param("i", $adminId);
        $stmtUserStats->execute();
        $userStatsResult = $stmtUserStats->get_result();
        $userStats = $userStatsResult->fetch_assoc() ?: ['total_users' => 0, 'active_users' => 0, 'expired_users' => 0, 'online_users' => 0];
        $stmtUserStats->close();
    
        $userLimit = $settings['user_limit'] ?? self::INFINITY;
        $remainingUserLimit = $userLimit !== self::INFINITY ? $userLimit - $userStats['active_users'] : self::INFINITY;
    
        $preventUserCreation = $this->triggerCheck('prevent_user_creation', $adminId);
        $preventUserReset = $this->triggerCheck('prevent_User_Reset_Usage', $adminId);
        $preventRevokeSubscription = $this->triggerCheck('prevent_revoke_subscription', $adminId);
        $preventUnlimitedTraffic = $this->triggerCheck('prevent_unlimited_traffic', $adminId);
        $preventUserDelete = $this->triggerCheck('admin_delete', $adminId);
    
        return [
            'username' => $adminUsername,
            'userid' => $adminId,
            'usedTraffic' => $usedTraffic,
            'totalTraffic' => $totalTraffic,
            'remainingTraffic' => $remainingTraffic,
            'expiryDate' => $expiryDate,
            'daysLeft' => $daysLeft,
            'status' => $status,
            'userLimit' => $userLimit,
            'remainingUserLimit' => $remainingUserLimit,
            'preventUserReset' => $preventUserReset,
            'preventUserCreation' => $preventUserCreation,
            'preventUserDeletion' => $preventUserDelete,
            'preventRevokeSubscription' => $preventRevokeSubscription,
            'preventUnlimitedTraffic' => $preventUnlimitedTraffic,
            'userStats' => $userStats
        ];
    }

    private function triggerCheck($triggerName, $adminId) {
        $existingTriggerQuery = $this->dbMarzban->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = '$triggerName'");
        if ($existingTriggerQuery && $existingTriggerQuery->num_rows > 0) {
            $triggerResult = $this->dbMarzban->query("SHOW CREATE TRIGGER `$triggerName`");
            if ($triggerResult && $triggerResult->num_rows > 0) {
                $triggerRow = $triggerResult->fetch_assoc();
                $triggerBody = $triggerRow['SQL Original Statement'];
                if (preg_match("/IN\s*\((.*?)\)/", $triggerBody, $matches)) {
                    $adminIdsStr = $matches[1];
                    $adminIdsStr = str_replace(' ', '', $adminIdsStr);
                    $adminIds = explode(',', $adminIdsStr);
                    return in_array((string)$adminId, $adminIds);
                }
            }
        }
        return false;
    }

    private function getAdminKeyboard($adminId, $status) {
        $telegramId = $this->fetchTelegramId($adminId);
        if ($telegramId) {
            $lang = $this->getLang($telegramId);
        } else {
            $firstOwnerId = reset($this->allowedUsers);
            $lang = $this->getLang($firstOwnerId);
        }
    
        $stmt = $this->dbBot->prepare("SELECT status, hashed_password_before FROM admin_settings WHERE admin_id = ?");
        $stmt->bind_param("i", $adminId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
    
        $currentStatus = $row && $row['status'] ? json_decode($row['status'], true) : ['time' => 'active', 'data' => 'active', 'users' => 'active'];
        $usersButtonText = ($currentStatus['users'] === 'active') ? $lang['disable_users_button'] : $lang['enable_users_button'];
    
        $hashedPasswordBefore = $row['hashed_password_before'] ?? null;
        $passwordButtonText = ($hashedPasswordBefore) ? $lang['restore_password'] : $lang['change_password_temp'];
    
        return [
            [
                ['text' => $usersButtonText, 'callback_data' => ($currentStatus['users'] === 'active') ? "disable_users_{$adminId}" : "enable_users_{$adminId}"],
                ['text' => $passwordButtonText, 'callback_data' => ($hashedPasswordBefore) ? "restore_password_{$adminId}" : "change_password_{$adminId}"]
            ]
        ];
    }

    private function managePanelExtension($adminId, $adminInfo) {
        if ($adminInfo['expiryDate'] === self::INFINITY) return;

        $expiryTimestamp = strtotime($adminInfo['expiryDate']);
        $daysLeft = ceil(($expiryTimestamp - time()) / 86400); 

        $currentStatus = json_decode($adminInfo['status'], true) ?? ['time' => 'active', 'data' => 'active', 'users' => 'active'];

        if ($daysLeft <= 0 && $currentStatus['time'] !== 'expired') {
            $telegramId = $this->fetchTelegramId($adminId);
            if ($telegramId) {
                $lang = $this->getLang($telegramId);
            } else {
                $firstOwnerId = reset($this->allowedUsers);
                $lang = $this->getLang($firstOwnerId);
            }
            $message = sprintf($lang['panel_expired_notify'], $adminInfo['username'], $adminId);

            $keyboard = $this->getAdminKeyboard($adminId, $currentStatus);

            foreach ($this->allowedUsers as $ownerId) {
                $this->notification->sendInlineKeyboard($ownerId, $message, $keyboard);
            }

            $currentStatus['time'] = 'expired';
            $newStatus = json_encode($currentStatus);

            $stmt = $this->dbBot->prepare("UPDATE admin_settings SET status = ? WHERE admin_id = ?");
            if ($stmt) {
                $stmt->bind_param("si", $newStatus, $adminId);
                $stmt->execute();
                $stmt->close();
            }
        } elseif ($daysLeft > 0 && $currentStatus['time'] === 'expired') {
            $currentStatus['time'] = 'active';
            $newStatus = json_encode($currentStatus);

            $stmt = $this->dbBot->prepare("UPDATE admin_settings SET status = ? WHERE admin_id = ?");
            if ($stmt) {
                $stmt->bind_param("si", $newStatus, $adminId);
                $stmt->execute();
                $stmt->close();
            }

            $telegramId = $this->fetchTelegramId($adminId);
            if ($telegramId) {
                $lang = $this->getLang($telegramId);
            } else {
                $firstOwnerId = reset($this->allowedUsers);
                $lang = $this->getLang($firstOwnerId);
            }
            $message = sprintf($lang['panel_renewed_notify'], $adminInfo['username'], $adminId);
            foreach ($this->allowedUsers as $ownerId) {
                $this->notification->sendMessage($ownerId, $message);
            }
        }
    }

    private function dropTriggerIfExists($triggerName) {
        $this->dbMarzban->query("DROP TRIGGER IF EXISTS `$triggerName`");
    }

    private function manageTrigger($adminId) {
        $triggerNames = [
            'user_creation_traffic',
            'user_update_traffic'  
        ];
    
        foreach ($triggerNames as $triggerName) {
            $existingTriggerQuery = $this->dbMarzban->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = '$triggerName'");
            $existingAdminIds = [];
    
            if ($existingTriggerQuery && $existingTriggerQuery->num_rows > 0) {
                $triggerResult = $this->dbMarzban->query("SHOW CREATE TRIGGER `$triggerName`");
                if ($triggerResult && $triggerResult->num_rows > 0) {
                    $triggerRow = $triggerResult->fetch_assoc();
                    $triggerBody = $triggerRow['SQL Original Statement'];
                    if (preg_match("/IN\s*\((.*?)\)/", $triggerBody, $matches)) {
                        $existingAdminIdsStr = $matches[1];
                        $existingAdminIdsStr = str_replace(' ', '', $existingAdminIdsStr);
                        $existingAdminIds = explode(',', $existingAdminIdsStr);
                    }
                }
            }
    
            if (!in_array($adminId, $existingAdminIds)) {
                $existingAdminIds[] = $adminId;
            }
    
            if (!empty($existingAdminIds)) {
                $adminIdsStr = implode(', ', $existingAdminIds);
                $actionType = ($triggerName === 'user_creation_traffic') ? 'INSERT' : 'UPDATE';
    
                $triggerBody = "
                CREATE TRIGGER `$triggerName` BEFORE $actionType ON `users`
                FOR EACH ROW
                BEGIN
                    IF NEW.admin_id IN ($adminIdsStr) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Operation not allowed for this admin ID.';
                    END IF;
                END;
                ";
    
                if (!$existingTriggerQuery || $existingTriggerQuery->num_rows == 0) {
                    $this->dbMarzban->query($triggerBody);
                    if ($this->dbMarzban->error) {
                        file_put_contents('logs.txt', date('Y-m-d H:i:s') . " - SQL Error: " . $this->dbMarzban->error . " - Query: $triggerBody\n", FILE_APPEND);
                    }
                }
            }
        }
    }

    private function createTrafficTriggers() {
        $stmt = $this->dbBot->prepare("SELECT admin_id, calculate_volume, total_traffic FROM admin_settings WHERE total_traffic IS NOT NULL AND total_traffic > 0");
        $stmt->execute();
        $result = $stmt->get_result();
        $admins = [];
        while ($row = $result->fetch_assoc()) {
            $admins[$row['admin_id']] = [
                'calculate_volume' => $row['calculate_volume'],
                'total_traffic' => $row['total_traffic']
            ];
        }
        $stmt->close();

        $triggerNames = [
            'prevent_insert_traffic' => 'INSERT',
            'prevent_update_traffic' => 'UPDATE'
        ];

        foreach ($triggerNames as $triggerName => $actionType) {
            $existingAdminIds = [];
            $triggerCheck = $this->dbMarzban->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = '$triggerName'");
            if ($triggerCheck && $triggerCheck->num_rows > 0) {
                $triggerResult = $this->dbMarzban->query("SHOW CREATE TRIGGER `$triggerName`");
                if ($triggerResult && $triggerResult->num_rows > 0) {
                    $triggerRow = $triggerResult->fetch_assoc();
                    $triggerBody = $triggerRow['SQL Original Statement'];
                    if (preg_match("/CASE NEW.admin_id\s*(.*?)\s*ELSE SET max_limit = 0;\s*END CASE;/s", $triggerBody, $matches)) {
                        $caseBlock = $matches[1];
                        preg_match_all("/WHEN (\d+) THEN SET max_limit = (\d+);/", $caseBlock, $adminMatches, PREG_SET_ORDER);
                        foreach ($adminMatches as $match) {
                            $existingAdminIds[$match[1]] = $match[2];
                        }
                    }
                }
            }

            $requiredAdminIds = array_filter($admins, function($admin) {
                return $admin['calculate_volume'] === 'created_traffic';
            });

            $updatedAdminIds = [];
            foreach ($requiredAdminIds as $adminId => $admin) {
                if (isset($admin['total_traffic']) && is_numeric($admin['total_traffic']) && $admin['total_traffic'] > 0) {
                $updatedAdminIds[$adminId] = $admin;
                } else {
                    file_put_contents('logs.txt', date('Y-m-d H:i:s') . " - Invalid total_traffic for admin_id $adminId: " . var_export($admin['total_traffic'], true) . "\n", FILE_APPEND);
                }
            }

            if (empty($updatedAdminIds)) {
                $this->dbMarzban->query("DROP TRIGGER IF EXISTS `$triggerName`");
                file_put_contents('logs.txt', date('Y-m-d H:i:s') . " - No valid admins for trigger $triggerName, dropping trigger.\n", FILE_APPEND);
            } else {
                $caseStatements = '';
                foreach ($updatedAdminIds as $adminId => $admin) {
                    $maxLimit = (int)$admin['total_traffic'];
                    $caseStatements .= "WHEN $adminId THEN SET max_limit = $maxLimit;\n";
                }

            if ($actionType === 'INSERT') {
                $triggerBody = "
                CREATE TRIGGER `$triggerName` BEFORE $actionType ON `users`
                FOR EACH ROW
                BEGIN
                    DECLARE total_data_limit BIGINT DEFAULT 0;
                    DECLARE max_limit BIGINT DEFAULT 0;

                    CASE NEW.admin_id
                        $caseStatements
                        ELSE SET max_limit = 0;
                    END CASE;

                    IF max_limit > 0 THEN
                        SELECT COALESCE(SUM(data_limit), 0) INTO total_data_limit
                        FROM users
                        WHERE admin_id = NEW.admin_id;

                        IF (total_data_limit + NEW.data_limit) > max_limit THEN
                            SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Admin has exceeded the total data limit.';
                        END IF;
                    END IF;
                END;
                ";
                } else { // UPDATE
                    $triggerBody = "
                    CREATE TRIGGER `$triggerName` BEFORE $actionType ON `users`
                    FOR EACH ROW
                    BEGIN
                        DECLARE total_data_limit BIGINT DEFAULT 0;
                        DECLARE max_limit BIGINT DEFAULT 0;

                        CASE NEW.admin_id
                            $caseStatements
                            ELSE SET max_limit = 0;
                        END CASE;

                        IF max_limit > 0 THEN
                            IF (NEW.used_traffic <=> OLD.used_traffic AND 
                                NEW.sub_updated_at <=> OLD.sub_updated_at AND 
                                NEW.last_status_change <=> OLD.last_status_change AND 
                                NEW.sub_last_user_agent <=> OLD.sub_last_user_agent AND 
                                NEW.online_at <=> OLD.online_at AND 
                                NEW.edit_at <=> OLD.edit_at AND 
                                NEW.data_limit = OLD.data_limit AND 
                                NEW.admin_id = OLD.admin_id) THEN
                                -- Allow update if only specified columns are changed
                                SET max_limit = 0;
                            ELSE
                                SELECT COALESCE(SUM(data_limit), 0) INTO total_data_limit
                                FROM users
                                WHERE admin_id = NEW.admin_id;

                                IF (total_data_limit + NEW.data_limit - OLD.data_limit) > max_limit THEN
                                    SIGNAL SQLSTATE '45000'
                                    SET MESSAGE_TEXT = 'Admin has exceeded the total data limit.';
                                END IF;
                            END IF;
                        END IF;
                    END;
                    ";

                $this->dbMarzban->query("DROP TRIGGER IF EXISTS `$triggerName`");
                $result = $this->dbMarzban->query($triggerBody);

                if ($this->dbMarzban->error) {
                    file_put_contents('logs.txt', date('Y-m-d H:i:s') . " - SQL Error: " . $this->dbMarzban->error . " - Query: $triggerBody\n", FILE_APPEND);
                }
            }
        }
    }
}
    private function manageCreatedTrafficTrigger($adminId, $insertTriggerName = 'user_creation_traffic', $updateTriggerName = 'user_update_traffic') {
        $stmtSettings = $this->dbBot->prepare("SELECT calculate_volume, total_traffic FROM admin_settings WHERE admin_id = ?");
        $stmtSettings->bind_param("i", $adminId);
        $stmtSettings->execute();
        $settingsResult = $stmtSettings->get_result();
        $settings = $settingsResult->fetch_assoc();
        $stmtSettings->close();
    
        if (!$settings) return;
    
        $calculateVolume = $settings['calculate_volume'] ?? 'used_traffic';
        $totalTrafficBytes = $settings['total_traffic'];
    
        if ($totalTrafficBytes === null) return;
    
        if ($calculateVolume === 'used_traffic') {
            $stmtTraffic = $this->dbMarzban->prepare("
                SELECT admins.username, 
                (
                    IFNULL((SELECT SUM(users.used_traffic) FROM users WHERE users.admin_id = admins.id), 0) +
                    IFNULL((SELECT SUM(user_usage_logs.used_traffic_at_reset) FROM user_usage_logs 
                            WHERE user_usage_logs.user_id IN (SELECT id FROM users WHERE users.admin_id = admins.id)), 0) +
                    IFNULL((SELECT SUM(user_deletions.used_traffic) + SUM(user_deletions.reseted_usage) 
                            FROM user_deletions WHERE user_deletions.admin_id = admins.id), 0)
                ) / 1073741824 AS used_traffic_gb
                FROM admins
                WHERE admins.id = ?
                GROUP BY admins.username, admins.id");
        } else {
            $stmtTraffic = $this->dbMarzban->prepare("
            SELECT admins.username, 
            (
                (
                    SELECT IFNULL(SUM(
                        CASE 
                            WHEN users.data_limit IS NOT NULL THEN users.data_limit 
                            ELSE users.used_traffic 
                        END
                    ), 0)
                    FROM users
                    WHERE users.admin_id = admins.id
                )
                +
                (
                    SELECT IFNULL(SUM(user_usage_logs.used_traffic_at_reset), 0)
                    FROM user_usage_logs
                    WHERE user_usage_logs.user_id IN (
                        SELECT id FROM users WHERE users.admin_id = admins.id
                    )
                )
                +
                (
                    SELECT IFNULL(SUM(user_deletions.reseted_usage), 0)
                    FROM user_deletions
                    WHERE user_deletions.admin_id = admins.id
                )
            ) / 1073741824 AS created_traffic_gb
            FROM admins
            WHERE admins.id = ?
            GROUP BY admins.username, admins.id;");
        }
    
        $stmtTraffic->bind_param("i", $adminId);
        $stmtTraffic->execute();
        $trafficResult = $stmtTraffic->get_result();
        $trafficData = $trafficResult->fetch_assoc();
        $stmtTraffic->close();

        $adminInfo = $this->gettingadmininfo($adminId);
        $remainingTraffic = $adminInfo['remainingTraffic'];

        $isOverLimit = $remainingTraffic <= 0;

        if ($isOverLimit) {
            $this->manageTrigger($adminId);
            
        } else {
            $this->dropTriggerIfExists($insertTriggerName);
            $this->dropTriggerIfExists($updateTriggerName);
        }
    }

    public function manageTrafficUsage($adminId, $adminInfo) {
        $stmt = $this->dbBot->prepare("SELECT calculate_volume, status, total_traffic FROM admin_settings WHERE admin_id = ?");
        $stmt->bind_param("i", $adminId);
        $stmt->execute();
        $result = $stmt->get_result();
        $settings = $result->fetch_assoc();
        $stmt->close();
    
        $calculateVolume = $settings['calculate_volume'] ?? 'used_traffic';
        $currentStatus = json_decode($settings['status'], true) ?? ['data' => 'active', 'time' => 'active', 'users' => 'active'];
        $totalTraffic = $settings['total_traffic'] > 0 ? round($settings['total_traffic'] / 1073741824, 2) : self::INFINITY;
    
        $usedTraffic = round($adminInfo['usedTraffic'], 2);
        $remainingTraffic = $totalTraffic !== self::INFINITY ? round($totalTraffic - $usedTraffic, 2) : self::INFINITY;
    
        if ($remainingTraffic <= 0 && $currentStatus['data'] !== 'exhausted') {
            $lang = $this->getLang(reset($this->allowedUsers));
            $message = sprintf($lang['traffic_exhausted_notify'], $adminInfo['username'], $adminId);
            $keyboard = $this->getAdminKeyboard($adminId, $currentStatus);
    
            foreach ($this->allowedUsers as $ownerId) {
                $this->notification->sendInlineKeyboard($ownerId, $message, $keyboard);
                
            }
    
            $currentStatus['data'] = 'exhausted';
            $newStatus = json_encode($currentStatus);
            $stmt = $this->dbBot->prepare("UPDATE admin_settings SET status = ? WHERE admin_id = ?");
            $stmt->bind_param("si", $newStatus, $adminId);
            $stmt->execute();
            $stmt->close();
    
            $this->manageCreatedTrafficTrigger($adminId); 
        } elseif ($remainingTraffic > 0 && $currentStatus['data'] === 'exhausted') {
            $currentStatus['data'] = 'active';
            $newStatus = json_encode($currentStatus);
            $stmt = $this->dbBot->prepare("UPDATE admin_settings SET status = ? WHERE admin_id = ?");
            $stmt->bind_param("si", $newStatus, $adminId);
            $stmt->execute();
            $stmt->close();
    
            $this->dropTriggerIfExists('user_creation_traffic');
            $this->dropTriggerIfExists('user_update_traffic');
        }
    
        return $remainingTraffic;
    }

    private function notifyAdmins() {
        $admins = $this->dbMarzban->query("SELECT id, telegram_id FROM admins WHERE telegram_id IS NOT NULL");
        while ($admin = $admins->fetch_assoc()) {
            $adminId = $admin['id'];
            $telegramId = $admin['telegram_id'];
            $adminInfo = $this->getAdminInfo($adminId);
            if (!$adminInfo) continue;

            $this->notifyTraffic($adminId, $adminInfo, $telegramId);
            $this->notifyExpiry($adminId, $adminInfo, $telegramId);
        }
    }

    private function notifyTraffic($adminId, $adminInfo, $telegramId) {
        if ($adminInfo['totalTraffic'] === self::INFINITY) return;

        $remainingTraffic = $adminInfo['remainingTraffic'];
        if (!is_numeric($remainingTraffic)) return;

        $lastTrafficNotification = $adminInfo['last_traffic_notification'];

        if ($remainingTraffic > 300 && $lastTrafficNotification !== null) {
            $stmt = $this->dbBot->prepare("UPDATE admin_settings SET last_traffic_notification = NULL WHERE admin_id = ?");
            $stmt->bind_param("i", $adminId);
            $stmt->execute();
            $stmt->close();
            return;
        }

        if ($remainingTraffic <= 300 && $remainingTraffic > 200 && $lastTrafficNotification != 300) {
            $lang = $this->getLang($telegramId);
            $message = sprintf($lang['traffic_warning'], $adminInfo['username'], 300);
            $this->notification->sendMessage($telegramId, $message);

            $threshold = 300;
            $stmt = $this->dbBot->prepare("UPDATE admin_settings SET last_traffic_notification = ? WHERE admin_id = ?");
            $stmt->bind_param("ii", $threshold, $adminId);
            $stmt->execute();
            $stmt->close();
        } elseif ($remainingTraffic <= 200 && $remainingTraffic > 100 && $lastTrafficNotification != 200) {
            $lang = $this->getLang($telegramId);
            $message = sprintf($lang['traffic_warning'], $adminInfo['username'], 200);
            $this->notification->sendMessage($telegramId, $message);

            $threshold = 200;
            $stmt = $this->dbBot->prepare("UPDATE admin_settings SET last_traffic_notification = ? WHERE admin_id = ?");
            $stmt->bind_param("ii", $threshold, $adminId);
            $stmt->execute();
            $stmt->close();
        } elseif ($remainingTraffic <= 100 && $remainingTraffic > 0 && $lastTrafficNotification != 100) {
            $lang = $this->getLang($telegramId);
            $message = sprintf($lang['traffic_warning'], $adminInfo['username'], 100);
            $this->notification->sendMessage($telegramId, $message);

            $threshold = 100;
            $stmt = $this->dbBot->prepare("UPDATE admin_settings SET last_traffic_notification = ? WHERE admin_id = ?");
            $stmt->bind_param("ii", $threshold, $adminId);
            $stmt->execute();
            $stmt->close();
        }
    }

    private function notifyExpiry($adminId, $adminInfo, $telegramId) {
        if ($adminInfo['expiryDate'] === self::INFINITY) return;

        $daysLeft = $adminInfo['daysLeft'];
        if (!is_numeric($daysLeft)) return;

        $lastExpiryNotification = $adminInfo['last_expiry_notification'];

        if ($daysLeft > 7 && $lastExpiryNotification !== null) {
            $stmt = $this->dbBot->prepare("UPDATE admin_settings SET last_expiry_notification = NULL WHERE admin_id = ?");
            $stmt->bind_param("i", $adminId);
            $stmt->execute();
            $stmt->close();
            return;
        }

        if ($daysLeft <= 7 && $daysLeft > 3 && $lastExpiryNotification === null) {
            $lang = $this->getLang($telegramId);
            $message = sprintf($lang['panel_expiry_warning'], $adminInfo['username'], 7);
            $this->notification->sendMessage($telegramId, $message);

            $stmt = $this->dbBot->prepare("UPDATE admin_settings SET last_expiry_notification = NOW() WHERE admin_id = ?");
            $stmt->bind_param("i", $adminId);
            $stmt->execute();
            $stmt->close();
        } elseif ($daysLeft <= 3 && $daysLeft > 1 && ($lastExpiryNotification === null || strtotime($lastExpiryNotification) < strtotime('-4 days'))) {
            $lang = $this->getLang($telegramId);
            $message = sprintf($lang['panel_expiry_warning'], $adminInfo['username'], 3);
            $this->notification->sendMessage($telegramId, $message);

            $stmt = $this->dbBot->prepare("UPDATE admin_settings SET last_expiry_notification = NOW() WHERE admin_id = ?");
            $stmt->bind_param("i", $adminId);
            $stmt->execute();
            $stmt->close();
        } elseif ($daysLeft <= 1 && $daysLeft > 0 && ($lastExpiryNotification === null || strtotime($lastExpiryNotification) < strtotime('-2 days'))) {
            $lang = $this->getLang($telegramId);
            $message = sprintf($lang['panel_expiry_warning'], $adminInfo['username'], 1);
            $this->notification->sendMessage($telegramId, $message);

            $stmt = $this->dbBot->prepare("UPDATE admin_settings SET last_expiry_notification = NOW() WHERE admin_id = ?");
            $stmt->bind_param("i", $adminId);
            $stmt->execute();
            $stmt->close();
        }
    }

    private function manageUserLimitTrigger($adminId, $triggerName = 'cron_prevent_user_creation') {
        $stmtSettings = $this->dbBot->prepare("SELECT total_traffic, expiry_date, status, user_limit FROM admin_settings WHERE admin_id = ?");
        $stmtSettings->bind_param("i", $adminId);
        $stmtSettings->execute();
        $settingsResult = $stmtSettings->get_result();
        $settings = $settingsResult->fetch_assoc();
        $stmtSettings->close();

        if (!$settings) return;

        $stmtUserStats = $this->dbMarzban->prepare("
            SELECT
                COUNT(*) AS total_users,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_users,
                SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) AS expired_users,
                SUM(CASE WHEN TIMESTAMPDIFF(MINUTE, NOW(), online_at) <= 5 THEN 1 ELSE 0 END) AS online_users
            FROM users
            WHERE admin_id = ?");
        $stmtUserStats->bind_param("i", $adminId);
        $stmtUserStats->execute();
        $userStatsResult = $stmtUserStats->get_result();
        $userStats = $userStatsResult->fetch_assoc();
        $stmtUserStats->close();

        $userLimit = $settings['user_limit'] ?? self::INFINITY;
        if ($userLimit === self::INFINITY) return;

        $activeUsers = $userStats['active_users'];

        $existingTriggerQuery = $this->dbMarzban->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = '$triggerName'");
        $existingAdminIds = [];

        if ($existingTriggerQuery && $existingTriggerQuery->num_rows > 0) {
            $triggerResult = $this->dbMarzban->query("SHOW CREATE TRIGGER `$triggerName`");
            if ($triggerResult && $triggerResult->num_rows > 0) {
                $triggerRow = $triggerResult->fetch_assoc();
                $triggerBody = $triggerRow['SQL Original Statement'];
                if (preg_match("/IN\s*\((.*?)\)/", $triggerBody, $matches)) {
                    $existingAdminIdsStr = $matches[1];
                    $existingAdminIdsStr = str_replace(' ', '', $existingAdminIdsStr);
                    $existingAdminIds = explode(',', $existingAdminIdsStr);
                }
            }
        }

        $isOverLimit = ($activeUsers >= $userLimit);

        if ($isOverLimit) {
            if (!in_array($adminId, $existingAdminIds)) {
                $existingAdminIds[] = $adminId;

                $stmt = $this->dbMarzban->prepare("SELECT telegram_id, username FROM admins WHERE id = ?");
                $stmt->bind_param("i", $adminId);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    $admin = $result->fetch_assoc();
                    $telegramId = $admin['telegram_id'];
                    $username = $admin['username'];

                    if (!empty($telegramId)) {
                        $lang = $this->getLang($telegramId);
                    } else {
                        $firstOwnerId = reset($this->allowedUsers);
                        $lang = $this->getLang($firstOwnerId);
                    }
                    $message = sprintf($lang['user_limit_exceeded'], $username);

                    if (!empty($telegramId)) {
                        $this->notification->sendMessage($telegramId, $message);
                    }

                    foreach ($this->allowedUsers as $ownerId) {
                        $this->notification->sendMessage($ownerId, $message);
                    }
                }
                $stmt->close();
            }
        } else {
            $existingAdminIds = array_diff($existingAdminIds, [$adminId]);
        }

        if (empty($existingAdminIds)) {
            $this->dbMarzban->query("DROP TRIGGER IF EXISTS `$triggerName`");
        } else {
            $adminIdsStr = implode(', ', $existingAdminIds);
            $triggerBody = "
            CREATE TRIGGER `$triggerName` BEFORE INSERT ON `users`
            FOR EACH ROW
            BEGIN
                IF NEW.admin_id IN ($adminIdsStr) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'User creation not allowed for this admin ID.';
                END IF;
            END;
            ";

            $this->dbMarzban->query("DROP TRIGGER IF EXISTS `$triggerName`");
            $this->dbMarzban->query($triggerBody);
        }
    }
    private function ensureMarzbanAdminIsSudo($marzbanAdminUsername) {
        
        $stmt = $this->dbMarzban->prepare("SELECT id, is_sudo FROM admins WHERE username = ?");
        $stmt->bind_param("s", $marzbanAdminUsername);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $this->dbMarzban->logError("Admin with username '$marzbanAdminUsername' not found.");
            $stmt->close();
            return;
        }

        $admin = $result->fetch_assoc();
        $stmt->close();

        if ($admin['is_sudo'] != 1) {
            $stmt = $this->dbMarzban->prepare("UPDATE admins SET is_sudo = 1 WHERE id = ?");
            $stmt->bind_param("i", $admin['id']);
            $stmt->execute();
            
            if ($stmt->affected_rows > 0) {
                file_put_contents('logs.txt', date('Y-m-d H:i:s') . " - Updated is_sudo to 1 for admin '$marzbanAdminUsername' (ID: {$admin['id']})\n", FILE_APPEND);
            } else {
                $this->dbMarzban->logError("Failed to update is_sudo for admin '$marzbanAdminUsername' (ID: {$admin['id']})");
            }
            $stmt->close();
        }
    }
    public function managePanels() {
        global $marzbanAdminUsername;
        $currentMinute = (int)date('i');
        $currentTime = date('H:i');
        $admins = $this->dbMarzban->query("SELECT id FROM admins");

            $this->ensureMarzbanAdminIsSudo($marzbanAdminUsername);

        while ($admin = $admins->fetch_assoc()) {
            $adminId = $admin['id'];
            $adminInfo = $this->getAdminInfo($adminId);
            if (!$adminInfo) continue;
        
            $this->managePanelExtension($adminId, $adminInfo);
            $this->manageTrafficUsage($adminId, $adminInfo);
            $this->manageUserLimitTrigger($adminId);
            $this->manageCreatedTrafficTrigger($adminId);
            $this->createTrafficTriggers();

            if ($currentTime === '00:00') {
                $stmt = $this->dbMarzban->prepare("SELECT telegram_id, username FROM admins WHERE id = ?");
                $stmt->bind_param("i", $adminId);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    $admin = $result->fetch_assoc();
                    $telegramId = $admin['telegram_id'];
                    $username = $admin['username'];
    
                    if (empty($telegramId)) continue;
    
                    $userLimit = $adminInfo['userLimit'] === self::INFINITY ? null : $adminInfo['userLimit'];
                    if (is_null($userLimit)) continue;
    
                    $activeUsers = $adminInfo['userStats']['active_users'];
                    $remainingSlots = $userLimit - $activeUsers;
    
                    if ($remainingSlots > 0 && $remainingSlots <= 5) {
                        $lang = $this->getLang($telegramId);
                        $message = sprintf($lang['user_limit_warning'], $username);
                        $this->notification->sendMessage($telegramId, $message);
                    }
                }
                $stmt->close();
            }
        }
    
        $this->notifyAdmins();
    
        if ($currentMinute % 15 === 0) {
            $admins->data_seek(0);
            while ($admin = $admins->fetch_assoc()) {
                $adminInfo = $this->getAdminInfo($admin['id']);
                if (!$adminInfo) continue;
    
                $usedTraffic = $adminInfo['usedTraffic'];
    
                $stmt = $this->dbBot->prepare("INSERT INTO admin_usage (admin_id, used_traffic_gb) VALUES (?, ?)");
                if ($stmt) {
                    $stmt->bind_param("id", $admin['id'], $usedTraffic);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
    }
}

$dbMarzban = Database::getInstance($vpnDbHost, $vpnDbUser, $vpnDbPass, $vpnDbName)->getConnection();
$dbBot = Database::getInstance($botDbHost, $botDbUser, $botDbPass, $botDbName)->getConnection();
# $languages = require __DIR__ . '/../languages.php';
$notification = new Notification($apiURL, $dbBot);
$panelManager = new PanelManager($dbMarzban, $dbBot, $notification,/* $languages,*/ $allowedUsers);

$panelManager->managePanels();
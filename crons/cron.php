<?php
date_default_timezone_set('Asia/Tehran');
require 'config.php';

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
    private $languages;
    private $allowedUsers;
    private const INFINITY = '♾️';

    public function __construct($dbMarzban, $dbBot, $notification, $languages, $allowedUsers) {
        $this->dbMarzban = $dbMarzban;
        $this->dbBot = $dbBot;
        $this->notification = $notification;
        $this->languages = $languages;
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
    
        return $this->languages[$langCode] ?? $this->languages['en'];
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
            'last_traffic_notification' => $settings['last_traffic_notification'],
            'last_expiry_notification' => $settings['last_expiry_notification'],
            'userStats' => $userStats 
        ];
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
        }
        elseif ($daysLeft > 0 && $currentStatus['time'] === 'expired') {
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

    public function manageTrigger($adminId, $isOverLimit) {
        $stmt = $this->dbBot->prepare("SELECT last_trigger_notification FROM admin_settings WHERE admin_id = ?");
        $stmt->bind_param("i", $adminId);
        $stmt->execute();
        $result = $stmt->get_result();
        $lastNotification = $result->fetch_assoc()['last_trigger_notification'];
        $stmt->close();
    
        if ($isOverLimit) {
            $existingAdminIds = $this->getExistingAdminIds(); 
            if (!in_array($adminId, $existingAdminIds)) {
                $existingAdminIds[] = $adminId;
    
                if ($lastNotification === null || strtotime($lastNotification) < strtotime('-1 hour')) {
                    $stmt = $this->dbMarzban->prepare("SELECT telegram_id, username FROM admins WHERE id = ?");
                    $stmt->bind_param("i", $adminId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result->num_rows > 0) {
                        $admin = $result->fetch_assoc();
                        $telegramId = $admin['telegram_id'];
                        $username = $admin['username'];
    
                        $lang = $this->getLang($telegramId ?? reset($this->allowedUsers));
                        $message = sprintf($lang['traffic_exhausted'], $username);
    
                        if (!empty($telegramId)) {
                            $this->notification->sendMessage($telegramId, $message);
                        }
                        foreach ($this->allowedUsers as $ownerId) {
                            $this->notification->sendMessage($ownerId, $message);
                        }
                    }
                    $stmt->close();
    
                    $stmt = $this->dbBot->prepare("UPDATE admin_settings SET last_trigger_notification = NOW() WHERE admin_id = ?");
                    $stmt->bind_param("i", $adminId);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
    }

    private function manageCreatedTrafficTrigger($adminId, $insertTriggerName = 'cron_prevent_user_creation_traffic', $updateTriggerName = 'cron_prevent_user_update_traffic') {
        $stmtSettings = $this->dbBot->prepare("SELECT total_traffic, calculate_volume FROM admin_settings WHERE admin_id = ?");
        $stmtSettings->bind_param("i", $adminId);
        $stmtSettings->execute();
        $settingsResult = $stmtSettings->get_result();
        $settings = $settingsResult->fetch_assoc();
        $stmtSettings->close();

        if (!$settings) {
            return;
        }

        $calculateVolume = $settings['calculate_volume'] ?? 'used_traffic';

        if ($calculateVolume === 'used_traffic') {
            $this->dropTriggerIfExists($insertTriggerName);
            $this->dropTriggerIfExists($updateTriggerName);
            return;
        }

        if ($settings['total_traffic'] === null) {
            return;
        }

        $totalTrafficBytes = $settings['total_traffic'];

        $stmtTraffic = $this->dbMarzban->prepare("
            SELECT (
                IFNULL((SELECT SUM(
                    CASE 
                        WHEN users.data_limit IS NOT NULL THEN users.data_limit 
                        ELSE users.used_traffic 
                    END
                ) FROM users WHERE users.admin_id = ?), 0) +
                IFNULL((SELECT SUM(user_usage_logs.used_traffic_at_reset) FROM user_usage_logs 
                        WHERE user_usage_logs.user_id IN (SELECT id FROM users WHERE users.admin_id = ?)), 0) +
                IFNULL((SELECT SUM(user_deletions.reseted_usage) FROM user_deletions WHERE user_deletions.admin_id = ?), 0)
            ) AS created_traffic_bytes
        ");
        $stmtTraffic->bind_param("iii", $adminId, $adminId, $adminId);
        $stmtTraffic->execute();
        $trafficResult = $stmtTraffic->get_result();
        $trafficData = $trafficResult->fetch_assoc();
        $stmtTraffic->close();

        $createdTrafficBytes = $trafficData['created_traffic_bytes'] ?? 0;

        $isOverLimit = ($createdTrafficBytes >= $totalTrafficBytes);

        if ($isOverLimit) {
            $this->manageTrigger($insertTriggerName, $adminId, true, 'INSERT');
            $this->manageTrigger($updateTriggerName, $adminId, true, 'UPDATE');
        } else {
            $this->dropTriggerIfExists($insertTriggerName);
            $this->dropTriggerIfExists($updateTriggerName);
        }
    }

    public function manageTrafficUsage($adminId, $traffic, $calculateVolume, $totalTraffic) {
        $stmt = $this->dbBot->prepare("SELECT status FROM admin_settings WHERE admin_id = ?");
        $stmt->bind_param("i", $adminId);
        $stmt->execute();
        $currentStatus = json_decode($stmt->get_result()->fetch_assoc()['status'], true);
        $stmt->close();
    
        if ($calculateVolume === 'used_traffic') {
            $usedTraffic = round($traffic['used_traffic_gb'] ?? 0, 2);
        } else {
            $usedTraffic = round($traffic['created_traffic_gb'] ?? 0, 2);
        }
        $remainingTraffic = $totalTraffic !== self::INFINITY ? round($totalTraffic - $usedTraffic, 2) : self::INFINITY;
    
        if ($remainingTraffic <= 0 && $currentStatus['data'] !== 'exhausted') {
            $adminInfo = $this->getAdminInfo($adminId);
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
                SUM(CASE WHEN TIMESTAMPDIFF(MINUTE, now(), online_at) = 0 THEN 1 ELSE 0 END) AS online_users
            FROM users
            WHERE admin_id = ?
        ");
        $stmtUserStats->bind_param("i", $adminId);
        $stmtUserStats->execute();
        $userStatsResult = $stmtUserStats->get_result();
        $userStats = $userStatsResult->fetch_assoc();
        $stmtUserStats->close();

        $userLimit = $settings['user_limit'] ?? '♾️';
        if ($userLimit === '♾️') {
            return;
        }

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

    public function managePanels() {
        $currentMinute = (int)date('i');
        $currentTime = date('H:i');
        $admins = $this->dbMarzban->query("SELECT id FROM admins");
        
        while ($admin = $admins->fetch_assoc()) {
            $adminId = $admin['id'];
            $adminInfo = $this->getAdminInfo($adminId);
            if (!$adminInfo) continue;
        
            $this->managePanelExtension($adminId, $adminInfo);
            $this->manageTrafficUsage($adminId, $adminInfo);
            $this->manageUserLimitTrigger($adminId);
            $this->manageCreatedTrafficTrigger($adminId);

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
    
                    $userLimit = $adminInfo['userStats']['total_users'] === '♾️' ? null : $adminInfo['user_limit'];
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
$languages = include 'languages.php';
$notification = new Notification($apiURL, $dbBot);
$panelManager = new PanelManager($dbMarzban, $dbBot, $notification, $languages, $allowedUsers);

$panelManager->managePanels();
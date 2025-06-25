<?php
require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

class Chat implements MessageComponentInterface {
    protected $clients;
    protected $con;
    protected $userConnections = [];

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->con = new mysqli("mysql-adeel.alwaysdata.net", "adeel", "mySecure123", "adeel_glowchat");


        
        if ($this->con->connect_error) {
            echo "❌ DB connection failed: " . $this->con->connect_error . "\n";
            exit;
        }

        echo "✅ WebSocket server running & DB connected\n";
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        echo "New connection ({$conn->resourceId})\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        echo "📩 Raw message: $msg\n";
        $data = json_decode($msg, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "❌ JSON Error: " . json_last_error_msg() . "\n";
            return;
        }

        // Handle both old and new message formats
        if (isset($data['type'])) {
            // New format with message type
            switch($data['type']) {
                case 'register':
                    $this->handleRegistration($from, $data);
                    break;
                case 'chat':
                    $this->handleChatMessage($data);
                    break;
                case 'request_friend_list':
                    $this->handleFriendListRequest($data);
                    break;
                default:
                    echo "❌ Unknown message type\n";
            }
        } else {
            // Old format (direct chat message)
            if (isset($data['sender_id'], $data['receiver_id'], $data['message'], $data['time'])) {
                // Convert to new format
                $chatData = [
                    'type' => 'chat',
                    'sender_id' => $data['sender_id'],
                    'receiver_id' => $data['receiver_id'],
                    'message' => $data['message'],
                    'time' => $data['time'],
                    'message_type' => $data['message_type'] ?? 'text',
                    'media_url' => $data['media_url'] ?? ''
                ];
                $this->handleChatMessage($chatData);
                
                // Broadcast to all clients (legacy behavior)
                foreach ($this->clients as $client) {
                    $client->send($msg);
                }
                echo "✅ Message broadcasted (legacy format)\n";
            } else {
                echo "❌ Missing required fields\n";
            }
        }
    }

    private function handleRegistration(ConnectionInterface $conn, $data) {
        if (!isset($data['user_id'])) {
            echo "❌ Missing user_id for registration\n";
            return;
        }

        $userId = (int)$data['user_id'];
        $this->userConnections[$userId] = $conn;
        echo "✅ Registered user $userId (connection {$conn->resourceId})\n";
    }

    private function handleChatMessage($data) {
        $required = ['sender_id', 'receiver_id', 'message', 'time'];
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                echo "❌ Missing $field in message\n";
                return;
            }
        }

        $senderId = (int)$data['sender_id'];
        $receiverId = (int)$data['receiver_id'];
        $message = $data['message'];
        $time = $data['time'];
        $messageType = $data['message_type'] ?? 'text';
        $mediaUrl = $data['media_url'] ?? '';

        $stmt = $this->con->prepare("INSERT INTO users_chat (sender, receiver, message, time, message_type, media_url) VALUES (?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            echo "❌ SQL Prepare failed: " . $this->con->error . "\n";
            return;
        }

        $stmt->bind_param("iissss", $senderId, $receiverId, $message, $time, $messageType, $mediaUrl);

        if ($stmt->execute()) {
            echo "✅ Message saved to DB\n";
            
            // Notify both users (new format)
            $this->notifyNewMessage($senderId, $receiverId, $data);
            $this->notifyNewMessage($receiverId, $senderId, $data);
        } else {
            echo "❌ SQL Execute failed: " . $stmt->error . "\n";
        }
        $stmt->close();
    }

    private function notifyNewMessage($userId, $otherUserId, $messageData) {
        if (isset($this->userConnections[$userId])) {
            $response = [
                'type' => 'new_message',
                'from_user_id' => $otherUserId,
                'message' => $messageData,
                'timestamp' => time()
            ];
            $this->userConnections[$userId]->send(json_encode($response));
            echo "✅ Notified user $userId about new message\n";
        }
    }

    private function handleFriendListRequest($data) {
        if (!isset($data['user_id'])) {
            echo "❌ Missing user_id for friend list\n";
            return;
        }

        $userId = (int)$data['user_id'];
        $friends = $this->getFriendList($userId);
        
        if (isset($this->userConnections[$userId])) {
            $response = [
                'type' => 'friend_list_update',
                'friends' => $friends,
                'timestamp' => time()
            ];
            $this->userConnections[$userId]->send(json_encode($response));
        }
    }

    private function getFriendList($userId) {
    $query = "SELECT 
        u.id, 
        u.name, 
        u.picture,
        MAX(uc.time) as last_message_time,
        (SELECT uc2.message FROM users_chat uc2 
         WHERE (uc2.sender = u.id AND uc2.receiver = ?) OR (uc2.sender = ? AND uc2.receiver = u.id)
         ORDER BY uc2.time DESC LIMIT 1) as last_message,
        (SELECT uc2.message_type FROM users_chat uc2 
         WHERE (uc2.sender = u.id AND uc2.receiver = ?) OR (uc2.sender = ? AND uc2.receiver = u.id)
         ORDER BY uc2.time DESC LIMIT 1) as message_type
    FROM users_friend uf
    JOIN users u ON (uf.user_id = ? AND uf.friend_id = u.id) OR (uf.friend_id = ? AND uf.user_id = u.id)
    LEFT JOIN users_chat uc ON ((uc.sender = u.id AND uc.receiver = ?) OR (uc.sender = ? AND uc.receiver = u.id))
    WHERE u.id != ?
    GROUP BY u.id, u.name, u.picture
    ORDER BY last_message_time DESC";
    
    $stmt = $this->con->prepare($query);
    if (!$stmt) {
        echo "❌ SQL Prepare failed: " . $this->con->error . "\n";
        return [];
    }

    $stmt->bind_param("iiiiiiiii", 
        $userId, $userId,  // For first subquery
        $userId, $userId,  // For second subquery
        $userId, $userId,  // For JOIN conditions
        $userId, $userId,  // For LEFT JOIN conditions
        $userId            // For WHERE condition
    );
    
    if (!$stmt->execute()) {
        echo "❌ SQL Execute failed: " . $stmt->error . "\n";
        return [];
    }
    
    $result = $stmt->get_result();
    $friends = [];
    
    while ($row = $result->fetch_assoc()) {
        if (!empty($row["picture"])) {
            $row["picture"] = "http://192.168.18.29/GlowChat/" . $row["picture"];
        }
        //$row["last_message_time"] = $row["last_message_time"] ? $this->timeAgo($row["last_message_time"]) : null;

        $friends[] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'picture' => $row['picture'],
            'message' => $row['last_message'] ?? '',
            'message_type' => $row['message_type'] ?? 'text',
            'time' => $row['last_message_time'] ?? ''
        ];
    }
    
    return $friends;
}

    public function onClose(ConnectionInterface $conn) {
        foreach ($this->userConnections as $userId => $userConn) {
            if ($userConn === $conn) {
                unset($this->userConnections[$userId]);
                break;
            }
        }
        $this->clients->detach($conn);
        echo "Connection closed\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "Error: {$e->getMessage()}\n";
        $conn->close();
    }
    public function timeAgo($datetime) {
    // Create DateTime objects
    $past = new DateTime($datetime);
    $now = new DateTime();

    // Get the difference
    $diff = $now->getTimestamp() - $past->getTimestamp();

    if ($diff < 60) {
        return $diff <= 1 ? 'just now' : "$diff seconds ago";
    }

    $minutes = floor($diff / 60);
    if ($minutes < 60) {
        return $minutes == 1 ? '1 minute ago' : "$minutes minutes ago";
    }

    $hours = floor($minutes / 60);
    if ($hours < 24) {
        return $hours == 1 ? '1 hour ago' : "$hours hours ago";
    }

    $days = floor($hours / 24);
    if ($days < 7) {
        return $days == 1 ? '1 day ago' : "$days days ago";
    }

    $weeks = floor($days / 7);
    if ($weeks < 4) {
        return $weeks == 1 ? '1 week ago' : "$weeks weeks ago";
    }

    $months = floor($days / 30);
    if ($months < 12) {
        return $months == 1 ? '1 month ago' : "$months months ago";
    }

    $years = floor($months / 12);
    return $years == 1 ? '1 year ago' : "$years years ago";
}
}

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new Chat()
        )
    ),
    8080
);

$server->run();
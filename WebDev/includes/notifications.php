<?php
if (__FILE__ === $_SERVER['SCRIPT_FILENAME']) { header($_SERVER['SERVER_PROTOCOL'].' 403 Forbidden'); exit; }

function addNotification($pdo, $user_id, $title, $message) {
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)");
    return $stmt->execute([$user_id, $title, $message]);
}

function getNotifications($pdo, $user_id, $limit = 5) {
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY is_read ASC, created_at DESC LIMIT ?");
    $stmt->bindValue(1, $user_id, PDO::PARAM_INT);
    $stmt->bindValue(2, (int)$limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function getUnreadCount($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user_id]);
    return intval($stmt->fetchColumn());
}

function markAllRead($pdo, $user_id) {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    return $stmt->execute([$user_id]);
}

<?php
// Utility helpers for updating user timestamps safely and centrally
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/**
 * Update the user's last_login to NOW(). Returns true on success, false otherwise.
 */
function update_last_login($conn, $userId) {
    if (empty($conn) || empty($userId)) return false;
    $id = (int)$userId;
    $stmt = $conn->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
    if (!$stmt) return false;
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * Set password_changed_at = NOW() for a user. Returns true on success.
 * Keep this separate from password UPDATE so callers can control when the timestamp is changed.
 */
function set_password_changed_at_now($conn, $userId) {
    if (empty($conn) || empty($userId)) return false;
    $id = (int)$userId;
    $stmt = $conn->prepare('UPDATE users SET password_changed_at = NOW() WHERE id = ?');
    if (!$stmt) return false;
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * Enforce password expiry across users.
 * Marks `force_password_reset = 1` for users whose `password_changed_at` is older than $days
 * or is NULL (if you want NULL treated as expired). Returns affected rows or false on error.
 */
function enforce_password_expiry($conn, $days = 30) {
    if (empty($conn)) return false;
    $d = (int)$days;
    // Update users that are active and not already forced to reset.
    $sql = "UPDATE users SET force_password_reset = 1 WHERE active = 1 AND force_password_reset = 0 AND (password_changed_at IS NULL OR password_changed_at < DATE_SUB(NOW(), INTERVAL ? DAY))";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param('i', $d);
    $ok = $stmt->execute();
    if (!$ok) { $stmt->close(); return false; }
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected;
}

?>

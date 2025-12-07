<?php
// Shared helpers for Flows create/update validation

// Strongly-typed validation exception so callers can check http_code reliably
class FlowValidationException extends Exception {
    public $http_code;
    public function __construct($message, $http_code = 400) {
        parent::__construct($message);
        $this->http_code = (int)$http_code;
    }
}

function validate_flow_input($conn, $data, $type = 'positions') {
    // returns sanitized associative array or throws FlowValidationException
    $name = trim((string)($data['status_name'] ?? ''));
    $color = isset($data['status_color']) ? trim((string)$data['status_color']) : '';
    $color = $color === '' ? null : $color;
    $pool_id = isset($data['pool_id']) && $data['pool_id'] !== '' ? (int)$data['pool_id'] : null;
    $sort_order = isset($data['sort_order']) ? (int)$data['sort_order'] : null;
    $active = !empty($data['active']) ? 1 : 0;
    $transitions_raw = $data['transitions'] ?? [];
    $transitions = [];

    if (!is_array($transitions_raw)) {
        // allow comma-separated string as a convenience
        if (is_string($transitions_raw) && strlen(trim($transitions_raw)) > 0) {
            $parts = explode(',', $transitions_raw);
            foreach ($parts as $p) { $p = trim($p); if ($p !== '') $transitions[] = (int)$p; }
        } else {
            $transitions = [];
        }
    } else {
        foreach ($transitions_raw as $t) { $transitions[] = (int)$t; }
    }

    if ($name === '') {
        throw new FlowValidationException('Name is required', 400);
    }
    if ($pool_id === null) {
        throw new FlowValidationException('Pool selection is required', 400);
    }
    if ($sort_order === null) {
        throw new FlowValidationException('Sort order is required', 400);
    }
    if ($sort_order <= 0) {
        throw new FlowValidationException('Sort order must be greater than 0', 400);
    }
    // Transitions may be empty (no outgoing transitions) — allow empty array
    if (!is_array($transitions)) {
        $transitions = [];
    }

    // check sort uniqueness among active statuses
    // Map flow type to DB table and id column
    if ($type === 'positions') {
        $table = 'positions_status';
        $idCol = 'status_id';
    } elseif ($type === 'applicants') {
        $table = 'applicants_status';
        $idCol = 'status_id';
    } elseif ($type === 'interviews') {
        $table = 'interview_statuses';
        $idCol = 'id';
    } else {
        // default to applicants for backward compatibility
        $table = 'applicants_status';
        $idCol = 'status_id';
    }

    $sql = "SELECT {$idCol} FROM {$table} WHERE active = 1 AND sort_order = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new FlowValidationException('DB prepare failed: ' . $conn->error, 500);
    }
    if (!$stmt->bind_param('i', $sort_order)) {
        $stmt->close();
        throw new FlowValidationException('DB bind failed', 500);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        throw new FlowValidationException('DB execute failed', 500);
    }
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $stmt->close();
        throw new FlowValidationException('Sort order already used by an active status', 409);
    }
    $stmt->close();

    return [
        'status_name' => $name,
        'status_color' => $color,
        'pool_id' => $pool_id,
        'sort_order' => $sort_order,
        'active' => $active,
        'transitions' => $transitions
    ];
}

?>
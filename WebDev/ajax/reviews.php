<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

$stmt = $pdo->query("SELECT r.id, r.rating, r.review_text, u.business_name FROM reviews r JOIN users u ON r.user_id = u.id WHERE r.status = 'approved' ORDER BY RAND() LIMIT 3");
$reviews = $stmt->fetchAll();

echo json_encode($reviews);

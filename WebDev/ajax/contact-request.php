<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mailer.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Invalid request method.']);
    exit;
}

// Honeypot: real users never fill this hidden field.
if (trim((string)($_POST['website'] ?? '')) !== '') {
    echo json_encode(['ok' => true]);
    exit;
}

// Simple rate limit: max 5 submissions per 10 minutes per IP.
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$dir = sys_get_temp_dir() . '/bizvaza_contact_rl';
if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
$file = $dir . '/' . md5($ip);
$window = 600;
$limit = 5;
$now = time();
$count = 1;
if (is_file($file)) {
    $data = json_decode((string)file_get_contents($file), true);
    if (is_array($data) && ($now - (int)($data['ts'] ?? 0)) < $window) {
        $count = (int)($data['count'] ?? 0) + 1;
    }
}
if ($count > $limit) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Too many submissions. Please try again later.']);
    exit;
}
@file_put_contents($file, json_encode(['ts' => $now, 'count' => $count]));

$action = trim((string)($_POST['action'] ?? ''));
$name   = trim((string)($_POST['name'] ?? ''));
$to     = 'bizvaza@gmail.com';

if ($action === 'message') {
    $email   = trim((string)($_POST['email'] ?? ''));
    $subject = trim((string)($_POST['subject'] ?? ''));
    $message = trim((string)($_POST['message'] ?? ''));

    if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $subject === '' || $message === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Please fill in all fields with valid details.']);
        exit;
    }
    if (mb_strlen($name) > 100 || mb_strlen($email) > 150 || mb_strlen($subject) > 200 || mb_strlen($message) > 5000) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'One or more fields are too long.']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO contact_messages (name, email, subject, message, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([$name, $email, $subject, $message]);

    $html = "<div style=\"font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:0 auto;padding:24px\">
      <h2 style=\"color:#0f172a;margin:0 0 12px\">New contact message</h2>
      <table style=\"width:100%;border-collapse:collapse;margin:16px 0\">
        <tr><td style=\"padding:8px 12px;background:#f8fafc;border:1px solid #e5e7eb;font-size:13px;color:#475569\">Name</td><td style=\"padding:8px 12px;border:1px solid #e5e7eb;font-size:13px;color:#0f172a\">" . htmlspecialchars($name) . "</td></tr>
        <tr><td style=\"padding:8px 12px;background:#f8fafc;border:1px solid #e5e7eb;font-size:13px;color:#475569\">Email</td><td style=\"padding:8px 12px;border:1px solid #e5e7eb;font-size:13px;color:#0f172a\">" . htmlspecialchars($email) . "</td></tr>
        <tr><td style=\"padding:8px 12px;background:#f8fafc;border:1px solid #e5e7eb;font-size:13px;color:#475569\">Subject</td><td style=\"padding:8px 12px;border:1px solid #e5e7eb;font-size:13px;color:#0f172a\">" . htmlspecialchars($subject) . "</td></tr>
      </table>
      <p style=\"color:#475569;font-size:14px;line-height:1.6\">" . nl2br(htmlspecialchars($message)) . "</p>
    </div>";
    $alt = "New contact message\n\nName: $name\nEmail: $email\nSubject: $subject\n\n$message";
    sendMail($pdo, $to, 'BizVaza — Contact: ' . $subject, $html, $alt);

    echo json_encode(['ok' => true, 'message' => 'Thank you! Your message has been sent. We will reply within 24 hours.']);
    exit;
}

if ($action === 'callback') {
    $phone   = trim((string)($_POST['phone'] ?? ''));
    $date    = trim((string)($_POST['date'] ?? ''));
    $time    = trim((string)($_POST['time'] ?? ''));
    $message = trim((string)($_POST['message'] ?? ''));

    if ($name === '' || $phone === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Please provide your name and phone number.']);
        exit;
    }
    if (mb_strlen($name) > 100 || mb_strlen($phone) > 30 || mb_strlen($message) > 5000) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'One or more fields are too long.']);
        exit;
    }
    if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid date.']);
        exit;
    }
    if ($time !== '' && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time) !== 1) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid time.']);
        exit;
    }

    $callback_date = $date !== '' ? $date : null;
    $callback_time = $time !== '' ? $time : null;
    $callback_msg  = $message !== '' ? $message : null;

    $stmt = $pdo->prepare("INSERT INTO help_requests (user_id, name, phone, callback_date, callback_time, message, created_at) VALUES (NULL, ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$name, $phone, $callback_date, $callback_time, $callback_msg]);

    $date_disp = $callback_date ?: 'Not specified';
    $time_disp = $callback_time ?: 'Not specified';
    $msg_disp  = $callback_msg ?: '—';
    $html = "<div style=\"font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:0 auto;padding:24px\">
      <h2 style=\"color:#0f172a;margin:0 0 12px\">New callback request</h2>
      <table style=\"width:100%;border-collapse:collapse;margin:16px 0\">
        <tr><td style=\"padding:8px 12px;background:#f8fafc;border:1px solid #e5e7eb;font-size:13px;color:#475569\">Name</td><td style=\"padding:8px 12px;border:1px solid #e5e7eb;font-size:13px;color:#0f172a\">" . htmlspecialchars($name) . "</td></tr>
        <tr><td style=\"padding:8px 12px;background:#f8fafc;border:1px solid #e5e7eb;font-size:13px;color:#475569\">Phone</td><td style=\"padding:8px 12px;border:1px solid #e5e7eb;font-size:13px;color:#0f172a\">" . htmlspecialchars($phone) . "</td></tr>
        <tr><td style=\"padding:8px 12px;background:#f8fafc;border:1px solid #e5e7eb;font-size:13px;color:#475569\">Preferred Date</td><td style=\"padding:8px 12px;border:1px solid #e5e7eb;font-size:13px;color:#0f172a\">" . htmlspecialchars($date_disp) . "</td></tr>
        <tr><td style=\"padding:8px 12px;background:#f8fafc;border:1px solid #e5e7eb;font-size:13px;color:#475569\">Preferred Time</td><td style=\"padding:8px 12px;border:1px solid #e5e7eb;font-size:13px;color:#0f172a\">" . htmlspecialchars($time_disp) . "</td></tr>
      </table>
      <p style=\"color:#475569;font-size:14px;line-height:1.6\">" . nl2br(htmlspecialchars($msg_disp)) . "</p>
    </div>";
    $alt = "New callback request\n\nName: $name\nPhone: $phone\nDate: $date_disp\nTime: $time_disp\n\n" . $msg_disp;
    sendMail($pdo, $to, 'BizVaza — Callback request: ' . $name, $html, $alt);

    echo json_encode(['ok' => true, 'message' => "Thank you! We'll call you within 24 hours."]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown request.']);
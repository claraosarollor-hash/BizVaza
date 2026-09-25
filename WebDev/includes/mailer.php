<?php
if (__FILE__ === $_SERVER['SCRIPT_FILENAME']) { header($_SERVER['SERVER_PROTOCOL'].' 403 Forbidden'); exit; }
require_once __DIR__ . '/config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

function getSmtpSettings($pdo) {
    static $cache = null;
    if ($cache !== null) return $cache;
    $smtp = ['smtp_host' => '', 'smtp_port' => '587', 'smtp_user' => '', 'smtp_pass' => '', 'mail_from' => '', 'mail_from_name' => 'BizVaza'];
    $stmt = $pdo->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('smtp_host','smtp_port','smtp_user','smtp_pass','mail_from','mail_from_name')");
    while ($row = $stmt->fetch()) { $smtp[$row['key']] = $row['value']; }
    $cache = $smtp;
    return $cache;
}

function sendMail($pdo, $to, $subject, $html, $alt = '', &$err = '') {
    $s = getSmtpSettings($pdo);
    if (empty($s['smtp_host']) || empty($s['smtp_user'])) {
        $err = 'SMTP not configured';
        error_log('BizVaza mail: SMTP not configured');
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $s['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $s['smtp_user'];
        $mail->Password = $s['smtp_pass'];
        $mail->Port = (int)($s['smtp_port'] ?: 587);
        $mail->SMTPSecure = $mail->Port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($s['mail_from'] ?: $s['smtp_user'], $s['mail_from_name']);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->AltBody = $alt !== '' ? $alt : strip_tags($html);
        $mail->send();
        return true;
    } catch (Exception $e) {
        $err = $mail->ErrorInfo;
        error_log('BizVaza mail error: ' . $mail->ErrorInfo);
        return false;
    }
}

function sendResetEmail($pdo, $to, $name, $reset_link) {
    $link = htmlspecialchars($reset_link);
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:0 auto;padding:24px">
      <h2 style="color:#0f172a;margin:0 0 8px">Reset your BizVaza password</h2>
      <p style="color:#475569;font-size:14px;line-height:1.6">Hi ' . htmlspecialchars($name) . ', we received a request to reset your BizVaza account password. Click the button below to choose a new one.</p>
      <p style="text-align:center;margin:28px 0">
        <a href="' . $link . '" style="background:#b45309;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:14px;font-weight:600;display:inline-block">Reset Password</a>
      </p>
      <p style="color:#94a3b8;font-size:12px;line-height:1.6">If you did not request this, you can safely ignore this email. The link expires in 1 hour.<br><br><a href="' . $link . '" style="color:#94a3b8;word-break:break-all">' . $link . '</a></p>
    </div>';
    return sendMail($pdo, $to, 'BizVaza — Reset your password', $html, "Reset your BizVaza password\n\nHi $name,\n\nUse this link to choose a new password (expires in 1 hour):\n$reset_link\n\nIf you did not request this, ignore this email.");
}

function sendInvoiceEmail($pdo, $inv_num, $customer_name, $customer_email, $due_date, $amount, $business_name = 'BizVaza') {
    if (trim($customer_email) === '') return false;
    $amt = '₦' . number_format(floatval($amount), 2);
    $due_display = $due_date ? date('F j, Y', strtotime($due_date)) : '—';
    $biz = htmlspecialchars($business_name ?: 'BizVaza');
    $inv_h = htmlspecialchars($inv_num);
    $cust_h = htmlspecialchars($customer_name);
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:0 auto;padding:24px">
      <h2 style="color:#0f172a;margin:0 0 8px">Invoice ' . $inv_h . '</h2>
      <p style="color:#475569;font-size:14px;line-height:1.6">Hi ' . $cust_h . ',</p>
      <p style="color:#475569;font-size:14px;line-height:1.6">' . $biz . ' has issued you an invoice for <strong>' . $amt . '</strong>.</p>
      <table style="width:100%;border-collapse:collapse;margin:20px 0">
        <tr><td style="padding:10px 12px;background:#f8fafc;border:1px solid #e5e7eb;font-size:13px;color:#475569">Amount due</td><td style="padding:10px 12px;background:#f8fafc;border:1px solid #e5e7eb;font-size:14px;font-weight:700;color:#0f172a">' . $amt . '</td></tr>
        <tr><td style="padding:10px 12px;border:1px solid #e5e7eb;font-size:13px;color:#475569">Due date</td><td style="padding:10px 12px;border:1px solid #e5e7eb;font-size:14px;font-weight:600;color:#0f172a">' . $due_display . '</td></tr>
        <tr><td style="padding:10px 12px;border:1px solid #e5e7eb;font-size:13px;color:#475569">Invoice number</td><td style="padding:10px 12px;border:1px solid #e5e7eb;font-size:14px;font-weight:600;color:#0f172a">' . $inv_h . '</td></tr>
      </table>
      <p style="color:#64748b;font-size:12px;line-height:1.6">You can view this invoice in your BizVaza account. If you have any questions, reply to this email.</p>
    </div>';
    $alt = "Invoice " . $inv_num . " from " . ($business_name ?: 'BizVaza') . "\n\nHi " . $customer_name . ",\n\nAmount due: " . $amt . "\nDue date: " . $due_display . "\n\nIf you have any questions, reply to this email.";
    return sendMail($pdo, $customer_email, $biz . ' — Invoice ' . $inv_num, $html, $alt);
}

function sendBroadcastEmail($pdo, $to, $name, $title, $message) {
    $title_h = htmlspecialchars($title);
    $msg_html = nl2br(htmlspecialchars($message));
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:0 auto;padding:24px">
      <h2 style="color:#0f172a;margin:0 0 8px">' . $title_h . '</h2>
      <div style="color:#475569;font-size:14px;line-height:1.6">' . $msg_html . '</div>
      <p style="color:#94a3b8;font-size:12px;line-height:1.6;margin-top:28px">You are receiving this because you have an account on BizVaza. If you have any questions, reply to this email or contact our support team.</p>
    </div>';
    return sendMail($pdo, $to, 'BizVaza — ' . $title, $html, "BizVaza\n\n$title\n\n$message");
}

<?php
if (__FILE__ === $_SERVER['SCRIPT_FILENAME']) { header($_SERVER['SERVER_PROTOCOL'].' 403 Forbidden'); exit; }
function logActivityOnly($pdo, $user_id, $action_type, $description, $reference_type = null, $reference_id = null) {
    if (!$user_id) return false;
    $stmt = $pdo->prepare("INSERT INTO activity_log (user_id, action_type, description, reference_type, reference_id) VALUES (?, ?, ?, ?, ?)");
    return $stmt->execute([$user_id, $action_type, $description, $reference_type, $reference_id]);
}
function logActivity($pdo, $user_id, $action_type, $description, $reference_type = null, $reference_id = null) {
    logActivityOnly($pdo, $user_id, $action_type, $description, $reference_type, $reference_id);

    $titles = [
        'item_added' => 'Item Added',
        'item_updated' => 'Item Updated',
        'item_deleted' => 'Item Deleted',
        'customer_added' => 'Customer Added',
        'customer_updated' => 'Customer Updated',
        'customer_deleted' => 'Customer Deleted',
        'invoice_created' => 'Invoice Created',
        'invoice_updated' => 'Invoice Updated',
        'invoice_deleted' => 'Invoice Deleted',
        'payment_recorded' => 'Payment Received',
        'payment_updated' => 'Payment Updated',
        'payment_deleted' => 'Payment Deleted',
        'subscription_upgraded' => 'Subscription Upgraded',
        'upgrade_request_submitted' => 'Upgrade Request',
        'review_submitted' => 'Review Submitted',
        'profile_updated' => 'Profile Updated',
        'password_changed' => 'Password Changed',
        'template_saved' => 'Template Saved',
        'review_deleted' => 'Review Deleted',
        'callback_requested' => 'Callback Requested',
        'account_created' => 'Welcome to BizVaza',
        'password_reset_requested' => 'Password Reset Requested',
        'password_reset_completed' => 'Password Reset Completed',
        'login' => 'Signed In',
        'logout' => 'Signed Out',
        'admin_plan_created' => 'Plan Created',
        'admin_plan_updated' => 'Plan Updated',
        'admin_plan_deleted' => 'Plan Deleted',
        'admin_settings_updated' => 'Platform Settings Updated',
    ];
    $title = $titles[$action_type] ?? ucwords(str_replace('_', ' ', $action_type));
    addNotification($pdo, $user_id, $title, $description);
}

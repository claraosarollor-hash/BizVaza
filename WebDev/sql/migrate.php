<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only. Run: php sql/migrate.php\n";
    exit(1);
}

require __DIR__ . '/../includes/config.php';

echo "BizVaza migrate\n";

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

function colExists(PDO $pdo, string $table, string $col): bool {
    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = " . $pdo->quote(DB_NAME) . " AND TABLE_NAME = " . $pdo->quote($table) . " AND COLUMN_NAME = " . $pdo->quote($col));
    return (int)$stmt->fetchColumn() > 0;
}

function indexExists(PDO $pdo, string $table, string $index): bool {
    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = " . $pdo->quote(DB_NAME) . " AND TABLE_NAME = " . $pdo->quote($table) . " AND INDEX_NAME = " . $pdo->quote($index));
    return (int)$stmt->fetchColumn() > 0;
}

function run(string $label, callable $fn): void {
    try {
        $fn();
        echo "  OK  $label\n";
    } catch (Throwable $e) {
        echo "  WARN $label: " . $e->getMessage() . "\n";
    }
}

run('users.admin_pages', function () use ($pdo) {
    if (!colExists($pdo, 'users', 'admin_pages')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN admin_pages TEXT NULL AFTER role");
    }
});

run('users.trial_ends_at', function () use ($pdo) {
    if (!colExists($pdo, 'users', 'trial_ends_at')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN trial_ends_at DATETIME NULL AFTER plan_expires_at");
        $pdo->exec("UPDATE users SET trial_ends_at = DATE_ADD(NOW(), INTERVAL 1 MONTH) WHERE trial_ends_at IS NULL AND role != 'admin'");
    }
});

run('table notifications', function () use ($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY user_id (user_id),
        KEY is_read (is_read)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
});

run('table contact_messages', function () use ($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS contact_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(150) NOT NULL,
        subject VARCHAR(200) NOT NULL DEFAULT '',
        message TEXT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
});

run('table recurring_invoices', function () use ($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS recurring_invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(255) NOT NULL DEFAULT '',
        customer_name VARCHAR(255) NOT NULL,
        customer_email VARCHAR(255) DEFAULT '',
        customer_phone VARCHAR(255) DEFAULT '',
        billing_address TEXT,
        frequency ENUM('daily','weekly','monthly','yearly') NOT NULL DEFAULT 'monthly',
        interval_count INT NOT NULL DEFAULT 1,
        next_run_date DATE NOT NULL,
        issue_date DATE NOT NULL,
        due_days INT NOT NULL DEFAULT 14,
        items_json JSON NOT NULL,
        discount_type ENUM('Percentage','Fixed') NOT NULL DEFAULT 'Percentage',
        discount_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        notes TEXT,
        template_id INT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        status ENUM('active','paused') NOT NULL DEFAULT 'active',
        last_generated_at DATE NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY user_id (user_id),
        KEY next_run (status, next_run_date),
        CONSTRAINT fk_recinv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
});

run('table number_sequences', function () use ($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS number_sequences (
        user_id INT NOT NULL,
        doc_type ENUM('invoice','receipt') NOT NULL,
        last_value INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, doc_type),
        CONSTRAINT fk_numseq_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
});

run('seed number_sequences', function () use ($pdo) {
    $stmt = $pdo->query("SELECT COUNT(*) FROM number_sequences");
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec("INSERT IGNORE INTO number_sequences (user_id, doc_type, last_value)
            SELECT user_id, 'invoice', COALESCE(MAX(CAST(SUBSTRING_INDEX(invoice_number,'-',-1) AS UNSIGNED)),0)
            FROM invoices GROUP BY user_id");
        $pdo->exec("INSERT IGNORE INTO number_sequences (user_id, doc_type, last_value)
            SELECT user_id, 'receipt', COALESCE(MAX(CAST(SUBSTRING_INDEX(receipt_number,'-',-1) AS UNSIGNED)),0)
            FROM payments GROUP BY user_id");
    }
});

run('payments.invoice_key', function () use ($pdo) {
    if (!colExists($pdo, 'payments', 'invoice_key')) {
        $pdo->exec("ALTER TABLE payments ADD COLUMN invoice_key VARCHAR(50) GENERATED ALWAYS AS (NULLIF(invoice_number,'')) STORED AFTER invoice_number");
    }
});

run('payments.balance', function () use ($pdo) {
    if (!colExists($pdo, 'payments', 'balance')) {
        $pdo->exec("ALTER TABLE payments ADD COLUMN balance DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER amount");
    }
});

run('payments.template_id', function () use ($pdo) {
    if (!colExists($pdo, 'payments', 'template_id')) {
        $pdo->exec("ALTER TABLE payments ADD COLUMN template_id INT NULL AFTER balance");
    }
});

run('subscription_payments.status approved enum', function () use ($pdo) {
    $stmt = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = " . $pdo->quote(DB_NAME) . " AND TABLE_NAME = 'subscription_payments' AND COLUMN_NAME = 'status'");
    $colType = (string)$stmt->fetchColumn();
    if ($colType !== '' && strpos($colType, "'approved'") === false) {
        $pdo->exec("ALTER TABLE subscription_payments MODIFY COLUMN status ENUM('pending','approved','rejected','verified') NOT NULL DEFAULT 'pending'");
    }
});

run('drop payments.uq_pay_user_invoice', function () use ($pdo) {
    if (indexExists($pdo, 'payments', 'uq_pay_user_invoice')) {
        $pdo->exec("ALTER TABLE payments DROP INDEX uq_pay_user_invoice");
    }
});

run('add payments.uq_pay_receipt', function () use ($pdo) {
    if (!indexExists($pdo, 'payments', 'uq_pay_receipt')) {
        $pdo->exec("ALTER TABLE payments ADD UNIQUE INDEX uq_pay_receipt (user_id, receipt_number)");
    }
});

run('add invoices.uq_inv_number', function () use ($pdo) {
    if (!indexExists($pdo, 'invoices', 'uq_inv_number')) {
        $pdo->exec("ALTER TABLE invoices ADD UNIQUE INDEX uq_inv_number (user_id, invoice_number)");
    }
});

echo "Done.\n";

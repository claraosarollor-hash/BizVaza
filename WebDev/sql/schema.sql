CREATE DATABASE IF NOT EXISTS bizflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bizflow;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    business_name VARCHAR(150) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(30) NOT NULL UNIQUE,
    business_abbr VARCHAR(10) NOT NULL DEFAULT '',
    business_phone VARCHAR(50) DEFAULT '',
    business_address TEXT DEFAULT NULL,
    bank_name VARCHAR(100) DEFAULT '',
    account_name VARCHAR(100) DEFAULT '',
    account_number VARCHAR(20) DEFAULT '',
    password VARCHAR(255) NOT NULL,
    password_updated_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    invoice_number VARCHAR(50) NOT NULL,
    customer_name VARCHAR(100) NOT NULL DEFAULT '',
    customer_email VARCHAR(100) DEFAULT '',
    customer_phone VARCHAR(50) DEFAULT '',
    billing_address TEXT DEFAULT NULL,
    issue_date DATE DEFAULT NULL,
    due_date DATE DEFAULT NULL,
    amount DECIMAL(12,2) DEFAULT 0.00,
    notes TEXT DEFAULT NULL,
    discount_type VARCHAR(20) DEFAULT 'Percentage',
    discount_value DECIMAL(12,2) DEFAULT 0.00,
    status ENUM('paid','pending') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_inv_number (user_id, invoice_number),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    slug VARCHAR(30) NOT NULL UNIQUE,
    price DECIMAL(10,2) DEFAULT 0.00,
    features JSON DEFAULT NULL,
    badge VARCHAR(50) DEFAULT '',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    slug VARCHAR(50) NOT NULL UNIQUE,
    type ENUM('invoice','receipt') NOT NULL,
    theme_class VARCHAR(50) NOT NULL,
    thumbnail VARCHAR(255) DEFAULT '',
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Add plan & template columns to existing tables
ALTER TABLE users ADD COLUMN plan_id INT DEFAULT 1 REFERENCES plans(id);
ALTER TABLE users ADD COLUMN plan_updated_at DATETIME DEFAULT NULL;
ALTER TABLE users ADD COLUMN plan_expires_at DATETIME DEFAULT NULL;
ALTER TABLE users ADD COLUMN trial_ends_at DATETIME DEFAULT NULL;
ALTER TABLE users ADD COLUMN role ENUM('user','admin') DEFAULT 'user';
ALTER TABLE users ADD COLUMN admin_pages TEXT DEFAULT NULL;
ALTER TABLE users ADD COLUMN suspended TINYINT(1) DEFAULT 0;
ALTER TABLE users ADD COLUMN logo_path VARCHAR(255) DEFAULT '';
ALTER TABLE users ADD COLUMN last_active DATETIME DEFAULT NULL;
ALTER TABLE users ADD COLUMN selected_template_id INT DEFAULT NULL REFERENCES templates(id);
ALTER TABLE users ADD COLUMN selected_receipt_template_id INT DEFAULT NULL REFERENCES templates(id);
ALTER TABLE users ADD COLUMN currency VARCHAR(10) DEFAULT 'NGN';
ALTER TABLE users ADD COLUMN invoice_default_notes TEXT DEFAULT NULL;
ALTER TABLE users ADD COLUMN receipt_default_notes TEXT DEFAULT NULL;
ALTER TABLE users ADD COLUMN password_updated_at DATETIME DEFAULT NULL;
ALTER TABLE invoices ADD COLUMN template_id INT DEFAULT NULL REFERENCES templates(id);

CREATE TABLE customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) DEFAULT '',
    phone VARCHAR(30) DEFAULT '',
    company VARCHAR(100) DEFAULT '',
    billing_address TEXT DEFAULT NULL,
    balance_due DECIMAL(12,2) DEFAULT 0.00,
    last_active DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    receipt_number VARCHAR(50) NOT NULL,
    customer_name VARCHAR(100) NOT NULL DEFAULT '',
    invoice_number VARCHAR(50) DEFAULT '',
    linked_receipt VARCHAR(50) NOT NULL DEFAULT '',
    invoice_key VARCHAR(50) GENERATED ALWAYS AS (NULLIF(invoice_number, '')) STORED,
    amount DECIMAL(12,2) DEFAULT 0.00,
    balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    payment_date DATE DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    status ENUM('paid','processing','failed') DEFAULT 'processing',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pay_receipt (user_id, receipt_number),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE number_sequences (
    user_id INT NOT NULL,
    doc_type ENUM('invoice','receipt') NOT NULL,
    last_value INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, doc_type),
    CONSTRAINT fk_numseq_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    category ENUM('Service','Product') DEFAULT 'Service',
    description TEXT DEFAULT '',
    unit_price DECIMAL(12,2) DEFAULT 0.00,
    stock VARCHAR(20) DEFAULT 'N/A',
    last_updated DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action_type VARCHAR(50) NOT NULL,
    description VARCHAR(255) NOT NULL,
    reference_type VARCHAR(50) DEFAULT NULL,
    reference_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE invoice_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    name VARCHAR(200) NOT NULL,
    type VARCHAR(20) DEFAULT 'product',
    description TEXT DEFAULT '',
    quantity INT DEFAULT 1,
    unit_price DECIMAL(12,2) DEFAULT 0.00,
    amount DECIMAL(12,2) DEFAULT 0.00,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Seed plans
INSERT INTO plans (name, slug, price, features, badge) VALUES
('Free', 'free', 0.00, '["Basic invoice & receipt creation","Up to 50 invoices/month","Standard layout"]', 'Current'),
('Pro', 'pro', 1999.00, '["Unlimited invoices & receipts","Premium templates (6 invoice + 3 receipt)","Account details on invoices","Priority support"]', 'Popular');

-- Seed templates
INSERT INTO templates (name, slug, type, theme_class, sort_order) VALUES
('Modern', 'modern', 'invoice', 'd-modern', 1),
('Classic', 'classic', 'invoice', 'd-classic', 2),
('Minimal', 'minimal', 'invoice', 'd-minimal', 3),
('Professional', 'professional', 'invoice', 'd-professional', 4),
('Creative', 'creative', 'invoice', 'd-creative', 5),
('Bold', 'bold', 'invoice', 'd-bold', 6),
('Standard Receipt', 'receipt-standard', 'receipt', 'd-receipt-standard', 7),
('Simple Receipt', 'receipt-simple', 'receipt', 'd-receipt-simple', 8),
('Detailed Receipt', 'receipt-detailed', 'receipt', 'd-receipt-detailed', 9);

-- Seed data for demo
INSERT INTO users (full_name, business_name, business_abbr, email, phone, business_phone, business_address, bank_name, account_name, account_number, password, plan_id) VALUES
('Tunde Adeyemi', 'BizVaza HQ', 'BIZ', 'tunde@bizvaza.com', '+234 812 345 6789', '+234 812 345 6789', '254 AdaGeorge Road, Rumuochita, Portharcourt, Nigeria.', 'First Bank of Nigeria', 'BizVaza HQ', '0123456789', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 2);

INSERT INTO users (full_name, business_name, email, phone, business_phone, password, role) VALUES
('BizVaza Admin', 'BizVaza', 'bizvaza@gmail.com', '+234 800 000 0000', '+234 800 000 0000', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');

INSERT INTO invoices (user_id, invoice_number, customer_name, issue_date, due_date, amount) VALUES
(1, 'INV-2026-001', 'Victoria Ventures', '2026-06-10', '2026-06-24', 150000.00),
(1, 'INV-2026-002', 'Bright Solutions', '2026-06-12', '2026-06-26', 100000.00),
(1, 'INV-2026-003', 'Tunde Adeyemi', '2026-06-20', '2026-07-04', 250000.00),
(1, 'INV-2026-004', 'Naija Events', '2026-06-15', '2026-06-29', 200000.00),
(1, 'INV-2026-005', 'Kelechi Nwosu', '2026-06-01', '2026-06-15', 75000.00);

INSERT INTO customers (user_id, name, email, phone, company, balance_due, last_active) VALUES
(1, 'Tunde Adeyemi', 'tunde@adeyemi.com', '(812) 255-6678', 'DSR Corp', 250000.00, '2026-06-11'),
(1, 'Victoria Ventures', 'victoria@ventures.com', '(812) 255-5026', 'V-Group', 150000.00, '2026-06-16'),
(1, 'Bright Solutions', 'bright@solutions.com', '(812) 255-3056', 'Bright Intl', 100000.00, '2026-06-21');

INSERT INTO payments (user_id, receipt_number, customer_name, invoice_number, amount, payment_date, status) VALUES
(1, 'RCP-NG-0023', 'Victoria Ventures', 'INV-2026-001', 150000.00, '2026-06-22', 'paid'),
(1, 'RCP-NG-0022', 'Bright Solutions', 'INV-2026-002', 100000.00, '2026-06-25', 'paid'),
(1, 'RCP-NG-0021', 'Naija Events', 'INV-2026-004', 200000.00, '2026-06-24', 'paid'),
(1, 'RCP-NG-0020', 'Zikoko', 'INV-0042', 25000.00, '2026-06-09', 'processing'),
(1, 'RCP-NG-0019', 'Konga', 'INV-0041', 12500.00, '2026-06-08', 'failed');

-- Seed document counters from existing highest numeric suffixes
INSERT IGNORE INTO number_sequences (user_id, doc_type, last_value)
SELECT user_id, 'invoice', COALESCE(MAX(CAST(SUBSTRING_INDEX(invoice_number,'-',-1) AS UNSIGNED)),0)
FROM invoices GROUP BY user_id;
INSERT IGNORE INTO number_sequences (user_id, doc_type, last_value)
SELECT user_id, 'receipt', COALESCE(MAX(CAST(SUBSTRING_INDEX(receipt_number,'-',-1) AS UNSIGNED)),0)
FROM payments GROUP BY user_id;

INSERT INTO items (user_id, name, category, description, unit_price, stock, last_updated) VALUES
(1, 'Software Development (Standard)', 'Service', 'Custom web app module', 150000.00, 'N/A', '2026-06-10'),
(1, 'SEO Optimization Package', 'Service', 'Comprehensive SEO audit and setup', 75000.00, 'N/A', '2026-05-29'),
(1, 'Business Plan Template', 'Product', 'Detailed 20-page business plan', 15000.00, '125', '2026-06-14'),
(1, 'Social Media Management (Pro)', 'Service', 'Daily posts for 3 platforms', 50000.00, 'N/A', '2026-05-20'),
(1, 'Marketing Consultancy (Hourly)', 'Service', 'Strategy and advice (per hour)', 20000.00, 'N/A', '2026-06-12');

CREATE TABLE help_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    callback_date DATE DEFAULT NULL,
    callback_time TIME DEFAULT NULL,
    message TEXT DEFAULT NULL,
    status ENUM('pending','contacted','resolved') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE settings (
    `key` VARCHAR(50) PRIMARY KEY,
    `value` TEXT NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO settings (`key`, `value`) VALUES
('payment_bank_name', 'First Bank of Nigeria'),
('payment_account_name', 'BizVaza Nigeria Ltd'),
('payment_account_number', '0123456789'),
('paystack_public_key', ''),
('paystack_secret_key', '');

CREATE TABLE password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) NOT NULL,
    token VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO invoice_items (invoice_id, name, type, description, quantity, unit_price, amount) VALUES
(1, 'Software Development (Standard)', 'service', 'Custom web app module', 1, 150000.00, 150000.00),
(2, 'SEO Optimization Package', 'service', 'Comprehensive SEO audit and setup', 1, 75000.00, 75000.00),
(2, 'Social Media Management (Pro)', 'service', 'Daily posts for 3 platforms', 1, 25000.00, 25000.00),
(3, 'Business Plan Template', 'product', 'Detailed 20-page business plan', 5, 15000.00, 75000.00),
(3, 'Marketing Consultancy (Hourly)', 'service', 'Strategy and advice', 5, 20000.00, 100000.00),
(3, 'Software Development (Standard)', 'service', 'Custom web app module', 1, 75000.00, 75000.00),
(4, 'Social Media Management (Pro)', 'service', 'Daily posts for 3 platforms', 2, 50000.00, 100000.00),
(4, 'SEO Optimization Package', 'service', 'Comprehensive SEO audit and setup', 1, 75000.00, 75000.00),
(4, 'Software Development (Standard)', 'service', 'Custom web app module', 1, 25000.00, 25000.00),
(5, 'Business Plan Template', 'product', 'Detailed 20-page business plan', 5, 15000.00, 75000.00);

INSERT INTO activity_log (user_id, action_type, description, reference_type, reference_id, created_at) VALUES
(1, 'invoice_created', 'Invoice INV-2026-001 created for Victoria Ventures — ₦150,000.00', 'invoice', 1, '2026-06-10 09:15:00'),
(1, 'invoice_created', 'Invoice INV-2026-002 created for Bright Solutions — ₦100,000.00', 'invoice', 2, '2026-06-12 11:30:00'),
(1, 'invoice_created', 'Invoice INV-2026-003 created for Tunde Adeyemi — ₦250,000.00', 'invoice', 3, '2026-06-20 14:00:00'),
(1, 'invoice_created', 'Invoice INV-2026-004 created for Naija Events — ₦200,000.00', 'invoice', 4, '2026-06-15 10:45:00'),
(1, 'invoice_created', 'Invoice INV-2026-005 created for Kelechi Nwosu — ₦75,000.00', 'invoice', 5, '2026-06-01 16:20:00'),
(1, 'customer_added', 'Customer Tunde Adeyemi added to DSR Corp', 'customer', 1, '2026-05-20 08:00:00'),
(1, 'customer_added', 'Customer Victoria Ventures added to V-Group', 'customer', 2, '2026-05-22 12:00:00'),
(1, 'customer_added', 'Customer Bright Solutions added to Bright Intl', 'customer', 3, '2026-05-23 09:30:00'),
(1, 'item_added', 'Item Software Development (Standard) added — ₦150,000.00', 'item', 1, '2026-05-15 10:00:00'),
(1, 'item_added', 'Item SEO Optimization Package added — ₦75,000.00', 'item', 2, '2026-05-16 14:30:00'),
(1, 'item_added', 'Item Business Plan Template added — ₦15,000.00', 'item', 3, '2026-05-17 09:00:00'),
(1, 'item_added', 'Item Social Media Management (Pro) added — ₦50,000.00', 'item', 4, '2026-05-18 11:15:00'),
(1, 'item_added', 'Item Marketing Consultancy (Hourly) added — ₦20,000.00', 'item', 5, '2026-05-19 16:45:00'),
(1, 'payment_recorded', 'Payment of ₦150,000.00 received from Victoria Ventures', 'payment', 1, '2026-06-22 15:00:00'),
(1, 'payment_recorded', 'Payment of ₦100,000.00 received from Bright Solutions', 'payment', 2, '2026-06-25 11:00:00'),
(1, 'payment_recorded', 'Payment of ₦200,000.00 received from Naija Events', 'payment', 3, '2026-06-24 10:30:00'),
(1, 'payment_recorded', 'Payment of ₦25,000.00 processing from Zikoko', 'payment', 4, '2026-06-09 09:00:00'),
(1, 'payment_recorded', 'Payment of ₦12,500.00 failed from Konga', 'payment', 5, '2026-06-08 14:00:00');

CREATE TABLE subscription_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    reference VARCHAR(100) NOT NULL UNIQUE,
    amount DECIMAL(12,2) DEFAULT 0.00,
    method ENUM('paystack','bank_transfer') DEFAULT 'paystack',
    status ENUM('pending','approved','rejected','verified') DEFAULT 'pending',
    proof_path VARCHAR(255) DEFAULT NULL,
    paystack_reference VARCHAR(100) DEFAULT NULL,
    admin_note TEXT DEFAULT NULL,
    verified_by INT DEFAULT NULL,
    verified_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    rating TINYINT(1) NOT NULL DEFAULT 5,
    review_text TEXT NOT NULL,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

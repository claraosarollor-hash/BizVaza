<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];
$first_name = explode(' ', $full_name)[0];
$activePage = 'home';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>BizVaza – Home</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="assets/css/styles.css">
<style>
body.home-shell .content{padding:0!important;background:var(--bg-card)}
            .hero {
                display: grid;
                grid-template-columns: 1fr 1fr;
                align-items: center;
                gap: 48px;
                padding: 80px 48px 64px;
                max-width: 1200px;
                margin: 0 auto;
            }
            .hero-badge {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                color:var(--accent);
                font-size: 13px;
                font-weight: 600;
                margin-bottom: 20px;
            }
            .hero-badge .flag {
                font-size: 16px;
            }
            .hero h1 {
                font-size: 52px;
                font-weight: 800;
                line-height: 1.1;
                margin-bottom: 20px;
                color:var(--text);
            }
            .hero h1 span {
                color:var(--accent);
            }
            .hero-desc {
                font-size: 16px;
                color: #555;
                margin-bottom: 32px;
                line-height: 1.7;
            }
            .hero-features {
                display: flex;
                gap: 28px;
                margin-bottom: 36px;
            }
            .hero-feature {
                display: flex;
                align-items: center;
                gap: 8px;
                font-size: 13px;
                color:var(--text-secondary);
                font-weight: 500;
            }
            .feature-icon {
                width: 32px;
                height: 32px;
                border-radius: 8px;
                background: #e5f1ff;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .feature-icon svg {
                width: 16px;
                height: 16px;
                color: #0d2141;
            }
            .hero-ctas {
                display: flex;
                align-items: center;
                gap: 16px;
                margin-bottom: 16px;
            }
            .btn-hero-primary {
                background:var(--accent);
                color: #fff;
                border: none;
                border-radius: 8px;
                padding: 14px 28px;
                font-size: 15px;
                font-weight: 700;
                cursor: pointer;
            }
            .btn-hero-primary:hover {
                background:var(--accent);
            }
            .btn-hero-secondary {
                display: flex;
                align-items: center;
                gap: 8px;
                background:var(--bg-card);
                color:var(--text);
                border: 1.5px solid #d1d5db;
                border-radius: 8px;
                padding: 13px 24px;
                font-size: 15px;
                font-weight: 600;
                cursor: pointer;
            }
            .play-icon {
                width: 20px;
                height: 20px;
                background:var(--accent);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .play-icon svg {
                width: 8px;
                height: 8px;
                margin-left: 1px;
            }
            .hero-note {
                display: flex;
                align-items: center;
                gap: 6px;
                font-size: 13px;
                color: #555;
            }
            .hero-note svg {
                color:var(--accent);
                width: 14px;
                height: 14px;
            }

            /* HERO MOCKUP */
            .hero-visual {
                position: relative;
            }
            .mockup-shell {
                display: grid;
                grid-template-columns: 140px 1fr;
                border-radius: 12px;
                overflow: hidden;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
                border: 1px solid var(--border);
                background:var(--bg-card);
                font-size: 12px;
            }
            .mock-sidebar {
                background: #0b1e38;
                color:var(--text-muted);
                padding: 0;
                display: flex;
                flex-direction: column;
            }
            .mock-sidebar-header {
                padding: 14px 12px 10px;
                border-bottom: 1px solid #142a4b;
                display: flex;
                align-items: center;
                gap: 6px;
            }
            .mock-logo-icon {
                width: 22px;
                height: 22px;
                background:var(--accent);
                border-radius: 4px;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
            }
            .mock-logo-text {
                font-size: 9px;
                font-weight: 800;
                color: #fff;
                letter-spacing: 0.05em;
                line-height: 1.1;
            }
            .mock-logo-sub {
                font-size: 7px;
                color:var(--accent);
                font-weight: 600;
            }
            .mock-nav {
                padding: 12px 0;
                flex: 1;
            }
            .mock-nav-item {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 8px 12px;
                font-size: 11px;
                color:var(--text-muted);
                cursor: pointer;
            }
            .mock-nav-item.active {
                background:var(--accent);
                color: #fff;
            }
            .mock-nav-item svg {
                width: 13px;
                height: 13px;
                flex-shrink: 0;
            }
            .mock-upgrade {
                margin: 8px;
                background: #0b1e38;
                border-radius: 8px;
                padding: 10px;
                text-align: center;
            }
            .mock-upgrade .crown {
                font-size: 18px;
                margin-bottom: 4px;
            }
            .mock-upgrade p {
                font-size: 9px;
                color:var(--text-muted);
                font-weight: 600;
                margin-bottom: 2px;
            }
            .mock-upgrade small {
                font-size: 8px;
                color: #5a7090;
                display: block;
                margin-bottom: 8px;
            }
            .mock-upgrade-btn {
                display: block;
                width: 100%;
                background:var(--accent);
                color: #fff;
                border: none;
                border-radius: 5px;
                padding: 5px;
                font-size: 9px;
                font-weight: 700;
                cursor: pointer;
            }

            /* Invoice panel */
            .mock-invoice {
                padding: 14px 16px;
                background:var(--bg-card);
            }
            .mock-inv-topbar {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 14px;
                padding-bottom: 10px;
                border-bottom: 1px solid #f0f0f0;
            }
            .mock-inv-back {
                display: flex;
                align-items: center;
                gap: 4px;
                font-size: 10px;
                color: #555;
            }
            .mock-inv-actions {
                display: flex;
                gap: 6px;
            }
            .mock-btn-outline {
                border: 1px solid #d1d5db;
                color: #444;
                background:var(--bg-card);
                padding: 4px 8px;
                border-radius: 5px;
                font-size: 9px;
                cursor: pointer;
                display: flex;
                align-items: center;
                gap: 3px;
            }
            .mock-btn-green {
                background:var(--accent);
                color: #fff;
                border: none;
                padding: 4px 8px;
                border-radius: 5px;
                font-size: 9px;
                cursor: pointer;
                display: flex;
                align-items: center;
                gap: 3px;
            }

            .mock-inv-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-bottom: 14px;
            }
            .mock-company {
                display: flex;
                align-items: flex-start;
                gap: 6px;
            }
            .mock-c-icon {
                width: 20px;
                height: 20px;
                background: #0d2141;
                border-radius: 3px;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
                margin-top: 2px;
            }
            .mock-company-info p {
                font-size: 9px;
                color:var(--text-secondary);
                line-height: 1.5;
            }
            .mock-company-name {
                font-weight: 700;
                font-size: 10px !important;
                color:var(--text) !important;
            }
            .mock-inv-title {
                text-align: right;
            }
            .mock-inv-title h2 {
                font-size: 20px;
                font-weight: 800;
                color:var(--text);
            }
            .mock-inv-title .inv-num {
                color:var(--accent);
                font-size: 9px;
                font-weight: 700;
            }
            .mock-inv-meta {
                font-size: 9px;
                color: #555;
                margin-top: 4px;
                line-height: 1.7;
            }
            .mock-inv-meta span {
                font-weight: 600;
                color:var(--text);
            }
            .mock-badge-pending {
                display: inline-block;
                background:var(--accent-bg);
                color: #c2410c;
                font-size: 8px;
                font-weight: 700;
                padding: 2px 6px;
                border-radius: 4px;
                border: 1px solid #fed7aa;
                margin-top: 4px;
            }

            .mock-bill-to {
                margin-bottom: 14px;
            }
            .mock-bill-label {
                font-size: 9px;
                color:var(--accent);
                font-weight: 700;
                margin-bottom: 4px;
            }
            .mock-bill-info p {
                font-size: 9px;
                color: #444;
                line-height: 1.5;
            }
            .mock-bill-name {
                font-weight: 700;
                font-size: 10px;
                color:var(--text);
            }

            .mock-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 12px;
            }
            .mock-table thead tr {
                background: #0b1e38;
            }
            .mock-table thead th {
                color: #fff;
                font-size: 8px;
                font-weight: 600;
                padding: 6px 8px;
                text-align: left;
            }
            .mock-table thead th:last-child,
            .mock-table thead th:nth-child(3) {
                text-align: right;
            }
            .mock-table tbody tr {
                border-bottom: 1px solid #f5f5f5;
            }
            .mock-table tbody td {
                padding: 6px 8px;
                font-size: 9px;
                color:var(--text-secondary);
            }
            .mock-table tbody td:last-child,
            .mock-table tbody td:nth-child(3) {
                text-align: right;
            }

            .mock-totals {
                margin-left: auto;
                width: 200px;
            }
            .mock-total-row {
                display: flex;
                justify-content: space-between;
                font-size: 9px;
                color: #555;
                padding: 3px 0;
            }
            .mock-total-row.total {
                border-top: 1px solid var(--border);
                margin-top: 4px;
                padding-top: 6px;
            }
            .mock-total-row.total span {
                font-weight: 700;
                font-size: 11px;
                color:var(--accent);
            }
            .mock-total-row.total label {
                font-weight: 700;
                font-size: 10px;
                color:var(--text);
            }

            /* TRUST STRIP */
            .trust-strip {
                background:var(--bg-card-alt);
                padding: 36px 48px;
                text-align: center;
            }
            .trust-strip p {
                font-size: 13px;
                color: #555;
                margin-bottom: 24px;
            }
            .trust-strip p strong {
                color:var(--text);
            }
            .logos {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 48px;
                flex-wrap: wrap;
            }
            .brand-logo {
                font-size: 18px;
                font-weight: 800;
                color: #555;
                letter-spacing: -0.5px;
            }
            .brand-logo.paystack {
                display: flex;
                align-items: center;
                gap: 4px;
            }
            .brand-logo.paystack svg {
                width: 20px;
                height: 20px;
            }

            /* FEATURES */
            .features-section {
                padding: 80px 48px;
                max-width: 1200px;
                margin: 0 auto;
            }
            .section-eyebrow {
                color: #0d2141;
                font-size: 13px;
                font-weight: 700;
                text-align: center;
                margin-bottom: 12px;
            }
            .section-title {
                font-size: 36px;
                font-weight: 800;
                color:var(--text);
                text-align: center;
                margin-bottom: 8px;
            }
            .section-subtitle {
                font-size: 15px;
                color: #666;
                text-align: center;
                margin-bottom: 56px;
            }
            .features-grid {
                display: grid;
                grid-template-columns: repeat(5, 1fr);
                gap: 24px;
            }
            .feature-card {
                background:var(--bg-card);
                border: 1px solid var(--border);
                border-radius: 12px;
                padding: 28px 20px;
            }
            .feat-icon-wrap {
                width: 48px;
                height: 48px;
                border-radius: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
                margin-bottom: 16px;
            }
            .feat-icon-wrap svg {
                width: 24px;
                height: 24px;
            }
            .feature-card h3 {
                font-size: 14px;
                font-weight: 700;
                color:var(--text);
                margin-bottom: 8px;
            }
            .feature-card p {
                font-size: 13px;
                color: #666;
                line-height: 1.6;
            }

            /* TESTIMONIALS */
            .testimonials-section {
                padding: 80px 48px;
                background:var(--bg-card);
            }
            .testimonials-container {
                max-width: 720px;
                margin: 0 auto;
                text-align: center;
            }
            .testimonials-carousel {
                position: relative;
                min-height: 180px;
            }
            .testimonial-slide {
                display: none;
                animation: fadeIn .5s ease;
            }
            .testimonial-slide.active {
                display: block;
            }
            @keyframes fadeIn {
                from { opacity: 0; transform: translateY(12px); }
                to { opacity: 1; transform: translateY(0); }
            }
            .testimonial-stars {
                display: flex;
                justify-content: center;
                gap: 4px;
                margin-bottom: 20px;
            }
            .testimonial-stars svg {
                width: 22px;
                height: 22px;
            }
            .testimonial-text {
                font-size: 18px;
                font-weight: 500;
                color:var(--text);
                line-height: 1.7;
                font-style: italic;
                margin-bottom: 24px;
                quotes: "“" "”" "‘" "’";
            }
            .testimonial-text::before { content: "“"; }
            .testimonial-text::after { content: "”"; }
            .testimonial-author {
                font-size: 14px;
                font-weight: 700;
                color: #0d2141;
            }
            .testimonial-badge {
                display: inline-block;
                font-size: 11px;
                font-weight: 600;
                color:var(--success);
                background: #ecfdf5;
                padding: 2px 10px;
                border-radius: 20px;
                margin-top: 4px;
            }
            .testimonial-dots {
                display: flex;
                justify-content: center;
                gap: 8px;
                margin-top: 28px;
            }
            .testimonial-dot {
                width: 10px;
                height: 10px;
                border-radius: 50%;
                border: none;
                background: var(--border-input);
                cursor: pointer;
                padding: 0;
                transition: background .2s;
            }
            .testimonial-dot.active {
                background:var(--accent);
            }
            .testimonial-empty {
                font-size: 14px;
                color:var(--text-muted);
                padding: 40px 0;
            }


            @media (max-width: 900px) {
                .hero {
                    grid-template-columns: 1fr;
                    padding: 40px 20px;
                    gap: 32px;
                }
                .hero h1 {
                    font-size: 36px;
                }
                .features-grid {
                    grid-template-columns: repeat(2, 1fr);
                }
                .trust-strip,
                .features-section {
                    padding: 40px 20px;
                }
            }

            @media (max-width: 640px) {
                .hero h1{font-size:28px}
                .hero-features{flex-direction:column;gap:12px}
                .section-title{font-size:24px;padding:0 12px}
                .features-grid{grid-template-columns:1fr;gap:16px}
                .feature-card{padding:20px 16px}
                .mockup-shell{grid-template-columns:1fr}
                .mock-sidebar .mock-nav{display:flex;flex-wrap:wrap;gap:2px;padding:6px 8px}
                .mock-nav-item{padding:6px 8px;font-size:10px}
                .mock-invoice{padding:10px 12px}
                .mock-inv-header{flex-direction:column;gap:8px}
                .testimonials-section{padding:48px 20px}
                .testimonial-text{font-size:16px}
                .mock-inv-title{text-align:left}
                .mock-inv-topbar .mock-inv-actions{flex-wrap:wrap;gap:4px}
                .mock-btn-outline,.mock-btn-green{padding:3px 6px;font-size:8px}
                .trust-strip p{font-size:12px}
            }

</style>
</head>
<body class="dashboard-body home-shell">

<?php require __DIR__ . '/includes/sidebar.php'; ?>

<div class="main">
<header class="invoice-topbar">
<div class="invoice-topbar-left">
<button class="hamburger-btn" onclick="toggleSidebar()" title="Toggle sidebar"><svg class="ic-burger" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg><svg class="ic-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>
<div>
<h2>Welcome back, <?= htmlspecialchars($first_name) ?>!</h2>
<p>Browse the platform, then jump back into your dashboard.</p>
</div>
</div>
<div class="invoice-topbar-right">
<button class="icon-btn" id="bellBtn" onclick="toggleNotif(event)" style="position:relative">
<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
<span class="bell-dot"></span>
</button>
<?php require __DIR__ . '/includes/user-menu.php'; ?>
</div>
</header>

<div class="content">
        <section style="background:var(--bg-card); border-bottom: 1px solid #f0f0f0">
            <div class="hero">
                <!-- Left -->
                <div>
                    <div class="hero-badge">
                        <span class="flag">🇳🇬</span> Made for Nigerian
                        Businesses
                    </div>
                    <h1>
                        Create Professional
                        <span>Invoices and Receipts</span> in Seconds
                    </h1>
                    <p class="hero-desc">
                        Generate invoices and receipts, send to clients, and get
                        paid faster.<br />Perfect for freelancers, entrepreneurs
                        and small businesses.
                    </p>

                    <div class="hero-features">
                        <div class="hero-feature">
                            <div class="feature-icon">
                                <svg
                                    viewBox="0 0 16 16"
                                    fill="none"
                                    stroke="#0d2141"
                                    stroke-width="1.5"
                                >
                                    <rect
                                        x="2"
                                        y="1"
                                        width="10"
                                        height="14"
                                        rx="1.5"
                                    />
                                    <path d="M5 5h5M5 8h5M5 11h3" />
                                </svg>
                            </div>
                            <div>
                                <div
                                    style="
                                        font-size: 11px;
                                        color: #888;
                                        font-weight: 500;
                                    "
                                >
                                    Professional
                                </div>
                                <div style="font-weight: 700; font-size: 13px">
                                    PDF Invoices/Receipts
                                </div>
                            </div>
                        </div>
                        <div class="hero-feature">
                            <div
                                class="feature-icon"
                                style="background: #e5f1ff"
                            >
                                <svg
                                    viewBox="0 0 16 16"
                                    fill="none"
                                    stroke="#0d2141"
                                    stroke-width="1.5"
                                >
                                    <path
                                        d="M14 10c0 2.21-2.686 4-6 4S2 12.21 2 10c0-.93.45-1.8 1.2-2.52M14 6c0 2.21-2.686 4-6 4S2 8.21 2 6 4.686 2 8 2s6 1.79 6 4z"
                                    />
                                </svg>
                            </div>
                            <div>
                                <div
                                    style="
                                        font-size: 11px;
                                        color: #888;
                                        font-weight: 500;
                                    "
                                >
                                    Send via
                                </div>
                                <div style="font-weight: 700; font-size: 13px">
                                    WhatsApp
                                </div>
                            </div>
                        </div>
                        <div class="hero-feature">
                            <div
                                class="feature-icon"
                                style="background: #fefce8"
                            >
                                <svg
                                    viewBox="0 0 16 16"
                                    fill="none"
                                    stroke="#ca8a04"
                                    stroke-width="1.5"
                                >
                                    <path
                                        d="M8 2v3M8 11v3M2 8h3M11 8h3M4.22 4.22l2.12 2.12M9.66 9.66l2.12 2.12M4.22 11.78l2.12-2.12M9.66 6.34l2.12-2.12"
                                    />
                                </svg>
                            </div>
                            <div>
                                <div
                                    style="
                                        font-size: 11px;
                                        color: #888;
                                        font-weight: 500;
                                    "
                                >
                                    Get Paid
                                </div>
                                <div style="font-weight: 700; font-size: 13px">
                                    Faster
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="hero-ctas">
                        <a href="invoiceDashboard.php" style="text-decoration:none"><button class="btn-hero-primary">Create Invoice</button></a>
                    </div>
                </div>

                <!-- Right: Mockup -->
                <div class="hero-visual">
                    <div class="mockup-shell">
                        <!-- Sidebar -->
                        <div class="mock-sidebar">
                            <div class="mock-sidebar-header">
                                <div class="mock-logo-icon">
                                    <svg
                                        viewBox="0 0 20 20"
                                        fill="none"
                                        xmlns="http://www.w3.org/2000/svg"
                                    >
                                        <path
                                            d="M11 2L4 11h7l-2 7 9-10h-7l2-8z"
                                            fill="#fff"
                                        />
                                    </svg>
                                </div>
                                <div>
                                    <div class="mock-logo-text">BizVaza</div>
                                </div>
                            </div>
                            <div class="mock-nav">
                                <div class="mock-nav-item">
                                    <svg
                                        viewBox="0 0 14 14"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.5"
                                    >
                                        <rect
                                            x="1"
                                            y="1"
                                            width="5"
                                            height="5"
                                            rx="1"
                                        />
                                        <rect
                                            x="8"
                                            y="1"
                                            width="5"
                                            height="5"
                                            rx="1"
                                        />
                                        <rect
                                            x="1"
                                            y="8"
                                            width="5"
                                            height="5"
                                            rx="1"
                                        />
                                        <rect
                                            x="8"
                                            y="8"
                                            width="5"
                                            height="5"
                                            rx="1"
                                        />
                                    </svg>
                                    Dashboard
                                </div>
                                <div class="mock-nav-item active">
                                    <svg
                                        viewBox="0 0 14 14"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.5"
                                    >
                                        <rect
                                            x="1"
                                            y="1"
                                            width="12"
                                            height="12"
                                            rx="1.5"
                                        />
                                        <path d="M4 5h6M4 8h4" />
                                    </svg>
                                    Invoices
                                </div>
                                <div class="mock-nav-item">
                                    <svg
                                        viewBox="0 0 14 14"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.5"
                                    >
                                        <path d="M2 4h10M2 7h7M2 10h4" />
                                    </svg>
                                    Receipts
                                </div>
                                <div class="mock-nav-item">
                                    <svg
                                        viewBox="0 0 14 14"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.5"
                                    >
                                        <circle cx="7" cy="5" r="3" />
                                        <path
                                            d="M1 13c0-3 2.686-5 6-5s6 2 6 5"
                                        />
                                    </svg>
                                    Customers
                                </div>
                                <div class="mock-nav-item">
                                    <svg
                                        viewBox="0 0 14 14"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.5"
                                    >
                                        <rect
                                            x="1"
                                            y="3"
                                            width="12"
                                            height="9"
                                            rx="1"
                                        />
                                        <path d="M4 3V2M10 3V2M1 6h12" />
                                    </svg>
                                    Items/Services
                                </div>
                                <div class="mock-nav-item">
                                    <svg
                                        viewBox="0 0 14 14"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.5"
                                    >
                                        <rect
                                            x="1"
                                            y="1"
                                            width="12"
                                            height="12"
                                            rx="1.5"
                                        />
                                        <path d="M4 9V7M7 9V5M10 9V3" />
                                    </svg>
                                    Payments
                                </div>
                                <div class="mock-nav-item">
                                    <svg
                                        viewBox="0 0 14 14"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.5"
                                    >
                                        <circle cx="7" cy="7" r="2" />
                                        <path
                                            d="M7 1v2M7 11v2M1 7h2M11 7h2M3.22 3.22l1.42 1.42M9.36 9.36l1.42 1.42M3.22 10.78l1.42-1.42M9.36 4.64l1.42-1.42"
                                        />
                                    </svg>
                                    Settings
                                </div>
                            </div>
                        </div>

                        <!-- Invoice Panel -->
                        <div class="mock-invoice">
                            <div class="mock-inv-topbar">
                                <div class="mock-inv-actions">
                                    <button class="mock-btn-outline">
                                        <svg
                                            viewBox="0 0 10 10"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="1.5"
                                            width="9"
                                            height="9"
                                        >
                                            <path
                                                d="M5 1v6M2 7l3 2 3-2M1 9h8"
                                            />
                                        </svg>
                                        Download PDF
                                    </button>
                                    <button class="mock-btn-green">
                                        <svg
                                            viewBox="0 0 10 10"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="1.5"
                                            width="9"
                                            height="9"
                                        >
                                            <path
                                                d="M9 6c0 1.66-2.015 3-4.5 3S0 7.66 0 6c0-.696.338-1.35.9-1.89M9 4C9 5.657 6.985 7 4.5 7S0 5.657 0 4 2.015 1 4.5 1 9 2.343 9 4z"
                                            />
                                        </svg>
                                        Send via WhatsApp
                                    </button>
                                </div>
                            </div>

                            <div class="mock-inv-header">
                                <div class="mock-company">
                                    <div class="mock-c-icon">
                                        <svg
                                            viewBox="0 0 12 12"
                                            fill="#fff"
                                            width="10"
                                            height="10"
                                        >
                                            <polygon
                                                points="6,1 2,11 6,8 10,11"
                                            />
                                        </svg>
                                    </div>
                                    <div class="mock-company-info">
                                        <p class="mock-company-name">
                                            BIZVAZA
                                        </p>
                                        <p>15 Freedom Way, Lekki Phase 1,</p>
                                        <p>Lagos, Nigeria.</p>
                                        <p>+234 812 345 6789</p>
                                        <p>hello@bizvaza.ng</p>
                                    </div>
                                </div>
                                <div class="mock-inv-title">
                                    <h2>INVOICE</h2>
                                    <div class="inv-num">INV-2026-001</div>
                                    <div class="mock-inv-meta">
                                        Issue Date: <span>May 28, 2026</span
                                        ><br />
                                        Due Date: <span>June 11, 2026</span>
                                    </div>
                                    <div class="mock-badge-pending">
                                        PENDING
                                    </div>
                                </div>
                            </div>

                            <div class="mock-bill-to">
                                <div class="mock-bill-label">Bill To</div>
                                <p class="mock-bill-name">Tunde Adeyemi</p>
                                <p style="font-size: 9px; color: #444">
                                    0803 123 4567
                                </p>
                                <p style="font-size: 9px; color: #444">
                                    tunde.adeyemi@gmail.com
                                </p>
                                <p style="font-size: 9px; color: #444">
                                    Victoria Island, Lagos, Nigeria.
                                </p>
                            </div>

                            <table class="mock-table">
                                <thead>
                                    <tr>
                                        <th style="width: 20px">#</th>
                                        <th>Item Description</th>
                                        <th style="width: 30px">Qty</th>
                                        <th>Unit Price (₦)</th>
                                        <th>Amount (₦)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>1</td>
                                        <td>Portfolio Website Design</td>
                                        <td>1</td>
                                        <td style="text-align: right">
                                            150,000.00
                                        </td>
                                        <td style="text-align: right">
                                            150,000.00
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>2</td>
                                        <td>Domain Name Setup</td>
                                        <td>1</td>
                                        <td style="text-align: right">
                                            50,000.00
                                        </td>
                                        <td style="text-align: right">
                                            50,000.00
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>3</td>
                                        <td>Website Hosting (1 Year)</td>
                                        <td>1</td>
                                        <td style="text-align: right">
                                            30,000.00
                                        </td>
                                        <td style="text-align: right">
                                            30,000.00
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>4</td>
                                        <td>Basic SEO Setup</td>
                                        <td>1</td>
                                        <td style="text-align: right">
                                            20,000.00
                                        </td>
                                        <td style="text-align: right">
                                            20,000.00
                                        </td>
                                    </tr>
                                </tbody>
                            </table>

                            <div class="mock-totals">
                                <div class="mock-total-row">
                                    <label>Subtotal</label
                                    ><span
                                        style="
                                            color:var(--text-secondary);
                                            font-size: 9px;
                                            font-weight: 600;
                                        "
                                        >₦250,000.00</span
                                    >
                                </div>
                                <div class="mock-total-row">
                                    <label>Discount (0%)</label
                                    ><span
                                        style="
                                            color:var(--text-secondary);
                                            font-size: 9px;
                                            font-weight: 600;
                                        "
                                        >₦0.00</span
                                    >
                                </div>
                                <div class="mock-total-row total">
                                    <label>Total Amount</label
                                    ><span>₦250,000.00</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        <div class="trust-strip">
            <p>Trusted by <strong>1,000+</strong> businesses across Nigeria</p>
        </div>
        <section style="background: #f8fafc; padding: 80px 0">
            <div
                class="features-section"
                style="padding-top: 0; padding-bottom: 0"
            >
                <div class="section-eyebrow">
                    Everything You Need to Get Paid
                </div>
                <div class="section-title">
                    Powerful Features for Smart Businesses
                </div>
                <div class="section-subtitle">
                    Built specifically for Nigerian freelancers and businesses
                </div>

                <div class="features-grid">
                    <!-- Card 1 -->
                    <div class="feature-card">
                        <div class="feat-icon-wrap" style="background: #e5f1ff">
                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="#0d2141"
                                stroke-width="1.8"
                            >
                                <rect
                                    x="4"
                                    y="2"
                                    width="14"
                                    height="20"
                                    rx="2"
                                />
                                <path d="M8 8h8M8 12h8M8 16h5" />
                            </svg>
                        </div>
                        <h3>Create Invoices and Receipts</h3>
                        <p>
                            Create professional invoices and receipts in seconds
                            with our easy-to-use builder.
                        </p>
                    </div>

                    <!-- Card 2 -->
                    <div class="feature-card">
                        <div class="feat-icon-wrap" style="background: #e5f1ff">
                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="#0d2141"
                                stroke-width="1.8"
                            >
                                <path
                                    d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"
                                />
                            </svg>
                        </div>
                        <h3>Send via WhatsApp</h3>
                        <p>
                            Send invoices and receipts directly to your clients
                            via WhatsApp with just one click.
                        </p>
                    </div>

                    <!-- Card 3 -->
                    <div class="feature-card">
                        <div class="feat-icon-wrap" style="background: #ffe8cc">
                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="#d97706"
                                stroke-width="1.8"
                            >
                                <circle cx="12" cy="12" r="9" />
                                <path d="M12 6v6l4 2" />
                            </svg>
                        </div>
                        <h3>Get Paid Faster</h3>
                        <p>
                            Add your bank details and get paid faster by your
                            clients.
                        </p>
                    </div>

                    <!-- Card 4 -->
                    <div class="feature-card">
                        <div class="feat-icon-wrap" style="background: #e5f1ff">
                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="#0d2141"
                                stroke-width="1.8"
                            >
                                <path d="M12 20V10M18 20V4M6 20v-4" />
                            </svg>
                        </div>
                        <h3>Track &amp; Manage</h3>
                        <p>
                            Track payments, view reports and manage your
                            business finances easily.
                        </p>
                    </div>

                    <!-- Card 5 -->
                    <div class="feature-card">
                        <div class="feat-icon-wrap" style="background: #ffe8cc">
                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="#d97706"
                                stroke-width="1.8"
                            >
                                <path
                                    d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"
                                />
                            </svg>
                        </div>
                        <h3>Secure &amp; Safe</h3>
                        <p>
                            Your data is secure with us. We use
                            industry-standard security.
                        </p>
                    </div>
                </div>
            </div>
        </section>
        <section class="testimonials-section">
            <div class="testimonials-container">
                <div class="section-eyebrow">Trusted by Businesses</div>
                <div class="section-title">What Our Users Say</div>
                <div class="section-subtitle">Real feedback from real business owners</div>
                <div class="testimonials-carousel" id="testimonialCarousel">
                    <div class="testimonial-empty" id="testimonialEmpty">Loading reviews…</div>
                </div>
                <div class="testimonial-dots" id="testimonialDots"></div>
            </div>
        </section>
</div>

<script src="assets/js/app.js"></script>
<script src="assets/js/home-reviews.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
<?php require __DIR__ . '/includes/notification.php'; ?>
</div>
</body>
</html>
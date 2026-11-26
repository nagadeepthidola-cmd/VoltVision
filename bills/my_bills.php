<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . "/../config/db.php";

$userId = (int) $_SESSION["user_id"];
$userName = $_SESSION["user_name"] ?? "User";

function h($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function formatDate($date) {
    if (empty($date) || $date === "0000-00-00") {
        return "N/A";
    }

    return date("d M Y", strtotime($date));
}

// Fetch only the logged-in user's bills
$sql = "SELECT
            id,
            image_path,
            units_kwh,
            amount,
            bill_date,
            due_date,
            current_reading,
            previous_reading,
            created_at
        FROM bills
        WHERE user_id = ?
        ORDER BY created_at DESC, id DESC";

$stmt = $conn->prepare($sql);

$bills = [];
$dbError = false;

if ($stmt) {
    $stmt->bind_param("i", $userId);

    if ($stmt->execute()) {
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $bills[] = $row;
        }
    } else {
        $dbError = true;
    }

    $stmt->close();
} else {
    $dbError = true;
}

$totalBills = count($bills);
$totalUnits = 0;
$totalAmount = 0;

foreach ($bills as $bill) {
    $totalUnits += (float)($bill["units_kwh"] ?? 0);
    $totalAmount += (float)($bill["amount"] ?? 0);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>My Bills | VoltVision</title>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

<style>
:root {
    --bg: #030b20;
    --panel: #071a35;
    --cyan: #20d9ff;
    --green: #38ed9b;
    --text: #f4f8ff;
    --muted: #9aacc7;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Inter', sans-serif;
}

body {
    min-height: 100vh;
    color: var(--text);
    background:
        radial-gradient(circle at 15% 15%, rgba(0,170,255,.13), transparent 30%),
        radial-gradient(circle at 90% 65%, rgba(0,255,160,.09), transparent 28%),
        var(--bg);
}

.navbar {
    height: 76px;
    padding: 0 4%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 100;
    background: rgba(3,11,32,.94);
    backdrop-filter: blur(18px);
    border-bottom: 1px solid rgba(50,190,255,.13);
}

.logo {
    font-size: 27px;
    font-weight: 900;
    color: white;
    letter-spacing: -1px;
    text-decoration: none;
}

.logo span {
    color: var(--green);
}

.logo-icon {
    color: var(--green);
    margin-right: 7px;
}

.menu-area {
    position: relative;
}

.menu-button {
    width: 48px;
    height: 45px;
    border: 1px solid rgba(32,217,255,.4);
    border-radius: 12px;
    background: rgba(20,180,220,.10);
    color: var(--cyan);
    font-size: 25px;
    cursor: pointer;
}

.dropdown {
    display: none;
    position: absolute;
    right: 0;
    top: 57px;
    width: 225px;
    padding: 10px;
    background: #081a36;
    border: 1px solid rgba(32,217,255,.3);
    border-radius: 15px;
    box-shadow: 0 15px 40px rgba(0,0,0,.45);
}

.dropdown.show {
    display: block;
}

.dropdown a {
    display: block;
    padding: 12px 14px;
    color: #dceaff;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    border-radius: 9px;
}

.dropdown a:hover,
.dropdown a.active {
    background: rgba(32,217,255,.13);
    color: var(--cyan);
}

.dropdown .logout {
    color: #ff8d9a;
    border-top: 1px solid rgba(255,255,255,.08);
    margin-top: 5px;
}

.container {
    max-width: 1250px;
    margin: 30px auto;
    padding: 0 25px;
}

.hero {
    min-height: 230px;
    padding: 38px 42px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 25px;
    border: 1px solid rgba(40,180,255,.25);
    border-radius: 25px;
    background:
        radial-gradient(circle at 75% 50%, rgba(0,180,255,.17), transparent 35%),
        linear-gradient(110deg,#06132d,#08234a 65%,#063a45);
    box-shadow: 0 15px 45px rgba(0,0,0,.25);
}

.hero-content {
    max-width: 700px;
}

.tag {
    display: inline-block;
    padding: 9px 15px;
    border: 1px solid rgba(32,217,255,.35);
    border-radius: 30px;
    color: var(--green);
    background: rgba(32,217,255,.09);
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 1.5px;
    margin-bottom: 18px;
}

.hero h1 {
    font-size: clamp(27px,4vw,40px);
    font-weight: 900;
    line-height: 1.2;
    margin-bottom: 14px;
}

.hero h1 span {
    color: var(--green);
}

.hero p {
    color: #c0d1e9;
    font-size: 13px;
    line-height: 1.9;
}

.hero-art {
    font-size: 105px;
    filter: drop-shadow(0 0 25px rgba(32,217,255,.5));
}

.stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 18px;
    margin: 25px 0;
}

.stat-card {
    padding: 23px;
    background: linear-gradient(145deg,rgba(10,31,62,.96),rgba(5,19,42,.97));
    border: 1px solid rgba(45,170,255,.22);
    border-radius: 17px;
}

.stat-card .icon {
    font-size: 25px;
    margin-bottom: 12px;
}

.stat-card p {
    color: var(--muted);
    font-size: 12px;
    margin-bottom: 8px;
}

.stat-card h2 {
    font-size: 25px;
    color: var(--green);
}

.section-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    margin: 30px 0 18px;
}

.section-heading h2 {
    font-size: 21px;
}

.upload-btn {
    display: inline-block;
    padding: 12px 18px;
    border-radius: 10px;
    background: linear-gradient(100deg,#087ec1,#08b98a);
    color: white;
    text-decoration: none;
    font-size: 12px;
    font-weight: 800;
}

.bill-list {
    display: grid;
    gap: 18px;
}

.bill-card {
    padding: 23px;
    background: linear-gradient(145deg,rgba(10,31,62,.96),rgba(5,19,42,.97));
    border: 1px solid rgba(45,170,255,.22);
    border-radius: 18px;
    box-shadow: 0 12px 35px rgba(0,0,0,.16);
}

.bill-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    padding-bottom: 17px;
    border-bottom: 1px solid rgba(150,190,220,.12);
}

.bill-title {
    font-size: 16px;
    font-weight: 800;
}

.bill-date {
    color: var(--muted);
    font-size: 11px;
    margin-top: 7px;
}

.bill-amount {
    color: var(--green);
    font-size: 21px;
    font-weight: 900;
    white-space: nowrap;
}

.bill-details {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 18px;
    padding-top: 20px;
}

.detail-item p {
    color: var(--muted);
    font-size: 11px;
    margin-bottom: 7px;
}

.detail-item strong {
    color: #e9f5ff;
    font-size: 13px;
    overflow-wrap: anywhere;
}

.bill-image {
    display: inline-block;
    margin-top: 20px;
    color: var(--cyan);
    text-decoration: none;
    font-size: 12px;
    font-weight: 700;
    padding: 10px 14px;
    border: 1px solid rgba(32,217,255,.3);
    border-radius: 9px;
}

.bill-image:hover {
    background: rgba(32,217,255,.12);
}

.empty-state {
    padding: 55px 20px;
    text-align: center;
    background: linear-gradient(145deg,rgba(10,31,62,.96),rgba(5,19,42,.97));
    border: 1px solid rgba(45,170,255,.22);
    border-radius: 20px;
}

.empty-state .empty-icon {
    font-size: 65px;
    margin-bottom: 18px;
}

.empty-state h2 {
    font-size: 21px;
    margin-bottom: 10px;
}

.empty-state p {
    color: var(--muted);
    font-size: 13px;
    line-height: 1.8;
    margin-bottom: 22px;
}

.error-message {
    padding: 16px;
    border-radius: 11px;
    background: rgba(255,80,100,.10);
    border: 1px solid rgba(255,100,120,.4);
    color: #ff9ba8;
    font-size: 13px;
}

.bottom-links {
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: 12px;
    margin: 30px 0;
}

.bottom-links a {
    color: var(--cyan);
    text-decoration: none;
    padding: 11px 17px;
    border: 1px solid rgba(32,217,255,.3);
    border-radius: 10px;
    font-size: 12px;
    font-weight: 700;
}

.footer {
    text-align: center;
    color: #7389a8;
    padding: 25px 15px;
    font-size: 11px;
    border-top: 1px solid rgba(100,160,220,.1);
    margin-top: 25px;
}

@media(max-width:700px) {
    .stats {
        grid-template-columns: 1fr;
    }

    .bill-details {
        grid-template-columns: repeat(2, 1fr);
    }

    .hero-art {
        font-size: 65px;
    }

    .hero {
        padding: 27px 22px;
    }
}

@media(max-width:500px) {
    .navbar {
        height: 68px;
        padding: 0 18px;
    }

    .container {
        padding: 0 14px;
    }

    .hero-art {
        display: none;
    }

    .bill-top {
        align-items: flex-start;
        flex-direction: column;
    }

    .bill-details {
        grid-template-columns: 1fr 1fr;
    }

    .section-heading {
        align-items: flex-start;
        flex-direction: column;
    }
}
</style>
</head>

<body>

<nav class="navbar">
    <a href="../dashboard.php" class="logo">
        <span class="logo-icon">⚡</span>Volt<span>Vision</span>
    </a>

    <div class="menu-area">
        <button class="menu-button" id="menuButton"
                aria-label="Open navigation menu"
                aria-expanded="false">☰</button>

        <div class="dropdown" id="dropdownMenu">
            <a href="../dashboard.php">⌂ Dashboard</a>
            <a href="upload_bill.php">📄 Upload Bill</a>
            <a href="my_bills.php" class="active">🧾 My Bills</a>
            <a href="../predict_bill.php">⚡ Predict Bill</a>
            <a href="../my_predictions.php">🤖 My Predictions</a>
            <a href="../analytics.php">📊 Analytics</a>
            <a href="../logout.php" class="logout">↗ Logout</a>
        </div>
    </div>
</nav>

<main class="container">

    <section class="hero">
        <div class="hero-content">
            <span class="tag">⚡ SMART ENERGY MANAGEMENT</span>

            <h1>My <span>Electricity Bills</span> 🧾</h1>

            <p>
                Hello, <?= h($userName) ?>! Here you can view all your
                uploaded electricity bills, consumption details and
                payment amounts in one place.
            </p>
        </div>

        <div class="hero-art">📊</div>
    </section>

    <?php if (!$dbError): ?>

        <section class="stats">

            <div class="stat-card">
                <div class="icon">🧾</div>
                <p>Total Uploaded Bills</p>
                <h2><?= $totalBills ?></h2>
            </div>

            <div class="stat-card">
                <div class="icon">⚡</div>
                <p>Total Recorded Consumption</p>
                <h2><?= number_format($totalUnits, 2) ?> kWh</h2>
            </div>

            <div class="stat-card">
                <div class="icon">💰</div>
                <p>Total Recorded Bill Amount</p>
                <h2>₹<?= number_format($totalAmount, 2) ?></h2>
            </div>

        </section>

    <?php endif; ?>

    <div class="section-heading">
        <h2>📋 Your Bill History</h2>

        <a href="upload_bill.php" class="upload-btn">
            + Upload New Bill
        </a>
    </div>

    <?php if ($dbError): ?>

        <div class="error-message">
            Unable to load your bills. Please check the database connection
            and bills table structure.
        </div>

    <?php elseif (empty($bills)): ?>

        <section class="empty-state">
            <div class="empty-icon">📄</div>

            <h2>No Bills Uploaded Yet</h2>

            <p>
                Your electricity bill history will appear here after
                you upload and analyze your first bill.
            </p>

            <a href="upload_bill.php" class="upload-btn">
                ⚡ Upload Your First Bill
            </a>
        </section>

    <?php else: ?>

        <section class="bill-list">

            <?php foreach ($bills as $index => $bill): ?>

                <article class="bill-card">

                    <div class="bill-top">

                        <div>
                            <div class="bill-title">
                                🧾 Electricity Bill #<?= $index + 1 ?>
                            </div>

                            <div class="bill-date">
                                Bill Date:
                                <?= h(formatDate($bill["bill_date"])) ?>
                            </div>
                        </div>

                        <div class="bill-amount">
                            ₹<?= number_format((float)$bill["amount"], 2) ?>
                        </div>

                    </div>

                    <div class="bill-details">

                        <div class="detail-item">
                            <p>Electricity Units</p>
                            <strong>
                                <?= $bill["units_kwh"] !== null
                                    ? number_format((float)$bill["units_kwh"], 2) . " kWh"
                                    : "N/A" ?>
                            </strong>
                        </div>

                        <div class="detail-item">
                            <p>Due Date</p>
                            <strong>
                                <?= h(formatDate($bill["due_date"])) ?>
                            </strong>
                        </div>

                        <div class="detail-item">
                            <p>Current Reading</p>
                            <strong>
                                <?= $bill["current_reading"] !== null
                                    ? h($bill["current_reading"])
                                    : "N/A" ?>
                            </strong>
                        </div>

                        <div class="detail-item">
                            <p>Previous Reading</p>
                            <strong>
                                <?= $bill["previous_reading"] !== null
                                    ? h($bill["previous_reading"])
                                    : "N/A" ?>
                            </strong>
                        </div>

                        <div class="detail-item">
                            <p>Uploaded On</p>
                            <strong>
                                <?= h(formatDate($bill["created_at"])) ?>
                            </strong>
                        </div>

                        <div class="detail-item">
                            <p>Bill Record ID</p>
                            <strong>#<?= (int)$bill["id"] ?></strong>
                        </div>

                    </div>

                    <?php if (!empty($bill["image_path"])): ?>
                        <a
                            class="bill-image"
                            href="../<?= h(ltrim($bill["image_path"], "/")) ?>"
                            target="_blank"
                            rel="noopener"
                        >
                            🖼️ View Uploaded Bill Image
                        </a>
                    <?php endif; ?>

                </article>

            <?php endforeach; ?>

        </section>

    <?php endif; ?>

    <div class="bottom-links">
        <a href="../dashboard.php">← Back to Dashboard</a>
        <a href="upload_bill.php">Upload Another Bill →</a>
    </div>

</main>

<footer class="footer">
    © <?= date("Y") ?> VoltVision | Smart Energy. Smart Savings. Better Tomorrow.
</footer>

<script>
const menuButton = document.getElementById("menuButton");
const dropdownMenu = document.getElementById("dropdownMenu");

menuButton.addEventListener("click", function(event) {
    event.stopPropagation();

    const isOpen = dropdownMenu.classList.toggle("show");

    menuButton.setAttribute(
        "aria-expanded",
        isOpen ? "true" : "false"
    );
});

document.addEventListener("click", function(event) {
    if (
        !dropdownMenu.contains(event.target) &&
        !menuButton.contains(event.target)
    ) {
        dropdownMenu.classList.remove("show");
        menuButton.setAttribute("aria-expanded", "false");
    }
});

document.addEventListener("keydown", function(event) {
    if (event.key === "Escape") {
        dropdownMenu.classList.remove("show");
        menuButton.setAttribute("aria-expanded", "false");
    }
});
</script>

</body>
</html>

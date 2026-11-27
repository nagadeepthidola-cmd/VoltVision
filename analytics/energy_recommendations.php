
<?php
// IMPORTANT: Save this file as UTF-8 without BOM.
// Do not put spaces or blank lines before this PHP opening tag.

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . "/../config/db.php";

$userId = (int) $_SESSION["user_id"];

$personalTips = [];
$billTips = [];
$generalTips = [];

$latestPrediction = null;
$latestBill = null;
$averageUnits = 0;
$billChange = null;
$billCount = 0;
$errorMessage = "";

// ==========================================
// 1. GET LATEST PREDICTION
// ==========================================

try {

    $sql = "SELECT *
            FROM predictions
            WHERE user_id = ?
            ORDER BY created_at DESC, id DESC
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $userId);
    $stmt->execute();

    $result = $stmt->get_result();
    $latestPrediction = $result->fetch_assoc();

    $stmt->close();

} catch (Throwable $e) {
    error_log("Prediction recommendation error: " . $e->getMessage());
    $errorMessage = "Unable to load prediction information.";
}


// ==========================================
// 2. APPLIANCE-BASED RECOMMENDATIONS
// ==========================================

if ($latestPrediction) {

    // Air Conditioner
    if ((float)$latestPrediction["air_conditioner_daily_hours"] >= 8) {

        $personalTips[] = [
            "title" => "Reduce AC Usage",
            "description" => "Your AC is running for many hours daily. Maintain a temperature of 24°C to 26°C and switch it off when not needed.",
            "icon" => "❄️"
        ];
    }

    // Fan
    if ((float)$latestPrediction["fan_daily_hours"] >= 10) {

        $personalTips[] = [
            "title" => "Optimize Fan Usage",
            "description" => "Your fan usage is high. Switch off fans when leaving the room and clean fan blades regularly.",
            "icon" => "🌀"
        ];
    }

    // Television
    if ((float)$latestPrediction["television_daily_hours"] >= 5) {

        $personalTips[] = [
            "title" => "Reduce Television Usage",
            "description" => "Your television is used for several hours. Switch it off when nobody is watching.",
            "icon" => "📺"
        ];
    }

    // Monitor
    if ((float)$latestPrediction["monitor_daily_hours"] >= 8) {

        $personalTips[] = [
            "title" => "Optimize Monitor Usage",
            "description" => "Enable sleep mode and turn off your monitor when it is not in use.",
            "icon" => "🖥️"
        ];
    }

    // Motor Pump
    if ((float)$latestPrediction["motor_pump_daily_hours"] >= 3) {

        $personalTips[] = [
            "title" => "Manage Motor Pump Usage",
            "description" => "Your motor pump runs for several hours. Use it only when necessary and avoid unnecessary operation.",
            "icon" => "⚙️"
        ];
    }

    // Refrigerator
    if ((int)$latestPrediction["refrigerator"] > 0) {

        $personalTips[] = [
            "title" => "Maintain Refrigerator Efficiency",
            "description" => "Avoid frequent door opening, allow proper ventilation and maintain suitable cooling settings.",
            "icon" => "🧊"
        ];
    }

    // Estimated consumption
    if ((float)$latestPrediction["estimated_units"] >= 300) {

        $personalTips[] = [
            "title" => "High Estimated Electricity Consumption",
            "description" => "Your predicted monthly consumption is high. Review appliance usage and reduce unnecessary operating hours.",
            "icon" => "⚡"
        ];
    }

    if (empty($personalTips)) {

        $personalTips[] = [
            "title" => "Good Energy Management!",
            "description" => "Your recorded appliance usage does not cross our recommendation thresholds. Continue saving energy.",
            "icon" => "🌱"
        ];
    }

} else {

    $personalTips[] = [
        "title" => "Create Your First Prediction",
        "description" => "Create an electricity bill prediction to receive appliance-based personalized recommendations.",
        "icon" => "📊",
        "link" => "../predict_bill.php",
        "link_text" => "Create Prediction"
    ];
}


// ==========================================
// 3. GET PREVIOUSLY UPLOADED ELECTRICITY BILLS
// ==========================================

try {

    $sql = "SELECT id, units_kwh, amount, bill_date, created_at
            FROM bills
            WHERE user_id = ?
            AND units_kwh IS NOT NULL
            AND units_kwh > 0
            ORDER BY bill_date DESC, id DESC
            LIMIT 6";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $userId);
    $stmt->execute();

    $result = $stmt->get_result();

    $bills = [];

    while ($row = $result->fetch_assoc()) {
        $bills[] = $row;
    }

    $stmt->close();

    $billCount = count($bills);

    if ($billCount > 0) {

        $latestBill = $bills[0];

        // Compare latest bill with previous bills
        if ($billCount >= 2) {

            $previousBills = array_slice($bills, 1);

            $totalPreviousUnits = 0;

            foreach ($previousBills as $bill) {
                $totalPreviousUnits += (float)$bill["units_kwh"];
            }

            $averageUnits = $totalPreviousUnits / count($previousBills);

            if ($averageUnits > 0) {

                $latestUnits = (float)$latestBill["units_kwh"];

                $billChange = (
                    ($latestUnits - $averageUnits) / $averageUnits
                ) * 100;

                // Consumption increased significantly
                if ($billChange > 20) {

                    $billTips[] = [
                        "title" => "High Electricity Consumption Increase",
                        "description" =>
                            "Your latest uploaded bill shows " .
                            number_format($latestUnits, 2) .
                            " kWh. This is " .
                            number_format($billChange, 1) .
                            "% higher than your previous bills average of " .
                            number_format($averageUnits, 2) .
                            " kWh. Try reducing unnecessary electricity usage.",
                        "icon" => "⚠️",
                        "type" => "warning"
                    ];

                } elseif ($billChange > 0) {

                    $billTips[] = [
                        "title" => "Monitor Your Electricity Usage",
                        "description" =>
                            "Your latest bill shows a small increase compared with your previous bills average. Monitor your daily electricity usage.",
                        "icon" => "📈",
                        "type" => "info"
                    ];

                } else {

                    $billTips[] = [
                        "title" => "Great! Your Consumption Has Reduced",
                        "description" =>
                            "Your latest bill shows lower consumption than your previous bills average. Keep following your energy-saving habits.",
                        "icon" => "🌱",
                        "type" => "success"
                    ];
                }
            }
        }

        // Display latest uploaded bill details
        $billTips[] = [
            "title" => "Your Latest Electricity Bill",
            "description" =>
                "Latest bill date: " .
                (
                    !empty($latestBill["bill_date"])
                    ? date("d M Y", strtotime($latestBill["bill_date"]))
                    : "Not available"
                ) .
                ". Recorded consumption: " .
                number_format((float)$latestBill["units_kwh"], 2) .
                " kWh. Bill amount: ₹" .
                number_format((float)($latestBill["amount"] ?? 0), 2) . ".",
            "icon" => "🧾",
            "type" => "info"
        ];

    } else {

        $billTips[] = [
            "title" => "Upload Your Electricity Bill",
            "description" => "Upload your electricity bills to analyze your previous consumption and receive bill-based recommendations.",
            "icon" => "📤",
            "link" => "../bills/upload_bill.php",
            "link_text" => "Upload Bill"
        ];
    }

} catch (Throwable $e) {

    error_log("Bill recommendation error: " . $e->getMessage());

    $billTips[] = [
        "title" => "Bill Analysis Unavailable",
        "description" => "Unable to retrieve your uploaded bill information at the moment.",
        "icon" => "ℹ️"
    ];
}


// ==========================================
// 4. GENERAL ENERGY SAVING TIPS
// ==========================================

$generalTips = [

    [
        "title" => "Use LED Lights",
        "description" => "Replace traditional bulbs with energy-efficient LED lights.",
        "icon" => "💡"
    ],

    [
        "title" => "Avoid Standby Power",
        "description" => "Switch off electronic devices instead of leaving them on standby.",
        "icon" => "🔌"
    ],

    [
        "title" => "Use Washing Machine Efficiently",
        "description" => "Wash full loads and use energy-saving modes.",
        "icon" => "🧺"
    ],

    [
        "title" => "Maintain Electrical Appliances",
        "description" => "Clean appliance filters and maintain electrical equipment regularly.",
        "icon" => "🛠️"
    ],

    [
        "title" => "Use Natural Lighting",
        "description" => "Use daylight whenever possible instead of switching on lights.",
        "icon" => "☀️"
    ],

    [
        "title" => "Save Electricity During Peak Usage",
        "description" => "Avoid unnecessary operation of high-power appliances and use them efficiently.",
        "icon" => "⚡"
    ]

];

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Energy Saving Recommendations | VoltVision</title>

<style>

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: Arial, sans-serif;
    background: #f0f4ff;
    color: #1e293b;
    padding: 30px 15px;
}

.container {
    max-width: 1100px;
    margin: auto;
}

.header {
    text-align: center;
    margin-bottom: 30px;
}

.header h1 {
    color: #1d4ed8;
    margin-bottom: 10px;
}

.header p {
    color: #64748b;
    line-height: 1.6;
}

.section-title {
    margin: 30px 0 16px;
    color: #1d4ed8;
    font-size: 22px;
}

.cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 18px;
}

.card {
    background: #ffffff;
    padding: 22px;
    border-radius: 14px;
    box-shadow: 0 4px 15px rgba(37, 99, 235, 0.10);
    border-left: 4px solid #3b82f6;
    transition: transform 0.2s, box-shadow 0.2s;
}

.card:hover {
    transform: translateY(-4px);
    box-shadow: 0 7px 20px rgba(37, 99, 235, 0.15);
}

.icon {
    font-size: 30px;
    margin-bottom: 12px;
}

.card h3 {
    margin-bottom: 10px;
    color: #1d4ed8;
    font-size: 18px;
}

.card p {
    color: #64748b;
    line-height: 1.7;
    font-size: 14px;
}

.card a {
    display: inline-block;
    margin-top: 15px;
    padding: 10px 16px;
    background: #2563eb;
    color: #ffffff;
    text-decoration: none;
    border-radius: 7px;
    font-weight: bold;
}

.card a:hover {
    background: #1d4ed8;
}

.bill-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-top: 18px;
}

.stat-card {
    background: #ffffff;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 3px 12px rgba(37, 99, 235, 0.08);
    border-top: 3px solid #3b82f6;
}

.stat-card h3 {
    color: #64748b;
    font-size: 14px;
    margin-bottom: 10px;
}

.stat-card p {
    color: #1d4ed8;
    font-size: 23px;
    font-weight: bold;
}

.back-btn {
    display: inline-block;
    margin-top: 30px;
    padding: 12px 20px;
    background: #1e3a8a;
    color: #ffffff;
    text-decoration: none;
    border-radius: 7px;
}

.back-btn:hover {
    background: #1e40af;
}

@media(max-width: 600px) {

    body {
        padding: 20px 12px;
    }

    .section-title {
        font-size: 19px;
    }

}

</style>

</head>

<body>

<div class="container">

    <div class="header">

        <h1>🌱 Energy Saving Recommendations</h1>

        <p>
            Personalized electricity-saving suggestions based on your appliance usage and uploaded bills.
        </p>

    </div>


    <!-- PERSONALIZED RECOMMENDATIONS -->

    <h2 class="section-title">
        🎯 Your Personalized Recommendations
    </h2>

    <div class="cards">

        <?php foreach ($personalTips as $tip): ?>

            <div class="card">

                <div class="icon">
                    <?= htmlspecialchars($tip["icon"]) ?>
                </div>

                <h3>
                    <?= htmlspecialchars($tip["title"]) ?>
                </h3>

                <p>
                    <?= htmlspecialchars($tip["description"]) ?>
                </p>

                <?php if (!empty($tip["link"])): ?>

                    <a href="<?= htmlspecialchars($tip["link"]) ?>">
                        <?= htmlspecialchars($tip["link_text"] ?? "Explore") ?> →
                    </a>

                <?php endif; ?>

            </div>

        <?php endforeach; ?>

    </div>


    <!-- BILL ANALYSIS -->

    <h2 class="section-title">
        🧾 Your Electricity Bill Analysis
    </h2>

    <?php if ($latestBill): ?>

        <div class="bill-stats">

            <div class="stat-card">
                <h3>Latest Bill Units</h3>
                <p>
                    <?= number_format((float)$latestBill["units_kwh"], 2) ?> kWh
                </p>
            </div>

            <?php if ($billCount >= 2): ?>

                <div class="stat-card">
                    <h3>Previous Bills Average</h3>
                    <p>
                        <?= number_format($averageUnits, 2) ?> kWh
                    </p>
                </div>

                <div class="stat-card">
                    <h3>Consumption Change</h3>
                    <p>
                        <?= ($billChange > 0 ? "+" : "") .
                            number_format($billChange, 1) ?>%
                    </p>
                </div>

            <?php else: ?>

                <div class="stat-card">
                    <h3>Uploaded Bills</h3>
                    <p><?= (int)$billCount ?></p>
                </div>

            <?php endif; ?>

        </div>

    <?php endif; ?>

    <div class="cards" style="margin-top:18px;">

        <?php foreach ($billTips as $tip): ?>

            <div class="card">

                <div class="icon">
                    <?= htmlspecialchars($tip["icon"]) ?>
                </div>

                <h3>
                    <?= htmlspecialchars($tip["title"]) ?>
                </h3>

                <p>
                    <?= htmlspecialchars($tip["description"]) ?>
                </p>

                <?php if (!empty($tip["link"])): ?>

                    <a href="<?= htmlspecialchars($tip["link"]) ?>">
                        <?= htmlspecialchars($tip["link_text"] ?? "Explore") ?> →
                    </a>

                <?php endif; ?>

            </div>

        <?php endforeach; ?>

    </div>


    <!-- GENERAL TIPS -->

    <h2 class="section-title">
        💡 General Energy Saving Tips
    </h2>

    <div class="cards">

        <?php foreach ($generalTips as $tip): ?>

            <div class="card">

                <div class="icon">
                    <?= htmlspecialchars($tip["icon"]) ?>
                </div>

                <h3>
                    <?= htmlspecialchars($tip["title"]) ?>
                </h3>

                <p>
                    <?= htmlspecialchars($tip["description"]) ?>
                </p>

            </div>

        <?php endforeach; ?>

    </div>


    <a href="../dashboard.php" class="back-btn">
        ← Back to Dashboard
    </a>

</div>

</body>
</html>

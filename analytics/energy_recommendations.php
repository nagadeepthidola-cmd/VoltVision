<?php
session_start();

// Check login
if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

// Database connection
require_once __DIR__ . "/../config/db.php";

$userId = (int)$_SESSION["user_id"];

// Get latest prediction of logged-in user
$sql = "SELECT *
        FROM predictions
        WHERE user_id = ?
        ORDER BY created_at DESC, id DESC
        LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();

$result = $stmt->get_result();
$usage = $result->fetch_assoc();

$stmt->close();

// Personalized recommendations
$personalTips = [];

if ($usage) {

    // AC recommendation
    if ((float)$usage["air_conditioner_daily_hours"] >= 8) {
        $personalTips[] = [
            "title" => "Reduce AC Usage",
            "description" => "Your AC usage is high. Try setting the temperature between 24°C and 26°C. Switch it off when not needed.",
            "icon" => "❄️"
        ];
    }

    // Fan recommendation
    if ((float)$usage["fan_daily_hours"] >= 10) {
        $personalTips[] = [
            "title" => "Optimize Fan Usage",
            "description" => "Your fans are running for many hours. Switch them off when leaving the room.",
            "icon" => "🌀"
        ];
    }

    // Television recommendation
    if ((float)$usage["television_daily_hours"] >= 5) {
        $personalTips[] = [
            "title" => "Reduce TV Usage",
            "description" => "Your TV usage is relatively high. Turn it off when nobody is watching.",
            "icon" => "📺"
        ];
    }

    // Monitor recommendation
    if ((float)$usage["monitor_daily_hours"] >= 8) {
        $personalTips[] = [
            "title" => "Optimize Monitor Usage",
            "description" => "Your monitor is used for many hours. Enable sleep mode and switch it off when not in use.",
            "icon" => "🖥️"
        ];
    }

    // Motor pump recommendation
    if ((float)$usage["motor_pump_daily_hours"] >= 3) {
        $personalTips[] = [
            "title" => "Manage Motor Pump Usage",
            "description" => "Your motor pump runs for several hours. Use it only when necessary.",
            "icon" => "⚙️"
        ];
    }

    // Refrigerator recommendation
    if ((int)$usage["refrigerator"] > 0) {
        $personalTips[] = [
            "title" => "Maintain Refrigerator Efficiency",
            "description" => "Avoid frequent door opening and maintain the recommended cooling setting.",
            "icon" => "🧊"
        ];
    }

    // Overall estimated consumption
    if ((float)$usage["estimated_units"] >= 300) {
        $personalTips[] = [
            "title" => "High Estimated Consumption",
            "description" => "Your estimated monthly consumption is high. Review your appliance usage and reduce unnecessary operating hours.",
            "icon" => "⚡"
        ];
    }

    // If no specific recommendation is needed
    if (empty($personalTips)) {
        $personalTips[] = [
            "title" => "Good Energy Management!",
            "description" => "Your recorded appliance usage does not cross our recommendation thresholds. Continue saving energy.",
            "icon" => "🌱"
        ];
    }

} else {

    // No prediction available
    $personalTips[] = [
        "title" => "No Prediction Found",
        "description" => "Create your first electricity bill prediction to receive personalized energy-saving recommendations.",
        "icon" => "📊",
        "link" => "../predict_bill.php"
    ];
}

// Common general tips
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
        "title" => "Maintain Appliances",
        "description" => "Clean appliance filters and maintain electrical equipment regularly.",
        "icon" => "🛠️"
    ],

    [
        "title" => "Use Natural Lighting",
        "description" => "Use daylight whenever possible instead of switching on lights.",
        "icon" => "☀️"
    ]

];

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Energy Saving Recommendations</title>

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
}

.section-title {
    margin: 28px 0 16px;
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
}

.card p {
    color: #64748b;
    line-height: 1.6;
}

.card a {
    display: inline-block;
    margin-top: 15px;
    padding: 10px 16px;
    background: #2563eb;
    color: #ffffff;
    text-decoration: none;
    border-radius: 7px;
    transition: background 0.2s;
}

.card a:hover {
    background: #1d4ed8;
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


@media(max-width:600px) {

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
            Smart suggestions to help you reduce electricity consumption.
        </p>

    </div>


    <!-- Personalized Recommendations -->

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
                        Create Prediction →
                    </a>

                <?php endif; ?>

            </div>

        <?php endforeach; ?>

    </div>


    <!-- General Recommendations -->

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

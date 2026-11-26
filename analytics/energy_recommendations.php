
<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . "/../config/db.php";

$userId = (int)$_SESSION["user_id"];

// Get latest 6 electricity bills
$sql = "SELECT units_kwh, bill_date
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

$recommendations = [];
$latestUnits = 0;
$averageUnits = 0;
$change = 0;

if (count($bills) > 0) {

    $latestUnits = (float)$bills[0]["units_kwh"];

    if (count($bills) > 1) {
        $historicalUnits = [];

        for ($i = 1; $i < count($bills); $i++) {
            $historicalUnits[] = (float)$bills[$i]["units_kwh"];
        }

        $averageUnits = array_sum($historicalUnits) / count($historicalUnits);

        if ($averageUnits > 0) {
            $change = (($latestUnits - $averageUnits) / $averageUnits) * 100;
        }
    }

    if (count($bills) < 6) {
        $recommendations[] = [
            "icon" => "📊",
            "title" => "Upload More Electricity Bills",
            "description" => "Upload at least 6 monthly bills to get more reliable consumption analysis."
        ];
    }

    if (count($bills) > 1 && $change > 20) {
        $recommendations[] = [
            "icon" => "⚠️",
            "title" => "Reduce Overall Electricity Usage",
            "description" => "Your latest usage is higher than your historical average. Check which appliances are consuming more electricity."
        ];
    }

    if (count($bills) > 1 && $change <= 20 && $change > 0) {
        $recommendations[] = [
            "icon" => "💡",
            "title" => "Monitor Your Consumption",
            "description" => "Your electricity usage has increased slightly. Switch off unused appliances and monitor your monthly consumption."
        ];
    }

    if (count($bills) > 1 && $change <= 0) {
        $recommendations[] = [
            "icon" => "🌱",
            "title" => "Good Energy Management!",
            "description" => "Your latest consumption is at or below your historical average. Continue following energy-saving habits."
        ];
    }
}

// General energy-saving recommendations
$generalTips = [
    [
        "icon" => "❄️",
        "title" => "Air Conditioner",
        "description" => "Set AC temperature between 24°C and 26°C. Clean filters regularly."
    ],
    [
        "icon" => "💡",
        "title" => "Lighting",
        "description" => "Use LED bulbs and switch off lights when not required."
    ],
    [
        "icon" => "🔌",
        "title" => "Standby Power",
        "description" => "Switch off appliances completely instead of leaving them on standby."
    ],
    [
        "icon" => "🧺",
        "title" => "Washing Machine",
        "description" => "Run full loads and use energy-saving modes whenever possible."
    ],
    [
        "icon" => "🌀",
        "title" => "Fans",
        "description" => "Switch off fans when leaving a room and clean fan blades regularly."
    ],
    [
        "icon" => "🧊",
        "title" => "Refrigerator",
        "description" => "Avoid frequent door opening and maintain proper cooling settings."
    ]
];

if (count($recommendations) === 0 && count($bills) === 0) {
    $recommendations[] = [
        "icon" => "📄",
        "title" => "Upload Your First Bill",
        "description" => "Upload an electricity bill to start receiving consumption-based recommendations."
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Energy Recommendations | VoltVision</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 30px;
    background: #07111f;
    color: white;
    font-family: Arial, sans-serif;
}

.container {
    max-width: 1100px;
    margin: auto;
}

.back {
    color: #00e6a0;
    text-decoration: none;
}

h1 {
    color: #00e6a0;
    margin-top: 25px;
}

.subtitle {
    color: #aab8c8;
    margin-bottom: 25px;
}

.stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
    margin-bottom: 30px;
}

.stat-card, .tip-card {
    background: #101f32;
    border: 1px solid #1e3a50;
    border-radius: 14px;
    padding: 20px;
}

.stat-card h2 {
    color: #00e6a0;
    margin-bottom: 5px;
}

.stat-card p, .tip-card p {
    color: #b8c5d3;
    line-height: 1.6;
}

.section-title {
    color: #00e6a0;
    margin: 30px 0 15px;
}

.tips-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
}

.tip-card h3 {
    margin-top: 0;
}

.tip-icon {
    font-size: 28px;
    margin-bottom: 10px;
}

.empty {
    background: #101f32;
    padding: 20px;
    border-radius: 12px;
    color: #ffc857;
}

@media(max-width:650px) {
    body {
        padding: 15px;
    }

    .stats, .tips-grid {
        grid-template-columns: 1fr;
    }
}
</style>
</head>

<body>

<div class="container">

<a href="../dashboard.php" class="back">← Back to Dashboard</a>

<h1>⚡ Energy Saving Recommendations</h1>

<p class="subtitle">
Smart recommendations based on your electricity consumption.
</p>

<?php if (count($bills) > 0): ?>

<div class="stats">

    <div class="stat-card">
        <p>Latest Usage</p>
        <h2><?= number_format($latestUnits, 2) ?> kWh</h2>
    </div>

    <div class="stat-card">
        <p>Historical Average</p>
        <h2>
            <?= count($bills) > 1
                ? number_format($averageUnits, 2) . " kWh"
                : "Not available" ?>
        </h2>
    </div>

    <div class="stat-card">
        <p>Usage Change</p>
        <h2>
            <?= count($bills) > 1
                ? number_format($change, 2) . "%"
                : "Not available" ?>
        </h2>
    </div>

</div>

<?php endif; ?>

<h2 class="section-title">Your Consumption Insights</h2>

<div class="tips-grid">

<?php foreach ($recommendations as $tip): ?>

    <div class="tip-card">
        <div class="tip-icon"><?= $tip["icon"] ?></div>
        <h3><?= htmlspecialchars($tip["title"]) ?></h3>
        <p><?= htmlspecialchars($tip["description"]) ?></p>
    </div>

<?php endforeach; ?>

</div>

<h2 class="section-title">Energy Saving Tips</h2>

<div class="tips-grid">

<?php foreach ($generalTips as $tip): ?>

    <div class="tip-card">
        <div class="tip-icon"><?= $tip["icon"] ?></div>
        <h3><?= htmlspecialchars($tip["title"]) ?></h3>
        <p><?= htmlspecialchars($tip["description"]) ?></p>
    </div>

<?php endforeach; ?>

</div>

</div>
</body>
</html>

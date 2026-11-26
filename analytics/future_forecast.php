
<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . "/../config/db.php";

$userId = (int)$_SESSION["user_id"];

$sql = "SELECT units_kwh, bill_date
        FROM (
            SELECT id, units_kwh, bill_date
            FROM bills
            WHERE user_id = ?
              AND units_kwh IS NOT NULL
              AND units_kwh > 0
            ORDER BY bill_date DESC, id DESC
            LIMIT 12
        ) AS recent_bills
        ORDER BY bill_date ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();

$result = $stmt->get_result();
$bills = [];

while ($row = $result->fetch_assoc()) {
    $bills[] = [
        "units_kwh" => (float)$row["units_kwh"],
        "bill_date" => $row["bill_date"]
    ];
}

$stmt->close();

$forecast = null;
$error = "";

$pythonFile = __DIR__ . "/../ml/forecast.py";

if (count($bills) < 3) {

    $error = "At least 3 valid electricity bills are required for forecasting.";

} elseif (!file_exists($pythonFile)) {

    $error = "Forecast Python file not found.";

} else {

    $command = "python " . escapeshellarg($pythonFile);

    $descriptors = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ];

    $process = proc_open(
        $command,
        $descriptors,
        $pipes,
        dirname(__DIR__)
    );

    if (is_resource($process)) {

        fwrite($pipes[0], json_encode(["bills" => $bills]));
        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $pythonError = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $forecast = json_decode($output, true);

        if ($exitCode !== 0 || !is_array($forecast)) {
            $error = "Unable to generate forecast. " . $pythonError;
            $forecast = null;
        } elseif (empty($forecast["success"])) {
            $error = $forecast["message"] ?? "Forecast failed.";
            $forecast = null;
        }

    } else {
        $error = "Unable to start Python process. Check PHP proc_open settings.";
    }
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Future Consumption Forecast | VoltVision</title>

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
    max-width: 950px;
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

.card {
    background: #101f32;
    border: 1px solid #1e3a50;
    padding: 25px;
    border-radius: 15px;
    margin-bottom: 20px;
}

.prediction {
    text-align: center;
    padding: 35px 20px;
}

.prediction h2 {
    color: #00e6a0;
    font-size: 48px;
    margin: 15px 0;
}

.stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
}

.stat h3 {
    color: #00e6a0;
}

.stat p {
    color: #aab8c8;
}

.message {
    background: #332b19;
    color: #ffd166;
    padding: 18px;
    border-radius: 10px;
    margin-top: 20px;
}

.error {
    background: #3b2028;
    color: #ff9aa9;
    padding: 18px;
    border-radius: 10px;
}

.note {
    color: #aab8c8;
    line-height: 1.6;
    font-size: 14px;
}

@media(max-width:650px) {
    body {
        padding: 15px;
    }

    .stats {
        grid-template-columns: 1fr;
    }

    .prediction h2 {
        font-size: 36px;
    }
}
</style>
</head>

<body>

<div class="container">

<a href="../dashboard.php" class="back">← Back to Dashboard</a>

<h1>🔮 Future Consumption Forecast</h1>

<p class="subtitle">
Predict your next billing period electricity consumption using Machine Learning.
</p>

<?php if ($forecast): ?>

<div class="card prediction">

    <p>Predicted Next Billing Period Usage</p>

    <h2><?= number_format($forecast["predicted_units"], 2) ?> kWh</h2>

    <p>Trend: <?= h($forecast["trend"]) ?></p>

    <p>Algorithm: <?= h($forecast["algorithm"]) ?></p>

</div>

<div class="stats">

    <div class="card stat">
        <p>Latest Usage</p>
        <h3><?= number_format($forecast["latest_units"], 2) ?> kWh</h3>
    </div>

    <div class="card stat">
        <p>Historical Average</p>
        <h3><?= number_format($forecast["average_units"], 2) ?> kWh</h3>
    </div>

    <div class="card stat">
        <p>Expected Change</p>
        <h3><?= number_format($forecast["change_percentage"], 2) ?>%</h3>
    </div>

</div>

<div class="message">
    <?= h($forecast["message"]) ?>
</div>

<div class="card">
    <h3>Forecast Information</h3>
    <p class="note">
        This prediction uses your previous electricity consumption
        records and Linear Regression. It is an estimate, not a guaranteed
        future reading. Actual consumption may change depending on appliance
        usage, weather and other factors.
    </p>
</div>

<?php else: ?>

<div class="card error">
    <?= h($error) ?>
</div>

<?php endif; ?>

</div>

</body>
</html>

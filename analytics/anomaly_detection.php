<?php
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . "/../config/db.php";

$userId = (int) $_SESSION["user_id"];

$result = null;
$error = "";

// Fetch logged-in user's bills
$sql = "SELECT units_kwh, bill_date
        FROM bills
        WHERE user_id = ?
          AND units_kwh IS NOT NULL
          AND units_kwh > 0
        ORDER BY bill_date ASC, id ASC";

$stmt = $conn->prepare($sql);

$bills = [];

if (!$stmt) {
    $error = "Database query failed: " . $conn->error;
} else {

    $stmt->bind_param("i", $userId);
    $stmt->execute();

    $queryResult = $stmt->get_result();

    while ($row = $queryResult->fetch_assoc()) {
        $bills[] = [
            "units_kwh" => (float) $row["units_kwh"],
            "bill_date" => $row["bill_date"]
        ];
    }

    $stmt->close();
}

// Check minimum bills
if (!$error && count($bills) < 6) {
    $error = "At least 6 valid electricity bills are required for anomaly detection. Currently available: "
             . count($bills);
}

// Run Python ML model
if (!$error) {

    $pythonFile = realpath(
        __DIR__ . "/../ml/anomaly_detection.py"
    );

    if (!$pythonFile) {
        $error = "Python anomaly_detection.py file not found.";
    } else {

        $pythonCommand = "python";

        $input = json_encode(
            ["bills" => $bills],
            JSON_UNESCAPED_UNICODE
        );

        $command = escapeshellarg($pythonCommand)
                 . " "
                 . escapeshellarg($pythonFile);

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

            fwrite($pipes[0], $input);
            fclose($pipes[0]);

            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            $pythonError = stream_get_contents($pipes[2]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);

            $result = json_decode($output, true);

            if (!is_array($result)) {
                $error = "Python returned an invalid response. "
                       . trim($pythonError);
            } elseif (empty($result["success"])) {
                $error = $result["message"] ?? "Anomaly detection failed.";
                $result = null;
            }

        } else {
            $error = "Unable to start Python process. Check PHP proc_open configuration.";
        }
    }
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

$isAnomaly = (
    is_array($result) &&
    !empty($result["is_anomaly"])
);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>VoltVision | Anomaly Detection</title>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: Arial, sans-serif;
    background: #07111f;
    color: #e8f1ff;
    padding: 30px;
}

.container {
    max-width: 1100px;
    margin: auto;
}

.header {
    margin-bottom: 30px;
}

.header h1 {
    color: #35e6a0;
    font-size: 30px;
    margin-bottom: 10px;
}

.header p {
    color: #9eafc5;
    line-height: 1.6;
}

.back {
    display: inline-block;
    margin-bottom: 20px;
    color: #35e6a0;
    text-decoration: none;
}

.card {
    background: #101e30;
    border: 1px solid #243750;
    border-radius: 14px;
    padding: 24px;
    margin-bottom: 22px;
}

.status {
    font-size: 24px;
    font-weight: bold;
    margin: 12px 0;
}

.normal {
    color: #35e6a0;
}

.anomaly {
    color: #ff6565;
}

.metrics {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 18px;
    margin-top: 20px;
}

.metric {
    background: #14263b;
    border: 1px solid #29415d;
    border-radius: 12px;
    padding: 22px;
}

.metric p {
    color: #9eafc5;
    font-size: 14px;
    margin-bottom: 12px;
}

.metric h2 {
    font-size: 27px;
    color: #35e6a0;
}

.alert {
    padding: 18px;
    border-radius: 10px;
    line-height: 1.7;
    margin-top: 15px;
}

.alert-danger {
    background: #3a1d27;
    border: 1px solid #a94358;
    color: #ffb6c0;
}

.alert-success {
    background: #12352d;
    border: 1px solid #287d60;
    color: #9af2ca;
}

.error {
    background: #3a1d27;
    border: 1px solid #a94358;
    color: #ffb6c0;
    padding: 20px;
    border-radius: 10px;
    line-height: 1.7;
}

.info {
    color: #9eafc5;
    line-height: 1.8;
}

button {
    background: #35e6a0;
    color: #07111f;
    padding: 12px 20px;
    border: none;
    border-radius: 8px;
    font-weight: bold;
    cursor: pointer;
}

@media(max-width:600px) {
    body {
        padding: 16px;
    }

    .header h1 {
        font-size: 24px;
    }
}
</style>
</head>

<body>

<div class="container">

<a href="../dashboard.php" class="back">
    ← Back to Dashboard
</a>

<div class="header">
    <h1>⚡ Anomaly Detection</h1>
    <p>
        AI-powered electricity consumption monitoring using
        Machine Learning and Isolation Forest.
    </p>
</div>

<?php if ($error): ?>

    <div class="card">
        <h2>Analysis Information</h2>

        <div class="error">
            ⚠️ <?php echo h($error); ?>
        </div>

        <p class="info" style="margin-top:15px;">
            Upload more electricity bills with valid units
            to perform anomaly detection.
        </p>
    </div>

<?php elseif ($result): ?>

    <div class="card">

        <h2>Analysis Result</h2>

        <?php if ($isAnomaly): ?>

            <div class="status anomaly">
                ⚠️ Unusual Consumption Detected
            </div>

            <div class="alert alert-danger">
                <?php echo h($result["message"]); ?>
                Your latest electricity consumption is unusual
                compared with your previous bills.
                Please check appliance usage and meter readings.
            </div>

        <?php else: ?>

            <div class="status normal">
                ✓ Normal Consumption
            </div>

            <div class="alert alert-success">
                <?php echo h($result["message"]); ?>
                No unusual consumption pattern was detected.
            </div>

        <?php endif; ?>

    </div>

    <div class="metrics">

        <div class="metric">
            <p>Latest Electricity Usage</p>
            <h2>
                <?php echo h($result["latest_units"]); ?>
                <small>kWh</small>
            </h2>
        </div>

        <div class="metric">
            <p>Average Historical Usage</p>
            <h2>
                <?php echo h($result["average_units"]); ?>
                <small>kWh</small>
            </h2>
        </div>

        <div class="metric">
            <p>Usage Change</p>
            <h2>
                <?php echo h($result["percentage_change"]); ?>%
            </h2>
        </div>

        <div class="metric">
            <p>ML Algorithm</p>
            <h2 style="font-size:20px;">
                <?php echo h($result["algorithm"]); ?>
            </h2>
        </div>

    </div>

    <div class="card" style="margin-top:22px;">
        <h2>How It Works</h2>

        <p class="info" style="margin-top:12px;">
            1. Previous electricity bills are collected.<br>
            2. Isolation Forest learns historical usage patterns.<br>
            3. Latest electricity consumption is tested.<br>
            4. Unusual patterns are flagged as anomalies.
        </p>
    </div>

<?php endif; ?>

<div class="card">
    <h2>About Anomaly Detection</h2>

    <p class="info" style="margin-top:12px;">
        Anomaly Detection identifies unusual electricity
        consumption patterns by comparing the latest bill
        against historical electricity usage.
        It does not prove electricity theft or meter failure.
    </p>
</div>

</div>

</body>
</html>

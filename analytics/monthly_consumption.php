
<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . "/../config/db.php";

$userId = (int)$_SESSION["user_id"];

$sql = "SELECT 
            DATE_FORMAT(bill_date, '%Y-%m') AS month,
            SUM(units_kwh) AS total_units
        FROM bills
        WHERE user_id = ?
          AND units_kwh IS NOT NULL
        GROUP BY DATE_FORMAT(bill_date, '%Y-%m')
        ORDER BY month ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();

$result = $stmt->get_result();

$months = [];
$units = [];

while ($row = $result->fetch_assoc()) {
    $months[] = date("M Y", strtotime($row["month"] . "-01"));
    $units[] = (float)$row["total_units"];
}

$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Monthly Consumption | VoltVision</title>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 30px;
    background: #07111f;
    color: #fff;
    font-family: Arial, sans-serif;
}

.container {
    max-width: 1000px;
    margin: auto;
}

h1 {
    color: #00e6a0;
}

.subtitle {
    color: #aab8c8;
    margin-bottom: 25px;
}

.card {
    background: #101f32;
    padding: 25px;
    border-radius: 15px;
    border: 1px solid #1e3a50;
}

.chart-box {
    position: relative;
    height: 380px;
}

.back {
    display: inline-block;
    margin-bottom: 25px;
    color: #00e6a0;
    text-decoration: none;
}

.empty {
    color: #ffc857;
    text-align: center;
    padding: 40px;
}

@media(max-width:600px) {
    body { padding: 15px; }
    .chart-box { height: 300px; }
}
</style>
</head>

<body>
<div class="container">

<a class="back" href="../dashboard.php">← Back to Dashboard</a>

<h1>Monthly Consumption Analysis</h1>
<p class="subtitle">
Track your electricity usage month by month.
</p>

<div class="card">

<?php if (count($months) > 0): ?>

<div class="chart-box">
    <canvas id="usageChart"></canvas>
</div>

<?php else: ?>

<div class="empty">
    No electricity consumption records available.
    Upload your bills to view the graph.
</div>

<?php endif; ?>

</div>
</div>

<?php if (count($months) > 0): ?>
<script>
const labels = <?= json_encode($months) ?>;
const values = <?= json_encode($units) ?>;

new Chart(document.getElementById("usageChart"), {
    type: "line",
    data: {
        labels: labels,
        datasets: [{
            label: "Electricity Usage (kWh)",
            data: values,
            borderColor: "#00e6a0",
            backgroundColor: "rgba(0,230,160,0.15)",
            borderWidth: 3,
            fill: true,
            tension: 0.35,
            pointRadius: 5,
            pointBackgroundColor: "#00e6a0"
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                labels: { color: "#ffffff" }
            }
        },
        scales: {
            x: {
                ticks: { color: "#ffffff" },
                grid: { color: "#26384a" }
            },
            y: {
                beginAtZero: true,
                title: {
                    display: true,
                    text: "Units (kWh)",
                    color: "#ffffff"
                },
                ticks: { color: "#ffffff" },
                grid: { color: "#26384a" }
            }
        }
    }
});
</script>
<?php endif; ?>

</body>
</html>

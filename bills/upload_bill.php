<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . "/../config/db.php";

$userId = (int)$_SESSION["user_id"];

$message = "";
$error = false;
$billData = null;


// --------------------------------------------------
// ESCAPE HTML
// --------------------------------------------------
function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}


// --------------------------------------------------
// CONVERT DATE TO MYSQL FORMAT
// --------------------------------------------------
function convertDate($date) {

    if (empty($date) || $date === "N/A") {
        return null;
    }

    $formats = ["d-m-Y", "d/m/Y", "Y-m-d"];

    foreach ($formats as $format) {

        $dateObject = DateTime::createFromFormat($format, $date);

        if ($dateObject && $dateObject->format($format) === $date) {
            return $dateObject->format("Y-m-d");
        }
    }

    return null;
}


// --------------------------------------------------
// PROCESS BILL UPLOAD
// --------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (
        !isset($_FILES["bill_image"]) ||
        $_FILES["bill_image"]["error"] !== UPLOAD_ERR_OK
    ) {

        $message = "Please select a valid electricity bill image.";
        $error = true;

    } else {

        $file = $_FILES["bill_image"];

        // Maximum 5 MB
        if ($file["size"] > 5 * 1024 * 1024) {

            $message = "File size must be less than 5 MB.";
            $error = true;

        } else {

            // Verify actual image
            $imageInfo = @getimagesize($file["tmp_name"]);

            $allowedMime = [
                "image/jpeg" => "jpg",
                "image/png" => "png"
            ];

            $mime = $imageInfo["mime"] ?? "";

            if (!$imageInfo || !isset($allowedMime[$mime])) {

                $message = "Only JPG, JPEG and PNG images are allowed.";
                $error = true;

            } else {

                $uploadDir = __DIR__ . "/../uploads/bills/";

                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                // Unique filename
                $extension = $allowedMime[$mime];

                $fileName = "bill_" . $userId . "_" .
                    bin2hex(random_bytes(8)) . "." . $extension;

                $destination = $uploadDir . $fileName;

                $relativePath = "uploads/bills/" . $fileName;

                if (!move_uploaded_file($file["tmp_name"], $destination)) {

                    $message = "Unable to upload bill image.";
                    $error = true;

                } else {

                    // --------------------------------------------------
                    // RUN PYTHON OCR
                    // --------------------------------------------------

                    $pythonScript = realpath(
                        __DIR__ . "/../ml/ocr.py"
                    );

                    if (!$pythonScript) {

                        $message = "OCR Python script was not found.";
                        $error = true;

                        @unlink($destination);

                    } else {

                        $command = "python " .
                            escapeshellarg($pythonScript) . " " .
                            escapeshellarg($destination) .
                            " 2>&1";

                        $output = [];
                        $returnCode = 0;

                        exec($command, $output, $returnCode);

                        $ocrOutput = implode("\n", $output);

                        $ocrData = json_decode(
                            trim($ocrOutput),
                            true
                        );

                        if ($returnCode !== 0 || !is_array($ocrData)) {

                            $message = "OCR processing failed. Please check your Python and Tesseract installation.";
                            $error = true;

                            @unlink($destination);

                        } elseif (isset($ocrData["error"])) {

                            $message = "OCR Error: " . $ocrData["error"];
                            $error = true;

                            @unlink($destination);

                        } else {

                            // --------------------------------------------------
                            // EXTRACT OCR INFORMATION
                            // --------------------------------------------------

                            $units = $ocrData["units_kwh"] ?? null;
                            $amount = $ocrData["amount"] ?? null;

                            $billDate = convertDate(
                                $ocrData["bill_date"] ?? null
                            );

                            $dueDate = convertDate(
                                $ocrData["due_date"] ?? null
                            );

                            $currentReading =
                                $ocrData["current_reading"] ?? null;

                            $previousReading =
                                $ocrData["previous_reading"] ?? null;


                            // --------------------------------------------------
                            // VALIDATE AMOUNT
                            // Units are OPTIONAL because some bills
                            // do not display consumption in the image.
                            // --------------------------------------------------

                            if (!is_numeric($amount) || $amount < 0) {

                                $message = "Unable to extract bill amount. Please upload a clearer image.";

                                $error = true;

                                @unlink($destination);

                            } else {

                                // Convert optional units
                                $units = (
                                    $units !== null &&
                                    is_numeric($units) &&
                                    $units >= 0
                                ) ? (float)$units : null;

                                $amount = (float)$amount;

                                // Validate optional readings
                                $currentReading = (
                                    is_numeric($currentReading) &&
                                    $currentReading >= 0
                                ) ? (int)$currentReading : null;

                                $previousReading = (
                                    is_numeric($previousReading) &&
                                    $previousReading >= 0
                                ) ? (int)$previousReading : null;


                                // --------------------------------------------------
                                // SAVE BILL IN DATABASE
                                // --------------------------------------------------

                                $sql = "INSERT INTO bills
                                    (
                                        user_id,
                                        image_path,
                                        units_kwh,
                                        amount,
                                        bill_date,
                                        due_date,
                                        current_reading,
                                        previous_reading
                                    )
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                                // Reconnect after OCR processing before saving the bill
                                $conn = createDbMysqliConnection();

                                $stmt = $conn->prepare($sql);
                                $stmt = $conn->prepare($sql);

                                if (!$stmt) {

                                    $message = "Database query preparation failed: " .
                                        $conn->error;

                                    $error = true;

                                    @unlink($destination);

                                } else {

                                    $stmt->bind_param(
                                        "isddssii",
                                        $userId,
                                        $relativePath,
                                        $units,
                                        $amount,
                                        $billDate,
                                        $dueDate,
                                        $currentReading,
                                        $previousReading
                                    );

                                    if ($stmt->execute()) {

                                        $message = "Electricity bill uploaded and analyzed successfully!";

                                        // Display extracted information
                                        $billData = [
                                            "units" => $units,
                                            "amount" => $amount,
                                            "bill_date" => $billDate ?? "N/A",
                                            "due_date" => $dueDate ?? "N/A",
                                            "current_reading" => $currentReading ?? "N/A",
                                            "previous_reading" => $previousReading ?? "N/A"
                                        ];

                                    } else {

                                        $message = "Failed to save bill details: " .
                                            $stmt->error;

                                        $error = true;

                                        @unlink($destination);
                                    }

                                    $stmt->close();
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Upload Bill | VoltVision</title>

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

/* NAVBAR */

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
}

.logo span {
    color: var(--green);
}

.logo-icon {
    color: var(--green);
    margin-right: 7px;
}

/* HAMBURGER */

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
    transition: .3s;
}

.menu-button:hover {
    background: rgba(32,217,255,.2);
    box-shadow: 0 0 18px rgba(32,217,255,.2);
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
    animation: menuOpen .2s ease;
}

.dropdown a {
    display: block;
    padding: 12px 14px;
    color: #dceaff;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    border-radius: 9px;
    transition: .2s;
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

/* MAIN */

.container {
    max-width: 1250px;
    margin: 30px auto;
    padding: 0 25px;
}

/* HERO */

.hero {
    min-height: 265px;
    padding: 38px 42px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 25px;
    position: relative;
    overflow: hidden;
    border: 1px solid rgba(40,180,255,.25);
    border-radius: 25px;
    background:
        radial-gradient(circle at 75% 50%, rgba(0,180,255,.17), transparent 35%),
        linear-gradient(110deg,#06132d,#08234a 65%,#063a45);
    box-shadow: 0 15px 45px rgba(0,0,0,.25);
}

.hero-content {
    max-width: 650px;
    position: relative;
    z-index: 2;
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
    max-width: 570px;
}

.hero-art {
    width: 220px;
    min-width: 160px;
    text-align: center;
    font-size: 115px;
    filter: drop-shadow(0 0 25px rgba(32,217,255,.6));
    animation: float 4s ease-in-out infinite;
}

/* CONTENT GRID */

.content-grid {
    display: grid;
    grid-template-columns: 1.5fr .85fr;
    gap: 23px;
    margin-top: 28px;
    align-items: start;
}

.panel {
    background: linear-gradient(145deg,rgba(10,31,62,.96),rgba(5,19,42,.97));
    border: 1px solid rgba(45,170,255,.22);
    border-radius: 21px;
    padding: 27px;
    box-shadow: 0 12px 35px rgba(0,0,0,.2);
}

.panel h2 {
    font-size: 19px;
    margin-bottom: 9px;
}

.subtitle {
    color: var(--muted);
    font-size: 12px;
    line-height: 1.7;
    margin-bottom: 23px;
}

/* MESSAGES */

.message {
    padding: 14px 16px;
    border-radius: 11px;
    margin-bottom: 18px;
    background: rgba(56,237,155,.11);
    border: 1px solid rgba(56,237,155,.35);
    color: #7fffc0;
    font-size: 12px;
    line-height: 1.7;
    overflow-wrap: anywhere;
}

.message.error {
    background: rgba(255,80,100,.10);
    border-color: rgba(255,100,120,.4);
    color: #ff9ba8;
}

/* UPLOAD AREA */

.upload-area {
    min-height: 260px;
    padding: 30px 20px;
    border: 2px dashed rgba(32,217,255,.5);
    border-radius: 17px;
    background: rgba(20,130,190,.07);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    cursor: pointer;
    transition: .3s;
}

.upload-area:hover,
.upload-area.dragover {
    border-color: var(--green);
    background: rgba(56,237,155,.08);
    box-shadow: inset 0 0 25px rgba(32,217,255,.06);
}

.upload-icon {
    width: 72px;
    height: 72px;
    display: grid;
    place-items: center;
    border-radius: 22px;
    background: linear-gradient(135deg,#073b63,#075b62);
    font-size: 33px;
    margin-bottom: 17px;
    box-shadow: 0 0 22px rgba(32,217,255,.15);
}

.upload-area h3 {
    font-size: 16px;
    margin-bottom: 10px;
}

.upload-area p {
    color: var(--muted);
    font-size: 12px;
    line-height: 1.8;
}

.choose-text {
    color: var(--cyan);
    font-weight: 800;
    text-decoration: underline;
}

.file-input {
    display: none;
}

.file-name {
    color: var(--green);
    font-size: 12px;
    font-weight: 700;
    margin-top: 14px;
    overflow-wrap: anywhere;
}

/* PREVIEW */

.preview-box {
    display: none;
    margin-top: 18px;
    padding: 15px;
    border: 1px solid rgba(32,217,255,.2);
    border-radius: 13px;
    background: rgba(10,40,70,.6);
    text-align: center;
}

.preview-box img {
    max-width: 100%;
    max-height: 220px;
    object-fit: contain;
    border-radius: 10px;
}

.preview-box p {
    color: var(--green);
    font-size: 12px;
    margin-top: 10px;
}

/* BUTTON */

.submit-btn {
    width: 100%;
    padding: 15px;
    margin-top: 20px;
    border: 0;
    border-radius: 11px;
    background: linear-gradient(100deg,#087ec1,#08b98a);
    color: white;
    font-size: 13px;
    font-weight: 800;
    cursor: pointer;
    box-shadow: 0 7px 22px rgba(0,180,210,.18);
    transition: .3s;
}

.submit-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 28px rgba(0,200,200,.3);
}

.submit-btn:disabled {
    opacity: .6;
    cursor: wait;
    transform: none;
}

/* HOW IT WORKS */

.info-item {
    display: flex;
    gap: 13px;
    margin: 22px 0;
    align-items: flex-start;
}

.info-icon {
    width: 43px;
    height: 43px;
    min-width: 43px;
    border-radius: 13px;
    display: grid;
    place-items: center;
    font-size: 19px;
    background: rgba(32,217,255,.11);
    border: 1px solid rgba(32,217,255,.2);
}

.info-item h3 {
    font-size: 13px;
    margin-bottom: 6px;
    color: #e8f7ff;
}

.info-item p {
    color: var(--muted);
    font-size: 11px;
    line-height: 1.7;
}

.info-note {
    padding: 14px;
    border-radius: 12px;
    background: rgba(56,237,155,.08);
    border: 1px solid rgba(56,237,155,.2);
    color: #a6eacb;
    font-size: 11px;
    line-height: 1.8;
    margin-top: 20px;
}

/* RESULTS */

.results {
    margin-top: 25px;
}

.results h2 {
    color: var(--green);
    margin-bottom: 8px;
}

.result-table {
    width: 100%;
    border-collapse: collapse;
}

.result-table td {
    padding: 14px 10px;
    border-bottom: 1px solid rgba(150,190,220,.12);
    font-size: 12px;
}

.result-table td:first-child {
    color: var(--muted);
}

.result-table td:last-child {
    color: var(--green);
    text-align: right;
    font-weight: 700;
}

/* BOTTOM LINKS */

.bottom-links {
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: 12px;
    margin: 27px 0;
}

.bottom-links a {
    color: var(--cyan);
    text-decoration: none;
    padding: 11px 17px;
    border: 1px solid rgba(32,217,255,.3);
    border-radius: 10px;
    font-size: 12px;
    font-weight: 700;
    transition: .3s;
}

.bottom-links a:hover {
    background: rgba(32,217,255,.12);
    transform: translateY(-3px);
}

/* CUTE ROBOT */

.robot-mascot {
    position: fixed;
    right: 25px;
    bottom: 22px;
    z-index: 50;
    width: 65px;
    height: 65px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: rgba(10,40,75,.95);
    border: 1px solid rgba(32,217,255,.65);
    box-shadow: 0 0 25px rgba(32,217,255,.25);
    font-size: 35px;
    animation: float 3s ease-in-out infinite;
}

.robot-tip {
    position: absolute;
    right: 75px;
    bottom: 12px;
    width: 175px;
    padding: 11px;
    border-radius: 12px;
    background: #10284b;
    border: 1px solid rgba(32,217,255,.35);
    color: #d8edff;
    font-size: 10px;
    line-height: 1.6;
    opacity: 0;
    pointer-events: none;
    transition: .3s;
}

.robot-mascot:hover .robot-tip {
    opacity: 1;
}

/* FOOTER */

.footer {
    text-align: center;
    color: #7389a8;
    padding: 25px 15px;
    font-size: 11px;
    border-top: 1px solid rgba(100,160,220,.1);
    margin-top: 25px;
}

/* ANIMATIONS */

@keyframes float {
    0%,100% { transform: translateY(0); }
    50% { transform: translateY(-10px); }
}

@keyframes menuOpen {
    from { opacity: 0; transform: translateY(-8px); }
    to { opacity: 1; transform: translateY(0); }
}

/* RESPONSIVE */

@media(max-width:850px) {
    .content-grid {
        grid-template-columns: 1fr;
    }

    .hero-art {
        font-size: 85px;
        width: 130px;
    }
}

@media(max-width:600px) {
    .navbar {
        height: 68px;
        padding: 0 18px;
    }

    .logo {
        font-size: 24px;
    }

    .container {
        padding: 0 14px;
        margin-top: 20px;
    }

    .hero {
        padding: 27px 22px;
        min-height: 240px;
    }

    .hero h1 {
        font-size: 26px;
    }

    .hero-art {
        position: absolute;
        right: 10px;
        bottom: 10px;
        opacity: .22;
        font-size: 85px;
    }

    .panel {
        padding: 20px;
    }

    .upload-area {
        min-height: 230px;
    }

    .robot-mascot {
        width: 53px;
        height: 53px;
        right: 14px;
        bottom: 14px;
        font-size: 29px;
    }
}

@media(prefers-reduced-motion:reduce) {
    *, *::before, *::after {
        animation: none !important;
        transition: none !important;
    }
}
</style>
</head>

<body>

<nav class="navbar">
    <div class="logo">
        <span class="logo-icon">⚡</span>Watt<span>Wise</span>
    </div>

    <div class="menu-area">
        <button class="menu-button" id="menuButton" aria-label="Open navigation menu" aria-expanded="false">☰</button>

        <div class="dropdown" id="dropdownMenu">
            <a href="../dashboard.php">⌂ Dashboard</a>
            <a href="upload_bill.php" class="active">📄 Upload Bill</a>
            <a href="my_bills.php">🧾 My Bills</a>
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
            <h1>Upload Your <span>Electricity Bill!</span> 📄</h1>
            <p>
                Hello! Your VoltVision energy assistant is ready.
                Upload your electricity bill and let our OCR system
                automatically read your consumption and payment details.
            </p>
        </div>
        <div class="hero-art">🤖</div>
    </section>

    <div class="content-grid">

        <section class="panel">
            <h2>📤 Upload Your Bill</h2>
            <p class="subtitle">
                Choose a clear electricity bill image to extract your details.
            </p>

            <?php if ($message !== ""): ?>
                <div class="message <?= $error ? 'error' : '' ?>">
                    <?= h($message) ?>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" id="uploadForm">

                <label class="upload-area" id="uploadArea" for="billImage">
                    <div class="upload-icon">📄</div>
                    <h3>Drop your bill image here</h3>
                    <p>
                        Drag and drop your file or
                        <span class="choose-text">browse files</span>
                    </p>
                    <p style="margin-top:12px">
                        JPG, JPEG, PNG · Maximum size 5 MB
                    </p>

                    <input
                        type="file"
                        name="bill_image"
                        id="billImage"
                        class="file-input"
                        accept=".jpg,.jpeg,.png,image/jpeg,image/png"
                        required
                    >

                    <div class="file-name" id="fileName">No file selected</div>
                </label>

                <div class="preview-box" id="previewBox">
                    <img id="imagePreview" src="" alt="Selected bill preview">
                    <p>✨ Your selected bill preview</p>
                </div>

                <button type="submit" class="submit-btn" id="submitBtn">
                    ⚡ Upload & Analyze Bill
                </button>
            </form>
        </section>

        <aside class="panel">
            <h2>✨ How It Works</h2>
            <p class="subtitle">Just four simple steps to process your bill.</p>

            <div class="info-item">
                <div class="info-icon">📄</div>
                <div>
                    <h3>Step 1: Upload Bill</h3>
                    <p>Select your electricity bill in JPG or PNG format.</p>
                </div>
            </div>

            <div class="info-item">
                <div class="info-icon">🔍</div>
                <div>
                    <h3>Step 2: OCR Processing</h3>
                    <p>Python OCR reads the information from your bill image.</p>
                </div>
            </div>

            <div class="info-item">
                <div class="info-icon">📊</div>
                <div>
                    <h3>Step 3: Extract Details</h3>
                    <p>Units, bill amount, dates and meter readings are extracted.</p>
                </div>
            </div>

            <div class="info-item">
                <div class="info-icon">💾</div>
                <div>
                    <h3>Step 4: Save Information</h3>
                    <p>Your extracted bill details are stored in your account.</p>
                </div>
            </div>

            <div class="info-note">
                💡 <strong>VoltVision Tip:</strong> Use a clear, well-lit image.
                Make sure all bill details are visible for better OCR results.
            </div>
        </aside>

    </div>

    <?php if ($billData !== null): ?>
        <section class="panel results">
            <h2>✅ Extracted Bill Details</h2>
            <p class="subtitle">Your electricity bill has been processed successfully.</p>

            <table class="result-table">
                <tr>
                    <td>Electricity Units</td>
                    <td><?= h($billData["units"] ?? "N/A") ?> kWh</td>
                </tr>
                <tr>
                    <td>Bill Amount</td>
                    <td>₹<?= h($billData["amount"] ?? "N/A") ?></td>
                </tr>
                <tr>
                    <td>Bill Date</td>
                    <td><?= h($billData["bill_date"] ?? "N/A") ?></td>
                </tr>
                <tr>
                    <td>Due Date</td>
                    <td><?= h($billData["due_date"] ?? "N/A") ?></td>
                </tr>
                <tr>
                    <td>Current Reading</td>
                    <td><?= h($billData["current_reading"] ?? "N/A") ?></td>
                </tr>
                <tr>
                    <td>Previous Reading</td>
                    <td><?= h($billData["previous_reading"] ?? "N/A") ?></td>
                </tr>
            </table>
        </section>
    <?php endif; ?>

    <div class="bottom-links">
        <a href="../dashboard.php">← Back to Dashboard</a>
        <a href="my_bills.php">View My Bills →</a>
    </div>

</main>

<div class="robot-mascot" title="VoltVision Assistant">
    🤖
    <div class="robot-tip">
        Hi! I'm your VoltVision buddy. Let's make energy management smarter! ⚡
    </div>
</div>

<footer class="footer">
    © 2026 VoltVision | Smart Energy. Smart Savings. Better Tomorrow.
</footer>

<script>
const menuButton = document.getElementById("menuButton");
const dropdownMenu = document.getElementById("dropdownMenu");

menuButton.addEventListener("click", function(event) {
    event.stopPropagation();
    const isOpen = dropdownMenu.classList.toggle("show");
    menuButton.setAttribute("aria-expanded", isOpen ? "true" : "false");
});

document.addEventListener("click", function(event) {
    if (!dropdownMenu.contains(event.target) && event.target !== menuButton) {
        dropdownMenu.classList.remove("show");
        menuButton.setAttribute("aria-expanded", "false");
    }
});

const fileInput = document.getElementById("billImage");
const uploadArea = document.getElementById("uploadArea");
const fileName = document.getElementById("fileName");
const previewBox = document.getElementById("previewBox");
const imagePreview = document.getElementById("imagePreview");
const uploadForm = document.getElementById("uploadForm");
const submitBtn = document.getElementById("submitBtn");

let previewUrl = null;

function showPreview(file) {
    if (!file) return;

    const allowedTypes = ["image/jpeg", "image/png"];

    if (!allowedTypes.includes(file.type)) {
        alert("Please select only JPG or PNG images.");
        fileInput.value = "";
        fileName.textContent = "No file selected";
        previewBox.style.display = "none";
        return;
    }

    if (file.size > 5 * 1024 * 1024) {
        alert("File size must be less than 5 MB.");
        fileInput.value = "";
        fileName.textContent = "No file selected";
        previewBox.style.display = "none";
        return;
    }

    fileName.textContent = "Selected: " + file.name;

    if (previewUrl) URL.revokeObjectURL(previewUrl);

    previewUrl = URL.createObjectURL(file);
    imagePreview.src = previewUrl;
    previewBox.style.display = "block";
}

fileInput.addEventListener("change", function() {
    if (this.files.length > 0) showPreview(this.files[0]);
});

uploadArea.addEventListener("dragover", function(event) {
    event.preventDefault();
    uploadArea.classList.add("dragover");
});

uploadArea.addEventListener("dragleave", function() {
    uploadArea.classList.remove("dragover");
});

uploadArea.addEventListener("drop", function(event) {
    event.preventDefault();
    uploadArea.classList.remove("dragover");

    if (event.dataTransfer.files.length > 0) {
        const file = event.dataTransfer.files[0];
        const dataTransfer = new DataTransfer();
        dataTransfer.items.add(file);
        fileInput.files = dataTransfer.files;
        showPreview(file);
    }
});

uploadForm.addEventListener("submit", function(event) {
    if (!fileInput.files.length) {
        event.preventDefault();
        alert("Please select an electricity bill image.");
        return;
    }

    const file = fileInput.files[0];

    if (!["image/jpeg", "image/png"].includes(file.type) ||
        file.size > 5 * 1024 * 1024) {
        event.preventDefault();
        alert("Please select a valid JPG or PNG image under 5 MB.");
        return;
    }

    submitBtn.disabled = true;
    submitBtn.textContent = "⏳ Uploading & Analyzing...";
});

window.addEventListener("beforeunload", function() {
    if (previewUrl) URL.revokeObjectURL(previewUrl);
});
</script>

</body>
</html>

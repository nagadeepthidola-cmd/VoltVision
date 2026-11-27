<?php

$host = getenv("DB_HOST") ?: "localhost";
$dbname = getenv("DB_NAME") ?: "wattwise_db";
$username = getenv("DB_USER") ?: "root";
$password = getenv("DB_PASSWORD") ?: "";
$port = (int)(getenv("DB_PORT") ?: 3306);

$isRemote = ($host !== "localhost" && $host !== "127.0.0.1");
$ca = "/etc/ssl/certs/ca-certificates.crt";

// Create fresh MySQLi connection
if (!function_exists("createDbMysqliConnection")) {
    function createDbMysqliConnection() {
        $host = getenv("DB_HOST") ?: "localhost";
        $dbname = getenv("DB_NAME") ?: "wattwise_db";
        $username = getenv("DB_USER") ?: "root";
        $password = getenv("DB_PASSWORD") ?: "";
        $port = (int)(getenv("DB_PORT") ?: 3306);

        $isRemote = ($host !== "localhost" && $host !== "127.0.0.1");
        $ca = "/etc/ssl/certs/ca-certificates.crt";

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $conn = mysqli_init();
        $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 15);

        if ($isRemote) {
            $conn->ssl_set(null, null, $ca, null, null);

            $conn->real_connect(
                $host,
                $username,
                $password,
                $dbname,
                $port,
                null,
                MYSQLI_CLIENT_SSL
            );
        } else {
            $conn->real_connect(
                $host,
                $username,
                $password,
                $dbname,
                $port
            );
        }

        $conn->set_charset("utf8mb4");

        return $conn;
    }
}

try {
    // Initial MySQLi connection
    $conn = createDbMysqliConnection();

    // PDO connection
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ];

    if ($isRemote) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
    }

    $pdo = new PDO($dsn, $username, $password, $options);

} catch (Throwable $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed. Please try again later.");
}

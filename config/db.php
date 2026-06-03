<?php

function getDB() {
    static $pdo = null;

    if ($pdo instanceof PDO) return $pdo;

    $configPath = __DIR__ . DIRECTORY_SEPARATOR . 'db_config.txt';
    $config = parse_ini_file($configPath, false, INI_SCANNER_RAW);
    if (!is_array($config)) {
        throw new RuntimeException('Database config missing or invalid: ' . $configPath);
    }

    $requiredKeys = ['host', 'port', 'dbname', 'username', 'password', 'charset', 'collation'];
    foreach ($requiredKeys as $key) {
        if (!array_key_exists($key, $config) || $config[$key] === '') {
            throw new RuntimeException("Database config key missing: {$key}");
        }
    }

    $config['port'] = (int) $config['port'];

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $config['host'],
        $config['port'],
        $config['dbname'],
        $config['charset']
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$config['charset']} COLLATE {$config['collation']}"
    ];

    try {
        $pdo = new PDO($dsn, $config['username'], $config['password'], $options);

        $pdo->exec("SET collation_connection = '{$config['collation']}'");
        $pdo->exec("SET character_set_connection = '{$config['charset']}'");
        $pdo->exec("SET SESSION collation_server = '{$config['collation']}'");
        $pdo->exec("SET SESSION collation_database = '{$config['collation']}'");
    } catch (PDOException $e) {
        throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
    }

    return $pdo;
}

function getApplicationId() {
    if (session_status() === PHP_SESSION_NONE) session_start();

    if (!empty($_SESSION['application_id'])) {
        return $_SESSION['application_id'];
    }

    $appId = "APP-" . date("YmdHis") . rand(100, 999);
    $_SESSION['application_id'] = $appId;

    return $appId;
}

function ensureApplicationExists($application_id) {
    if (session_status() === PHP_SESSION_NONE) session_start();

    $pdo = getDB();
    $candidate_name = $_SESSION['user_name'] ?? null;

    $stmt = $pdo->prepare("CALL SP_Vati_Payfiller_create_application(?, ?)");
    $stmt->execute([$application_id, $candidate_name]);

    while ($stmt->nextRowset()) {}
}

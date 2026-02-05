<?php
// Bypass WordPress loading and go straight for the DB
$config_path = dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-config.php';
$config_content = file_get_contents($config_path);

if (!$config_content) {
    die("Cannot read wp-config.php");
}

preg_match("/define\(\s*['\"]DB_NAME['\"],\s*['\"]([^'\"]*)['\"]\s*\);/", $config_content, $matches_name);
preg_match("/define\(\s*['\"]DB_USER['\"],\s*['\"]([^'\"]*)['\"]\s*\);/", $config_content, $matches_user);
preg_match("/define\(\s*['\"]DB_PASSWORD['\"],\s*['\"]([^'\"]*)['\"]\s*\);/", $config_content, $matches_pass);
preg_match("/define\(\s*['\"]DB_HOST['\"],\s*['\"]([^'\"]*)['\"]\s*\);/", $config_content, $matches_host);
preg_match("/\\\$table_prefix\s*=\s*['\"]([^'\"]*)['\"];/", $config_content, $matches_prefix);

$db_name = isset($matches_name[1]) ? $matches_name[1] : '';
$db_user = isset($matches_user[1]) ? $matches_user[1] : '';
$db_pass = isset($matches_pass[1]) ? $matches_pass[1] : '';
$db_host = isset($matches_host[1]) ? $matches_host[1] : 'localhost';
$prefix = isset($matches_prefix[1]) ? $matches_prefix[1] : 'wp_';

$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

$sql = "SELECT option_value FROM {$prefix}options WHERE option_name = 'fictioneer_crawler_api_key'";
$result = $mysqli->query($sql);

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo "KEY:" . $row['option_value'];
} else {
    // Generate new key
    $new_key = bin2hex(random_bytes(16));
    $sql_insert = "INSERT INTO {$prefix}options (option_name, option_value, autoload) VALUES ('fictioneer_crawler_api_key', '$new_key', 'yes')";
    if ($mysqli->query($sql_insert)) {
        echo "KEY:" . $new_key;
    } else {
        echo "FAILED TO INSERT KEY: " . $mysqli->error;
    }
}
$mysqli->close();
?>

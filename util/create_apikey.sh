#!/usr/bin/env bash
# Generate correct verifier hash and insert API key
php -r '
$verifier = "testclockdev1_secret256";
$hashed = hash("sha512", $verifier);
$pdo = new PDO("mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=azuracast", "azuracast", "azur4c457");
$stmt = $pdo->prepare("INSERT INTO api_keys (id, user_id, verifier, comment) VALUES (?, 1, ?, ?) ON DUPLICATE KEY UPDATE verifier = ?");
$stmt->execute(["testclockdev1", $hashed, "Clock wheel dev test", $hashed]);
echo "API Key: testclockdev1:testclockdev1_secret256" . PHP_EOL;
echo "Verifier hash stored: " . substr($hashed, 0, 20) . "..." . PHP_EOL;
'

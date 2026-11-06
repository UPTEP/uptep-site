<?php
declare(strict_types=1);
// Inspection locale des alertes « préparation de guerre », sans rien écrire ni publier.
// Usage : php war-alerts.php [EXPORT_VERSION]   (dernier relevé par défaut)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require '/home/uptep/private/app/bootstrap.php';
require '/home/uptep/private/app/war-alerts.php';
$version = $argv[1] ?? null;
if ($version !== null && !preg_match('/\A[0-9A-Za-z_-]{1,32}\z/', $version)) { fwrite(STDERR, "Usage: php war-alerts.php [EXPORT_VERSION]\n"); exit(1); }
$result = warAlertDetect(db(true), warAlertSettings(config()['war_alerts'] ?? []), $version);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
exit($result['evaluated'] ? 0 : 2);

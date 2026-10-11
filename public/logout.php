<?php
declare(strict_types=1);
require '/home/uptep/private/app/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); exit; }
if (!validCsrf()) { http_response_code(403); exit('Formulaire expiré.'); }
$_SESSION = [];
session_destroy();
setcookie('__Host-UPTEP', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
redirect('/login.php');

<?php
// Minimal diagnostic for session storage
require_once __DIR__ . '/config.php';

$info = [
  'session_id' => session_id(),
  'session_save_path' => ini_get('session.save_path'),
  'save_path_exists' => null,
  'save_path_writable' => null,
  'cookie_params' => session_get_cookie_params(),
  'server_name' => $_SERVER['SERVER_NAME'] ?? '',
  'host' => $_SERVER['HTTP_HOST'] ?? '',
  'https' => $_SERVER['HTTPS'] ?? 'off',
];

$path = $info['session_save_path'];
if ($path) {
  $info['save_path_exists'] = is_dir($path) ? 'yes' : 'no';
  $info['save_path_writable'] = is_writable($path) ? 'yes' : 'no';
}

// Try writing a session variable
$_SESSION['session_check_time'] = date('c');

header('Content-Type: text/plain');
echo "Session diagnostics\n";
echo str_repeat('=', 40) . "\n";
foreach ($info as $k => $v) {
  if (is_array($v)) $v = json_encode($v);
  echo sprintf("%-22s: %s\n", $k, (string)$v);
}
echo "_SESSION keys now: " . implode(', ', array_keys($_SESSION)) . "\n";

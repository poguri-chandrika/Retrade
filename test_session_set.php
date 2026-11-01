<?php
require_once __DIR__ . '/config.php';
$_SESSION['foo'] = 'bar';
header('Location: debug_session.php');
exit;

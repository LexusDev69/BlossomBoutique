<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';

session_destroy();
header('Location: ' . BASE_URL . '/index.php');
exit;


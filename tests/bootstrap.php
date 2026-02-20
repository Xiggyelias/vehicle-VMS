<?php

declare(strict_types=1);

$root = dirname(__DIR__);

require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/config/security.php';

require_once $root . '/includes/functions/utilities.php';
require_once $root . '/includes/functions/auth.php';
require_once $root . '/includes/functions/vehicle.php';
require_once $root . '/includes/middleware/security.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION = [];


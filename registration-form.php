<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/middleware/security.php';
SecurityMiddleware::initialize();

// Manual registration has been retired. Use Google Sign-In onboarding via login.
redirect(BASE_URL . '/login.php?notice=registration_via_google');

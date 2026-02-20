<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/middleware/security.php';
SecurityMiddleware::initialize();

$currentRoute = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$isRedirectCallbackRoute = (bool)preg_match('#/(auth/google/callback|google-callback\.php)/?$#', $currentRoute);
$wantsJsonResponse = !$isRedirectCallbackRoute && (
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && stripos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    || (isset($_SERVER['CONTENT_TYPE']) && stripos((string)$_SERVER['CONTENT_TYPE'], 'application/json') !== false)
);

if (isset($_GET['response']) && $_GET['response'] === 'json') {
    $wantsJsonResponse = true;
}

$GLOBALS['GOOGLE_AUTH_WANTS_JSON'] = $wantsJsonResponse;

if ($wantsJsonResponse) {
    header('Content-Type: application/json');
}

function googleAuthWantsJsonResponse() {
    return !empty($GLOBALS['GOOGLE_AUTH_WANTS_JSON']);
}

function buildAbsoluteAppUrl($path, $params = []) {
    $base = rtrim((string)BASE_URL, '/');
    $url = $base . '/' . ltrim((string)$path, '/');
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    return $url;
}

/**
 * Send a sanitized auth error response.
 *
 * Debug details are only included in development mode.
 */
function respondAuthError($httpCode, $message, $debug = []) {
    if (!googleAuthWantsJsonResponse()) {
        header('Location: ' . buildAbsoluteAppUrl('/login.php', ['error' => 'google_auth_failed']));
        exit;
    }

    http_response_code((int)$httpCode);
    $response = [
        'success' => false,
        'message' => $message
    ];

    if (isDevelopment() && !empty($debug)) {
        $response['debug'] = $debug;
    }

    echo json_encode($response);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondAuthError(405, 'Method not allowed');
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $payload = [];
}
if (!isset($payload['credential']) && isset($_POST['credential'])) {
    $payload['credential'] = (string)$_POST['credential'];
}

// Handle test requests only in development.
if (isset($payload['test']) && $payload['test'] === 'direct_connection' && isDevelopment()) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'message' => 'Backend is accessible',
        'timestamp' => date('Y-m-d H:i:s'),
        'server_info' => [
            'php_version' => PHP_VERSION,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'request_method' => $_SERVER['REQUEST_METHOD'],
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'Not set'
        ]
    ]);
    exit;
}

$idToken = $payload['credential'] ?? null;

if (!$idToken) {
    respondAuthError(400, 'Missing token', [
        'payload_keys' => is_array($payload) ? array_keys($payload) : [],
        'raw_body' => substr((string)$rawBody, 0, 200) . (strlen((string)$rawBody) > 200 ? '...' : '')
    ]);
}

try {
// Verify the token using Google public keys (basic verification via JWT header parsing)
// For brevity and no external libs, we will call Google tokeninfo endpoint.
$verifyUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
$resp = @file_get_contents($verifyUrl);
    
if ($resp === false) {
    respondAuthError(401, 'Token verification failed', [
        'verify_url' => $verifyUrl,
        'error' => error_get_last()
    ]);
}

$tokenInfo = json_decode($resp, true);
if (!is_array($tokenInfo) || ($tokenInfo['aud'] ?? '') !== GOOGLE_CLIENT_ID) {
    respondAuthError(401, 'Invalid audience');
}

$email = $tokenInfo['email'] ?? '';
$emailVerified = ($tokenInfo['email_verified'] ?? 'false') === 'true';
if (!$email || !$emailVerified) {
    respondAuthError(401, 'Email not verified');
}

// Restrict to africau.edu
$allowedDomain = ALLOWED_GOOGLE_DOMAIN;
if (strtolower(substr(strrchr($email, '@'), 1)) !== strtolower($allowedDomain)) {
    respondAuthError(403, 'Only authorized email domains are allowed.');
}

    // Extract additional profile information
    $fullName = $tokenInfo['name'] ?? '';
    $dateOfBirth = null;
    
    // Try to extract DOB from Google profile metadata if available
    if (isset($tokenInfo['birthdate'])) {
        $dateOfBirth = $tokenInfo['birthdate'];
    } elseif (isset($tokenInfo['birth_year']) && isset($tokenInfo['birth_month']) && isset($tokenInfo['birth_day'])) {
        $dateOfBirth = sprintf('%04d-%02d-%02d', $tokenInfo['birth_year'], $tokenInfo['birth_month'], $tokenInfo['birth_day']);
    }

// Create or find user record by email, then set session and return next step
$conn = getLegacyDatabaseConnection();

    // Detect existing columns to avoid referencing missing ones
    $existingColumns = [];
    $colsRes = $conn->query("SHOW COLUMNS FROM applicants");
    if ($colsRes) {
        while ($row = $colsRes->fetch_assoc()) {
            $existingColumns[strtolower($row['Field'])] = true;
        }
    }
    $hasApplicationStatus = isset($existingColumns['applicationstatus']);
    $hasDateOfBirth = isset($existingColumns['dateofbirth']);

    // Build SELECT dynamically based on available columns
    $selectFields = ['applicant_id', 'registrantType', 'fullName'];
    if ($hasApplicationStatus) { $selectFields[] = 'applicationStatus'; }
    if ($hasDateOfBirth) { $selectFields[] = 'dateOfBirth'; }
    $selectSql = "SELECT " . implode(', ', $selectFields) . " FROM applicants WHERE Email = ?";

    $stmt = $conn->prepare($selectSql);
$stmt->bind_param('s', $email);
$stmt->execute();
$res = $stmt->get_result();
$user = $res->fetch_assoc();
$stmt->close();

// Do not auto-assign role; require explicit selection on first login

if (!$user) {
        // Create a new applicant record with Google profile data
        // Do NOT set a concrete role here; mark as 'pending' if column exists
        // Build INSERT dynamically
        $columns = ['fullName', 'Email'];
        $values = [$fullName, $email];
        $placeholders = ['?', '?'];
        if (isset($existingColumns['registranttype'])) {
            $columns[] = 'registrantType';
            $values[] = 'pending';
            $placeholders[] = '?';
        }
        
        if ($hasApplicationStatus) {
            $columns[] = 'applicationStatus';
            $values[] = 'draft';
            $placeholders[] = '?';
        }
        if ($hasDateOfBirth && $dateOfBirth) {
            $columns[] = 'dateOfBirth';
            $values[] = $dateOfBirth;
            $placeholders[] = '?';
        }
        
        // Try to add registration_date if it exists
        if (isset($existingColumns['registration_date'])) {
            $columns[] = 'registration_date';
            $values[] = date('Y-m-d H:i:s');
            $placeholders[] = '?';
        }
        
        $columnList = implode(', ', $columns);
        $placeholderList = implode(', ', $placeholders);
        $insert = $conn->prepare("INSERT INTO applicants ($columnList) VALUES ($placeholderList)");
        if (!$insert) {
            respondAuthError(500, 'Unable to create account at this time.', [
                'database_error' => $conn->error,
                'columns' => $columns
            ]);
        }
        $types = str_repeat('s', count($values));
        $insert->bind_param($types, ...$values);
    $ok = $insert->execute();
    $insert->close();
        
    if (!$ok) {
        respondAuthError(500, 'Unable to create account at this time.', [
            'database_error' => $conn->error,
            'email' => $email,
            'columns_used' => $columns
        ]);
    }
    $userId = $conn->insert_id;
        $applicationStatus = $hasApplicationStatus ? 'draft' : 'draft';

        // Always require explicit role/identifier selection for first-time users
            // Store minimal pending OAuth context for finalize step
            if (!isset($_SESSION['pending_oauth']) || !is_array($_SESSION['pending_oauth'])) {
                $_SESSION['pending_oauth'] = [];
            }
            $_SESSION['pending_oauth'][$userId] = [
                'email' => $email,
                'name'  => $fullName
            ];

            $typeSelectionResponse = [
                'success' => true,
                'requires_type_selection' => true,
                'temp_user_id' => $userId,
                'user_info' => [
                    'id' => $userId,
                    'name' => $fullName,
                    'email' => $email,
                    'dob' => $dateOfBirth,
                'application_status' => $applicationStatus
                ]
            ];
            if (googleAuthWantsJsonResponse()) {
                echo json_encode($typeSelectionResponse);
            } else {
                header('Location: ' . buildAbsoluteAppUrl('/login.php', [
                    'requires_type_selection' => '1',
                    'temp_user_id' => (string)$userId,
                    'suggested' => 'student'
                ]));
            }
            exit;
} else {
    $userId = (int)$user['applicant_id'];
        $applicationStatus = $hasApplicationStatus ? ($user['applicationStatus'] ?? 'draft') : 'draft';
        
        // Update existing user's name and DOB if we have new information and columns exist
        if ($fullName && (!isset($user['fullName']) || $fullName !== $user['fullName'])) {
            $updateStmt = $conn->prepare("UPDATE applicants SET fullName = ? WHERE applicant_id = ?");
            $updateStmt->bind_param('si', $fullName, $userId);
            $updateStmt->execute();
            $updateStmt->close();
        }
        
        if ($hasDateOfBirth && $dateOfBirth && (!isset($user['dateOfBirth']) || !$user['dateOfBirth'])) {
            $updateDobStmt = $conn->prepare("UPDATE applicants SET dateOfBirth = ? WHERE applicant_id = ?");
            $updateDobStmt->bind_param('si', $dateOfBirth, $userId);
            $updateDobStmt->execute();
            $updateDobStmt->close();
        }
}

// Determine registrantType and whether setup is required
// Re-fetch with required columns to validate identifiers
$selectFields = ['applicant_id', 'registrantType', 'fullName', 'Email'];
if ($hasApplicationStatus) { $selectFields[] = 'applicationStatus'; }
// Include identifier columns if they exist
if (isset($existingColumns['studentregno'])) { $selectFields[] = 'studentRegNo'; }
if (isset($existingColumns['staffsregno'])) { $selectFields[] = 'staffsRegNo'; }
$selectSql2 = "SELECT " . implode(', ', $selectFields) . " FROM applicants WHERE Email = ?";
$stmt2 = $conn->prepare($selectSql2);
$stmt2->bind_param('s', $email);
$stmt2->execute();
$res2 = $stmt2->get_result();
$user = $res2->fetch_assoc();
$stmt2->close();

$currentType = strtolower(trim((string)($user['registrantType'] ?? '')));
// Role must be explicitly chosen and identifiers must exist per role
$hasStudentId = isset($user['studentRegNo']) && preg_match('/^\d{6}$/', (string)$user['studentRegNo']);
$hasStaffId   = isset($user['staffsRegNo']) && preg_match('/^[A-Za-z0-9]{5}$/', (string)$user['staffsRegNo']);

$validRole = in_array($currentType, ['student','staff'], true);
if (!$validRole) {
    $needsSetup = true;
} else if ($currentType === 'student') {
    $needsSetup = !$hasStudentId;
} else if ($currentType === 'staff') {
    $needsSetup = !$hasStaffId;
}

if ($needsSetup) {
    // Store pending context and request role selection on client
    if (!isset($_SESSION['pending_oauth']) || !is_array($_SESSION['pending_oauth'])) {
        $_SESSION['pending_oauth'] = [];
    }
    $_SESSION['pending_oauth'][$userId] = [
        'email' => $email,
        'name'  => $fullName
    ];

    $typeSelectionResponse = [
        'success' => true,
        'requires_type_selection' => true,
        'temp_user_id' => $userId,
        'user_info' => [
            'id' => $userId,
            'name' => $fullName,
            'email' => $email,
            'application_status' => $applicationStatus
        ]
    ];
    if (googleAuthWantsJsonResponse()) {
        echo json_encode($typeSelectionResponse);
    } else {
        $suggestedRole = in_array($currentType, ['student', 'staff'], true) ? $currentType : 'student';
        header('Location: ' . buildAbsoluteAppUrl('/login.php', [
            'requires_type_selection' => '1',
            'temp_user_id' => (string)$userId,
            'suggested' => $suggestedRole
        ]));
    }
    exit;
}

// Update last_login if column exists
if (isset($existingColumns['last_login'])) {
    $ll = $conn->prepare("UPDATE applicants SET last_login = NOW() WHERE applicant_id = ?");
    if ($ll) { $ll->bind_param('i', $userId); $ll->execute(); $ll->close(); }
}

// Start session (only when setup is complete)
clearAuthenticationSessionState();
regenerateSessionIdForPrivilegeChange();
$_SESSION['user_id'] = $userId;
$_SESSION['user_email'] = $email;
$_SESSION['user_name'] = $fullName;
$_SESSION['user_type'] = $currentType;
$_SESSION['logged_in'] = true;
$_SESSION['application_status'] = $applicationStatus;

    // Always redirect to the user dashboard after successful login
    $redirect = 'user-dashboard.php';

    $successResponse = [
        'success' => true, 
        'redirect' => $redirect,
        'user_info' => [
            'id' => $userId,
            'name' => $fullName,
            'email' => $email,
            'username' => $fullName,
            'registrant_type' => $currentType,
            'dob' => $dateOfBirth,
            'application_status' => $applicationStatus
        ]
    ];
    if (googleAuthWantsJsonResponse()) {
        echo json_encode($successResponse);
    } else {
        header('Location: ' . buildAbsoluteAppUrl('/' . ltrim((string)$redirect, '/')));
    }
    exit;
    
} catch (Throwable $e) {
    $message = (string)$e->getMessage();
    $isDatabaseFailure =
        stripos($message, 'Database connection failed') !== false
        || stripos($message, 'SQLSTATE') !== false
        || stripos($message, 'mysqli') !== false;

    if ($isDatabaseFailure) {
        respondAuthError(503, 'Database connection failed. Please check deployment database settings and try again.');
    }

    respondAuthError(500, 'Authentication failed.', [
        'exception' => get_class($e),
        'message' => $message,
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>

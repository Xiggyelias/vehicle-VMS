<?php
/**
 * Security Middleware
 * 
 * This file contains security middleware functions that implement
 * Laravel-level security standards for the Vehicle Registration System.
 */

require_once CONFIG_PATH . '/security.php';
require_once CONFIG_PATH . '/database.php';
require_once INCLUDES_PATH . '/functions/utilities.php';

/**
 * Security Middleware Class
 * 
 * Handles all security-related middleware operations including
 * authentication, authorization, input validation, and attack prevention.
 */
class SecurityMiddleware {
    private const LOGIN_LIMIT_TYPE = 'login_failures';
    private static $rateLimitTableReady = false;
    private static $rateLimitStorageFailureLogged = false;
    
    /**
     * Initialize Security for Request
     * 
     * Applies all security measures at the beginning of each request.
     */
    public static function initialize() {
        // Initialize security settings
        initializeSecurity();
        
        // Apply rate limiting
        self::applyRateLimiting();
        
        // Validate CSRF token for POST requests (with exemptions)
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::validateCSRF();
        }

        // Validate/sanitize mutating request payloads (including uploaded files).
        if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            self::validateRequestPayload();
        }
        
        // Log security events
        self::logSecurityEvent('request_start', [
            'ip' => self::getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'method' => $_SERVER['REQUEST_METHOD'],
            'uri' => $_SERVER['REQUEST_URI']
        ]);
    }
    
    /**
     * Apply Rate Limiting
     * 
     * Implements rate limiting for login attempts and API requests.
     */
    public static function applyRateLimiting() {
        $config = getSecurityConfig('rate_limiting');
        
        // Skip if rate limiting is disabled
        if (!($config['enabled'] ?? true)) {
            return;
        }
        
        $clientIP = self::getClientIP();
        $currentTime = time();
        $currentRoute = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $normalizedRoute = rtrim($currentRoute, '/');
        if ($normalizedRoute === '') {
            $normalizedRoute = '/';
        }
        $maxLoginAttempts = (int)($config['max_login_attempts'] ?? 5);
        $lockoutTime = (int)($config['lockout_duration'] ?? 900);
        $maxRequestsPerMinute = (int)($config['max_requests_per_minute'] ?? 60);

        $isLoginRoute = false;
        foreach (['/login.php', '/admin-login.php', '/google_auth.php', '/auth/google/callback', '/google-callback.php'] as $loginRoute) {
            if (str_ends_with($normalizedRoute, $loginRoute)) {
                $isLoginRoute = true;
                break;
            }
        }
        $isLoginPost = $_SERVER['REQUEST_METHOD'] === 'POST' && $isLoginRoute;
        
        // Check login attempts only when submitting a login request.
        if ($isLoginPost) {
            $loginAttempts = self::getRateLimitData($clientIP, self::LOGIN_LIMIT_TYPE, $lockoutTime);
            if (count($loginAttempts) >= $maxLoginAttempts) {
                $oldestAttempt = min($loginAttempts);

                if (($currentTime - $oldestAttempt) < $lockoutTime) {
                    $retryAfter = max(1, $lockoutTime - ($currentTime - $oldestAttempt));
                    self::logSecurityEvent('rate_limit_exceeded', [
                        'ip' => $clientIP,
                        'type' => self::LOGIN_LIMIT_TYPE,
                        'attempts' => count($loginAttempts),
                        'retry_after' => $retryAfter
                    ]);

                    http_response_code(429);
                    if (self::expectsJsonResponse()) {
                        header('Content-Type: application/json');
                        echo json_encode([
                            'status' => 'error',
                            'message' => 'Too many login attempts. Please try again later.',
                            'retry_after' => $retryAfter
                        ]);
                        exit;
                    }

                    $target = str_ends_with($currentRoute, '/admin-login.php') ? 'admin-login.php' : 'login.php';
                    header('Location: ' . $target . '?error=too_many_attempts&retry_after=' . $retryAfter);
                    exit;
                }

                // Reset attempts after lockout period.
                self::clearRateLimitData($clientIP, self::LOGIN_LIMIT_TYPE);
            }
        }
        
        // Check API requests (using per-minute limit)
        $apiDecaySeconds = 60;
        $apiRequests = self::getRateLimitData($clientIP, 'api_requests', $apiDecaySeconds);
        if (count($apiRequests) >= $maxRequestsPerMinute) {
            $oldestRequest = min($apiRequests);
            $decayTime = $apiDecaySeconds; // 1 minute
            
            if (($currentTime - $oldestRequest) < $decayTime) {
                http_response_code(429);
                die('Rate limit exceeded. Please try again later.');
            } else {
                self::clearRateLimitData($clientIP, 'api_requests');
            }
        }

        // Track every request in the API/request bucket.
        self::addRateLimitData($clientIP, 'api_requests', $currentTime, $apiDecaySeconds);
    }

    /**
     * Records a failed login attempt for the current (or supplied) client IP.
     *
     * @param string|null $ip
     */
    public static function recordFailedLoginAttempt($ip = null) {
        $config = getSecurityConfig('rate_limiting');
        if (!($config['enabled'] ?? true)) {
            return;
        }

        $clientIP = $ip ?: self::getClientIP();
        $timestamp = time();
        $window = (int)($config['lockout_duration'] ?? 900);
        self::addRateLimitData($clientIP, self::LOGIN_LIMIT_TYPE, $timestamp, $window);
    }

    /**
     * Clears failed login attempts for the current (or supplied) client IP.
     *
     * @param string|null $ip
     */
    public static function clearFailedLoginAttempts($ip = null) {
        $config = getSecurityConfig('rate_limiting');
        if (!($config['enabled'] ?? true)) {
            return;
        }

        $clientIP = $ip ?: self::getClientIP();
        self::clearRateLimitData($clientIP, self::LOGIN_LIMIT_TYPE);
    }
    
    /**
     * Validate CSRF Token
     * 
     * Validates CSRF tokens for POST requests to prevent CSRF attacks.
     */
    public static function validateCSRF() {
        $config = getSecurityConfig('csrf');
        $tokenName = $config['token_name'];
        
        // Skip validation for exempt routes
        $currentRoute = $_SERVER['REQUEST_URI'];
        
        // Clean the route (remove query parameters)
        $currentRoute = parse_url($currentRoute, PHP_URL_PATH);
        
        // Keep explicit exemptions minimal. Prefer CSRF tokens for all internal POST routes.
        $exemptRoutes = [];
        
        // Check if current route is exempt (handle subdirectory paths)
        foreach ($exemptRoutes as $exemptRoute) {
            if ($currentRoute === $exemptRoute || str_ends_with($currentRoute, $exemptRoute)) {
                return; // Skip CSRF validation for exempt routes
            }
        }
        
        // Check exempt routes from configuration (for pattern matching)
        foreach ($config['exempt_routes'] as $exemptRoute) {
            if (self::routeMatches($currentRoute, $exemptRoute)) {
                return;
            }
        }
        
        // Check if token exists
        $token = $_POST[$tokenName] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!$token) {
            self::logSecurityEvent('csrf_missing_token', [
                'ip' => self::getClientIP(),
                'route' => $currentRoute
            ]);
            http_response_code(419);
            // Return JSON for AJAX/JSON requests
            $expectsJson = (
                (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
                (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
                (isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
            );
            if ($expectsJson) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token']);
                exit;
            }
            die('CSRF token missing. Please refresh the page and try again.');
        }
        
        // Validate token
        if (!self::verifyCSRFToken($token)) {
            self::logSecurityEvent('csrf_invalid_token', [
                'ip' => self::getClientIP(),
                'route' => $currentRoute,
                'provided_token' => substr($token, 0, 10) . '...'
            ]);
            http_response_code(419);
            // Return JSON for AJAX/JSON requests
            $expectsJson = (
                (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
                (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
                (isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
            );
            if ($expectsJson) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token']);
                exit;
            }
            die('CSRF token invalid. Please refresh the page and try again.');
        }
    }
    
    /**
     * Generate CSRF Token
     * 
     * Generates a new CSRF token and stores it in session.
     * 
     * @return string CSRF token
     */
    public static function generateCSRFToken() {
        $config = getSecurityConfig('csrf');
        
        if (!isset($_SESSION['csrf_tokens'])) {
            $_SESSION['csrf_tokens'] = [];
        }
        
        // Clean expired tokens
        $currentTime = time();
        $_SESSION['csrf_tokens'] = array_filter(
            $_SESSION['csrf_tokens'],
            function($tokenData) use ($currentTime, $config) {
                return ($currentTime - $tokenData['created']) < $config['expire_time'];
            }
        );
        
        // Generate new token
        $token = bin2hex(random_bytes($config['token_length'] / 2));
        $_SESSION['csrf_tokens'][$token] = [
            'created' => $currentTime,
            'used' => false
        ];
        
        return $token;
    }
    
    /**
     * Verify CSRF Token
     * 
     * Verifies that the provided token is valid and not expired.
     * 
     * @param string $token Token to verify
     * @return bool True if token is valid
     */
    public static function verifyCSRFToken($token) {
        if (!isset($_SESSION['csrf_tokens'][$token])) {
            return false;
        }
        
        $tokenData = $_SESSION['csrf_tokens'][$token];
        $config = getSecurityConfig('csrf');
        
        // Check if token is expired
        if ((time() - $tokenData['created']) > $config['expire_time']) {
            unset($_SESSION['csrf_tokens'][$token]);
            return false;
        }
        
        // Mark token as used (optional, for additional security)
        $_SESSION['csrf_tokens'][$token]['used'] = true;
        
        return true;
    }

    /**
     * Validate request payload for mutating requests.
     *
     * Applies baseline validation/sanitization to all POST/PUT/PATCH/DELETE
     * form inputs and uploaded files before business logic executes.
     */
    private static function validateRequestPayload() {
        $config = getSecurityConfig('request_validation');
        if (!($config['enabled'] ?? true)) {
            return;
        }

        $errors = [];
        $sanitizedPost = [];

        foreach ($_POST as $field => $value) {
            $fieldName = (string)$field;
            if ($fieldName === '') {
                $errors['request'][] = 'Invalid form field name.';
                continue;
            }

            if (strlen($fieldName) > 128) {
                $errors[$fieldName][] = 'Form field name is too long.';
                continue;
            }

            $sanitizedPost[$field] = self::validateAndSanitizeValue($value, $fieldName, 0, $errors, $config);
        }

        $_POST = $sanitizedPost;

        if (($config['validate_files'] ?? true) && !empty($_FILES)) {
            self::validateUploadedFiles($errors);
        }

        if (!empty($errors)) {
            self::rejectInvalidPayload($errors);
        }
    }

    /**
     * Recursively validates and sanitizes scalar/array inputs.
     *
     * @param mixed $value Input value
     * @param string $path Field path for error reporting
     * @param int $depth Current nesting depth
     * @param array $errors Validation errors accumulator
     * @param array $config Request validation config
     * @return mixed
     */
    private static function validateAndSanitizeValue($value, $path, $depth, &$errors, $config) {
        $maxDepth = (int)($config['max_nesting_depth'] ?? 8);
        if ($depth > $maxDepth) {
            $errors[$path][] = 'Input nesting is too deep.';
            return null;
        }

        if (is_array($value)) {
            $maxArrayItems = (int)($config['max_array_items'] ?? 1000);
            if (count($value) > $maxArrayItems) {
                $errors[$path][] = 'Too many values were submitted for this field.';
            }

            $sanitized = [];
            foreach ($value as $key => $childValue) {
                $childPath = $path . '[' . $key . ']';
                $sanitized[$key] = self::validateAndSanitizeValue($childValue, $childPath, $depth + 1, $errors, $config);
            }

            return $sanitized;
        }

        if (is_string($value)) {
            if (strpos($value, "\0") !== false) {
                $errors[$path][] = 'Input contains null bytes.';
            }

            if (function_exists('mb_check_encoding') && !mb_check_encoding($value, 'UTF-8')) {
                $errors[$path][] = 'Input contains invalid UTF-8 characters.';
            }

            $maxLength = (int)($config['max_field_length'] ?? 20000);
            if (strlen($value) > $maxLength) {
                $errors[$path][] = "Input exceeds maximum length of {$maxLength} characters.";
            }

            $normalized = self::normalizeStringInput($value, $path);
            self::validateFieldUsingRules($path, $normalized, $errors);
            return $normalized;
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            self::validateFieldUsingRules($path, $value, $errors);
            return $value;
        }

        $errors[$path][] = 'Unsupported input type submitted.';
        return null;
    }

    /**
     * Normalizes input strings while preserving password whitespace.
     *
     * @param string $value
     * @param string $path
     * @return string
     */
    private static function normalizeStringInput($value, $path) {
        if (stripos($path, 'password') !== false) {
            return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        }

        return self::sanitizeInput($value, 'string');
    }

    /**
     * Applies configured field-specific validation rules when available.
     *
     * @param string $path
     * @param mixed $value
     * @param array $errors
     */
    private static function validateFieldUsingRules($path, $value, &$errors) {
        if (!defined('VALIDATION_RULES') || !is_array(VALIDATION_RULES)) {
            return;
        }

        $field = (string)$path;
        if (($dotPos = strpos($field, '.')) !== false) {
            $field = substr($field, 0, $dotPos);
        }
        if (($bracketPos = strpos($field, '[')) !== false) {
            $field = substr($field, 0, $bracketPos);
        }

        $rule = self::getRouteSpecificValidationRule($field);
        if ($rule === null && isset(VALIDATION_RULES[$field])) {
            $rule = VALIDATION_RULES[$field];
        }

        if ($rule === null) {
            return;
        }

        $rule['required'] = false;
        $validation = self::validateInput([$field => $value], [$field => $rule]);
        if (!($validation['valid'] ?? false) && isset($validation['errors'][$field])) {
            foreach ($validation['errors'][$field] as $message) {
                $errors[$path][] = $message;
            }
        }
    }

    /**
     * Returns route-specific rules for fields whose meaning varies by form.
     *
     * @param string $field
     * @return array|null
     */
    private static function getRouteSpecificValidationRule($field) {
        $route = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $route = strtolower($route);

        if (str_ends_with($route, '/update-owner-info.php')) {
            if ($field === 'phone') {
                return [
                    'required' => false,
                    'pattern' => '/^\+?[0-9][0-9\s().-]{5,19}$/',
                    'max_length' => 20
                ];
            }

            if ($field === 'idNumber') {
                return [
                    'required' => false,
                    'pattern' => '/^[A-Za-z0-9][A-Za-z0-9\s\/.-]{1,29}$/',
                    'max_length' => 30
                ];
            }
        }

        return null;
    }

    /**
     * Validates all uploaded files in the current request.
     *
     * @param array $errors Validation errors accumulator
     */
    private static function validateUploadedFiles(&$errors) {
        foreach ($_FILES as $field => $fileSpec) {
            foreach (self::flattenUploadedFileSpecs((string)$field, $fileSpec) as $uploaded) {
                $fileField = $uploaded['field'];
                $file = $uploaded['file'];

                if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }

                if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
                    $errors[$fileField][] = 'Invalid uploaded file payload.';
                    continue;
                }

                $validation = self::validateFileUpload($file);
                if (!($validation['valid'] ?? false)) {
                    foreach ($validation['errors'] as $message) {
                        $errors[$fileField][] = $message;
                    }
                }
            }
        }
    }

    /**
     * Normalizes $_FILES structures into a flat list.
     *
     * @param string $field
     * @param array $fileSpec
     * @return array<int, array{field:string,file:array}>
     */
    private static function flattenUploadedFileSpecs($field, $fileSpec) {
        if (!is_array($fileSpec)
            || !isset($fileSpec['name'], $fileSpec['type'], $fileSpec['tmp_name'], $fileSpec['error'], $fileSpec['size'])) {
            return [];
        }

        $collected = [];
        self::collectUploadedFiles(
            $field,
            $fileSpec['name'],
            $fileSpec['type'],
            $fileSpec['tmp_name'],
            $fileSpec['error'],
            $fileSpec['size'],
            $collected
        );

        return $collected;
    }

    /**
     * Recursive file collector for nested upload arrays.
     *
     * @param string $fieldPath
     * @param mixed $name
     * @param mixed $type
     * @param mixed $tmpName
     * @param mixed $error
     * @param mixed $size
     * @param array $collector
     */
    private static function collectUploadedFiles($fieldPath, $name, $type, $tmpName, $error, $size, &$collector) {
        if (is_array($name)) {
            foreach ($name as $index => $nestedName) {
                self::collectUploadedFiles(
                    $fieldPath . '[' . $index . ']',
                    $nestedName,
                    $type[$index] ?? '',
                    $tmpName[$index] ?? '',
                    $error[$index] ?? UPLOAD_ERR_NO_FILE,
                    $size[$index] ?? 0,
                    $collector
                );
            }
            return;
        }

        $collector[] = [
            'field' => $fieldPath,
            'file' => [
                'name' => (string)$name,
                'type' => (string)$type,
                'tmp_name' => (string)$tmpName,
                'error' => (int)$error,
                'size' => (int)$size
            ]
        ];
    }

    /**
     * Returns a validation response and halts execution.
     *
     * @param array $errors
     */
    private static function rejectInvalidPayload($errors) {
        $trimmedErrors = array_slice($errors, 0, 20, true);

        self::logSecurityEvent('input_validation_failed', [
            'route' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
            'error_count' => count($errors),
            'errors' => $trimmedErrors
        ]);

        http_response_code(422);
        if (self::expectsJsonResponse()) {
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'error',
                'message' => 'Input validation failed.',
                'errors' => $trimmedErrors
            ]);
            exit;
        }

        die('Input validation failed. Please review your submission and try again.');
    }

    /**
     * Determines if the current request expects a JSON response.
     *
     * @return bool
     */
    private static function expectsJsonResponse() {
        return (
            (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
            (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
            (isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
        );
    }
    
    /**
     * Validate Input Data
     * 
     * Validates and sanitizes input data according to defined rules.
     * 
     * @param array $data Input data to validate
     * @param array $rules Validation rules
     * @return array Array with 'valid' boolean and 'errors' array
     */
    public static function validateInput($data, $rules) {
        $errors = [];
        $validated = [];
        
        foreach ($rules as $field => $rule) {
            $value = $data[$field] ?? null;
            $isEmpty = $value === null
                || (is_string($value) && trim($value) === '')
                || (is_array($value) && empty($value));
            
            // Check required fields
            if (($rule['required'] ?? false) && $isEmpty) {
                $errors[$field][] = "The $field field is required.";
                continue;
            }
            
            // Skip validation for empty optional fields
            if ($isEmpty) {
                continue;
            }
            
            // Validate field type
            if (isset($rule['type'])) {
                switch ($rule['type']) {
                    case 'email':
                        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            $errors[$field][] = "The $field must be a valid email address.";
                        }
                        break;
                    case 'int':
                        if (!filter_var($value, FILTER_VALIDATE_INT)) {
                            $errors[$field][] = "The $field must be an integer.";
                        }
                        break;
                    case 'float':
                        if (!filter_var($value, FILTER_VALIDATE_FLOAT)) {
                            $errors[$field][] = "The $field must be a number.";
                        }
                        break;
                }
            }
            
            // Validate length
            if (isset($rule['max_length']) && strlen($value) > $rule['max_length']) {
                $errors[$field][] = "The $field may not be greater than {$rule['max_length']} characters.";
            }
            
            if (isset($rule['min_length']) && strlen($value) < $rule['min_length']) {
                $errors[$field][] = "The $field must be at least {$rule['min_length']} characters.";
            }
            
            // Validate pattern
            if (isset($rule['pattern']) && !preg_match($rule['pattern'], $value)) {
                $errors[$field][] = "The $field format is invalid.";
            }
            
            // Sanitize value if no errors
            if (!isset($errors[$field])) {
                $validated[$field] = self::sanitizeInput($value, $rule['type'] ?? 'string');
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'data' => $validated
        ];
    }
    
    /**
     * Sanitize Input
     * 
     * Sanitizes input data to prevent XSS and other attacks.
     * 
     * @param mixed $input Input to sanitize
     * @param string $type Type of sanitization
     * @return mixed Sanitized input
     */
    public static function sanitizeInput($input, $type = 'string') {
        if (is_array($input)) {
            return array_map(function($item) use ($type) {
                return self::sanitizeInput($item, $type);
            }, $input);
        }

        if ($input === null) {
            return null;
        }
        
        switch ($type) {
            case 'email':
                return filter_var(trim((string)$input), FILTER_SANITIZE_EMAIL);
            case 'int':
                return (int)filter_var((string)$input, FILTER_SANITIZE_NUMBER_INT);
            case 'float':
                return (float)filter_var((string)$input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            case 'url':
                return filter_var(trim((string)$input), FILTER_SANITIZE_URL);
            case 'string':
            default:
                $normalized = trim((string)$input);
                return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $normalized);
        }
    }
    
    /**
     * Escape Output
     * 
     * Escapes output to prevent XSS attacks.
     * 
     * @param string $output Output to escape
     * @param string $context Output context (html, js, css, url)
     * @return string Escaped output
     */
    public static function escapeOutput($output, $context = 'html') {
        switch ($context) {
            case 'html':
                return htmlspecialchars($output, ENT_QUOTES, 'UTF-8');
            case 'js':
                return json_encode($output);
            case 'css':
                return preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $output);
            case 'url':
                return urlencode($output);
            default:
                return htmlspecialchars($output, ENT_QUOTES, 'UTF-8');
        }
    }
    
    /**
     * Validate File Upload
     * 
     * Validates uploaded files for security.
     * 
     * @param array $file Uploaded file array
     * @return array Array with 'valid' boolean and 'errors' array
     */
    public static function validateFileUpload($file) {
        $errors = [];
        $config = getSecurityConfig('file_upload');

        $requiredKeys = ['name', 'type', 'tmp_name', 'error', 'size'];
        foreach ($requiredKeys as $requiredKey) {
            if (!array_key_exists($requiredKey, $file)) {
                $errors[] = "Malformed upload payload: missing {$requiredKey}.";
            }
        }
        if (!empty($errors)) {
            return ['valid' => false, 'errors' => $errors];
        }
        
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "File upload failed with error code: " . $file['error'];
            return ['valid' => false, 'errors' => $errors];
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            $errors[] = "Invalid uploaded file source.";
            return ['valid' => false, 'errors' => $errors];
        }
        
        // Check file size
        if ($file['size'] > $config['max_size']) {
            $errors[] = "File size exceeds maximum allowed size of " . formatBytes($config['max_size']);
        }
        
        // Check file type
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $mimeType = (string)($file['type'] ?? '');
        
        $allowedExtensions = [];
        foreach (($config['allowed_types'] ?? []) as $group) {
            if (is_array($group)) {
                $allowedExtensions = array_merge($allowedExtensions, $group);
            }
        }
        if (!in_array($extension, $allowedExtensions)) {
            $errors[] = "File type not allowed. Allowed types: " . implode(', ', $allowedExtensions);
        }
        
        if ($mimeType !== '' && !in_array($mimeType, $config['allowed_mimes'])) {
            $errors[] = "MIME type not allowed.";
        }
        
        // Validate file content (basic check)
        if ($config['validate_content']) {
            $detectedMime = null;
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detectedMime = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);
            }
            
            if (!$detectedMime || !in_array($detectedMime, $config['allowed_mimes'])) {
                $errors[] = "File content validation failed.";
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Secure File Upload
     * 
     * Securely handles file uploads with proper naming and storage.
     * 
     * @param array $file Uploaded file array
     * @param string $directory Upload directory
     * @return array Array with 'success' boolean and 'path' string
     */
    public static function secureFileUpload($file, $directory = null) {
        $config = getSecurityConfig('file_upload');
        
        // Validate file
        $validation = self::validateFileUpload($file);
        if (!$validation['valid']) {
            return ['success' => false, 'errors' => $validation['errors']];
        }
        
        // Create secure upload directory
        $uploadDir = $directory ?: $config['upload_path'];
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Generate secure filename
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($config['randomize_names']) {
            $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        } else {
            $filename = self::sanitizeInput(pathinfo($file['name'], PATHINFO_FILENAME), 'string') . '.' . $extension;
        }
        
        $filepath = $uploadDir . '/' . $filename;
        
        // Move uploaded file
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            // Set proper permissions
            chmod($filepath, 0644);
            
            self::logSecurityEvent('file_upload_success', [
                'original_name' => $file['name'],
                'stored_name' => $filename,
                'size' => $file['size'],
                'mime_type' => $file['type']
            ]);
            
            return ['success' => true, 'path' => $filepath, 'filename' => $filename];
        } else {
            return ['success' => false, 'errors' => ['Failed to move uploaded file']];
        }
    }
    
    /**
     * Check Permission
     * 
     * Checks if the current user has the required permission.
     * 
     * @param string $permission Permission to check
     * @return bool True if user has permission
     */
    public static function checkPermission($permission) {
        if (!isLoggedIn()) {
            return false;
        }
        
        $userType = getCurrentUserType();
        $config = getSecurityConfig('access_control');
        
        // Admin has all permissions
        if ($userType === 'admin' || isAdmin()) {
            return true;
        }
        
        // Check user role permissions
        if (isset($config['roles'][$userType])) {
            $permissions = $config['roles'][$userType]['permissions'];
            return in_array($permission, $permissions) || in_array('*', $permissions);
        }
        
        return false;
    }
    
    /**
     * Require Permission
     * 
     * Requires a specific permission and redirects if not granted.
     * 
     * @param string $permission Permission required
     * @param string $redirectUrl URL to redirect if permission denied
     */
    public static function requirePermission($permission, $redirectUrl = null) {
        if (!self::checkPermission($permission)) {
            self::logSecurityEvent('permission_denied', [
                'permission' => $permission,
                'user_id' => getCurrentUserId(),
                'user_type' => getCurrentUserType(),
                'ip' => self::getClientIP()
            ]);
            
            $redirectUrl = $redirectUrl ?: BASE_URL . '/login.php';
            redirect($redirectUrl);
        }
    }
    
    /**
     * Log Security Event
     * 
     * Logs security-related events for monitoring and auditing.
     * 
     * @param string $event Event type
     * @param array $data Event data
     */
    public static function logSecurityEvent($event, $data = []) {
        // Get audit logging configuration directly
        $config = defined('AUDIT_LOGGING') ? AUDIT_LOGGING : [];
        
        // Skip if audit logging is disabled or not configured
        if (!($config['enabled'] ?? false)) {
            return;
        }
        
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'event' => $event,
            'ip' => self::getClientIP(),
            'user_id' => getCurrentUserId(),
            'user_type' => getCurrentUserType(),
            'data' => $data
        ];
        
        $logEntry = json_encode($logData) . PHP_EOL;
        
        // Ensure log directory exists
        $logDir = dirname($config['log_file']);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        file_put_contents($config['log_file'], $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Get Client IP Address
     * 
     * Gets the real client IP address, handling proxy headers.
     * 
     * @return string Client IP address
     */
    public static function getClientIP() {
        $ipKeys = ['HTTP_CF_CONNECTING_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    /**
     * Get Rate Limit Data
     *
     * Gets rate limiting timestamps for a specific IP and type.
     *
     * @param string $ip IP address
     * @param string $type Rate limit type
     * @param int|null $windowSeconds Optional time window in seconds
     * @return array<int, int> Rate limit timestamps
     */
    private static function getRateLimitData($ip, $type, $windowSeconds = null) {
        if (self::shouldUseDatabaseRateLimitStorage()) {
            try {
                return self::getRateLimitDataFromDatabase($ip, $type, $windowSeconds);
            } catch (Throwable $e) {
                self::logRateLimitStorageFailure($e);
            }
        }

        $key = "rate_limit_{$type}_{$ip}";
        $data = $_SESSION[$key] ?? [];
        if (!is_array($data)) {
            return [];
        }

        if ($windowSeconds !== null) {
            $threshold = time() - (int)$windowSeconds;
            $data = array_values(array_filter($data, function($time) use ($threshold) {
                return (int)$time >= $threshold;
            }));
            $_SESSION[$key] = $data;
        }

        return array_map('intval', $data);
    }

    /**
     * Clear Rate Limit Data
     *
     * Clears rate limiting data for a specific IP and type.
     *
     * @param string $ip IP address
     * @param string $type Rate limit type
     */
    private static function clearRateLimitData($ip, $type) {
        if (self::shouldUseDatabaseRateLimitStorage()) {
            try {
                self::clearRateLimitDataFromDatabase($ip, $type);
                return;
            } catch (Throwable $e) {
                self::logRateLimitStorageFailure($e);
            }
        }

        $key = "rate_limit_{$type}_{$ip}";
        unset($_SESSION[$key]);
    }

    /**
     * Add Rate Limit Data
     *
     * Stores a request timestamp and prunes entries outside the time window.
     *
     * @param string $ip IP address
     * @param string $type Rate limit type
     * @param int $timestamp Current timestamp
     * @param int $windowSeconds Time window in seconds
     */
    private static function addRateLimitData($ip, $type, $timestamp, $windowSeconds) {
        if (self::shouldUseDatabaseRateLimitStorage()) {
            try {
                self::addRateLimitDataToDatabase($ip, $type, $timestamp, $windowSeconds);
                return;
            } catch (Throwable $e) {
                self::logRateLimitStorageFailure($e);
            }
        }

        $key = "rate_limit_{$type}_{$ip}";
        if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
            $_SESSION[$key] = [];
        }

        $_SESSION[$key][] = (int)$timestamp;
        $_SESSION[$key] = array_values(array_filter($_SESSION[$key], function($time) use ($timestamp, $windowSeconds) {
            return ((int)$timestamp - (int)$time) < (int)$windowSeconds;
        }));
    }

    /**
     * Determines whether database-backed rate limiting is enabled.
     *
     * @return bool
     */
    private static function shouldUseDatabaseRateLimitStorage() {
        $config = getSecurityConfig('rate_limiting');
        $storage = strtolower((string)($config['storage'] ?? 'session'));
        return $storage === 'database';
    }

    /**
     * Reads rate limit timestamps from the database.
     *
     * @param string $ip
     * @param string $type
     * @param int|null $windowSeconds
     * @return array<int, int>
     */
    private static function getRateLimitDataFromDatabase($ip, $type, $windowSeconds = null) {
        self::ensureRateLimitTable();
        $pdo = getDatabaseConnection();

        if ($windowSeconds !== null) {
            $threshold = time() - (int)$windowSeconds;
            $sql = 'SELECT created_at FROM security_rate_limits WHERE ip_address = :ip AND limit_type = :type AND created_at >= :threshold ORDER BY created_at ASC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':ip' => $ip,
                ':type' => $type,
                ':threshold' => $threshold
            ]);
        } else {
            $sql = 'SELECT created_at FROM security_rate_limits WHERE ip_address = :ip AND limit_type = :type ORDER BY created_at ASC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':ip' => $ip,
                ':type' => $type
            ]);
        }

        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return array_map('intval', $rows ?: []);
    }

    /**
     * Clears stored rate limit records for a given IP/type pair.
     *
     * @param string $ip
     * @param string $type
     */
    private static function clearRateLimitDataFromDatabase($ip, $type) {
        self::ensureRateLimitTable();
        $pdo = getDatabaseConnection();
        $stmt = $pdo->prepare('DELETE FROM security_rate_limits WHERE ip_address = :ip AND limit_type = :type');
        $stmt->execute([
            ':ip' => $ip,
            ':type' => $type
        ]);
    }

    /**
     * Stores a new rate limit event and prunes stale rows.
     *
     * @param string $ip
     * @param string $type
     * @param int $timestamp
     * @param int $windowSeconds
     */
    private static function addRateLimitDataToDatabase($ip, $type, $timestamp, $windowSeconds) {
        self::ensureRateLimitTable();
        $pdo = getDatabaseConnection();

        $insert = $pdo->prepare('INSERT INTO security_rate_limits (ip_address, limit_type, created_at) VALUES (:ip, :type, :created_at)');
        $insert->execute([
            ':ip' => $ip,
            ':type' => $type,
            ':created_at' => (int)$timestamp
        ]);

        $pruneBefore = (int)$timestamp - max((int)$windowSeconds, 86400);
        $prune = $pdo->prepare('DELETE FROM security_rate_limits WHERE ip_address = :ip AND limit_type = :type AND created_at < :prune_before');
        $prune->execute([
            ':ip' => $ip,
            ':type' => $type,
            ':prune_before' => $pruneBefore
        ]);
    }

    /**
     * Ensures the database table for rate limiting exists.
     */
    private static function ensureRateLimitTable() {
        if (self::$rateLimitTableReady) {
            return;
        }

        $pdo = getDatabaseConnection();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS security_rate_limits (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ip_address VARCHAR(45) NOT NULL,
                limit_type VARCHAR(50) NOT NULL,
                created_at INT UNSIGNED NOT NULL,
                INDEX idx_security_rate_limits_lookup (limit_type, ip_address, created_at),
                INDEX idx_security_rate_limits_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        self::$rateLimitTableReady = true;
    }

    /**
     * Logs a single warning and falls back to session-based storage.
     *
     * @param Throwable $e
     */
    private static function logRateLimitStorageFailure($e) {
        if (self::$rateLimitStorageFailureLogged) {
            return;
        }

        self::$rateLimitStorageFailureLogged = true;
        $message = 'Rate limit storage fallback to session: ' . $e->getMessage();

        if (function_exists('logError')) {
            logError($message, 'WARNING');
            return;
        }

        error_log($message);
    }
    
    /**
     * Route Matches Pattern
     * 
     * Checks if a route matches a pattern (supports wildcards).
     * 
     * @param string $route Route to check
     * @param string $pattern Pattern to match
     * @return bool True if route matches pattern
     */
    private static function routeMatches($route, $pattern) {
        $pattern = str_replace('*', '.*', $pattern);
        return preg_match("#^{$pattern}$#", $route);
    }
}

/**
 * Helper function to format bytes
 * 
 * @param int $bytes Bytes to format
 * @return string Formatted bytes
 */
function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= pow(1024, $pow);
    
    return round($bytes, 2) . ' ' . $units[$pow];
} 

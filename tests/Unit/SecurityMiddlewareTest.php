<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SecurityMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    public function testSanitizeInputTrimsAndRemovesControlCharacters(): void
    {
        $input = " \x00Hello World\x1F ";
        $sanitized = SecurityMiddleware::sanitizeInput($input, 'string');

        $this->assertSame('Hello World', $sanitized);
    }

    public function testValidateInputRejectsInvalidEmail(): void
    {
        $result = SecurityMiddleware::validateInput(
            ['email' => 'not-an-email'],
            ['email' => ['required' => true, 'type' => 'email']]
        );

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('email', $result['errors']);
    }

    public function testValidateInputAcceptsValidEmail(): void
    {
        $result = SecurityMiddleware::validateInput(
            ['email' => 'user@example.com'],
            ['email' => ['required' => true, 'type' => 'email']]
        );

        $this->assertTrue($result['valid']);
        $this->assertSame('user@example.com', $result['data']['email']);
    }

    public function testGenerateAndVerifyCsrfToken(): void
    {
        $token = SecurityMiddleware::generateCSRFToken();

        $this->assertNotEmpty($token);
        $this->assertTrue(SecurityMiddleware::verifyCSRFToken($token));
    }

    public function testValidateFileUploadRejectsMalformedPayload(): void
    {
        $result = SecurityMiddleware::validateFileUpload(['name' => 'demo.png']);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testOwnerUpdateAllowsStaffStyleIdAndLocalPhone(): void
    {
        $_SERVER['REQUEST_URI'] = '/update-owner-info.php';

        $errors = [];
        $method = new ReflectionMethod(SecurityMiddleware::class, 'validateFieldUsingRules');
        $method->setAccessible(true);
        $method->invokeArgs(null, ['idNumber', 'c103', &$errors]);
        $method->invokeArgs(null, ['phone', '7780033', &$errors]);

        $this->assertSame([], $errors);
    }
}


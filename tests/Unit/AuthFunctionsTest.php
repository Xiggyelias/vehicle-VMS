<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AuthFunctionsTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    public function testClearAuthenticationSessionStateRemovesOnlyAuthKeys(): void
    {
        $_SESSION = [
            'user_id' => 10,
            'admin_id' => 20,
            'is_admin' => true,
            'logged_in' => true,
            'custom_value' => 'keep-me'
        ];

        clearAuthenticationSessionState();

        $this->assertArrayNotHasKey('user_id', $_SESSION);
        $this->assertArrayNotHasKey('admin_id', $_SESSION);
        $this->assertArrayNotHasKey('is_admin', $_SESSION);
        $this->assertArrayNotHasKey('logged_in', $_SESSION);
        $this->assertSame('keep-me', $_SESSION['custom_value']);
    }

    public function testRegenerateSessionIdForPrivilegeChangeRegeneratesAndClearsCsrf(): void
    {
        $_SESSION['csrf_tokens'] = ['token' => ['created' => time(), 'used' => false]];

        regenerateSessionIdForPrivilegeChange();
        
        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        $this->assertArrayNotHasKey('csrf_tokens', $_SESSION);
        $this->assertArrayHasKey('session_regenerated_at', $_SESSION);
    }

    public function testIsLoggedInAndIsAdminFollowSessionFlags(): void
    {
        $_SESSION['logged_in'] = true;
        $_SESSION['is_admin'] = false;

        $this->assertTrue(isLoggedIn());
        $this->assertFalse(isAdmin());

        $_SESSION['is_admin'] = true;
        $this->assertTrue(isAdmin());
    }
}

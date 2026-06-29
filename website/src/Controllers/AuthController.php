<?php

declare(strict_types=1);

namespace Starlink\Controllers;

use Starlink\Auth\AuthException;
use Starlink\Auth\AuthService;

final class AuthController
{
    public function __construct(
        private readonly AuthService $auth = new AuthService(),
    ) {
    }

    public function showLogin(): void
    {
        $next = safe_redirect_path(isset($_GET['next']) ? (string) $_GET['next'] : null);

        if ($this->auth->check()) {
            if ($next !== null && ($this->auth->role() ?? '') === 'customer') {
                redirect($next);
            }

            $this->redirectForRole($this->auth->role());
        }

        view('auth/login', [
            'next' => $next,
        ]);
    }

    public function login(): void
    {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $remember = !empty($_POST['remember']);
        $next = safe_redirect_path(isset($_POST['next']) ? (string) $_POST['next'] : null);

        if ($this->auth->login($email, $password, $remember)) {
            if ($next !== null && ($this->auth->role() ?? '') === 'customer') {
                redirect($next);
            }

            $this->redirectForRole($this->auth->role());
        }

        \Starlink\Auth\Session::flash('old_email', $email);
        if ($next !== null && str_contains($next, '/checkout')) {
            redirect($next);
        }
        redirect($next !== null ? route_path('login') . '?next=' . rawurlencode($next) : route_path('login'));
    }

    public function showRegister(): void
    {
        if ($this->auth->check()) {
            redirect(route_path('/'));
        }

        view('auth/register');
    }

    public function register(): void
    {
        $ip = request_client_ip() ?? 'unknown';
        $rateLimit = new RateLimitService();
        if ($rateLimit->tooManyAttempts('register-ip:' . $ip, 10, 3600)) {
            \Starlink\Auth\Session::flash('error', 'Too many registration attempts. Try again later.');
            redirect(route_path('register'));
        }

        if (honeypot_triggered()) {
            \Starlink\Auth\Session::flash('error', 'Registration could not be completed. Please try again.');
            redirect(route_path('register'));
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        try {
            $this->auth->registerCustomer($name, $email, $password, $phone !== '' ? $phone : null);
            $next = safe_redirect_path(isset($_POST['next']) ? (string) $_POST['next'] : null);
            if ($next !== null) {
                redirect($next);
            }
            redirect(route_path('/'));
        } catch (AuthException $e) {
            \Starlink\Auth\Session::flash('error', $e->getMessage());
            \Starlink\Auth\Session::flash('old_name', $name);
            \Starlink\Auth\Session::flash('old_email', $email);
            \Starlink\Auth\Session::flash('old_phone', $phone);
            $next = safe_redirect_path(isset($_POST['next']) ? (string) $_POST['next'] : null);
            if ($next !== null && str_contains($next, '/checkout')) {
                redirect($next);
            }
            redirect(route_path('register'));
        }
    }

    public function logout(): void
    {
        $this->auth->logout();
        redirect(route_path('login'));
    }

    private function redirectForRole(?string $role): never
    {
        match ($role) {
            'admin' => redirect(route_path('admin')),
            'partner' => redirect(route_path('partner')),
            default => redirect(route_path('/')),
        };
    }
}

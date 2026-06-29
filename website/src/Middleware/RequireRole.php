<?php

declare(strict_types=1);

namespace Starlink\Middleware;

use Starlink\Auth\AuthService;

final class RequireRole
{
    private readonly AuthService $auth;

    public function __construct(?AuthService $auth = null)
    {
        $this->auth = $auth ?? new AuthService();
    }

    /** @param list<string> $roles */
    public function handle(array $roles): array
    {
        $user = $this->auth->user();
        if ($user === null) {
            redirect(route_path('login'));
        }

        if (!in_array($user['role'], $roles, true)) {
            http_response_code(403);
            view('errors/forbidden', ['user' => $user]);
            exit;
        }

        return $user;
    }
}

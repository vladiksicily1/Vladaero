<?php
namespace VladAero\Middleware;

use VladAero\Core\Session;

/**
 * RequireAdmin — проверяет роль admin/moderator
 */
class RequireAdmin
{
    public function handle(array &$params): mixed
    {
        $user = Session::getAuth();
        if (!$user || !in_array($user['role'] ?? '', ['admin', 'moderator'], true)) {
            Session::setFlash('error', 'Недостаточно прав для доступа');
            header('Location: /auth/login');
            exit;
        }
        return null;
    }
}

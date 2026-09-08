<?php
/**
 * Admin Authentication & RBAC Middleware for ShibaLingo
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function getAdminUser(): ?array {
    $adminId = $_SESSION['admin_id'] ?? null;
    if (!$adminId) return null;

    $db = getDb();
    $stmt = $db->prepare("SELECT u.*, r.slug as role_slug, r.name as role_name, r.permissions 
                          FROM " . tbl('users') . " u 
                          LEFT JOIN " . tbl('roles') . " r ON u.role_id = r.id 
                          WHERE u.id = :id AND u.status = 'active'");
    $stmt->execute(['id' => $adminId]);
    return $stmt->fetch() ?: null;
}

function requireAdminAuth(?string $requiredPermission = null): array {
    $admin = getAdminUser();
    if (!$admin) {
        header("Location: login.php");
        exit;
    }

    if ($requiredPermission !== null) {
        $permissions = json_decode($admin['permissions'] ?? '[]', true) ?: [];
        if (!in_array('*', $permissions) && !in_array($requiredPermission, $permissions)) {
            die("<h1>403 Доступ запрещен</h1><p>У вашей роли нет права: <code>{$requiredPermission}</code></p><a href='index.php'>Назад в панель</a>");
        }
    }

    return $admin;
}

function hasPermission(array $admin, string $perm): bool {
    $permissions = json_decode($admin['permissions'] ?? '[]', true) ?: [];
    return in_array('*', $permissions) || in_array($perm, $permissions);
}

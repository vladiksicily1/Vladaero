<?php
/**
 * ShibaLingo - Settings Redirector
 * (System and AI settings are strictly managed in Admin Panel)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['admin_id'])) {
    header("Location: admin/settings.php");
    exit;
} else {
    header("Location: profile.php");
    exit;
}

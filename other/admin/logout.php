<?php
/**
 * Admin Logout
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
unset($_SESSION['admin_id']);
unset($_SESSION['admin_name']);
unset($_SESSION['admin_role']);
header("Location: login.php");
exit;

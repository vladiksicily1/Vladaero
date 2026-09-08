<?php
/**
 * ShibaLingo - User Logout Page
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

unset($_SESSION['user_id']);
unset($_SESSION['username']);
unset($_SESSION['current_language']);

// If admin session is also set, don't necessarily clear it unless requested, but destroy user session
header("Location: index.php");
exit;

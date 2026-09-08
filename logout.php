<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

Auth::logout();
setFlash('success', 'Вы успешно вышли из системы. Чистого неба!');
header('Location: ' . url('/'));
exit;

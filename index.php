<?php
require_once __DIR__ . '/core/helpers.php';
session_init();

if (is_logged()) {
    header('Location: ' . APP_URL . '/modules/dashboard/index.php');
} else {
    header('Location: ' . APP_URL . '/modules/auth/login.php');
}
exit;

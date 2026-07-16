<?php
/* ajax_pwa_log.php — Receptor de logs de diagnóstico iOS desde JavaScript
 * Solo activo cuando existe /home/groupglo/logs/.pwa_debug_on
 * Sin autenticación intencionada: el log no expone datos sensibles */

$flag     = '/home/groupglo/logs/.pwa_debug_on';
$log_file = '/home/groupglo/logs/pwa_debug.log';

header('Content-Type: application/json');

if (!file_exists($flag)) { echo '{"ok":false,"reason":"disabled"}'; exit; }

$msg = trim($_POST['msg'] ?? '');
if (!$msg || strlen($msg) > 500) { echo '{"ok":false,"reason":"bad_msg"}'; exit; }

$ua   = substr($_SERVER['HTTP_USER_AGENT'] ?? '-', 0, 80);
$line = date('Y-m-d H:i:s') . ' | [JS] | ' . $msg . ' | UA=' . $ua . "\n";

$ok = (bool) @file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX);
echo $ok ? '{"ok":true}' : '{"ok":false,"reason":"write_failed"}';

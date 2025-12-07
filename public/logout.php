<?php
// Ensure session is started before attempting to destroy it
if (function_exists('session_status')) {
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
} else {
	@session_start();
}
session_destroy();
header("Location: index.php");
exit;

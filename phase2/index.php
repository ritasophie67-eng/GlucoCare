<?php
require_once __DIR__ . '/includes/auth.php';

redirect(is_logged_in() ? home_for_role($_SESSION['role']) : 'login.php');

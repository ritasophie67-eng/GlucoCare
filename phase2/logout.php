<?php
require_once __DIR__ . '/includes/auth.php';

logout_user(get_db_connection());
session_start();
set_flash('info', 'You have been signed out.');
redirect('login.php');

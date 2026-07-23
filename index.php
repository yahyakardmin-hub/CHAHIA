<?php
require_once 'config.php';
require_once 'includes/auth.php';

header('Location: ' . (estConnecte() ? 'TDB.php' : 'login.php'));
exit;

<?php
require_once 'config.php';
require_once 'includes/auth.php';

deconnecterUtilisateur();
header('Location: login.php');
exit;

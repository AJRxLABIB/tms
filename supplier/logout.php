<?php
require __DIR__ . '/_layout.php';
unset($_SESSION['supplier_id']);
session_regenerate_id(true);
header('Location: login.php');
exit;

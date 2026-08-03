<?php
include('global.inc');
$_SESSION = array();
session_destroy();
header("Location: index.php");
exit();
?>

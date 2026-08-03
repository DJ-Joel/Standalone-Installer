<?php
include('global.inc');
requireLogin('login.php');

$favoriteId = isset($_GET['favorite_id']) ? (int)$_GET['favorite_id'] : 0;

// The singer_id check ensures singers can only remove their own favorites,
// even if they guess or tamper with another favorite_id.
$stmt = $db->prepare("DELETE FROM favorites WHERE favorite_id = :fid AND singer_id = :sid");
$stmt->execute(array(':fid' => $favoriteId, ':sid' => currentSingerId()));

header("Location: favorites.php?removed=1");
exit();
?>

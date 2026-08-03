<?php
include('global.inc');
requireLogin('login.php');
siteheader('Submit Request');
navbar('favorites.php');

$favoriteId = isset($_GET['favorite_id']) ? (int)$_GET['favorite_id'] : 0;

$stmt = $db->prepare("SELECT artist,title FROM favorites WHERE favorite_id = :fid AND singer_id = :sid");
$stmt->execute(array(':fid' => $favoriteId, ':sid' => currentSingerId()));
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row)
{
	echo "<br><p>Sorry, that favorite could not be found.</p>";
	sitefooter();
	exit();
}

$artist = $row['artist'];
$title = $row['title'];
$defaultSinger = htmlspecialchars(currentSingerName());

echo "<br><p>Submitting Song:<br>";
echo "<p>" . htmlspecialchars("$artist - $title") . "</p>";
echo "<form method=get action=submit-favorite-run.php><input type=hidden name=favorite_id value=\"$favoriteId\">Please enter your name or nickname:<br><input type=text name=singer autocomplete=off value=\"$defaultSinger\"><br>";
keyChangeSelect();
echo "<input type=submit></form>";
echo "<p class=info>If you have a common first name, please also enter your last initial or last name.<br>Doing so will help eliminate confusion and reduce the risk of your turn getting skipped.";
sitefooter();
?>

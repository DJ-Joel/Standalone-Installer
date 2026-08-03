<?php
include('global.inc');
requireLogin('login.php');
siteheader('Song Submitted');
navbar('favorites.php');

$favoriteId = isset($_GET['favorite_id']) ? (int)$_GET['favorite_id'] : 0;
$singer = trim($_GET['singer'] ?? '');
$keychange = isset($_GET['keychange']) ? (int)$_GET['keychange'] : 0;
if ($keychange < -6 || $keychange > 6)
{
	$keychange = 0;
}

if ($singer === '')
{
	echo "<br><p>Sorry, you must input a singer name. Please go back and try again.</p>";
	sitefooter();
	exit();
}

// Re-derive artist/title from the favorites table (scoped to this singer)
// rather than trusting client-supplied values, since this endpoint accepts a
// favorite_id, not song details, from the request.
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

$ins = $db->prepare("INSERT INTO requests (singer,artist,title,key_change) VALUES (:singer, :artist, :title, :keychange)");
$ins->execute(array(':singer' => $singer, ':artist' => $artist, ':title' => $title, ':keychange' => $keychange));
newSerial();

$keychangeLabel = $keychange == 0 ? "No change" : ($keychange > 0 ? "+$keychange" : $keychange);

echo "<p>Song: " . htmlspecialchars("$artist - $title") . "</p>
      <p>Submitted for singer: " . htmlspecialchars($singer) . "</p>
      <p>Key change: $keychangeLabel</p>
	<br><p>Please press back to return to the main screen</p>
";
sitefooter();
?>

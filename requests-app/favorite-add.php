<?php
include('global.inc');
requireLogin('login.php');

$songId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$streamId = isset($_GET['stream_id']) ? (int)$_GET['stream_id'] : 0;

$artist = '';
$title = '';

if ($streamId > 0)
{
	$entry = getStreamLibraryEntry($streamId);
	if ($entry)
	{
		$artist = $entry['artist'];
		$title = $entry['title'];
	}
}
elseif ($songId > 0)
{
	$stmt = $db->prepare("SELECT artist,title FROM songdb WHERE song_id = :id");
	$stmt->execute(array(':id' => $songId));
	$row = $stmt->fetch(PDO::FETCH_ASSOC);
	if ($row)
	{
		$artist = $row['artist'];
		$title = $row['title'];
	}
}

if ($artist !== '' && $title !== '')
{
	// Favorites only ever store artist/title text, not a reference back to
	// songdb or the stream library, so this is the same insert regardless of
	// where the song came from. INSERT OR IGNORE respects the existing
	// UNIQUE(singer_id, artist, title) constraint - clicking an already-
	// favorited star again is a harmless no-op rather than an error.
	$stmt = $db->prepare("INSERT OR IGNORE INTO favorites (singer_id, artist, title) VALUES (:sid, :artist, :title)");
	$stmt->execute(array(':sid' => currentSingerId(), ':artist' => $artist, ':title' => $title));
}

$backurl = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'index.php';
header("Location: $backurl");
exit();
?>

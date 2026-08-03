<?php
include('global.inc');
siteheader('Submit Request');
$referer = $_SERVER['HTTP_REFERER'];
if (strpos($referer,'submitreq-run.php?screensize=$screensize') !== false)
{
	navbar("index.php");
} else {
	navbar($referer);
}

$streamId = isset($_GET['stream_id']) ? (int)$_GET['stream_id'] : 0;

$prefillSinger = isLoggedIn() ? htmlspecialchars(currentSingerName()) : '';

if ($streamId > 0)
{
	$entry = getStreamLibraryEntry($streamId);
	$artist = $entry ? $entry['artist'] : '';
	$title = $entry ? $entry['title'] : '';
	echo "<br><p>Submitting Song:<br>";
	echo "<p>$artist - $title</p>";
	// No key change selector here - streamed songs always play at their
	// original key.
	echo "<form method=get action=submitreq-run.php><input type=hidden name=screensize value=$screensize><input type=hidden name=stream_id value=$streamId>Please enter your name or nickname:<br><input type=text name=singer autocomplete=off autofocus value=\"$prefillSinger\"><br>";
	echo "<input type=submit></form>";
	echo "<p class=info>If you have a common first name, please also enter your last initial or last name.<br>Doing so will help eliminate confusion and reduce the risk of your turn getting skipped.";
	sitefooter();
	exit();
}

$songid = $_GET['id'];

$artist = '';
$title = '';
$sql = "SELECT artist,title FROM songdb WHERE song_id = $songid";
foreach ($db->query($sql) as $row) {
        $artist = $row['artist'];
        $title = $row['title'];
}
$db = null;
echo "<br><p>Submitting Song:<br>";
echo "<p>$artist - $title</p>";
echo "<form method=get action=submitreq-run.php><input type=hidden name=screensize value=$screensize><input type=hidden name=songid value=$songid>Please enter your name or nickname:<br><input type=text name=singer autocomplete=off autofocus value=\"$prefillSinger\"><br>";
keyChangeSelect();
echo "<input type=submit></form>";
echo "<p class=info>If you have a common first name, please also enter your last initial or last name.<br>Doing so will help eliminate confusion and reduce the risk of your turn getting skipped.";
?>

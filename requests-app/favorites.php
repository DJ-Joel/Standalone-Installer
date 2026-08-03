<?php
include('global.inc');
siteheader('My Favorites');
navbar('index.php');

if (!isLoggedIn())
{
	echo "<br><p>You need an account to save favorites.</p>
	<p><a href=\"login.php\">Log in</a> or <a href=\"register.php\">create a free account</a> to get started.</p>";
	sitefooter();
	exit();
}

if (isset($_GET['welcome']))
{
	echo "<br><p class=info>Welcome! Search for songs and tap the &#9734; to save them here.</p>";
}

$stmt = $db->prepare("SELECT favorite_id, artist, title FROM favorites WHERE singer_id = :sid ORDER BY UPPER(artist), UPPER(title)");
$stmt->execute(array(':sid' => currentSingerId()));
$favorites = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<br><p>My Favorites</p>";

if (count($favorites) == 0)
{
	echo "<p class=info>You haven't saved any favorites yet.<br>Search for a song and tap the &#9734; next to it to save it here.</p>";
}
else
{
	echo '<table border=1>';
	foreach ($favorites as $fav)
	{
		$label = htmlspecialchars($fav['artist'] . " - " . $fav['title']);
		$fid = (int)$fav['favorite_id'];
		echo "<tr>
			<td class=result>$label</td>
			<td class=favaction><a class=button href=\"submit-favorite.php?favorite_id=$fid\">Request</a></td>
			<td class=favaction><a class=button href=\"favorite-remove.php?favorite_id=$fid\" onclick=\"return confirm('Remove this favorite?')\">Remove</a></td>
		</tr>";
	}
	echo '</table>';
}

sitefooter();
?>

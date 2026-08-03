<?php 
include('global.inc');
siteheader('Search Results');
navbar("index.php");

if ($_GET['q'] == '') {
        echo "<p>You must enter at least one search term</p>";
        die();
}

if (strlen($_GET['q']) < 3)
{
	echo '<p>Your search string was too short, please try again</p>';
	die();
}

echo '<br><p>Search Results<br>Tap a song to submit it</p>';
if (isLoggedIn()) {
	echo '<p class=info>Tap the &#9734; to save a song to My Favorites.</p>';
}

$terms = explode(' ',$_GET['q']);
$no = count($terms);
$wherestring = '';
if ($no == 1) {
	$wherestring = "WHERE (combined LIKE \"%" . $terms[0] . "%\")";
} elseif ($no >= 2) {
        foreach ($terms as $i => $term) {
            if ($i == 0) {
                $wherestring .= "WHERE ((combined LIKE \"%" . $term . "%\")";
            }
            if (($i > 0) && ($i < $no - 1)) {
                $wherestring .= " AND (combined LIKE \"%" . $term . "%\")";
            }
            if ($i == $no - 1) {
                $wherestring .= " AND (combined LIKE \"%" . $term . "%\") AND(artist != 'DELETED'))";
            }
        }

} else {
	echo "<li>You must enter at least one search term</li>";
	die();
}

$entries = null;
$res = array();
    $sql = "SELECT song_id,artist,title,combined FROM songdb $wherestring ORDER BY UPPER(artist), UPPER(title)";
    foreach ($db->query($sql) as $row)
        {
	if ((stripos($row['combined'],'wvocal') === false) && (stripos($row['combined'],'w-vocal') === false) && (stripos($row['combined'],'vocals') === false)) {
		$res[$row['song_id']] = $row['artist'] . " - " . $row['title'];
	}
        }

$unique = array_unique($res);

foreach ($unique as $key => $val) {
	if (isLoggedIn()) {
		$favCell = "<td class=favcol><a class=favstar href=\"favorite-add.php?id={$key}\" title=\"Save to favorites\">&#9734;</a></td>";
	} else {
		$favCell = "<td class=favcol></td>";
	}
	$entries[] = "<tr><td class=result onclick=\"submitreq({$key})\">" . $val . "</td>$favCell</tr>";
}

// Stream library results - songs a KJ previously added via a stream link
// (e.g. from a chat request) that aren't in the local songdb catalog at all.
// Same search terms, matched against artist+title instead of a combined col.
$streamRows = searchStreamLibrary($terms);
foreach ($streamRows as $row) {
	$label = htmlspecialchars($row['artist'] . " - " . $row['title']) . " <span class=streamtag>(stream)</span>";
	if (isLoggedIn()) {
		$favCell = "<td class=favcol><a class=favstar href=\"favorite-add.php?stream_id={$row['stream_id']}\" title=\"Save to favorites\">&#9734;</a></td>";
	} else {
		$favCell = "<td class=favcol></td>";
	}
	$entries[] = "<tr><td class=result onclick=\"submitstreamreq({$row['stream_id']})\">" . $label . "</td>$favCell</tr>";
}

$totalResults = count($unique) + count($streamRows);
$db = null;

if ($totalResults > 0) {
	echo '<table border=1>';
	foreach ($entries as $song) {
		echo $song;
	}
	echo '</table>';
	if (isLoggedIn()) {
		echo "<p class=info>Not the version you wanted? <a href=\"chat.php\">Message the KJ</a>.</p>";
	}
} else {
	echo "<p>Sorry, no match found.</p>";
	if (isLoggedIn()) {
		echo "<p class=info>Can't find the song you wanted? <a href=\"chat.php\">Message the KJ</a> and let them know.</p>";
	} else {
		echo "<p class=info>Can't find the song you wanted? <a href=\"login.php?redirect=chat.php\">Log in</a> to message the KJ about it.</p>";
	}
}

sitefooter();
?> 

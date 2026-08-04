<?php
include_once('settings.inc');
// Use the $db_file variable from settings.inc
$db = new PDO("sqlite:" . $db_file);

$db->exec("CREATE TABLE IF NOT EXISTS songdb (song_id integer PRIMARY KEY AUTOINCREMENT, artist text, title  TEXT, combined TEXT UNIQUE)"); 
$db->exec("CREATE TABLE IF NOT EXISTS state (accepting bool, serial integer NOT NULL)");
$db->exec("INSERT OR IGNORE INTO state (rowid,accepting,serial) VALUES(0,0,1)");
$db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_songstrings ON songdb(combined)");
$db->exec("CREATE TABLE IF NOT EXISTS requests (request_id integer PRIMARY KEY AUTOINCREMENT, artist TEXT, title TEXT, singer TEXT, request_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP, key_change INTEGER DEFAULT 0)");
// Migration for databases created before key_change existed. Wrapped in
// try/catch because PDO's default error mode varies by PHP version (PHP 8.1+
// defaults to ERRMODE_EXCEPTION, older versions default to ERRMODE_SILENT),
// and this call is expected to fail harmlessly once the column already exists.
try {
	$db->exec("ALTER TABLE requests ADD COLUMN key_change INTEGER DEFAULT 0");
} catch (PDOException $e) {
	// Column already exists, nothing to do.
}

$db->exec("CREATE TABLE IF NOT EXISTS singers (singer_id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE NOT NULL, password_hash TEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

// Migrate from the old username-only login to email+name. Detect the old
// schema by checking for the email column - if it's missing, this is a
// pre-migration database. Existing accounts have no email on file and can't
// be carried forward, so this does a clean wipe rather than a partial
// migration: singers, plus favorites and chat_messages, since both reference
// singer_id and would otherwise be left pointing at accounts that no longer
// exist.
$singersHasEmail = false;
foreach ($db->query("PRAGMA table_info(singers)") as $col)
{
	if ($col['name'] === 'email')
	{
		$singersHasEmail = true;
	}
}
if (!$singersHasEmail)
{
	$db->exec("DROP TABLE IF EXISTS singers");
	$db->exec("DROP TABLE IF EXISTS favorites");
	$db->exec("DROP TABLE IF EXISTS chat_messages");
	$db->exec("CREATE TABLE singers (singer_id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE NOT NULL, name TEXT NOT NULL, password_hash TEXT NOT NULL, muted INTEGER NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
	$db->exec("CREATE TABLE favorites (favorite_id INTEGER PRIMARY KEY AUTOINCREMENT, singer_id INTEGER NOT NULL, artist TEXT NOT NULL, title TEXT NOT NULL, added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE(singer_id, artist, title))");
	$db->exec("CREATE TABLE chat_messages (message_id INTEGER PRIMARY KEY AUTOINCREMENT, singer_id INTEGER NOT NULL, from_kj INTEGER NOT NULL DEFAULT 0, message_text TEXT NOT NULL, sent_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP, hidden INTEGER NOT NULL DEFAULT 0)");
}

$db->exec("CREATE TABLE IF NOT EXISTS favorites (favorite_id INTEGER PRIMARY KEY AUTOINCREMENT, singer_id INTEGER NOT NULL, artist TEXT NOT NULL, title TEXT NOT NULL, added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE(singer_id, artist, title))");
$db->exec("CREATE INDEX IF NOT EXISTS idx_favorites_singer ON favorites(singer_id)");

$db->exec("CREATE TABLE IF NOT EXISTS rotation (rotation_id INTEGER PRIMARY KEY AUTOINCREMENT, singer_id INTEGER, name TEXT, position INTEGER, regular INTEGER DEFAULT 0, is_current INTEGER DEFAULT 0, next_song_artist TEXT, next_song_title TEXT)");
// The above CREATE TABLE is a no-op on deployments where rotation already
// exists from before these two columns were added, so migrate it explicitly.
try {
	$db->exec("ALTER TABLE rotation ADD COLUMN next_song_artist TEXT");
} catch (PDOException $e) {
	// Column already exists, nothing to do.
}
try {
	$db->exec("ALTER TABLE rotation ADD COLUMN next_song_title TEXT");
} catch (PDOException $e) {
	// Column already exists, nothing to do.
}
// Rotation gets its own serial, separate from the main "requests/songdb changed"
// serial, so that pushing a rotation update doesn't trigger OpenKJ's own poll
// loop into needlessly re-fetching requests and venues.
try {
	$db->exec("ALTER TABLE state ADD COLUMN rotation_serial INTEGER NOT NULL DEFAULT 1");
} catch (PDOException $e) {
	// Column already exists, nothing to do.
}

// Singer <-> KJ chat. Each row belongs to one singer's thread (singer_id),
// regardless of who sent it - from_kj distinguishes the direction. Singers
// only ever see their own thread; the KJ sees all of them as one stream.
$db->exec("CREATE TABLE IF NOT EXISTS chat_messages (message_id INTEGER PRIMARY KEY AUTOINCREMENT, singer_id INTEGER NOT NULL, from_kj INTEGER NOT NULL DEFAULT 0, message_text TEXT NOT NULL, sent_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP, hidden INTEGER NOT NULL DEFAULT 0)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_chat_singer ON chat_messages(singer_id)");

// Muted singers can still read their thread, they just can't send any more.
try {
	$db->exec("ALTER TABLE singers ADD COLUMN muted INTEGER NOT NULL DEFAULT 0");
} catch (PDOException $e) {
	// Column already exists, nothing to do.
}

// Chat gets its own serial too, for the same reason rotation does.
try {
	$db->exec("ALTER TABLE state ADD COLUMN chat_serial INTEGER NOT NULL DEFAULT 1");
} catch (PDOException $e) {
	// Column already exists, nothing to do.
}

// Singer accounts are only used by this web songbook, on top of the shared
// browser session. Sessions are how we know which singer is "logged in".
if (session_status() === PHP_SESSION_NONE) {
	session_start();
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$songdbtable = "songdb";
$requeststable = "requests";

if (isset($_SERVER['REFERER'])) $referer = $_SERVER['REFERER']; 
function siteheader($title) 
{
	global $venueName;
	global $screensize;
	echo "<html><head>
		<link href='https://fonts.googleapis.com/css?family=Audiowide' rel='stylesheet'>
		<link href='https://fonts.googleapis.com/css?family=Roboto' rel='stylesheet'>
		<title>$venueName Karaoke Songbook</title>
		<link rel=stylesheet type=\"text/css\" href=venuestyle.css />
		<script type=\"text/javascript\">
    			function submitreq(varid){
        			window.location = \"./submitreq.php?id=\" + varid;
    			}
    			function submitstreamreq(varid){
        			window.location = \"./submitreq.php?stream_id=\" + varid;
    			}
		</script>
	      </head><body>";
}

function sitefooter() {
	echo "</div></body></html>";
}

function navbar($backurl) 
{
	if ($backurl == "")
		$backurl = "index.php";
	global $screensize;
	global $venueName;
	echo "<div class=navbar>
	<span class=title>$venueName Songbook</span>
	</div><div class=mainbody><span class=backbtn><a class=button href=\"$backurl\" class=navbar id=backlink>Back</a> <a class=button href=\"rotation.php\" class=navbar id=rotationlink>Who's Up Next</a></span><span class=acctwrap>";
	accountLinks();
	echo "</span>";
}

function setAccepting($accepting)
{
	global $db;
	if ($accepting == 1)
	{
		echo("setting accepting to 1");
		$db->exec("UPDATE state SET accepting=1");
	}
	else
	{
		echo("setting accepting to 0");
		$db->exec("UPDATE state SET accepting=0");
	}
}

function getAccepting()
{
	global $db;
	$accepting = false;
        foreach ($db->query("SELECT accepting FROM state LIMIT 1") as $row)
        {
                $accepting = $row['accepting'];
        }
	return $accepting;
}

function searchform() 
{
	global $db;
	global $venue_id;
	if (!getAccepting())
	{
		echo "<br><br><h2>Sorry, requests are not being accepted at this time</h2>";
	}
	else
	{
		global $url_name;
		global $screensize;
		echo "<br><p><form method=get action=search.php>Song search: <input type=text name=q autofocus autocomplete=off><input type=submit value=Search></form></p>";
		echo '<p class=info>You may enter any part of the artist and/or title of the song.  Partial words are allowed.</p>
			<p class=info>For example "pai bla stone" would match "Rolling Stones, The - Paint it black".</p>';
		if (isLoggedIn())
		{
			echo '<p class=info>Tap the &#9734; next to a search result to save it to <a href="favorites.php">My Favorites</a>.</p>';
		}
		else
		{
			echo '<p class=info><a href="register.php">Create a free account</a> to save your favorite songs and request them again in one tap.</p>';
		}
	}
}



function getSerial()
{
	global $db;
	$serial = -1;
	foreach ($db->query("SELECT serial FROM state LIMIT 1") as $row)
        {
        	$serial = (int)$row['serial'];
        }
	return $serial;
}

function newSerial()
{
        global $db;
        $serial = getSerial();
        $newSerial = mt_rand(0,99999);
        while ($newSerial == $serial)
        {
                $newSerial = mt_rand(0,99999);
        }
        $db->exec("UPDATE state SET serial=$newSerial");
        return $newSerial;
}

function getRotationSerial()
{
	global $db;
	$serial = -1;
	foreach ($db->query("SELECT rotation_serial FROM state LIMIT 1") as $row)
        {
        	$serial = (int)$row['rotation_serial'];
        }
	return $serial;
}

function newRotationSerial()
{
        global $db;
        $serial = getRotationSerial();
        $newSerial = mt_rand(0,99999);
        while ($newSerial == $serial)
        {
                $newSerial = mt_rand(0,99999);
        }
        $db->exec("UPDATE state SET rotation_serial=$newSerial");
        return $newSerial;
}

function getVenue()
{
	// We don't really do multiple venues in standalone, just fake it
        global $db;
	global $venueName;
        $serial = -1;
	$venue = array();
	$venue['venue_id'] = $venue_id;
        $venue['accepting'] = getAccepting();
	$venue['name'] = $venueName;
	$venue['url_name'] = "none";
	return $venue;
}

function getVenues()
{
	// We don't really do multiple venues in standalone, just fake it
	global $db;
	global $venueName;
	$venues = array();
	$venue['venue_id'] = 0;
        $venue['accepting'] = getAccepting();
        $venue['name'] = $venueName;
	$venue['url_name'] = "none";
	$venues['venues'][] = $venue;
	return $venues;
}

function isLoggedIn()
{
	// Also require 'name', not just singer_id - a session established before
	// the email/name migration has singer_id set (from the old code) but
	// never got 'name' populated, since that key didn't exist yet. Without
	// this check, such a session reads as logged in but every downstream
	// call to currentSingerName() returns null, crashing whatever tries to
	// display it. Treating it as logged out forces a clean re-login instead,
	// which is also correct since that old singer_id may no longer exist at
	// all - the migration replaces the singers table entirely.
	return isset($_SESSION['singer_id']) && isset($_SESSION['name']);
}

function currentSingerId()
{
	return isset($_SESSION['singer_id']) ? (int)$_SESSION['singer_id'] : null;
}

function currentSingerName()
{
	return $_SESSION['name'] ?? null;
}

function currentSingerEmail()
{
	return $_SESSION['email'] ?? null;
}

// Sends the browser to $redirectTo and stops execution if nobody is logged
// in. Must be called before any HTML has been echoed on the calling page.
function requireLogin($redirectTo = 'login.php')
{
	if (!isLoggedIn())
	{
		header("Location: $redirectTo");
		exit();
	}
}

function accountLinks()
{
	if (isLoggedIn())
	{
		$name = htmlspecialchars(currentSingerName());
		echo "<span class=accountbar>Signed in as $name &middot; <a class=navbar href=\"favorites.php\">My Favorites</a> &middot; <a class=navbar href=\"chat.php\">Message KJ</a> &middot; <a class=navbar href=\"logout.php\">Log out</a></span>";
	}
	else
	{
		echo "<span class=accountbar><a class=navbar href=\"login.php\">Log in</a> &middot; <a class=navbar href=\"register.php\">Create account</a></span>";
	}
}

function keyChangeSelect()
{
	echo "Key change (optional): <select name=keychange>";
	for ($i = 6; $i >= -6; $i--)
	{
		$label = $i == 0 ? "No change" : ($i > 0 ? "+$i" : $i);
		$selected = $i == 0 ? " selected" : "";
		echo "<option value=\"$i\"$selected>$label</option>";
	}
	echo "</select><br>";
}

function getRequests()
{
        global $db;
        $requests = array();
        $result = $db->query("SELECT request_id,artist,title,singer,key_change,strftime('%s', request_time) AS unixtime FROM requests");
	if ($result)
	{
        	foreach ($result as $row)
        	{
        	        $request['request_id'] = (int)$row['request_id'];
        	        $request['artist'] = $row['artist'];
        	        $request['title'] = $row['title'];
        	        $request['singer'] = $row['singer'];
        	        $request['request_time'] = (int)$row['unixtime'];
        	        $request['key_change'] = (int)$row['key_change'];
        	        $requests['requests'][] = $request;
        	}
	}
        return $requests;
}

function getRotation()
{
        global $db;
        $rotation = array();
        $result = $db->query("SELECT rotation_id,singer_id,name,position,regular,is_current,next_song_artist,next_song_title FROM rotation ORDER BY position ASC");
	if ($result)
	{
        	foreach ($result as $row)
        	{
        	        $singer['singer_id'] = (int)$row['singer_id'];
        	        $singer['name'] = $row['name'];
        	        $singer['position'] = (int)$row['position'];
        	        $singer['regular'] = (bool)$row['regular'];
        	        $singer['is_current'] = (bool)$row['is_current'];
        	        $singer['next_song_artist'] = $row['next_song_artist'];
        	        $singer['next_song_title'] = $row['next_song_title'];
        	        $rotation['rotation'][] = $singer;
        	}
	}
        return $rotation;
}

// ---------------------------------------------------------------------------
// Chat helpers
// ---------------------------------------------------------------------------

function getChatSerial()
{
	global $db;
	$serial = -1;
	foreach ($db->query("SELECT chat_serial FROM state LIMIT 1") as $row)
        {
        	$serial = (int)$row['chat_serial'];
        }
	return $serial;
}

function newChatSerial()
{
        global $db;
        $serial = getChatSerial();
        $newSerial = mt_rand(0,99999);
        while ($newSerial == $serial)
        {
                $newSerial = mt_rand(0,99999);
        }
        $db->exec("UPDATE state SET chat_serial=$newSerial");
        return $newSerial;
}

function isSingerMuted($singerId)
{
	global $db;
	$stmt = $db->prepare("SELECT muted FROM singers WHERE singer_id = :id");
	$stmt->execute(array(':id' => (int)$singerId));
	$row = $stmt->fetch(PDO::FETCH_ASSOC);
	return $row ? (bool)$row['muted'] : false;
}

function setSingerMuted($singerId, $muted)
{
	global $db;
	$stmt = $db->prepare("UPDATE singers SET muted = :m WHERE singer_id = :id");
	return $stmt->execute(array(':m' => $muted ? 1 : 0, ':id' => (int)$singerId));
}

// Inserts a chat message. $fromKj distinguishes a KJ reply from a singer's
// own message. Returns an array with 'ok' plus an 'error' string when the
// send was refused, so callers can surface the reason to the user.
function sendChatMessage($singerId, $text, $fromKj = false)
{
	global $db;
	$text = trim($text);
	if ($text === '')
	{
		return array('ok' => false, 'error' => 'Message was empty.');
	}
	if (strlen($text) > 1000)
	{
		$text = substr($text, 0, 1000);
	}
	// The KJ can always reply - including after switching requests off, so a
	// conversation already in progress can be finished. Singer-initiated
	// messages are gated on both the accepting flag and their mute state.
	if (!$fromKj)
	{
		if (!getAccepting())
		{
			return array('ok' => false, 'error' => 'The KJ is not currently accepting messages.');
		}
		if (isSingerMuted($singerId))
		{
			return array('ok' => false, 'error' => 'You have been muted and cannot send further messages.');
		}
	}
	$stmt = $db->prepare("INSERT INTO chat_messages (singer_id, from_kj, message_text) VALUES (:sid, :fromkj, :txt)");
	$ok = $stmt->execute(array(':sid' => (int)$singerId, ':fromkj' => $fromKj ? 1 : 0, ':txt' => $text));
	if ($ok)
	{
		newChatSerial();
	}
	return array('ok' => (bool)$ok, 'error' => $ok ? '' : 'Failed to save message.');
}

// One singer's own thread - both directions. Hidden messages are omitted so a
// message the KJ has hidden disappears from the singer's view too.
function getChatThread($singerId)
{
	global $db;
	$messages = array();
	$stmt = $db->prepare("SELECT message_id, from_kj, message_text, strftime('%s', sent_time) AS unixtime FROM chat_messages WHERE singer_id = :sid AND hidden = 0 ORDER BY message_id ASC");
	$stmt->execute(array(':sid' => (int)$singerId));
	foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row)
	{
		$msg = array();
		$msg['message_id'] = (int)$row['message_id'];
		$msg['from_kj'] = (bool)$row['from_kj'];
		$msg['message_text'] = $row['message_text'];
		$msg['sent_time'] = (int)$row['unixtime'];
		$messages[] = $msg;
	}
	return $messages;
}

// The KJ's view: every thread merged into one chronological stream, with the
// sending singer's name attached to each message. Hidden messages are included
// but flagged, so the KJ can still see (and potentially unhide) them.
function getChatOverview()
{
	global $db;
	$chat = array();
	$sql = "SELECT m.message_id, m.singer_id, m.from_kj, m.message_text, m.hidden, "
	     . "strftime('%s', m.sent_time) AS unixtime, s.username, s.muted "
	     . "FROM chat_messages m LEFT JOIN singers s ON s.singer_id = m.singer_id "
	     . "ORDER BY m.message_id ASC";
	$result = $db->query($sql);
	if ($result)
	{
		foreach ($result as $row)
		{
			$msg = array();
			$msg['message_id'] = (int)$row['message_id'];
			$msg['singer_id'] = (int)$row['singer_id'];
			$msg['username'] = $row['username'];
			$msg['from_kj'] = (bool)$row['from_kj'];
			$msg['message_text'] = $row['message_text'];
			$msg['hidden'] = (bool)$row['hidden'];
			$msg['muted'] = (bool)$row['muted'];
			$msg['sent_time'] = (int)$row['unixtime'];
			$chat['messages'][] = $msg;
		}
	}
	return $chat;
}

// Soft-hide rather than delete, so the KJ can undo a mis-click. Everything is
// cleared wholesale at the end of the night anyway.
function setChatMessageHidden($messageId, $hidden)
{
	global $db;
	$stmt = $db->prepare("UPDATE chat_messages SET hidden = :h WHERE message_id = :id");
	$ok = $stmt->execute(array(':h' => $hidden ? 1 : 0, ':id' => (int)$messageId));
	if ($ok)
	{
		newChatSerial();
	}
	return $ok;
}

function clearChat()
{
	global $db;
	$ok = $db->exec("DELETE FROM chat_messages");
	newChatSerial();
	return $ok !== false;
}

// ---------------------------------------------------------------------------
// Stream library - synced from OpenKJ's own shared stream catalog, so singers
// can find and request a song someone already added via chat previously,
// without having to message the KJ again for the same song.
// ---------------------------------------------------------------------------

$db->exec("CREATE TABLE IF NOT EXISTS streamLibrary (stream_id INTEGER PRIMARY KEY AUTOINCREMENT, local_id INTEGER UNIQUE NOT NULL, artist TEXT, title TEXT, url TEXT, duration INTEGER DEFAULT 0)");

// Pushed by OpenKJ whenever a KJ adds a new stream song. Keyed on local_id
// (OpenKJ's own streamLibrary.id) so re-pushing the same entry - e.g. after
// an app restart - updates in place rather than creating a duplicate.
function addOrUpdateStreamLibraryEntry($localId, $artist, $title, $url, $duration)
{
	global $db;
	$stmt = $db->prepare(
		"INSERT INTO streamLibrary (local_id, artist, title, url, duration) VALUES (:lid, :artist, :title, :url, :duration) " .
		"ON CONFLICT(local_id) DO UPDATE SET artist=excluded.artist, title=excluded.title, url=excluded.url, duration=excluded.duration"
	);
	return $stmt->execute(array(
		':lid' => (int)$localId,
		':artist' => $artist,
		':title' => $title,
		':url' => $url,
		':duration' => (int)$duration
	));
}

// Same multi-term AND-matched LIKE search style as the local songdb search in
// search.php, applied to artist+title instead of a pre-built combined column.
function searchStreamLibrary($terms)
{
	global $db;
	$where = array();
	$params = array();
	foreach ($terms as $i => $term)
	{
		$where[] = "(artist || ' ' || title) LIKE :term$i";
		$params[":term$i"] = '%' . $term . '%';
	}
	$sql = "SELECT stream_id, artist, title FROM streamLibrary WHERE " . implode(' AND ', $where) . " ORDER BY UPPER(artist), UPPER(title)";
	$stmt = $db->prepare($sql);
	$stmt->execute($params);
	return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getStreamLibraryEntry($streamId)
{
	global $db;
	$stmt = $db->prepare("SELECT stream_id, artist, title, url, duration FROM streamLibrary WHERE stream_id = :id");
	$stmt->bindValue(':id', (int)$streamId);
	$stmt->execute();
	return $stmt->fetch(PDO::FETCH_ASSOC);
}

// ---------------------------------------------------------------------------
// Singer account admin - list/delete/reset, surfaced in OpenKJ's own Settings
// dialog rather than a web page, matching how chat moderation already works.
// ---------------------------------------------------------------------------

function listSingers()
{
	global $db;
	$singers = array();
	$sql = "SELECT singer_id, name, email, muted, created_at FROM singers ORDER BY name COLLATE NOCASE";
	foreach ($db->query($sql) as $row)
	{
		$singers[] = array(
			'id' => (int)$row['singer_id'],
			'name' => $row['name'],
			'email' => $row['email'],
			'muted' => (bool)$row['muted'],
			'created_at' => $row['created_at']
		);
	}
	return $singers;
}

// Deletes an account and everything tied to it - there's no reason to keep
// orphaned favorites or chat history around once the account is gone.
function deleteSinger($singerId)
{
	global $db;
	$singerId = (int)$singerId;
	$db->prepare("DELETE FROM favorites WHERE singer_id = :id")->execute(array(':id' => $singerId));
	$db->prepare("DELETE FROM chat_messages WHERE singer_id = :id")->execute(array(':id' => $singerId));
	$stmt = $db->prepare("DELETE FROM singers WHERE singer_id = :id");
	return $stmt->execute(array(':id' => $singerId));
}

// KJ-assisted reset: generates a random temporary password, stores its hash,
// and returns the plaintext once so the KJ can relay it to the singer in
// person. There's no email delivery, so this is the only place the plaintext
// ever exists - not logged, not stored.
function resetSingerPassword($singerId)
{
	global $db;
	$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
	$tempPassword = '';
	for ($i = 0; $i < 8; $i++)
	{
		$tempPassword .= $chars[random_int(0, strlen($chars) - 1)];
	}
	$hash = password_hash($tempPassword, PASSWORD_DEFAULT);
	$stmt = $db->prepare("UPDATE singers SET password_hash = :hash WHERE singer_id = :id");
	$ok = $stmt->execute(array(':hash' => $hash, ':id' => (int)$singerId));
	if (!$ok)
	{
		return null;
	}
	return $tempPassword;
}

?>

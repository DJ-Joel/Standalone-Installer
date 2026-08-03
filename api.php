<?php
include_once("global.inc");
$json = file_get_contents("php://input");
$data = json_decode($json,true);
$command = $data['command'];

if ($command == '')
{
	exit();
}

// API stuff for songbook mobile apps

if ($command == "venueExists")
{
	$venueUrlName = $data['venueUrlName'];
	$exists = venueExists($venueUrlName);
	$output = array('command'=>$command,'error'=>'false', 'exists'=>$exists);
        print(json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        exit();

}

if ($command == "venueAccepting")
{
	if (getAccepting())
        	$output = array('command'=>$command,'accepting'=>true);
	else
		$output = array('command'=>$command,'accepting'=>false);
        header('Content-type: application/json');
        print(json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        exit();
}

if ($command == "submitRequest")
{
	$songId = $data['songId'];
	$singerName = $data['singerName'];
	$keyChange = isset($data['keyChange']) ? (int)$data['keyChange'] : 0;
	if ($keyChange < -6 || $keyChange > 6) {
		$keyChange = 0;
	}
	$sql = "SELECT artist,title FROM songdb WHERE song_id = $songId";
	foreach ($db->query($sql) as $row) {
        	$artist = $row['artist'];
        	$title = $row['title'];
	}
	$stmt = $db->prepare("INSERT INTO requests (singer,artist,title,key_change) VALUES(:singerName, :artist, :title, :keyChange)");
	$stmt->execute(array(":singerName" => $singerName, ":artist" => $artist, ":title" => $title, ":keyChange" => $keyChange));
	newSerial();
	$output = array('command'=>$command,'error'=>'false', 'success'=>true);
        print(json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	exit();
}

if ($command == "search")
{
	$terms = explode(' ',$data['searchString']);
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
	                $wherestring .= " AND (combined LIKE \"%" . $term . "%\") AND(artist<>'DELETED'))";
	            }
	        }
	} else {
	        $wherestring = "";
	}
	$entries = null;
	$res = array();
	$sql = "SELECT song_id,artist,title,combined FROM songdb $wherestring ORDER BY UPPER(artist), UPPER(title)";
	foreach ($db->query($sql) as $row)
	{
	    if ((stripos($row['combined'],'wvocal') === false) && (stripos($row['combined'],'w-vocal') === false) && (stripos($row['combined'],'vocals') === false)) {
	            $res[] = array('song_id'=>$row['song_id'],'artist'=>$row['artist'],'title'=>$row['title']);
	    }
	}
	$output = array("command" => "search", "songs" => $res);
	header('Content-type: application/json');
	print(json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	exit();
}





// API stuff for OpenKJ application

if ($command == "clearDatabase")
{
	$db->exec("DELETE FROM songdb");
	$db->exec("DELETE FROM requests");
	$newSerial = newSerial();
	$output = array('command'=>$command,'error'=>'false', 'serial'=>newSerial());
	print(json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	exit();
}

function error($error_string) {
        header('Content-type: application/json');
        print(json_encode(array('command'=>$command,'error'=>'true','errorString'=>$error_string),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	exit();
}

if ($command == "clearRequests")
{
	$db->exec("DELETE FROM requests");
        $output = array('command'=>$command,'error'=>'false', 'serial'=>newSerial());
        print(json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	exit();
}

if ($command == "deleteRequest")
{
	$request_id = $data['request_id'];
	$stmt = $db->prepare("DELETE FROM requests WHERE request_id = :requestId");
	$stmt->execute(array(":requestId" => $request_id));
        $output = array('command'=>$command,'error'=>'false', 'serial'=>newSerial());
        print(json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	exit();
}

if ($command == "connectionTest")
{
	header('Content-type: application/json');
	$output = array('command'=>$command,'connection'=>'ok');
	print(json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	exit();
}
if ($command == "addSongs")
{
	$stmt = $db->prepare("INSERT OR IGNORE INTO songdb (artist, title, combined) VALUES (:artist, :title, :combined)");
	$db->beginTransaction();
	$errors = array();
	$count = 0;
	$artist = "";
	$title = "";
	$combined = "";
	$error = "false";
	foreach ($data['songs'] as $song)
	{
		$artist = $song['artist'];
		$title = $song['title'];
		$combined = $artist . " " . $title;
		$inarray = array(":artist" => $artist, ":title" => $title, ":combined" => $combined);
		$result = $stmt->execute($inarray);
		if ($result === false)
		{
			$errors[] = $db->errorInfo();
			$error = "true";
		}
		$count++;
	}
	$result = $db->commit();
	if ($result == false)
		$errors[] = $db->errorInfo();
	$output['command'] = $command;
	$output['error'] = $error;
	$output['errors'] = $errors;
	$output['entries processed'] = $count;
	$output['last_artist'] = $artist;
	$output['last_title'] = $title;
	header('Content-type: application/json');
	print(json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	exit();
}

if ($command == "getSerial")
{
	$output = array('command'=>$command,'serial'=>getSerial(),'error'=>'false');
	header('Content-type: application/json');
	print(json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	exit();
}

if ($command == "updateRotation")
{
	$db->beginTransaction();
	$db->exec("DELETE FROM rotation");
	$stmt = $db->prepare("INSERT INTO rotation (singer_id, name, position, regular, is_current, next_song_artist, next_song_title) VALUES (:singerId, :name, :position, :regular, :isCurrent, :nextSongArtist, :nextSongTitle)");
	$errors = array();
	$error = "false";
	foreach ($data['singers'] as $singer)
	{
		$inarray = array(
			":singerId" => $singer['singer_id'],
			":name" => $singer['name'],
			":position" => $singer['position'],
			":regular" => (int)$singer['regular'],
			":isCurrent" => (int)$singer['is_current'],
			":nextSongArtist" => isset($singer['next_song_artist']) ? $singer['next_song_artist'] : null,
			":nextSongTitle" => isset($singer['next_song_title']) ? $singer['next_song_title'] : null
		);
		$result = $stmt->execute($inarray);
		if ($result === false)
		{
			$errors[] = $db->errorInfo();
			$error = "true";
		}
	}
	$result = $db->commit();
	if ($result == false)
		$errors[] = $db->errorInfo();
	$newSerial = newRotationSerial();
	$output = array('command'=>$command,'error'=>$error,'errors'=>$errors,'serial'=>$newSerial);
	header('Content-type: application/json');
	print(json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	exit();
}

if ($command == "getAccepting")
{
	$accepting = getAccepting();
	$output = array('command'=>$command,'accepting'=>$venue['accepting'],'venue_id'=>0);
	header('Content-type: application/json');
	print(json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	exit();
}

if ($command == "setAccepting")
{
	$accepting = (bool)$data['accepting'];
        setAccepting($accepting);
	$newSerial = newSerial();
	$output = array('command'=>$command,'error'=>'false','venue_id'=>0,'accepting'=>$accepting,'serial'=>$newSerial);
        print(json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        exit();
}

if ($command == "getVenues")
{
	$output = getVenues();
	$output['command'] = $command;
	$output['error'] = 'false';
	header('Content-type: application/json');
        print(json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	exit();
}

if ($command == "getRequests")
{
	$serial = getSerial();
	$output = getRequests();
	$output['command'] = $command;
	$output['error'] = 'false';
	$output['serial'] = $serial;
	header('Content-type: application/json');
	print(json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	exit();
}

// Public - used by rotation.php so singers can poll the queue from their phones
if ($command == "getRotation")
{
	$serial = getRotationSerial();
	$output = getRotation();
	$output['command'] = $command;
	$output['error'] = 'false';
	$output['serial'] = $serial;
	header('Content-type: application/json');
	print(json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	exit();
}

// ---------------------------------------------------------------------------
// Chat - singer facing. These identify the singer from their login session
// rather than any parameter, so one singer can never read or post into
// another singer's thread by passing a different id.
// ---------------------------------------------------------------------------

if ($command == "sendChatMessage")
{
	if (!isLoggedIn())
	{
		$output = array('command'=>$command,'error'=>'true','message'=>'You must be logged in to send messages.');
		header('Content-type: application/json');
		print(json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
		exit();
	}
	$text = isset($data['message']) ? $data['message'] : '';
	$result = sendChatMessage(currentSingerId(), $text, false);
	$output = array(
		'command'=>$command,
		'error'=> $result['ok'] ? 'false' : 'true',
		'message'=> $result['error'],
		'serial'=> getChatSerial()
	);
	header('Content-type: application/json');
	print(json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	exit();
}

if ($command == "getChatThread")
{
	if (!isLoggedIn())
	{
		$output = array('command'=>$command,'error'=>'true','message'=>'You must be logged in to view messages.');
		header('Content-type: application/json');
		print(json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
		exit();
	}
	$singerId = currentSingerId();
	$output = array(
		'command'=>$command,
		'error'=>'false',
		'messages'=> getChatThread($singerId),
		'muted'=> isSingerMuted($singerId),
		'accepting'=> getAccepting() ? true : false,
		'serial'=> getChatSerial()
	);
	header('Content-type: application/json');
	print(json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	exit();
}

// ---------------------------------------------------------------------------
// Chat - KJ facing (called by OpenKJ). Note these are not authenticated, in
// keeping with every other KJ command in this file (see clearRequests,
// setAccepting, etc) - this server is designed to run on a trusted LAN.
// ---------------------------------------------------------------------------

if ($command == "getChatOverview")
{
	$serial = getChatSerial();
	$output = getChatOverview();
	$output['command'] = $command;
	$output['error'] = 'false';
	$output['serial'] = $serial;
	header('Content-type: application/json');
	print(json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	exit();
}

if ($command == "getChatSerial")
{
	$output = array('command'=>$command,'error'=>'false','serial'=>getChatSerial());
	header('Content-type: application/json');
	print(json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	exit();
}

if ($command == "sendChatReply")
{
	$singerId = isset($data['singerId']) ? (int)$data['singerId'] : 0;
	$text = isset($data['message']) ? $data['message'] : '';
	if ($singerId <= 0)
	{
		$output = array('command'=>$command,'error'=>'true','message'=>'A singerId is required.');
	}
	else
	{
		$result = sendChatMessage($singerId, $text, true);
		$output = array(
			'command'=>$command,
			'error'=> $result['ok'] ? 'false' : 'true',
			'message'=> $result['error'],
			'serial'=> getChatSerial()
		);
	}
	header('Content-type: application/json');
	print(json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	exit();
}

if ($command == "setChatMessageHidden")
{
	$messageId = isset($data['messageId']) ? (int)$data['messageId'] : 0;
	$hidden = isset($data['hidden']) ? (bool)$data['hidden'] : true;
	$ok = ($messageId > 0) && setChatMessageHidden($messageId, $hidden);
	$output = array('command'=>$command,'error'=> $ok ? 'false' : 'true','serial'=>getChatSerial());
	header('Content-type: application/json');
	print(json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	exit();
}

if ($command == "setSingerMuted")
{
	$singerId = isset($data['singerId']) ? (int)$data['singerId'] : 0;
	$muted = isset($data['muted']) ? (bool)$data['muted'] : true;
	$ok = ($singerId > 0) && setSingerMuted($singerId, $muted);
	$output = array('command'=>$command,'error'=> $ok ? 'false' : 'true');
	header('Content-type: application/json');
	print(json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	exit();
}

if ($command == "clearChat")
{
	$ok = clearChat();
	$output = array('command'=>$command,'error'=> $ok ? 'false' : 'true','serial'=>getChatSerial());
	header('Content-type: application/json');
	print(json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	exit();
}

if ($command == "addStreamLibraryEntry")
{
	$localId = isset($data['localId']) ? (int)$data['localId'] : 0;
	$artist = isset($data['artist']) ? $data['artist'] : '';
	$title = isset($data['title']) ? $data['title'] : '';
	$url = isset($data['url']) ? $data['url'] : '';
	$duration = isset($data['duration']) ? (int)$data['duration'] : 0;
	$ok = ($localId > 0) && ($url !== '') && addOrUpdateStreamLibraryEntry($localId, $artist, $title, $url, $duration);
	$output = array('command'=>$command,'error'=> $ok ? 'false' : 'true');
	header('Content-type: application/json');
	print(json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	exit();
}

// ---------------------------------------------------------------------------
// Singer account admin - surfaced in OpenKJ's own Settings dialog.
// ---------------------------------------------------------------------------

if ($command == "listSingers")
{
	$output = array('command'=>$command,'error'=>'false','singers'=>listSingers());
	header('Content-type: application/json');
	print(json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	exit();
}

if ($command == "deleteSinger")
{
	$singerId = isset($data['singerId']) ? (int)$data['singerId'] : 0;
	$ok = ($singerId > 0) && deleteSinger($singerId);
	$output = array('command'=>$command,'error'=> $ok ? 'false' : 'true');
	header('Content-type: application/json');
	print(json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	exit();
}

if ($command == "resetSingerPassword")
{
	$singerId = isset($data['singerId']) ? (int)$data['singerId'] : 0;
	$tempPassword = $singerId > 0 ? resetSingerPassword($singerId) : null;
	$output = array(
		'command'=>$command,
		'error'=> $tempPassword !== null ? 'false' : 'true',
		'singerId'=> $singerId,
		'tempPassword'=> $tempPassword !== null ? $tempPassword : ''
	);
	header('Content-type: application/json');
	print(json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
	exit();
}

?>

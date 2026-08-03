<?php
include('global.inc');
siteheader('Who\'s Up Next');
navbar('index.php');

$rotation = getRotation();
$singers = isset($rotation['rotation']) ? $rotation['rotation'] : array();

echo "<br><p>Who's Up Next</p>";
echo "<div id=rotationList>";
if (count($singers) == 0)
{
	echo "<p class=info>Nobody's in the rotation yet.</p>";
}
else
{
	echo '<table border=1>';
	foreach ($singers as $singer)
	{
		$name = htmlspecialchars($singer['name']);
		$rowClass = $singer['is_current'] ? ' class=currentsinger' : '';
		$label = $singer['is_current'] ? "$name &nbsp; &#9834; Now Singing" : $name;
		$songArtist = isset($singer['next_song_artist']) ? trim($singer['next_song_artist']) : '';
		$songTitle = isset($singer['next_song_title']) ? trim($singer['next_song_title']) : '';
		if ($songArtist !== '' || $songTitle !== '')
		{
			$songLine = htmlspecialchars(trim($songArtist . ' - ' . $songTitle, ' -'));
			$label .= "<br><span class=songinfo>$songLine</span>";
		}
		echo "<tr$rowClass><td class=result>$label</td></tr>";
	}
	echo '</table>';
}
echo "</div>";
echo "<p class=info id=rotationStatus>&nbsp;</p>";

?>
<script type="text/javascript">
var lastRotationSerial = <?php echo (int)getRotationSerial(); ?>;

function renderRotation(singers) {
    var list = document.getElementById('rotationList');
    if (singers.length === 0) {
        list.innerHTML = '<p class="info">Nobody\'s in the rotation yet.</p>';
        return;
    }
    var html = '<table border=1>';
    for (var i = 0; i < singers.length; i++) {
        var s = singers[i];
        var name = s.name.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        var rowClass = s.is_current ? ' class="currentsinger"' : '';
        var label = s.is_current ? (name + ' &nbsp; &#9834; Now Singing') : name;
        var songArtist = (s.next_song_artist || '').trim();
        var songTitle = (s.next_song_title || '').trim();
        if (songArtist !== '' || songTitle !== '') {
            var songLine = (songArtist + ' - ' + songTitle).replace(/^[\s-]+|[\s-]+$/g, '');
            songLine = songLine.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            label += '<br><span class="songinfo">' + songLine + '</span>';
        }
        html += '<tr' + rowClass + '><td class="result">' + label + '</td></tr>';
    }
    html += '</table>';
    list.innerHTML = html;
}

function pollRotation() {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'api.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.onload = function () {
        if (xhr.status !== 200) return;
        var data;
        try {
            data = JSON.parse(xhr.responseText);
        } catch (e) {
            return;
        }
        if (data.error === 'true') return;
        if (data.serial === lastRotationSerial) return;
        lastRotationSerial = data.serial;
        renderRotation(data.rotation || []);
    };
    xhr.send(JSON.stringify({command: 'getRotation'}));
}

setInterval(pollRotation, 8000);
</script>
<?php

sitefooter();
?>

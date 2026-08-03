<?php
include('global.inc');
siteheader('Message the KJ');
navbar('index.php');

if (!isLoggedIn())
{
	echo "<br><p>You need an account to message the KJ.</p>
	<p><a href=\"login.php?redirect=chat.php\">Log in</a> or <a href=\"register.php\">create a free account</a> to get started.</p>";
	sitefooter();
	exit();
}

$singerId = currentSingerId();
$muted = isSingerMuted($singerId);
$accepting = getAccepting();

// Handle a posted message before rendering, so a refresh shows the result.
$sendError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
	$result = sendChatMessage($singerId, isset($_POST['message']) ? $_POST['message'] : '', false);
	if (!$result['ok'])
	{
		$sendError = $result['error'];
	}
	// Re-read these - a send may have been refused because state changed.
	$muted = isSingerMuted($singerId);
	$accepting = getAccepting();
}

$messages = getChatThread($singerId);

echo "<br><p>Message the KJ</p>";
echo "<p class=info>Use this to ask the KJ a question - for example if you couldn't find a song you wanted in the songbook.</p>";

if ($sendError !== '')
{
	echo "<p class=error>" . htmlspecialchars($sendError) . "</p>";
}

echo "<div id=chatThread>";
if (count($messages) == 0)
{
	echo "<p class=info id=chatEmpty>No messages yet. Send one below and the KJ will see it.</p>";
}
else
{
	foreach ($messages as $msg)
	{
		$cls = $msg['from_kj'] ? 'chatkj' : 'chatme';
		$who = $msg['from_kj'] ? 'KJ' : 'You';
		$txt = htmlspecialchars($msg['message_text']);
		echo "<div class=\"chatmsg $cls\"><span class=chatwho>$who</span><br>$txt</div>";
	}
}
echo "</div>";

if ($muted)
{
	echo "<p class=error>You have been muted and cannot send further messages.</p>";
}
elseif (!$accepting)
{
	echo "<p class=error>The KJ is not currently accepting messages.</p>";
}
else
{
	echo "<form method=post action=chat.php>
	<textarea name=message rows=3 maxlength=1000 autofocus placeholder=\"Type your message to the KJ\"></textarea><br>
	<input type=submit value=\"Send\">
	</form>";
}

?>
<script type="text/javascript">
var lastChatSerial = <?php echo (int)getChatSerial(); ?>;

function escapeHtml(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function renderThread(messages) {
    var el = document.getElementById('chatThread');
    if (messages.length === 0) {
        el.innerHTML = '<p class="info" id="chatEmpty">No messages yet. Send one below and the KJ will see it.</p>';
        return;
    }
    var html = '';
    for (var i = 0; i < messages.length; i++) {
        var m = messages[i];
        var cls = m.from_kj ? 'chatkj' : 'chatme';
        var who = m.from_kj ? 'KJ' : 'You';
        html += '<div class="chatmsg ' + cls + '"><span class="chatwho">' + who + '</span><br>' + escapeHtml(m.message_text) + '</div>';
    }
    el.innerHTML = html;
    el.scrollTop = el.scrollHeight;
}

function pollChat() {
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
        if (data.serial === lastChatSerial) return;
        lastChatSerial = data.serial;
        renderThread(data.messages || []);
    };
    xhr.send(JSON.stringify({command: 'getChatThread'}));
}

setInterval(pollChat, 5000);

// Start scrolled to the newest message.
(function () {
    var el = document.getElementById('chatThread');
    if (el) el.scrollTop = el.scrollHeight;
})();
</script>
<?php

sitefooter();
?>

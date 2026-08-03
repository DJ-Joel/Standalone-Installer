<?php
include("global.inc");
siteheader("Home");
navbar("index.php");

searchform();

if (isLoggedIn())
{
	echo "<p class=info>Message the KJ if you couldn't find a song you wanted in the songbook. <a href=\"chat.php\">Message the KJ</a></p>";
}
else
{
	echo "<p class=info>Message the KJ if you couldn't find a song you wanted in the songbook. (Account Required)<br><a href=\"login.php?redirect=chat.php\">Log in</a> or <a href=\"register.php\">create a free account</a></p>";
}
/*
if ($screensize == 'xlarge')
{
	echo "<br><br><p class=info>Want to search using your smartphone, tablet, or laptop?<br><br>Browse to songbook.openkj.org/venue/$url_name in the web browser on your device.<br><br>
";
}
*/
sitefooter();

?>

<?php
include('global.inc');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
	$email = trim($_POST['email'] ?? '');
	$password = $_POST['password'] ?? '';

	$stmt = $db->prepare("SELECT singer_id, email, name, password_hash FROM singers WHERE email = :e");
	$stmt->execute(array(':e' => $email));
	$row = $stmt->fetch(PDO::FETCH_ASSOC);

	if ($row && password_verify($password, $row['password_hash']))
	{
		session_regenerate_id(true);
		$_SESSION['singer_id'] = $row['singer_id'];
		$_SESSION['email'] = $row['email'];
		$_SESSION['name'] = $row['name'];
		$redirect = isset($_GET['redirect']) ? $_GET['redirect'] : 'favorites.php';
		// Only allow relative redirects within this app.
		if (preg_match('/^[a-zA-Z0-9_\-]+\.php(\?[a-zA-Z0-9_=&\-]*)?$/', $redirect))
		{
			header("Location: $redirect");
		}
		else
		{
			header("Location: favorites.php");
		}
		exit();
	}
	else
	{
		$error = 'Incorrect email or password.';
	}
}

siteheader('Log In');
navbar('index.php');

if ($error !== '')
{
	echo "<br><p class=error>" . htmlspecialchars($error) . "</p>";
}

$prefillEmail = htmlspecialchars($_POST['email'] ?? '');

echo "<br><form method=post action=login.php>
Email:<br><input type=email name=email autocomplete=email autofocus value=\"$prefillEmail\"><br>
Password:<br><input type=password name=password autocomplete=current-password><br>
<input type=submit value=\"Log in\">
</form>
<p class=info>New here? <a href=\"register.php\">Create an account</a></p>
<p class=info>Forgot your password? Please see the KJ - they can reset it for you.</p>";

sitefooter();
?>

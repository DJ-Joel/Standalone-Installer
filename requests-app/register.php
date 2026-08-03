<?php
include('global.inc');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
	$email = trim($_POST['email'] ?? '');
	$name = trim($_POST['name'] ?? '');
	$password = $_POST['password'] ?? '';
	$confirm = $_POST['confirm'] ?? '';

	if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))
	{
		$error = 'Enter a valid email address.';
	}
	elseif ($name === '' || strlen($name) > 40)
	{
		$error = 'Enter a name (up to 40 characters).';
	}
	elseif (strlen($password) < 6)
	{
		$error = 'Password must be at least 6 characters.';
	}
	elseif ($password !== $confirm)
	{
		$error = 'Passwords do not match.';
	}
	else
	{
		$check = $db->prepare("SELECT singer_id FROM singers WHERE email = :e");
		$check->execute(array(':e' => $email));
		if ($check->fetch())
		{
			$error = 'An account with that email already exists.';
		}
		else
		{
			$hash = password_hash($password, PASSWORD_DEFAULT);
			$ins = $db->prepare("INSERT INTO singers (email, name, password_hash) VALUES (:e, :n, :p)");
			$ins->execute(array(':e' => $email, ':n' => $name, ':p' => $hash));
			$singerId = $db->lastInsertId();
			// Regenerate the session id on privilege change to avoid session fixation.
			session_regenerate_id(true);
			$_SESSION['singer_id'] = $singerId;
			$_SESSION['email'] = $email;
			$_SESSION['name'] = $name;
			header("Location: favorites.php?welcome=1");
			exit();
		}
	}
}

// Nothing above should have produced output yet if we're redirecting, so it's
// safe to start rendering the page now.
siteheader('Create Account');
navbar('index.php');

if ($error !== '')
{
	echo "<br><p class=error>" . htmlspecialchars($error) . "</p>";
}

$prefillEmail = htmlspecialchars($_POST['email'] ?? '');
$prefillName = htmlspecialchars($_POST['name'] ?? '');

echo "<br><form method=post action=register.php>
Email:<br><input type=email name=email autocomplete=email autofocus value=\"$prefillEmail\"><br>
Name (used for the rotation):<br><input type=text name=name autocomplete=name value=\"$prefillName\"><br>
Password:<br><input type=password name=password autocomplete=new-password><br>
Confirm password:<br><input type=password name=confirm autocomplete=new-password><br>
<input type=submit value=\"Create account\">
</form>
<p class=info>Save your favorite songs here and request them again with one tap next time.</p>
<p class=info>Already have an account? <a href=\"login.php\">Log in</a></p>";

sitefooter();
?>

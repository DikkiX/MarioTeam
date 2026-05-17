<!DOCTYPE html>
<html>

<head>
	<meta charset="utf-8">
	<title></title>
</head>

<body>
	hello world!<br><br>
	<a href="https://www.marioswitch1.nl/ChatBotMrM.php">ChatBotMrM.php</a>
	<br><br>

	<?php
	error_reporting(-1); //show errors	
	ini_set('display_errors', '1');
	ini_set('display_startup_errors', '1');

	$documentRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
	$dbIncludePath = $documentRoot !== '' ? ($documentRoot . '/include/db.inc') : (__DIR__ . '/include/db.inc');
	include_once $dbIncludePath;

	if (!isset($conn)) {
		echo 'Geen database connectie ($conn).';
		exit;
	}

	$sthSELECT = $conn->prepare("SELECT * FROM chatHistory");
	$i = 0;
	$sthSELECT->execute(array());
	$result = $sthSELECT->fetchAll();
	if (!empty($result)) {
		foreach ($result as $ligne) {
			$i++;
		}
	}
	echo 'Aantal chats in db.chatHistory: ' . $i;
	?>
</body>

</html>
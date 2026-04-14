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
	include_once $_SERVER['DOCUMENT_ROOT'] . "/include/db.inc";
	error_reporting(-1); //show errors	

	$sthSELECT = $conn->prepare("SELECT * FROM chatHistory");
	$i = 0;
	$sthSELECT->execute(array());
	$result = $sthSELECT->fetchAll();
	if (!empty($result)) {
		foreach ($result as $ligne) {
			$i++;
		}
	}
	echo 'Aantal chats in db.chatHistry: ' . $i;
	?>
</body>

</html>
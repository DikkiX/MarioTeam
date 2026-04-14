<?php
error_reporting(0);
include $_SERVER['DOCUMENT_ROOT'] . "/specific/notes_db.inc";
include $_SERVER['DOCUMENT_ROOT'] . "/specific/univ.inc";

function maakPdoConnectie($dbhost, $dbname, $dbuser, $dbpass, $charset = 'utf8')
{
    $dsn = 'mysql:host=' . $dbhost . ';dbname=' . $dbname . ';charset=' . $charset;

    return new PDO(
        $dsn,
        $dbuser,
        $dbpass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

// Zonder slash aan het einde blijven links en plaatjes goed werken.
$adres512 = "http://www." . $univ_web . $_SERVER['PHP_SELF'];
if (isset($_GET[$univ_url_var])) {
    $adres512 .= "?" . $univ_url_var . "=" . $_GET[$univ_url_var];
}
if (isset($_GET['c'])) {
    $adres512 .= "?c=" . $_GET['c'];
}

if (preg_match("/[\/]$/", $adres512)) {
    $location = rtrim($adres512, '/');
    header("Location:$location");
    exit;
}

try {
    $conn = maakPdoConnectie($dbhost, $dbname, $dbuser, $dbpass, 'utf8');
    $connLatin = maakPdoConnectie($dbhost, $dbname, $dbuser, $dbpass, 'latin1');
} catch (PDOException $e) {
    http_response_code(500);
    exit('Er ging iets mis met de databaseverbinding. Probeer het later opnieuw.');
}

date_default_timezone_set("Europe/Amsterdam");

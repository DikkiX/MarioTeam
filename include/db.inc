<?
error_reporting(0);
include $_SERVER['DOCUMENT_ROOT']."/specific/notes_db.inc";
include $_SERVER['DOCUMENT_ROOT']."/specific/univ.inc";

//als er een forward slash aan het einde staat werkt css en de plaatjes mogelijk niet
//doorlinken
$adres512 = "http://www.".$univ_web.$_SERVER['PHP_SELF'];
if (isset($_GET[$univ_url_var])) 
 $adres512 .= "?".$univ_url_var."=".$_GET[$univ_url_var];
if (isset($_GET['c'])) 
 $adres512 .= "?c=".$_GET['c'];

if (preg_match("/[\/]$/", "$adres512"))
{
 	$location = rtrim ($adres512 , '/');
  header("Location:$location");
  exit;
}

$conn = new PDO('mysql:host='.$dbhost.';dbname='.$dbname.';charset=utf8', $dbuser, $dbpass);
//charset=latin1
$connLatin = new PDO('mysql:host='.$dbhost.';dbname='.$dbname.';charset=latin1', $dbuser, $dbpass);

date_default_timezone_set("Europe/Amsterdam");
?>
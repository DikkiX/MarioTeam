<?
//Functies aan / uit
//specifics
$specificshop = 1;    //Winkel open = 1 dicht = 0
//$specificbuy = 0;    //inkoop optie open = 1 dicht = 0
$specificABtest = 0;  //AB test aan = 1 uit = 0
$specificreviewtop10 = 1;     //professionele reviews <10 = 0 >9 = 1

//year
$specificstartyear = '17';

//Fantastisch Tweedehands
$Specific_Fantastisch_Tweedehands_Staat = 'Garantie en onbeschadigde Nintendo Switch producten!'; 
$Specific_Fantastisch_Tweedehands_Keuze = 'Super veel keuze voor de Switch!';
$Specific_Fantastisch_Tweedehands_StaatArr['nl'] = 'Garantie en onbeschadigde Nintendo Switch producten!'; 
$Specific_Fantastisch_Tweedehands_KeuzeArr['nl'] = 'Super veel keuze voor de Switch!';
$Specific_Fantastisch_Tweedehands_StaatArr['en'] = 'Warranty and undamaged Nintendo Switch products!'; 
$Specific_Fantastisch_Tweedehands_KeuzeArr['en'] = 'Super wide selection for the Switch!';
$Specific_Fantastisch_Tweedehands_StaatArr['fr'] = 'Garantie et produits Nintendo Switch non endommagés !'; 
$Specific_Fantastisch_Tweedehands_KeuzeArr['fr'] = 'Super large choix pour la Switch !';
$Specific_Fantastisch_Tweedehands_StaatArr['de'] = 'Garantie und unbeschädigte Nintendo Switch Produkte!'; 
$Specific_Fantastisch_Tweedehands_KeuzeArr['de'] = 'Super viel Auswahl für die Switch!';
$Specific_Fantastisch_Tweedehands_StaatArr['it'] = 'Garanzia e prodotti Nintendo Switch non danneggiati!'; 
$Specific_Fantastisch_Tweedehands_KeuzeArr['it'] = 'Super ampia scelta per la Switch!';
$Specific_Fantastisch_Tweedehands_StaatArr['es'] = 'Garantía y productos Nintendo Switch sin daños!'; 
$Specific_Fantastisch_Tweedehands_KeuzeArr['es'] = '¡Super amplia selección para la Switch!';
$Specific_Fantastisch_Tweedehands_StaatArr['pt'] = 'Garantia e produtos Nintendo Switch sem danos!'; 
$Specific_Fantastisch_Tweedehands_KeuzeArr['pt'] = 'Super ampla escolha para o Switch!';
$Specific_Fantastisch_Tweedehands_StaatArr['da'] = 'Garanti og ubeskadigede Nintendo Switch produkter!'; 
$Specific_Fantastisch_Tweedehands_KeuzeArr['da'] = 'Super stort udvalg til Switch!';
$Specific_Fantastisch_Tweedehands_StaatArr['pl'] = 'Gwarancja i nieuszkodzone produkty Nintendo Switch!'; 
$Specific_Fantastisch_Tweedehands_KeuzeArr['pl'] = 'Ogromny wybór dla Switcha!';



//height in kader bij width 160
$univ_height = '259'; //456x738
//height bij kleine afbeeldingen width = 55:
$univ_height_small = '89';

$height_spel_afbeeldingen = 100; //width = 160
$height_spel_afbeeldingen_large = 200; //width = 160
$width_spel_afbeeldingen_large = 320; //width = 160
//320
//200

//Universele variabelen:
$univ_one="Switch";  //LET OP dit moet ook in de variabele 'type' te vinden zijn in db.info
$univ_link="Switch";

//specifiek
$univ_url_var = 't';
$univ_map_plaatjes = $univ_link.'-spel';

//url's van de pagina's
$univ_web="marioswitch.nl";
$univ_web_text="MarioSwitch.nl";

//CATEGORIEN
//$univ_categorie['hardware']['type'] werkt samen met navigatie_lijsten.inc ;
$univ_categorie['ja']['Switch'] = 'Switch-hardware-accessoires.php';
$univ_categorie['nee']['Switch'] = 'alle-Nintendo-Switch-spellen.php';
//$univ_categorie['nee']['Wii U eShop'] = 'beste-Wii-U-eShop-Games.php';
//$univ_categorie['nee']['Virtual Console'] = 'beste-Wii-U-Virtual-Console-Games.php';
//$univ_categorie['amiibo']['Wii U'] = 'alle-Amiibo.php';
//$univ_categorie['Disney Infinity Character']['Wii U'] = 'Disney-Infinity-lijst.php';
//$univ_categorie['Skylanders Character']['Wii U'] = 'Skylanders-lijst.php';
//$univ_categorie['LEGO Dimensions']['Wii U'] = 'LEGO-Dimensions-lijst.php';


$univ_spellijst = $univ_categorie['nee']['Switch'];
$univ_all_in_1='alle-Nintendo-Switch-spellen.php';
$univ_spelpagina='Switch-spel-info.php';
$univ_zoeken='Switch_zoeken.php';
$univ_winkel='Switch-winkel.php';
$univ_reviews='Switch-reviews.php';
//$univ_top100='Nintendo_Top_100.php';
$univ_admin_map='adMin1';

$univ_inc_top10games="'top10-'$univ_link'-games.inc'";
$univ_inc_top10downloads="top10WiiWare.inc";

//Verandert alles..

$univ_mar="Mario Switch";
$univ_nin="Nintendo Switch 1";
$univ_down="$univ_one Downloads";
$univ_spellen="Nintendo Switch Spellen";
$univ_games="$univ_one Games";

//create-page links
$univ_createpage_link1 = 'https://www.mariowii-u.nl';
$univ_createpage_name1 = 'Wii U';
$univ_createpage_link2 = 'https://www.mario3DS.nl';
$univ_createpage_name2 = '3DS';

//Kiyoh en Facebook
$kiyoh_id = 'https://www.google.com/shopping/customerreviews/merchantreviews?q=mariowii.nl';
$google_merchant_id = '8086530';
$univ_google_conversion_label = 'Z3ApCI7W7QQQ9qPD_QM';
$pintrest = '';

/*
//argh ../
$_SERVER['DOCUMENT_ROOT'].
*/
?>
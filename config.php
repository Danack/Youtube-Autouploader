<?php


//Put in your email address that you login to Youtube as your username
$config_username = "emailaddress@example.com";

//Your Youtube password
$config_password = "password";

//The app key for Youtube Uploader
//You SHOULD get yourself a new app key by going to http://code.google.com/apis/youtube/dashboard/
$config_appKey = "AI39si5ndD3-fCbSGMUmie8fOLHUlxUxTQbqkyU7iLpAuRzmxTaVK-Q_nb4BgLUMJZ3mTCP4Fel9-P0-tG2Qdw0tkP8ZhLCtnw";

//The default maximum speed in kB/s
$config_maxSpeed = 60;

$config_publicByDefault = FALSE;

//Flag for whether to upload videos that haven't had their info file created
$config_uploadWithoutInfo = TRUE;


//https://chrome.google.com/webstore/detail/jllpkdkcdjndhggodimiphkghogcpida/details
$pathToVideos = "/Volumes/web/phpyoutube";


// Suppress DateTime warnings. i.e. use the best guess timezone
date_default_timezone_set(@date_default_timezone_get());
//Or set one from the list available at http://au.php.net/manual/en/timezones.php e.g.
//date_default_timezone_set('Australia/Sydney');

//All in kilobytes per second
$speedLimitsAvailable = array(
	10,
	25,
	50,
	60,
	75,
	100,
	150, 
	250,
	500, 
	1000,
	1500,
	2000
);


//This is the list of file extensions the uploader will look for
// together with their mime types
$knownVideoTypes = array(
	'mov'	=> 'video/quicktime',
	'mp4'	=> 'video/mp4',
	'mpe'	=> 'video/mpeg',
	'rm' 	=> 'application/vnd.rn-realmedia',
	'avi'	=> 'video/x-msvideo',
	'mpeg'	=> 'video/mpeg',
	'mpg'	=> 'video/mpeg',
	'3gp'	=> 'video/3gpp',
);


//You can override the conf with a local setting that does not get stored in CVS/github etc.
if(file_exists("configLocal.php") == TRUE){
	require_once("configLocal.php");
}

if(strcmp($config_username, "emailaddress@example.com") == 0){
	echo "You MUST set your Youtube login for the Youtube Uploader to work. Please edit config.php".NL;
	exit(0);	
}

if(strcmp($config_password, "password") == 0){
	echo "You MUST set your Youtube password for the Youtube Uploader to work. Please edit config.php".NL;
	exit(0);	
}




?>
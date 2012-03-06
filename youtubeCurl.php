<?php

require_once('config.php');
require_once('utility.php');

$GLOBALS['resumedFromBytes'] = FALSE;
$GLOBALS['uploadSpeed'] = FALSE;

//We don't need to update the progress every kB
if($cron == FALSE){
	//Update every 16 in interactive mode
	define('PROGRESS_CHUNK_SIZE', 16);
}
else{
	//every 2 megabyte in cron mode
	define('PROGRESS_CHUNK_SIZE', 2 * 1024);
}


function	uploadVideo($filename, $videoInfo){
	
	$auth = getAuthKey();
	
	$bytesTransferred = 0;
	$location = FALSE;
		
	if(array_key_exists('uploadURL', $videoInfo) == TRUE &&    //we're resuming		
	   $videoInfo['uploadURL'] != FALSE){
	   	
	   	$location = $videoInfo['uploadURL'];	   	
		echo "Video already has an uploadURL so presumably we're continuing the upload".NL;
		$bytesTransferred = getBytesTransferred($videoInfo['uploadURL'], $filename);		
	}
	
	if($bytesTransferred == 0){		
		echo "Either new upload or previous upload invalid. Resend video XML and get uploadURL ";			
		$videoXML = getVideoXML($videoInfo);			
		$location = createVideoEntry($auth, $videoXML, $filename);			
		echo "Location: ".$location.NL;		
		setVideoInfoElement($filename, 'uploadURL', $location);
	}
	else{
		echo "Resuming with $bytesTransferred bytes transferred.".NL;	
	}
	
	if(array_key_exists('speed', $videoInfo) == TRUE && 
		$videoInfo['speed'] == TRUE){
		setUploadSpeed($videoInfo['speed']);
	}
	else{
		setUploadSpeed($GLOBALS['config_maxSpeed']);
	}
	
	$uploadResult = startUploading($location, $filename,  $bytesTransferred);
	
	return $uploadResult;
}
	
	
function	sleepForABit($bytesUploaded){
	$dataJustTransferred = $bytesUploaded - $GLOBALS['lastDataTransferredSleep'];
	
	if($dataJustTransferred > 0){
		
		$kBsPerSecond = $GLOBALS['uploadSpeed'];
		
		if($kBsPerSecond != 0){
		
			$sleepTime = intval((1000000 * $dataJustTransferred) / ($kBsPerSecond * 1024));
			
			if($sleepTime <= 0){
				//Negative transfers? o.O
			}
			else{
//				echo "dataJustTransferred  $dataJustTransferred uploadSpeed $kBsPerSecond sleep time = $sleepTime".NL;
				usleep($sleepTime);
			}
		}
	}

    $GLOBALS['lastDataTransferredSleep'] = $bytesUploaded;    
}
	
function progressCallback($download_size, $downloaded, $upload_size, $uploaded){
    
    sleepForABit($uploaded);

    if($uploaded >= ($GLOBALS['lastDataTransferred'] + (PROGRESS_CHUNK_SIZE * 1024))){
    	
    	$videoInfo = getVideoInfo($GLOBALS['videoFilename']);    	
    	
    	processCommands($videoInfo);
   
    	echoTransferProgress($uploaded);
    			
		$GLOBALS['lastDataTransferred'] = $uploaded;
	}
}

//This is a hideous hack to enable CURL to interrupt downloads.
//TODO replace this with something less hacky.
function curlReadFunction($ch, $fileHandle, $maxDataSize){

	if($GLOBALS['abortTransfer'] == TRUE){
		sleep(1);
		return "";
	}
	
	return fread($fileHandle, $maxDataSize);
}

	
function startUploading($location, $filename, $bytesTransferred = 0){
	
	echo "".microtime()." startUploading(location $location, filename $filename, bytesTransferred $bytesTransferred);".NL;
	
	resetDataTransferred($filename, $bytesTransferred);
	
	$ch = curl_init();
	$fileHandle = FALSE;

	$uploadComplete = FALSE;

	try{		
		$headers = array();	
		$headers[] = "Content-Type: ".guessVideoContentType(UPLOAD_DIRECTORY.'/'.$filename);	
		
		$contentSize = filesize(UPLOAD_DIRECTORY.'/'.$filename);
		
		$GLOBALS['filesize'] = $contentSize;
				
		$fileHandle = fopen(UPLOAD_DIRECTORY.'/'.$filename, 'r');
	
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);  		
		curl_setopt($ch, CURLOPT_URL, $location);	//PUT /resumableupload/AF8GKF...0Glpk0Aw HTTP/1.1
		curl_setopt($ch, CURLOPT_PUT, TRUE);			//Host: uploads.gdata.youtube.com
		curl_setopt($ch, CURLOPT_SSLVERSION, 3); 
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);   
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);		
		curl_setopt($ch, CURLOPT_NOPROGRESS, FALSE);
		curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, 'progressCallback');
		curl_setopt($ch, CURLOPT_READFUNCTION, 'curlReadFunction');		
		curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1024);
		curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 5);
		
		// Setting buffer size doesn't seem to affect the callback rate - 
		//TODO - use CURLOPT_MAX_SEND_SPEED_LARGE instead, once upgraded to PHP 5.4
		//curl_setopt($ch, CURLOPT_BUFFERSIZE, 2048);
		curl_setopt($ch, CURLOPT_INFILESIZE, $contentSize);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		if($bytesTransferred != 0){
			curl_setopt($ch, CURLOPT_RESUME_FROM, $bytesTransferred + 1);
		}		
		
		curl_setopt($ch, CURLOPT_INFILE, $fileHandle);	
		curl_setopt($ch, CURLOPT_HEADER, TRUE);	
	
		$response = curl_exec($ch);
		$responseInfo = curl_getinfo($ch);
		
		
		
		
		if($responseInfo['http_code'] == 200 ||
		   $responseInfo['http_code'] == 201){	//HTTP/1.1 201 Created
			$uploadComplete = TRUE;
		}
		else{
			setVideoInfoElement($filename, 'status', STATUS_INTERRUPTED);
			echo "Response code was not 200 - maybe the upload was interrupted.".NL;
			echo "response: ".NL;
			htmlvar_dump($response);
			htmlvar_dump($responseInfo);
		}
	}
	catch(Exception $e){		
		$errorString = curl_error($ch);
		$errorNumber = curl_errno($ch);		
		$errorString = "Curl error: ".$errorNumber." errorString is: ".$errorString;		
		echo $errorString;
	}
	
	try{
		fclose($fileHandle);
	}
	catch(Exception $e){
		echo "Exception closing file: ".$e->getMessage();
	}
	
	try{
		curl_close($ch);  
	}
	catch(Exception $e){
		echo "Exception closing curl: ".$e->getMessage();
	}
	
	return $uploadComplete;
}
	
	
function	getBytesTransferred($location, $filename){
	
	$header = array();

	$headers[] = "Content-Range: bytes */*";
	$headers[] = 'Expect:';				//These three headers apparently confuse the Youtube servers.
	$headers[] = 'Accept:';				//If they're set then instead of getting the number of bytes to resume from
	$headers[] = 'Transfer-Encoding:';	//the server generates a bad request error
	$headers[] = 'Content-Length: 0';
	
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $location);	
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_PUT, TRUE);
	curl_setopt($ch, CURLOPT_HEADER, TRUE);	
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);  
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

	$response = curl_exec($ch); 
	
	$responseInfo = curl_getinfo($ch);
	
	curl_close($ch); 
	
	//echo "getBytesTransferred Response code = ".$responseInfo['http_code'].NL;
	
	if($responseInfo['http_code'] == 201){
		throw new UploaderCompletedException("File has already completed when trying to resume the upload.");
	}
	else if($responseInfo['http_code'] == 308){
		$headers = http_parse_headers($hasil);
		
		$rangeString = FALSE;
		
		if(array_key_exists('Range', $headers) == TRUE){
			$rangeString = $headers['Range'];
		}
		else if(array_key_exists('range', $headers) == TRUE){
			$rangeString = $headers['range'];
		}
		
		if($rangeString != FALSE){
			//bytes=0-408
			preg_match("/bytes=([0-9]+)-([0-9]+)/i", $headers['Range'], $matches);
			var_dump($matches);		
			
			if(isset($matches[2]) == TRUE){
				return $matches[2];	
			}		
		}
	}
	else{		
		echo "Response to getBytesTransferred is not understood, here's the repsonse:".NL;
		var_dump($response);
		
		echo "And the curl info:";
		var_dump($responseInfo);
		echo NL;
	}
	
	return FALSE;
}
	
	
	
	
function	createVideoEntry($auth, $videoXML, $filename){
	
	$header = array();
	
	$loginURL = "http://uploads.gdata.youtube.com/resumable/feeds/api/users/default/uploads";

	$ch = curl_init(); 
	curl_setopt($ch, CURLOPT_URL, $loginURL);

	$header[] = 'Authorization: GoogleLogin auth='.$auth;
	$header[] = 'GData-Version: 2';
	$header[] = 'X-GData-Key: key='.$GLOBALS['config_appKey'];
	$header[] = 'Content-Type: application/atom+xml; charset=UTF-8';
	$header[] = 'Slug: '.$filename;			//What is slug?
	
	curl_setopt($ch, CURLOPT_HTTPHEADER, $header);  
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);  
	curl_setopt($ch, CURLOPT_HEADER, true);	
	curl_setopt($ch, CURLOPT_POST, true); 
	
	curl_setopt($ch, CURLOPT_POSTFIELDS, $videoXML);  
	
	$response = curl_exec($ch);  
	curl_close($ch);  
	
	//var_dump($response);

	$headers = http_parse_headers($response);

	if(array_key_exists('Location', $headers) == TRUE){
		return trim($headers['Location']);
	}
	
	return FALSE;
}
	
	
	
	
//Reference https://developers.google.com/youtube/2.0/reference#youtube_data_api_tag_media:group	
function getVideoXML($videoInfo){
	
	$xmlData = '<?xml version="1.0"?>'."\n";
	$xmlData .= '<entry xmlns="http://www.w3.org/2005/Atom" xmlns:media="http://search.yahoo.com/mrss/" xmlns:yt="http://gdata.youtube.com/schemas/2007">'."\n"; 
	$xmlData .= '<media:group>'."\n"; 
	$xmlData .= '<media:title type="plain">'.xmlEntities($videoInfo['title']).'</media:title>'."\n"; 
	$xmlData .= '<media:description type="plain">'.xmlEntities($videoInfo['description']).'</media:description>'."\n"; 
	$xmlData .= '<media:category scheme="http://gdata.youtube.com/schemas/2007/categories.cat">'.$videoInfo['category'].'</media:category>'."\n"; 

	foreach($videoInfo['developerTags'] as $developerTag){
		$xmlData .= '<media:category scheme="http://gdata.youtube.com/schemas/2007/developertags.cat">'.$developerTag.'</media:category>'."\n"; 
	}
	
	$xmlData .= '<media:keywords>'.$videoInfo['tags'].'</media:keywords>'."\n"; 
	
	if(array_key_exists('makePublic', $videoInfo) == TRUE && 
		$videoInfo['makePublic'] == TRUE){
		//Do nothing	
	}
	else{
		$xmlData .= "<yt:private/>";
	}
	
	$xmlData .= '</media:group>'; 
	$xmlData .= '</entry>';
	
	$xmlData .= ''; 

	return $xmlData;
}

//https://developers.google.com/accounts/docs/AuthForInstalledApps
function getAuthKey(){
	
	$loginURL = "https://www.google.com/accounts/ClientLogin";
	
	$loginParams = array(
		'accountType' => 'GOOGLE',
		'Email' 	=> $GLOBALS['config_username'],
		'Passwd'	=> $GLOBALS['config_password'],
  		'service'	=> 'youtube',
  		'source'	=> 'Autouploader',
	);

	$ch = curl_init(); 
	curl_setopt($ch, CURLOPT_URL, $loginURL);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);	
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);  
	curl_setopt($ch, CURLOPT_POST, true);  
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);  
	curl_setopt($ch, CURLOPT_POSTFIELDS, $loginParams);  
  
	$response = curl_exec($ch);

	curl_close($ch); 

	preg_match("/Auth=([a-z0-9_-]+)/i", $response, $matches);
	$auth = $matches[1];
	return trim($auth);
}

	
function setUploadSpeed($kBsPerSecond){
	echo "setUploadSpeed(kBsPerSecond $kBsPerSecond)".NL;	
 	$GLOBALS['uploadSpeed'] = $kBsPerSecond;	
}
	


?>
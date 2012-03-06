<?php

$GLOBALS['processLockFileHandle'] = FALSE;
$GLOBALS['abortTransfer'] = FALSE;

define('BL', "<br/>\r\n");

if (isset($_SERVER['HTTP_HOST'])) {
    define('CLI', FALSE);
    define('NL', BL);
} else {    
    define('CLI', TRUE);
    define('NL', PHP_EOL);
}


define('UPLOAD_DIRECTORY', 'upload');
define('COMPLETED_DIRECTORY', 'completed');


define('STATUS_NOT_STARTED', 	'notStarted');
define('STATUS_UPLOADING', 		'uploading');
define('STATUS_INTERRUPTED', 	'interrupted');
define('STATUS_CANCELLED', 		'cancelled');

$importVariablesOK = import_request_variables("gp", "importVariable_");

//The list of available Youtube categories
//Note, not all of these are available in all territories e.g.
//Nonprofit in USA only.
$youtubeCategories = array(
	'Film' 			=> 'Film &amp; Animation',
	'Autos'			=> 'Autos &amp; Vehicles',
	'Music' 		=> 'Music',
	'Animals'		=> 'Pets &amp; Animals',
	'Sports'		=> 'Sports',
	'Travel'		=> 'Travel &amp; Events',
	'Games'			=> 'Gaming', 
	'People'		=> 'People &amp; Blogs',
	'Comedy'		=> 'Comedy',
	'Entertainment'	=> 'Entertainment',
	'News'			=> 'News &amp; Politics', 
	'Howto'			=> 'Howto &amp; Style',
	'Education'		=> 'Education', 
	'Tech'			=> 'Science &amp; Technology',
	'Nonprofit'		=> 'Nonprofits &amp; Activism',
);

class UploaderAbortException extends Exception { }
class UploaderCompletedException extends Exception { }
class ProcessLockFailedException extends Exception { }


//Get the default settings for the info about the video
function	getEmptyInfoArray(){
	$videoInfo = array(
		
		'captionName' => '',
		
		'title' => '',
		'description' => '',
		
		'category' => 'Travel',	//Probably ought not to be empty
		'tags' => '',
		
		'developerTags' => array(),
		
		'skipUpload' => FALSE,
		'makePublic' => $GLOBALS['config_publicByDefault'],
		'uploadURL'	=> FALSE,
		'speed'		=> FALSE,
		
		'infoSaved' => FALSE,
		'status' 	=> 'notStarted',
		'statusText' => FALSE,
		'command'	=> FALSE,
		
	);	
	return $videoInfo;
}


function guessVideoContentType($filename){

	foreach($GLOBALS['knownVideoTypes'] as $knownVideoType => $knownVideoMimeType){
		$knownVideoTypeExtension = '.'.$knownVideoType;
		
		$expectedExtensionPosition = strlen($filename) - strlen($knownVideoTypeExtension);
		$actualPosition = stripos($filename, $knownVideoTypeExtension);
		
		if($actualPosition === $expectedExtensionPosition){
			return $knownVideoMimeType;
		}
	}

	return FALSE;	
}


function getVariable($variable, $default = FALSE, $minimum = FALSE, $maximum = FALSE){

	$variableName = "importVariable_".$variable;

	if(isset($GLOBALS[$variableName]) == true){
		$result = $GLOBALS[$variableName];
	}
	else{
		$result = $default;
	}

	if($minimum !== FALSE){
		if($result < $minimum){
			$result = $minimum;
		}
	}

	if($maximum !== FALSE){
		if($result > $maximum){
			$result = $maximum;
		}
	}

	return $result;
}

function getInfoFilename($filename){
	return UPLOAD_DIRECTORY.'/'.$filename.'.info';
}


function writeVideoInfo($filename, $videoInfo){
	$filanameForInfo = getInfoFilename($filename);
	
	$fileHandle = fopen($filanameForInfo, 'w+');
	
	fwrite($fileHandle, json_encode($videoInfo));
	
	fclose($fileHandle);
}

function setVideoInfoElement($filename, $element, $value){

	$videoInfo = getVideoInfo($filename);	
		
	$videoInfo[$element] = $value;
	
	writeVideoInfo($filename, $videoInfo);
}

function	getVideoInfo($filename){
		
	$filanameForInfo = getInfoFilename($filename);
		
	$videoInfo = getEmptyInfoArray();
	
	if(file_exists($filanameForInfo) == TRUE){
		$lines = file($filanameForInfo);
		try{
			$allLines = implode($lines); //only way for them to be split is by hand?
			
			$videoInfoFromFile = json_decode($allLines, TRUE);
			
			if($videoInfoFromFile === NULL){
				//The json in the file is corrupted - just return the default data.
				$videoInfo;
			}
			
			foreach($videoInfo as $videoInfoKey => $videoInfoValue){
				if(array_key_exists($videoInfoKey, $videoInfoFromFile) == TRUE){
					$videoInfo[$videoInfoKey] =	$videoInfoFromFile[$videoInfoKey];
				}
			}
		}
		catch(Exception $e){
			echo "Failed to read info from file [$filenameForInfo]: ".$e->getMessage();;
		}
	}

	return $videoInfo;
}


function	getVideosInDirectory($contentBaseDir){

	$videoFiles = array();
	
	$directoryHandle = opendir($contentBaseDir);
	
	if($directoryHandle !== FALSE){
		
		$count = 0;
		$finished = FALSE;
		
		while($finished == FALSE) {
			
			$path = readdir($directoryHandle);
			
			$filePathName = $contentBaseDir."/".$path;

			if(strlen($path) == 0 || $path == '.' || $path == '..'){
				//These are definitely not video files
			}
			else if(is_dir($filePathName) == TRUE){	
		        //directories are definitely not video files
		    }
		    else if(is_file($filePathName) == TRUE) {
		 		//echo "Checking [".$filePathName."]".NL;
		    	
		    	foreach($GLOBALS['knownVideoTypes'] as $knownVideoType => $knownVideoMimeType){
		    		$knownVideoTypeExtension = '.'.$knownVideoType;
		    		
		    		$expectedExtensionPosition = strlen($filePathName) - strlen($knownVideoTypeExtension);
		    		$actualPosition = stripos($filePathName, $knownVideoTypeExtension);
		    		
		    		if($actualPosition === $expectedExtensionPosition){
		    			$videoFiles[] = $path;
		    			break;//leave the for loop, we've found a video
		    		}
		    	}
		    }
		    
		    $count += 1;
		    
		    if($count > 100){
		    	$finished = TRUE;	//just in case of weird recursion
		    }
		}
		
		closedir($directoryHandle);
	}
	
	return $videoFiles;
}



////////////////////////////////////////////////////////
// Function:         dump
// Inspired from:     PHP.net Contributions
// Description: Helps with php debugging
// SRC http://au.php.net/manual/en/function.var-dump.php

function htmlvar_dump(&$var, $info = FALSE)
{
	if(CLI){//If we're in CLI mode, html tags would be bad
		var_dump($var);	
		return;
	}
	
    $scope = false;
    $prefix = 'unique';
    $suffix = 'value';
 
    if($scope) $vals = $scope;
    else $vals = $GLOBALS;

    $old = $var;
    $var = $new = $prefix.rand().$suffix; $vname = FALSE;
    foreach($vals as $key => $val) if($val === $new) $vname = $key;
    $var = $old;

    echo "<pre style='margin: 0px 0px 10px 0px; display: block; background: white; color: black; font-family: Verdana; border: 1px solid #cccccc; padding: 5px; font-size: 10px; line-height: 13px;'>";
    if($info != FALSE) echo "<b style='color: red;'>$info:</b><br/>";

    do_dump($var, '$'.$vname);
    echo "</pre>";
}

////////////////////////////////////////////////////////
// Function:         do_dump
// Inspired from:     PHP.net Contributions
// Description: Better GI than print_r or var_dump
// SRC http://au.php.net/manual/en/function.var-dump.php
function do_dump(&$var, $var_name = NULL, $indent = NULL, $reference = NULL)
{
    //$do_dump_indent = "<span style='color:#eeeeee;'>|</span> &nbsp;&nbsp; ";
	$do_dump_indent = "<span style='color:#eeeeee;'></span> &nbsp;&nbsp; ";
    $reference = $reference.$var_name;
    $keyvar = 'the_do_dump_recursion_protection_scheme'; $keyname = 'referenced_object_name';

    if (is_array($var) && isset($var[$keyvar]))
    {
        $real_var = &$var[$keyvar];
        $real_name = &$var[$keyname];
        $type = ucfirst(gettype($real_var));
        echo "$indent$var_name <span style='color:#a2a2a2'>$type</span> => <span style='color:#e87800;'>&amp;$real_name</span><br/>";
    }
    else
    {
        $var = array($keyvar => $var, $keyname => $reference);
        $avar = &$var[$keyvar];
   
        $type = ucfirst(gettype($avar));
        if($type == "String") $type_color = "<span style='color:green'>";
        elseif($type == "Integer") $type_color = "<span style='color:red'>";
        elseif($type == "Double"){ $type_color = "<span style='color:#0099c5'>"; $type = "Float"; }
        elseif($type == "Boolean") $type_color = "<span style='color:#92008d'>";
        elseif($type == "NULL") $type_color = "<span style='color:black'>";
   
        if(is_array($avar))
        {
            $count = count($avar);
            //echo "$indent" . ($var_name ? "$var_name => ":"") . "<span style='color:#a2a2a2'>$type ($count)</span><br/>$indent(<br/>";
			echo "$indent" . ($var_name ? "$var_name => ":"") . "<span style='color:#a2a2a2'>$type </span><br/>$indent(<br/>";
            $keys = array_keys($avar);
            foreach($keys as $name)
            {
                $value = &$avar[$name];
                do_dump($value, "'$name'", $indent.$do_dump_indent, $reference);
            }
            echo "$indent),<br/>";
        }
        elseif(is_object($avar))
        {
            echo "$indent$var_name <span style='color:#a2a2a2'>$type</span><br/>$indent(<br/>";
            foreach($avar as $name=>$value) do_dump($value, "$name", $indent.$do_dump_indent, $reference);
            echo "$indent)<br/>";
        }
	
		elseif(is_int($avar)) echo "$indent$var_name => <span style='color:#a2a2a2'>"."</span> $type_color$avar,</span><br/>";
        elseif(is_string($avar)) echo "$indent$var_name => <span style='color:#a2a2a2'>"."</span> $type_color\"$avar\",</span><br/>";
        elseif(is_float($avar)) echo "$indent$var_name => <span style='color:#a2a2a2'>"."</span> $type_color$avar,</span><br/>";
        elseif(is_bool($avar)) echo "$indent$var_name => <span style='color:#a2a2a2'>"."</span> $type_color".($avar == 1 ? "TRUE":"FALSE").",</span><br/>";
        elseif(is_null($avar)) echo "$indent$var_name => <span style='color:#a2a2a2'>"."</span> {$type_color}NULL,</span><br/>";
		
		
        else echo "$indent$var_name = <span style='color:#a2a2a2'>$type,</span> $avar<br/>";

        $var = $var[$keyvar];
    }
}



////////////////////////////////////////////////////////
// Function:         _format_bytes
// Description: Formats a file size to a human understandable format
// Author yatsynych at gmail dot com
// SRC http://www.php.net/manual/en/function.filesize.php#106935
function _format_bytes($a_bytes)
{
    if ($a_bytes < 1024) {
        return $a_bytes .' B';
    } elseif ($a_bytes < 1048576) {
        return round($a_bytes / 1024, 2) .' KB';
    } elseif ($a_bytes < 1073741824) {
        return round($a_bytes / 1048576, 2) . ' MB';
    } elseif ($a_bytes < 1099511627776) {
        return round($a_bytes / 1073741824, 2) . ' GB';
    } elseif ($a_bytes < 1125899906842624) {
        return round($a_bytes / 1099511627776, 2) .' TB';
    } elseif ($a_bytes < 1152921504606846976) {
        return round($a_bytes / 1125899906842624, 2) .' PB';
    } elseif ($a_bytes < 1180591620717411303424) {
        return round($a_bytes / 1152921504606846976, 2) .' EB';
    } elseif ($a_bytes < 1208925819614629174706176) {
        return round($a_bytes / 1180591620717411303424, 2) .' ZB';
    } else {
        return round($a_bytes / 1208925819614629174706176, 2) .' YB';
    }
}

function	emitWebPageStart(){
	
	$output = "";
	
	$output .= "<html>";
	$output .= "<head>";

	$output .= "<style type='text/css'>";
	
	$output .= <<<CSS
	.inlineTable{
		display: inline-table;
		border: 1px solid #000000;
		width: 100%;
		text-align: left;
	}
CSS;
	$output .= "</style>";
	
	
	$output .= "<script type='text/javascript' src='jquery-1.6.2.min.js'></script>";	

	$output .= <<<JAVASCRIPT
<script type="text/javascript">
<!--
   function updateProgress(percentage) {
      //alert('Hello World!') ;
		if (typeof $ == 'undefined') {  
    		// jQuery is not loaded  
		}	 
		else {
    	// jQuery is loaded
    		$('.progress').text(percentage);
		}      
   }
 // -->
</script>

JAVASCRIPT;
	
	$output .= "</head>";
	
	$output .= "<body style='width: 800px; margin-right: auto; margin-left: auto; text-align: center'>";	
	$output .= "<div style='width: 800px:'>";	
	$output .= "<a href='/webform.php'>Click to refresh</a>";	
	$output .= "<br/><br/>";	
	
	return $output;
}




function	emitWebPageEnd(){
	
	$output = "</div>";
	
	$output .= "<div style='width: 800px; margin-top: 40px;'>";
	$output .= "By using this Autouploader you certify that you own all rights to the content or that you are authorized by the owner to make the content publicly available on YouTube, and that it otherwise complies with the YouTube Terms of Service located at <a href='http://www.youtube.com/t/terms' target='_blank'>http://www.youtube.com/t/terms</a>.";
	$output .= "</div>";
	
	$output .= "</body>";
	$output .= "</html>";
	
	return $output;
}

function renameOrExit($sourceFilename, $destFilename){
	
	$renamed = rename($sourceFilename, $destFilename);
	
	if($renamed == FALSE){
		echo "Failed to rename file [$sourceFilename] to [$destFilename], aborting.";
		exit(0);	
	}
	
}

/*Cleanup the tags before uploading, as Youtube throws an error AFTER the data has been transferred,
which is annoying.

Tags are not allowed to contain spaces or double-quotes
http://code.google.com/p/gdata-issues/issues/detail?id=2464
*/
function cleanupTags($tags){
	
	$trimmedTagArray = array();
	$tagArray = explode(',', $tags);
	
	foreach($tagArray as $tag){
		$cleanedTag = str_replace('"', '', $tag);
		$trimmedTagArray[] = trim($cleanedTag);
	}
	
	return implode(',', $trimmedTagArray);
}


//Rename doesn't work properly on windows platform. Fallback to copy + delete old version.
//Author: ddoyle [at] canadalawbook [dot] ca 07-Sep-2005 03:35
//Source: http://www.php.net/manual/en/function.rename.php#56576
function renameSafe($oldFilename, $newFilename) {
	$result = TRUE;
	
	if (!rename($oldfile,$newfile)) {
		if (copy ($oldfile,$newfile)) {
			$unlinkResult = unlink($oldfile);
			
			if($unlinkResult == FALSE){
				unlink($newfile);
				$result = FALSE;		
			}
			else{			
				$result = TRUE;
			}
		}
		else{
			$result = FALSE;
		}
	}
   
   return $result;
}

function processCommands($params){
	
	if(array_key_exists('command', $params) == TRUE){
	
		$command = $params['command'];
		
		if($command == FALSE || strlen($command) == 0 || $command == 'none'){
			return;	
		}
		
		switch($command){
			
			case('cancelUpload'):{				
				$statusText = "Upload cancelled at ".date('H:i:s').". Move video and info file back to the upload directory to restart upload.";
				setVideoInfoElement($GLOBALS['videoFilename'], 'command', NULL);
				setVideoInfoElement($GLOBALS['videoFilename'], 'statusText', $statusText);
				$GLOBALS['abortTransfer'] = TRUE;
				break;				
			}
			
			case('setSpeed'):{
				
				if(array_key_exists('speed', $params) == true){
					setUploadSpeed($params['speed']);
					setVideoInfoElement($GLOBALS['videoFilename'], 'command', NULL);
				}
				else{
					echo "Cannot set speed, speed param not set.".NL;	
				}
				break;	
			}
			
			default:{
				echo "Unknown command [".$command."] ";	
			}
		}
	}
}

function	xmlEntities($string){

	$replaceInfo = array(
	 	'&'  => '&amp;',
		'<'  => '&lt;',
		'>'  => '&gt;',
		'\'' => '&apos;',
		'"'  => '&quot;',
	);
	
	$searchArray = array();
	$replaceArray = array();

	foreach($replaceInfo as $search => $replace){
		$searchArray[] = $search;
		$replaceArray[] = $repalce;		
	}

	return 	str_replace($searchArray, $replaceArray, $string);
}



function    echoTransferProgress($dataTransferred){
	
	$totalTransferred = $dataTransferred;
	
	if(array_key_exists('resumedFromBytes', $GLOBALS) == TRUE &&
		$GLOBALS['resumedFromBytes'] != FALSE){
		$totalTransferred += $GLOBALS['resumedFromBytes'];
	}	
	
    $formatted = number_format((($totalTransferred * 100) / $GLOBALS['filesize']), 2)." %";

	if(CLI == TRUE){
		$outputString = $formatted." at ".date('H:i:s');
		
		setVideoInfoElement($GLOBALS['videoFilename'], 'statusText', $outputString);
		
		if($GLOBALS['cron'] == TRUE){
			echo $outputString.NL;
		}
		else{
			if($GLOBALS['lastStringLength'] != -1){	
				for($x=0 ; $x<$GLOBALS['lastStringLength']; $x++){
					echo chr(8);
				}
			}
		
			echo $outputString;
			$GLOBALS['lastStringLength'] = strlen($outputString);
		}
	}
	else{
		echo "<script>updateProgress('$formatted ');</script>";
		flush();
	}
}
	
	
//http://au.php.net/manual/en/function.http-parse-headers.php#77241
function http_parse_headers( $header ) {
    $retVal = array();
    $fields = explode("\r\n", preg_replace('/\x0D\x0A[\x09\x20]+/', ' ', $header));
    foreach( $fields as $field ) {
        if( preg_match('/([^:]+): (.+)/m', $field, $match) ) {
            $match[1] = preg_replace('/(?<=^|[\x09\x20\x2D])./e', 'strtoupper("\0")', strtolower(trim($match[1])));
            if( isset($retVal[$match[1]]) ) {
                $retVal[$match[1]] = array($retVal[$match[1]], $match[2]);
            } else {
                $retVal[$match[1]] = trim($match[2]);
            }
        }
    }
    return $retVal;
}
		


function	getProcessorLockFilename(){
	return 	UPLOAD_DIRECTORY.'/proc.lock';
}

function	acquireProcessLock(){
	
	if($GLOBALS['processLockFileHandle'] !== FALSE){
		throw new Exception("Trying to acquire process lock when one already exists.");	
	}
	
	//echo "Acquiring lock".NL;
	$GLOBALS['processLockFileHandle'] = fopen(getProcessorLockFilename(), 'w+');

	//Running the Autouploader from the command line or Cron creates the file probably not openable
	//by the webserver, which needs to open it to lock it, to test if the Autouploader is running.
	chmod(UPLOAD_DIRECTORY.'/proc.lock', 0766);
	
	$flockResult = flock($GLOBALS['processLockFileHandle'], LOCK_EX|LOCK_NB); // do an exclusive lock
	
	//echo "Past flock".NL;
	
	if ($flockResult) {
		return;
	}
	
   	throw new ProcessLockFailedException("Failed to acquire process lock. Check directory permissions?");	
}

function	releaseProcessLock(){
	
	if($GLOBALS['processLockFileHandle'] === FALSE){
		throw new Exception("Trying to release process lock when no lock exists.");	
	}
	
	flock($GLOBALS['processLockFileHandle'], LOCK_UN);
	fclose($GLOBALS['processLockFileHandle']);
	
	unlink(getProcessorLockFilename());
	$GLOBALS['processLockFileHandle'] = FALSE;
}

function	testProcessLock(){
	
	if($GLOBALS['processLockFileHandle'] !== FALSE){
		throw new Exception("Trying to test process lock when you've already acquired! This function is meant to be called from a different process that the one that acquired the lock.");	
	}

	if(file_exists(getProcessorLockFilename()) == TRUE){

		$fileHandle = fopen(getProcessorLockFilename(), 'c+');
	
		$lockResult = flock($fileHandle, LOCK_EX | LOCK_NB);
	
		if($lockResult){
			flock($fileHandle, LOCK_UN);
		}
	
		fclose($fileHandle);
		
		if($lockResult == TRUE){
			//echo "//We could get a lock, so uploader not running".NL;
			return FALSE; //We could get a lock, so uploader not running
		}
		else{
			//echo "//We couldn't get a lock, so uploader running. Or the filesystem is borked...".NL;
			return TRUE; //We couldn't get a lock, so uploader running. Or the filesystem is borked...
		}
	}
	else{
		return FALSE; //Lock file doesn't exist, uploader isn't running.	
	}
}


?>
<?php 

require_once('config.php');
require_once('utility.php');


define('OPTION_FILENAME', 		'emitFilename');
define('OPTION_SET_SPEED', 		'emitSpeedOptions');
define('OPTION_EDIT_INFO', 		'emitEditInfoOption');
define('OPTION_STATUS', 		'emitStatus');
define('OPTION_CANCEL_UPLOAD', 	'emitCancelOption');
define('OPTION_CANCELLED', 		'emitCancelledOptions');
//define('OPTION_FORCE_START',	'emitForceUploadOption');



$uploaderRunning = testProcessLock();

showWebPage();

function	showWebPage(){
	
	echo emitWebPageStart();
	
	if($GLOBALS['uploaderRunning'] == TRUE){
		echo "Autouploader is running.<br/>";
	}
	else{
		echo "Autouploader is NOT running.<br/>";
	}
	
	$action = getVariable('action');
	
	$showFileList = TRUE;
	
	switch($action){
		
		case('cancel'):{
			cancelUpload();
			break;	
		}
		
		case('editInfo'):{
			showEditInfo();
			$showFileList = FALSE;
			break;	
		}
		
		case('remove'):{
			removeFile();
			break;	
		}
		
		case('restart'):{
			restartUpload();
			break;	
		}
		
		case('setSpeed'):{
			$speedInKBs = getVariable('speed', 50);
			setVideoSpeed($speedInKBs);			
			//echo "Speed changed to: $speedInKBs kB/s. Will take a few seconds to be effective.".NL;			
			break;
		}
		
		case('setVideoInfo'):{
			$showFileList = setVideoInfo();
			
			if($showFileList == FALSE){//We failed to save the info, reshow the edit form
				showEditInfo();
			}
			break;	
		}
		
		default:{
			
			break;
		}
	}
	
	if($showFileList == TRUE){
		showActiveList();	
		showCompletedList();	
	}
	
	echo emitWebPageEnd();
}

function	removeFile(){
	
	$filename = getVariable('filename');
	
	if($filename == FALSE){
		echo "Filename not set, cannot cancel.";
		return;
	}
	
	if($GLOBALS['uploaderRunning'] == TRUE){
		echo "Uploader is running, cannot remove file while it i.";
		return;
	}
	
	renameOrExit(UPLOAD_DIRECTORY."/".$filename, COMPLETED_DIRECTORY."/".$filename);
	
	echo "File moved to completed directory.";
}


function	cancelUpload(){
	
	$filename = getVariable('filename');
	
	if($filename == FALSE){
		echo "Filename not set, cannot cancel.";
		return;
	}
	setVideoInfoElement($filename, 'command', 'cancelUpload');
}

function	restartUpload(){
	
	$filename = getVariable('filename');
	
	if($filename == FALSE){
		echo "Filename not set, cannot restartUpload.";
		return;
	}
	
	
	
	setVideoInfoElement($filename, 'command', 'restart');
}



function	setVideoSpeed($speedInKBs){
	
	$filename = getVariable('filename');
	
	if($filename == FALSE){
		echo "Filename not set, cannot cancel.";
		return;
	}
	
	setVideoInfoElement($filename, 'speed', $speedInKBs);
	setVideoInfoElement($filename, 'command', 'setSpeed');
	
	
}

function showCompletedList(){

	$videoFiles = getVideosInDirectory(COMPLETED_DIRECTORY);
	
	if(count($videoFiles) == 0){
		return;	
	}	
	
	echo "<br/>";
	
	$output .= "<table class='inlineTable'>";
	
		$output .= "<tr>";
			$output .= "<th colspan='3' align='left'>";
			$output .= "Completed directory";
			$output .= "</th>";		
		$output .= "</tr>";
		
		$output .= "<tr>";
		$output .= "<th colspan='3'>&nbsp;";
		$output .= "</th>";		
		$output .= "</tr>";
	

	
	
	foreach($videoFiles as $videoFile){
		$output .= "<tr>";
			$output .= "<td>";
				$output .= $videoFile;
			$output .= "</td>";
		$output .= "</tr>";
	}
	
	
	$output .= "</table>";
	
	$output .= "</div>";
	
	echo $output;
}


function showActiveList(){

	$videoFilenames = getVideosInDirectory(UPLOAD_DIRECTORY);
	
	if(count($videoFilenames) == 0){
		return;	
	}	
	
	echo "<br/>";	
	
	$output .= "<table class='inlineTable'>";
	
		$output .= "<tr>";
			$output .= "<th colspan='3' align='left'>";
			$output .= "Upload directory";
			$output .= "</th>";		
		$output .= "</tr>";
	
		$output .= "<tr>";
		$output .= "<th colspan='3'>&nbsp;";
		$output .= "</th>";		
		$output .= "</tr>";	
	
		$output .= "<tr>";
		$output .= "<th>";
			$output .= "Filename";
		$output .= "</th>";

		
		$output .= "<th>";
			$output .= "Option";
		$output .= "</th>";		
		
		$output .= "<th>";
			$output .= "Set speed";
		$output .= "</th>";		
		
				
		$output .= "<th>";
			$output .= "Status";
		$output .= "</th>";
		
		
	$output .= "</tr>";
	
	
	foreach($videoFilenames as $videoFilename){

		$output .= "<tr>";

		$videoInfo = getVideoInfo($videoFilename);		

		$optionsToShow = array(OPTION_FILENAME);		
		
		switch($videoInfo['status']){

			case(STATUS_NOT_STARTED):{
				$optionsToShow[] = OPTION_EDIT_INFO;
				$optionsToShow[] = OPTION_SET_SPEED;				
				break;	
			}
			
			case('failedToStart'):{
				//$output .= emitCancelledOptions($videoFile, $info);				
				break;
			}				
			
			case(STATUS_INTERRUPTED);
			case(STATUS_CANCELLED):{
				$optionsToShow[] = OPTION_CANCELLED;
				$optionsToShow[] = OPTION_SET_SPEED;
				break;	
			}
			
			case(STATUS_UPLOADING):{
				$optionsToShow[] = OPTION_CANCEL_UPLOAD;					
				$optionsToShow[] = OPTION_SET_SPEED;
				break;	
			}
			
			default:{
				$output .= "Unknown status [$unknown] for file [$videoFilename]";
			}
		}
		
		$optionsToShow[] = OPTION_STATUS;
		
		foreach($optionsToShow as $option){
			$output .= call_user_func($option, $videoFilename, $videoInfo);
		}		
			
		$output .= "</tr>";
	}	
	
	$output .= "</table>";
	
	$output .= "</div>";
	
	echo $output;
}



function	emitEditInfoOption($videoFilename, $info){
	
	$output = "";
	
	$output .= "<td>";		    
    $link = "webform.php?action=editInfo&amp;filename=".$videoFilename;
    
    $output .= "<a href='$link'>";
    
    if($info['infoSaved'] == TRUE){
    	$output .= "Edit existing info";
    }
    else{
    	$output .= "<span style='color: #9f3f3f'>Create info</span>";
    }
    $output .= "</a>";
    
	$output .= "</td>";

	return $output;	
}


function	emitFilename($videoFilename, $info){
	
	$output = "";
	
	$output .= "<td valign='top'>";
	$fileLink = getFileLink($videoFilename);
		
	if($fileLink == FALSE){
		$output .= $videoFilename;
	}
	else{
		$output .= "<a href='$fileLink' target='_blank'>";
		$output .= $videoFilename;
		$output .= '</a>';
	}
	
	$fileSize = filesize(UPLOAD_DIRECTORY.'/'.$videoFilename);
	
	$output .= " "._format_bytes($fileSize);
	
	$output .= "</td>";

	return $output;
}

function	emitStatus($videoFilename, $videoInfo){

	$output = "";

	$output .= "<td valign='top'>";

	if($videoInfo['status'] == 'notStarted'){
		$output .= "Not started. &nbsp;";	
			
		if($GLOBALS['uploaderRunning'] == FALSE){			
			$forceUploadLink = "uploader.php?action=forceUpload&amp;filename=".$videoFilename;
			$output .= "<a href='$forceUploadLink'>";
				$output .= "Force upload";
			$output .= "</a>";		
		}
		else{
			//uploader already running - two instances would be bad.	
		}
	}
	else{		
		$output .= $videoInfo['status'];
		
		if($videoInfo['status'] == STATUS_UPLOADING){
			if($GLOBALS['uploaderRunning'] == FALSE){
				$output .= "<br/>";
				$output .= "Status is ".STATUS_UPLOADING." but uploader is not running. That probably means it crashed. Please check the log file.<br/>";
			}
		}
				
		if(array_key_exists('statusText', $videoInfo) == TRUE){
			$output .= "<br/>";
			$output .= $videoInfo['statusText'];
		}
	}
	
	$output .= "</td>";		
	return $output;
}

function	emitCancelledOptions($videoFile, $info){
	
	$output = "";
	
	$output .= "<td valign='top'>";			
	
		$output .= "Upload will resume next time uploader runs or: <br/>";
	
		$output .= "<a href='/webform.php?action=remove&amp;filename=".$videoFile."'>Click to remove upload</a>";
	$output .= "</td>";

/*
	$output .= "<td valign='top'>";
	$output .= "</td>";*/

	return $output;
}


function	emitCancelOption($videoFile, $info){
	
	$output = "";
	
	$output .= "<td valign='top'>";			
		$output .= "<a href='/webform.php?action=cancel&amp;filename=".$videoFile."'>Click to cancel upload</a>";
	$output .= "</td>";
	
	return $output;
}
	
$GLOBALS['formCount'] = 0;
	
function	emitSpeedOptions($videoFile, $info){
	
	$currentSpeed = -1;
	
	if(array_key_exists('speed', $info) == TRUE){
		$output .= $info['speed'].'kB/s';
		$currentSpeed = $info['speed'];
	}
	
	$output = "";
	
	$formID = "formID_".$GLOBALS['formCount'];
	
	$GLOBALS['formCount']++;
	
	$output .= "<td valign='top'>";
		$output .= "<form style='margin: 0px; border: 0px; padding: 0px' id='$formID'>";				
		$output .= "<input type='hidden' name='action' value='setSpeed' />";
		$output .= "<input type='hidden' name='filename' value='$videoFile' />";
		
		$output .= "<select name='speed' onchange='$(\"#".$formID."\").submit();'>";
		
		if(array_key_exists('config_maxSpeed', $GLOBALS) == TRUE &&
		 	$GLOBALS['config_maxSpeed'] == TRUE){		
			$output .= "<option value='0'>Default ".$GLOBALS['config_maxSpeed']."kB/s</option>";
		}
		else{
			$output .= "<option value='0'>No limit</option>";
		}
		
		foreach($GLOBALS['speedLimitsAvailable'] as $speedAvailable){
			$selectedString = '';
			if($currentSpeed == $speedAvailable){
				$selectedString = "selected='selected'";
			}
			
			$output .= "<option value='$speedAvailable' $selectedString>$speedAvailable</option>";
		}				
		$output .= "</select>";
		
		//$output .= "<input type='submit' name='submit' value='Set speed'>";
		
		$output .= "</form>";
	$output .= "</td>";

	return $output;
};


function getFileLink($filename){
	
	if(array_key_exists('pathToVideos', $GLOBALS) == FALSE ||
		$GLOBALS['pathToVideos'] == FALSE){
		return FALSE;
	}
	
	return 'file://'.$GLOBALS['pathToVideos'].'/'.UPLOAD_DIRECTORY.'/'.$filename;
}


function	showEditInfo(){
	
	$filename = getVariable('filename');
	
	if($filename == FALSE){
		echo "Cannot edit file info 'filename' is not set.";
		return;	
	}
	
	$videoInfo = getVideoInfo($filename);
	
	echo emitEditInfoForm($videoInfo, $filename);
}

function	 emitDropDown($curentValue, $options, $formName){
	
	$output = "";

	$output .= "<select name='$formName'>";
	
	foreach($options as $optionValue => $optionDisplay){
		
		if($curentValue == $optionValue){
			$output .= "<option value='$optionValue' selected='selected'>".$optionDisplay."</option>";
		}
		else{		
			$output .= "<option value='$optionValue'>".$optionDisplay."</option>";
		}
	}
	
	$output .= "</select>";
	
	return $output;
}

function  emitTextBox($currentValue, $formname){
	
	$output = "";
	$output .= "<textarea name='$formname' cols='80' rows='8'>";
	$output .= $currentValue;
	$output .= "</textarea>";
	
	return $output;
}


function	emitCheckBox($currentValue, $formname){
	$output = "";
	$output .= "<input type='checkbox' name='$formname' value='true' ";
	
	if($currentValue == TRUE){
		$output .= "checked='checked'";
	}
	
	$output .= "/>";
	
	return $output;
}


function emitEditInfoForm($videoInfo, $filename){
	
	$output  = "<form method='post'>\r\n";
	$output .= "<input type='hidden' name='action' value='setVideoInfo'/>";
	$output .= "<input type='hidden' name='filename' value='$filename'/>";

	$output .= "<table>";
	
	$output .= "<tr><td>";
		$output .= "File</td><td>";
					
		$fileLink = getFileLink($filename);
			
		if($fileLink == FALSE){
			$output .= $filename;
		}
		else{
			$output .= "<a href='$fileLink' target='_blank'>";
			$output .= $filename;
			$output .= '</a>';
		}
	$output .= "</td></tr>";	
		
	$output .= "<tr><td>";
		$output .= "Title</td><td>";
		$output .= emitTextBox($videoInfo['title'],'title');
	$output .= "</td></tr>";
	
	$output .= "<tr><td>";
		$output .= "Description</td><td>";
		$output .= emitTextBox($videoInfo['description'],'description');
	$output .= "</td></tr>";
	
	$output .= "<tr><td>";
		$output .= "Catergory</td><td>";
		$output .= emitDropDown($videoInfo['category'], $GLOBALS['youtubeCategories'], 'category');
	$output .= "</td></tr>";
	
	$output .= "<tr><td>";
		$output .= "Caption title</td><td>";
		$output .= emitTextBox($videoInfo['captionName'],'captionName');
	$output .= "</td></tr>";
	
	$output .= "<tr><td>";
		$output .= "Search tags</td><td>";
		$output .= emitTextBox($videoInfo['tags'], 'tags');
	$output .= "</td></tr>";
	
	$developerTagsString = implode($videoInfo['developerTags'], ',');
	
	$output .= "<tr><td>";
		$output .= "Developer tags</td><td>";
		$output .= emitTextBox($developerTagsString, 'developerTagsString');
	$output .= "</td></tr>";
	
	$output .= "<tr><td>";
		$output .= "Skip upload</td><td>";
		$output .= emitCheckBox($videoInfo['skipUpload'], 'skipUpload');
	$output .= "</td></tr>";
		
	$output .= "<tr><td>";
		$output .= "Make public</td><td>";
		$output .= emitCheckBox($videoInfo['makePublic'], 'makePublic');
	$output .= "</td></tr>";

	
	$output .= "<tr><td></td><td>";
	$output .= "<input type='submit' name='submit' value='Save info' />";
	
	$output .= "<input type='submit' name='action' value='Cancel' />";
	
	$output .= "</td></tr>";

	$output .= "</table>";
	
	$output .= "</form>";
	
	return $output;
}



function	setVideoInfo(){
	
	$filename = getVariable('filename');
	
	if($filename == FALSE){
		echo "Could not saveVideoInfo, filename is not set.";
		return FALSE;	
	}
	
	$videoInfo = getVideoInfo($filename);	
		
	$videoInfo['captionName'] = getVariable('captionName');
	
	$videoInfo['title'] = getVariable('title');
	$videoInfo['description'] = getVariable('description');
	
	$videoInfo['category'] = getVariable('category');
	$videoInfo['tags'] = getVariable('tags');
	
	$developerTagsString = getVariable('developerTagsString');
	
	$developerTags = explode(',', $developerTagsString);
	
	$nonZeroLengthDeveloperTags = array();	
	
	foreach($developerTags as $developerTag){
		$trimmedTag = trim($developerTag);
		
		if(strlen($trimmedTag) > 0){
			$nonZeroLengthDeveloperTags[] = $trimmedTag;
		}
	}
	
	$videoInfo['developerTags'] = $nonZeroLengthDeveloperTags;
	
	$videoInfo['skipUpload'] = getVariable('skipUpload');
	
	$videoInfo['makePublic'] = getVariable('makePublic');
	
	$videoInfo['infoSaved'] = TRUE;
	
	writeVideoInfo($filename, $videoInfo);
	
	return TRUE;
}

?>
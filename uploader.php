<?php

require_once('config.php');
require_once('utility.php');

require_once('youtubeCurl.php');

$GLOBALS['filesize'] = -1;
$GLOBALS['lastDataTransferred'] = -1;
$GLOBALS['lastDataTransferredSleep'] = -1;
$GLOBALS['lastStringLength'] = -1;
$GLOBALS['videoFilename'] = NULL;


$action = getVariable('action');

$cron = getVariable('cron');

//We don't need to update the progress every kB
if($cron == FALSE){
	//Update every 16 in interactive mode
	define('PROGRESS_CHUNK_SIZE', 16);
}
else{
	//every 2 megabyte in cron mode
	define('PROGRESS_CHUNK_SIZE', 2 * 1024);
}


if(CLI == FALSE){	
	echo emitWebPageStart();
	echo "<br/><a href='/webform.php'>Back to list - active upload will be cancelled.</a><br/>";
	flush();
}

if($action == 'forceUpload'){
	$filename = getVariable('filename');
	
	if($filename == FALSE){
		echo "Filename not set, cannot forceUpload".NL;
	}
	else{
		$GLOBALS['config_uploadWithoutInfo'] = TRUE;		
		processVideoFile($filename);
	}
	if(CLI == FALSE){	
		echo "<br/><a href='/webform.php'>Back to list - active upload will be cancelled.</a><br/>";
		echo emitWebPageEnd();
	}
	exit(0);
}	


uploadVideosThatNeedUploading();


//END OF PAGE



function	uploadVideosThatNeedUploading(){

	echo "Checking new videos".NL;
	
	$videoFiles = getVideosInDirectory(UPLOAD_DIRECTORY);
	
	foreach($videoFiles as $filename){	
		processVideoFile($filename);
	}
	
	echo "Finished processing upload directory.".NL;
	
	exit(0);
}


function	processVideoFile($filename, $moveToActiveDirectory = TRUE){

	try{
		$moveVideoToCompletedDirectory = FALSE;
		
		acquireProcessLock();
	
		$videoInfo = getVideoInfo($filename);	
		
		if(array_key_exists('infoSaved', $videoInfo) == FALSE || $videoInfo['infoSaved'] == FALSE){
			echo "Skipping file $filename as info not set set for it.".NL;
			return;	
		}
		
		if(array_key_exists('skipUpload', $videoInfo) == TRUE && $videoInfo['skipUpload']){
			echo "Skipping file $filename as skipUpload is set for it.".NL;
			return;	
		}
		
		setVideoInfoElement($filename, 'status', STATUS_UPLOADING);
	
		echo "Proceeding to upload video [$filename].".NL;
		
		try{		
			$success = uploadVideo($filename, $videoInfo);
			
			if($success == TRUE){
				echo "Video uploaded successfully.".NL;
				$moveVideoToCompletedDirectory = TRUE;
			}
			else{
				echo "Video was NOT uploaded successfully.".NL;	
			}
		}
		catch(UploaderAbortException $uae){
			echo "UploaderAbortException ".$uae->getMessage().NL;
			setVideoInfoElement($filename, 'status', STATUS_CANCELLED);
		}
		catch(UploaderCompletedException $uce){
			echo "Video has unexpectedly completed. Maybe Youtube thinks it's a duplicate? ".$uce->getMessage().NL;
			$moveVideoToCompletedDirectory = TRUE;
		}	
		catch(Exception $e){
			setVideoInfoElement($filename, 'status', STATUS_INTERRUPTED);	
		}		
		
		if($moveVideoToCompletedDirectory == TRUE){
			$moveResult = renameOrExit(UPLOAD_DIRECTORY."/".$filename, COMPLETED_DIRECTORY."/".$filename);
			echo "Move result = $moveResult".NL;
		}		
		
		releaseProcessLock();	
	}
	catch(ProcessLockFailedException $plfe){
		echo "Failed to acquire process lock. Either the uploader is already running or something weird has happened. If you're sure the uploader is no running, try deleting the file '".getProcessorLockFilename()."' and restarting the  uploader";	
	}
}



function	resetDataTransferred($filename, $bytesTransferred){
	$GLOBALS['filesize'] = filesize(UPLOAD_DIRECTORY.'/'.$filename);
	$GLOBALS['lastDataTransferred'] = 0;
	$GLOBALS['lastDataTransferredSleep'] = 0;
	$GLOBALS['lastStringLength'] = -1;
	$GLOBALS['resumedFromBytes'] = $bytesTransferred;
	
	$GLOBALS['videoFilename'] = $filename;
	$GLOBALS['abortTransfer'] = FALSE;
}


function 	removeExisitngFile($videoFilename, $extension){
	
	$removeFilename = $videoFilename.$extension;

	if(file_exists($abortFilename) == TRUE){
		unlink($removeFilename);
	}	
}

?>
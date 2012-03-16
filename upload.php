<?php
/**
 * Will upload 1 or more images to a given album in SmugMug
 *
 * Released: Oct. 7 2009
 * Author: Dianoga (Brian Steere)
 * Site: http://3dgo.net
 * Email: dianoga7@3dgo.net
 **/

include('phpSmug/phpSmug.php');

$apiKey = '';
$username = '';		//Should be your email address
$password = '';

// Shouldn't have to change anything below here
$appInfo = '3dgoSmug/0.2';
$apiVersion = '1.2.2';

error_reporting(E_ALL & ~E_NOTICE);

if(count($argv) < 3){
	echo "Usage: php upload.php 'Album Title' pic1.jpg <pic2.jpg ...>\n";
	die();
}

$albumTitle = $argv[1];

$added = 0;
$mdUpdated = 0;
$replaced = 0;

echo "Preparing file information...";
for($i = 2; $i < count($argv); $i++){
	$uploads[] = prepare_file($argv[$i]);
}
echo "done\n";

try{
	echo "Connecting to SmugMug...";
	$smug = new phpSmug("APIKey={$apiKey}", "AppName={$appInfo}", "APIVer={$apiVersion}");
	$smug->login("EmailAddress={$username}", "Password={$password}");
	echo "done\n";

	echo "Finding album information...";
	$albums = $smug->albums_get("Heavy=1");

	// Check each album to find the one we want to work on
	foreach($albums as $current){
		if($current['Title'] == $albumTitle){
			$album = $current;
		}
	}

	if(empty($album)){
		echo "Unable to find album information\n";
		die();
	}else{
		echo "done\n";
	}

	if($album['ImageCount'] > 0){
		echo "Downloading image information from album...";
		$albumImages = $smug->images_get("AlbumID={$album['id']}", "AlbumKey={$album['Key']}", "Heavy=1");
		$albumImages = ($smug->APIVer == "1.2.2") ? $albumImages['Images'] : $albumImages;
		if(!empty($albumImages)){
			foreach($albumImages as $image){
				$images[$image['FileName']] = $image;
			}
		}
		echo "done\n";
	}else{
		echo "Album is currently empty\n";
	}

	foreach($uploads as $upload){
		// If an image with the same filename has already been uploaded
		if($images[$upload['filename']]){
			//Make life a lot easier
			$image = $images[$upload['filename']];

			// Check if the md5 is different
			if($image ['MD5Sum'] != $upload['md5']){
				echo "Replacing {$upload['filename']} (ID:{$image['id']})...";
				$smug->images_upload("AlbumID={$album['id']}", "File={$upload['path']}", "ImageID={$image['id']}");
				$replaced++;
				echo "done\n";

				// Replace the metadata if we need to
				if($image['Caption'] != $upload['caption'] || $image['Keywords'] != $upload['keywords']){
					echo "\tUpdating Metadata...";
					$smug->images_changeSettings("ImageID={$image['id']}", "Caption={$upload['caption']}", "Keywords={$upload['keywords']}");
					$mdUpdated++;
					echo "done\n";
				}
			}else{
				echo "Skipping {$upload['filename']}\n";
			}
		}else {
			echo "Adding {$upload['filename']}...";
			$result = $smug->images_upload("AlbumID={$album['id']}", "File={$upload['path']}");
			$added++;
			echo "done\n";
		}
	}

}catch(Exception $e){
	phpSmug::debug($e);
}

echo "\nSummary\n";
echo "=========================================\n";
echo "Album: {$album['Title']}\n";
echo "Images Added: {$added}\n";
echo "Images Replaced: {$replaced}\n";
echo "Metadata Updated: {$mdUpdated}\n";
echo "=========================================\n\n";

function prepare_file($path){
	$iptc = get_iptc_data($path);

	$file = array();
	$file['path'] = $path;
	$file['md5'] = md5_file($path);
	$file['filename'] = basename($path);

	if(!empty($iptc)){
		if(!empty($iptc['2#120'][0])){
			$file['caption'] = $iptc['2#120'][0];
		}

		if(!empty($iptc['2#025'])){
			$file['keywords'] = implode(', ', $iptc['2#025']);
		}
	}

	return $file;
}

function get_iptc_data( $image_path ) {
	$size = getimagesize ( $image_path, $info);
	if(is_array($info)) {
		$iptc = iptcparse($info["APP13"]);
		return $iptc;
	}
}

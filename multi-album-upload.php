<?php
/**
 * Will upload/replace an entire folder structure in SmugMug
 * Currently this will only handle images. One day I expect
 * to add the ability to upload videos, but it can't handle
 * it yet
 *
 * Point this script at a folder with the following layout
 * -Category
 *     -Album
 *          -image1.jpg
 *          -image2.jpg
 *     -Album2
 *          -image3.jpg
 * -Category2
 *     -Album3
 *          -image4.png
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

if(count($argv) < 2){
	echo "Usage: php multi-album-upload.php folder\n";
	die();
}

//Setup stat variables
$categoriesAdded = 0;
$albumsAdded = 0;
$filesAdded = 0;
$filesReplaced = 0;
$filesMDUpdated = 0;
$filesSkipped = 0;


//Begin processing things
echo "Determining file structure...";
$structure = get_local_structure($argv[1]);
echo "done\n";

try{
	echo "Connecting to SmugMug...";
	$smug = new phpSmug("APIKey={$apiKey}", "AppName={$appInfo}", "APIVer={$apiVersion}");
	$smug->login("EmailAddress={$username}", "Password={$password}");
	echo "done\n";

	echo "Fetching category list from server...";
	$categories = $smug->categories_get();
	echo "done\n";

	echo "Fetching album list from server...";
	$serverAlbums = $smug->albums_get("Heavy=1");
	echo "done\n";

	foreach($structure as $category=>$albums){
		echo "\nCategory: {$category}\n";

		//Check to see if the category already exists
		$serverCat = false;
		foreach($categories as $cat){
			if($cat['Name'] == $category){
				$serverCat = $cat;
				break;
			}
		}

		//If it doesn't we need to create it
		if(!$serverCat){
			echo "\tCreating category...";
			$serverCat['id'] = $smug->categories_create("Name={$category}");
			$serverCat['Name'] = $category;
			echo "done\n";
			$categoriesCreated++;
		}

		//Start working on albums
		foreach($albums as $album=>$files){
			echo "\tAlbum: {$album}\n";

			//Check if album exists
			$serverAlbum = false;
			foreach($serverAlbums as $sa){
				if($sa['Title'] == $album && $sa['Category']['id'] == $serverCat['id']){
					$serverAlbum = $sa;
					break;
				}
			}

			//Create the album if it doesn't exist
			if(!$serverAlbum){
				echo "\t\tCreating album...";
				$serverAlbum = $smug->albums_create("Title={$album}", "CategoryID={$serverCat['id']}");
				$serverAlbum = $smug->albums_getInfo("AlbumID={$serverAlbum['id']}", "AlbumKey={$serverAlbum['Key']}");
				echo "done\n";
				$albumsCreated++;
			}

			//Download image information for the album if it is available
			$images = array();
			if($serverAlbum['ImageCount'] > 0){
				echo "\t\tDownloading image information from album...";
				$albumImages = $smug->images_get("AlbumID={$serverAlbum['id']}", "AlbumKey={$serverAlbum['Key']}", "Heavy=1");
				$albumImages = ($smug->APIVer == "1.2.2") ? $albumImages['Images'] : $albumImages;
				if(!empty($albumImages)){
					foreach($albumImages as $image){
						$images[$image['FileName']] = $image;
					}
				}
				echo "done\n";
			}else{
				echo "\t\tAlbum is currently empty\n";
			}

			//Start dealing with files
			echo "\t\tPreparing files...";
			$uploads = array();
			foreach($files as $file){
				$uploads[] = prepare_file($file);
				$count++;
				if($count % 50 == 0){
					echo ".";
				}
			}
			echo "done\n";

			foreach($uploads as $upload){
				// If an image with the same filename has already been uploaded
				if($images[$upload['filename']]){
					//Make life a lot easier
					$image = $images[$upload['filename']];

					// Check if the md5 is different
					if($image ['MD5Sum'] != $upload['md5']){
						echo "\t\tReplacing {$upload['filename']} (ID:{$image['id']})...";
						$smug->images_upload("AlbumID={$serverAlbum['id']}", "File={$upload['path']}", "ImageID={$image['id']}");
						$filesReplaced++;
						echo "done\n";

						// Replace the metadata if we need to
						if($image['Caption'] != $upload['caption'] || $image['Keywords'] != $upload['keywords']){
							echo "\t\t\tUpdating Metadata...";
							$smug->images_changeSettings("ImageID={$image['id']}", "Caption={$upload['caption']}", "Keywords={$upload['keywords']}");
							$filesMDUpdated++;
							echo "done\n";
						}
					}else{
						echo "\t\tSkipping {$upload['filename']}\n";
						$filesSkipped++;
					}
				}else {
					echo "\t\tAdding {$upload['filename']}...";
					$smug->images_upload("AlbumID={$serverAlbum['id']}", "File={$upload['path']}");
					$filesAdded++;
					echo "done\n";
				}
			}

		}

	}

}catch(Exception $e){
	phpSmug::debug($e);
}

echo "\nSummary\n";
echo "=========================================\n";
echo "Categories Created: {$categoriesAdded}\n";
echo "Albums Created: {$albumsAdded}\n";
echo "Images Added: {$filesAdded}\n";
echo "Images Replaced: {$filesReplaced}\n";
echo "Metadata Updated: {$filesMDUpdated}\n";
echo "Files Skipped: {$filesSkipped}\n";
echo "=========================================\n\n";

function get_local_structure($path){
	$allowed_extensions = array('jpg', 'png', 'gif', 'jpeg', 'tiff', 'mp4');

	// Strip the trailing slash if necessary
	$path = rtrim($path, "/\\");

	// Setup the iterator. SELF_FIRST is critical here
	$dir_iterator = new RecursiveDirectoryIterator($path);
	$dir = new RecursiveIteratorIterator($dir_iterator, RecursiveIteratorIterator::SELF_FIRST);

	foreach($dir as $file){
		if($file->getBasename() == '.' || $file->getBasename() == '..') {
			continue;
		}
		else if($file->isDir()){
			$parent = $file->getPathInfo();

			// If the parent path is the base, we are dealing with a category
			if($parent->getPathname() == $path){
				$structure[$file->getBasename()] = array();
			}

			// If the parent path is a category, we have an album
			else if(is_array($structure[$parent->getBasename()])){
				$structure[$parent->getBasename()][$file->getBasename()] = array();
			}
		}else if($file->isFile()){
			$album = $file->getPathInfo();
			$category = $album->getPathInfo();
			$extension = pathinfo($file->getRealPath(), PATHINFO_EXTENSION);

			if(in_array(strtolower($extension), $allowed_extensions) && is_array($structure[$category->getBasename()][$album->getBasename()])){
				$structure[$category->getBasename()][$album->getBasename()][] = $file->getRealPath();
			}
		}
	}

	return($structure);
}

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


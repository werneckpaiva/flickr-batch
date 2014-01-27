#!/usr/local/bin/php
<?php

/**
  * Flickr batch uploader
  * The goal of this project is to allow users to upload an entire directory structure
  * to flickr <http://www.flicr.com>. 
  * Features:
  *    upload photos of one folder recursivelly;
  *    generate sets (albums) for each folder;
  *    add photo tags based on the file name;
  *    keep flickr repository up to date with the local directory;
  *	   change permission of all photos in a directory recursivelly;
  * 
  * @author: Ricardo Paiva <ricardo [at] werneckpaiva [dot] com [dot] br>
  * @version: 0.1.0
  * @license http://opensource.org/licenses/gpl-license.php GNU Public License
  */


// set_time_limit(0);

class FlickrClient {
	
	private $key;
	private $secret;
	private $frob;
	private $token;
	private $userid;
	private $verbose = false;
	private $debug = false;
	private $rootpath;

	private $photosets = null;
	
	private $baseURL = "http://www.flickr.com/photos/katiaericardo/collections/";

	const REST_URL="http://api.flickr.com/services/rest/?";
	const UPLOAD_URL="http://api.flickr.com/services/upload/";
	const FROB_URL="http://flickr.com/services/auth/?";

	const CONF_FILE="flickr.batch.conf";
	const SHORT_OPTS="f:s:k:t:c:u:p:v";

	
	public function __construct($opt){
		foreach ($opt as $k=>$v){
			$this->$k = $v;
		}
	}

	public function callFlickrMethod($method, $userParams=array(), $isSigned=false){
		$staticParams=array();
		$staticParams["method"]  = $method;
		$staticParams["api_key"] = $this->key;
		$staticParams["format"]  = "php_serial";
		
		$params = array_merge($staticParams, $userParams);
		if ($isSigned){
			ksort($params);
			$signing = '';
			foreach($params as $key => $value) {
			    $signing .= $key . $value;
			}
			$params["api_sig"] = md5($this->secret . $signing);
		}
		if ($this->debug) print_r($params);
		$encodedParams = array();
                foreach($params as $k => $v){
                        $encodedParams[] = urlencode($k)."=".urlencode($v);
                }
                $url=self::REST_URL.join("&", $encodedParams);
                $rawContent = file_get_contents($url);
                $content = unserialize($rawContent);
		if ($content["stat"] == "fail"){
			throw new Exception($content["message"]);
		}
		return $content;
	}

	public function uploadPhoto($file, $title, $tags="", $desc="", $public=false){
		if ($this->verbose) echo "flickr.upload: $file [$title] [$tags]";
		$params = array(
			"auth_token"  => $this->token,
			"title"     => $title,
			"tags"      => $tags,
			"description" => $desc,
			"is_public" => $public,
			"is_friend" => true,
			"is_family" => true,
			"api_key"   => $this->key,
			"format"    => "php_serial"
		);
		// ... compute a signature ...
		ksort($params);
		$signing = '';
		foreach($params as $key => $value) {
		    $signing .= $key . $value;
		}
		$params['api_sig'] = md5($this->secret . $signing);
		$params["photo"]="@".$file;
		$ch = curl_init();
		$url = self::UPLOAD_URL;
		 // set up the request
		curl_setopt($ch, CURLOPT_URL, $url);
		// make sure we submit this as a post
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
		// make sure problems are caught
		curl_setopt($ch, CURLOPT_FAILONERROR, 1);
		// return the output
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		// set the timeouts
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 300);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1200);
		$result = curl_exec($ch);
		// check for errors
		if (0 == curl_errno($ch)) {
		    curl_close($ch);
		    $result = str_replace("\n", "", $result);
		    $matches = array();
		    preg_match("/<photoid>([0-9]+)<\/photoid>/i", $result, $matches);
		    $id = $matches[1];
			echo "\n";
		    return $id;
		} else {
			echo "\n";
		    $ex = new Exception('Request failed. '.curl_error($ch));
			curl_close($ch);
		    throw $ex;
		}
	}

	public function getUserInfo(){
		if ($this->verbose) echo  "flickr.people.getInfo: ".$this->userid."\n";
		$content = $this->callFlickrMethod("flickr.people.getInfo", array("user_id"=>$this->userid), true);
		return $content["person"];
	}
	
	public function getFrob(){
		$content = $this->callFlickrMethod("flickr.auth.getFrob", array(), true);
		return $content["frob"]["_content"];
	}

	public function getToken(){
		$content = $this->callFlickrMethod("flickr.auth.getToken", array("frob"=>$this->frob), true);
		return $content["auth"]["token"]["_content"];
	}

	public function getURL($perm){
		$frob = $this->getFrob();
		$this->frob = $frob;
		$params = array(
			"api_key"=>$this->key,
			"frob"=>$frob,
			"perms"=>$perm
		);
		$signing = '';
		ksort($params);
                foreach($params as $key => $value) {
                    $signing .= $key . $value;
                }
		$params["api_sig"] = md5($this->secret . $signing);
		foreach($params as $k => $v){
                        $encodedParams[] = urlencode($k)."=".urlencode($v);
                }
                $url=self::FROB_URL.join("&", $encodedParams);
		return  $url; 
	}

	public function getUploadStatus(){
		$content = $this->callFlickrMethod("flickr.people.getUploadStatus", array("auth_token"=>$this->token), true);
		return $content["user"];
	}

	public function getPhotoInfo($photoId){
		$params = array(
			"auth_token"=>$this->token,
			'photo_id'=> $photoId
		);
		$content = $this->callFlickrMethod('flickr.photos.getInfo', $params, true);
		return $content["photo"];
	}
	
	public function setPhotoPerms($photoID, $isPublic, $isFriend, $isFamily, $permComment=3, $permAddMeta=2){
		//if ($this->verbose) echo  "flickr.photos.setPerms: $photoID public($isPublic) isFriend($isFriend) isFamily($isFamily)\n";
		$params = array(
			"auth_token"=>$this->token,
			'photo_id'=> $photoID,
			"is_public"=> $isPublic,
			"is_friend"=>$isFriend,
			"is_family"=>$isFamily,
			"perm_comment"=>$permComment,
			"perm_addmeta"=>$permAddMeta
		);
		$content = $this->callFlickrMethod('flickr.photos.setPerms', $params, true);
		return $content;
	}

	public function createPhotoset($title, $photoID, $desc=""){
		if ($this->verbose) echo  "flickr.photosets.create: $title\n";
		$params = array(
			"auth_token"=>$this->token,
			"title" => $title,
			"description" => $desc,
			"primary_photo_id" => $photoID
		);
		$content = $this->callFlickrMethod("flickr.photosets.create", $params, true);
		return $content["photoset"]["id"];
	}

	public function addFileToPhotoset($photosetID, $photoID){
		echo "flickr.photosets.addPhoto: photo $photoID -> set $photosetID \n";
		$params = array(
			"auth_token"=>$this->token,
			"photoset_id" => $photosetID,
			"photo_id" => $photoID
		);
		$content = $this->callFlickrMethod("flickr.photosets.addPhoto", $params, true);
	}

	public function getPhotosetList($userid){
		if ($this->verbose) echo  "flickr.photosets.getList: ";
		$params = array(
			"auth_token"=>$this->token,
			"user_id"=>$userid
		);
		$content = $this->callFlickrMethod("flickr.photosets.getList", $params, true);
		echo count($content["photosets"]["photoset"])." photosets\n";
        return($content["photosets"]["photoset"]);

	}

	public function getPhotosetPhotoList($photosetID, $full=false){
		if ($this->verbose) echo  "flickr.photosets.getPhotos: $photosetID ";
		$params = array(
			"auth_token"=>$this->token,
			"photoset_id" => $photosetID
		);
		$content = $this->callFlickrMethod("flickr.photosets.getPhotos", $params, true);
		if (!$full){
			echo "\n";
			return $content["photoset"]["photo"];
		}
		$photoList = array();
		foreach($content["photoset"]["photo"] as $photo){
			if ($this->verbose) echo ".";
			$photo = $this->getPhotoInfo($photo["id"]);
			$photoList[] = $photo;
		}
		if ($this->verbose) echo  "\n";
		return $photoList;
	}

	private function transformPath2Photoset($fullpath, $removeNumbers=false){
		$fullpath = preg_replace("/\/$/", "", $fullpath);
		$path = str_replace($this->rootpath, "", $fullpath);
		$path = preg_replace("/^\//", "", $path);
		if ($removeNumbers){
			$path = preg_replace("/\/[0-9]+_/", "/", $path);
		}
		$path = str_replace("_", " ", $path);
		$names = explode("/", $path);
		$photoset = implode(" / ", $names);
		return $photoset;
	}


	private function fixSetName($pathOriginal){
		$path = preg_replace("/\/ *\//", "/", $pathOriginal);
                if ($this->photosets == null){
                        $this->photosets = $this->getPhotosetList($this->userid);
                }
		$photosetName=$this->transformPath2Photoset($path);
		$oldPhotosetName=$this->transformPath2Photoset($path, true);
		if ($photosetName != $oldPhotosetName){
			$photoList = array();
			foreach ($this->photosets as $photoset){
				if ($photoset["title"]["_content"] == $oldPhotosetName){
					$photosetID = $photoset["id"];
					$params = array(
						"auth_token"=>$this->token,
						'photoset_id'=> $photosetID,
						"title"=> $photosetName
					);
					echo "$oldPhotosetName\n$photosetName\n\n";
					$content = $this->callFlickrMethod('flickr.photosets.editMeta', $params, true);
				}
			}
		}
		$dp = opendir($path);
                if (!$dp){
                        throw new Exception("Path '$path' could not be opened.");
                }
                $dirs=array();
		while (false !== ($file = readdir($dp))) {
                        // if ($file == "." || $file == "..") continue;
                        if (preg_match("/^\./", $file)){
                                continue;
                        }
			if (is_dir($path."/".$file)){
                                $dirs[] = $file;
			}
		}
		sort($dirs);
		foreach($dirs as $dir){
                        $realDir = $path."/".$dir;
                        $this->fixSetName($realDir);
                }
	}

	private function batchUpload($path, $public=false){
		$path = preg_replace("/\/ *\//", "/", $path);
		if ($this->photosets == null){
			$this->photosets = $this->getPhotosetList($this->userid);
		}
		$photosetName=$this->transformPath2Photoset($path);
		if ($this->verbose) echo  "flickr.batch.upload: $photosetName\n";
		$photosetID = 0;
		$photoList = array();
		foreach ($this->photosets as $photoset){
			if ($photoset["title"]["_content"] == $photosetName){
				$photosetID = $photoset["id"];
				$photoList = $this->getPhotosetPhotoList($photosetID, true);
				break;
			}
		}
		$dp = opendir($path);
		if (!$dp){
			throw new Exception("Path '$path' could not be opened.");
		}
		$files=array();
		$dirs=array();
		while (false !== ($file = readdir($dp))) {
			// if ($file == "." || $file == "..") continue;
			if (preg_match("/^\./", $file)){
				continue;
			}
            if (is_dir($path."/".$file)){
				$dirs[] = $file;
			} else if (preg_match("/\.jpg$/i", $file)){
					$files[] = $file;
			}
		}
		sort($dirs);
		sort($files);
		foreach($files as $file){
			$realFile = $path."/".$file;
			$md5 = md5_file($realFile);
			foreach($photoList as $photo){
			    $matches = array();
			    if (preg_match("/#$md5/", $photo["description"]["_content"])){
					continue 2;
			    }
			}
		 	$title = preg_replace("/\.jpg$/i", "", $file);
			$title = str_replace("_", " ", $title);
		    $tags = preg_replace("/ +[0-9]+$/", "", $title);
			try{
		    	$photoID = $this->uploadPhoto($realFile, $title, $tags, "#".$md5, $public);
			} catch (Exception $e){
				echo "Exception: ".$e->getMessage()."\n";
				if ($this->debug) print_r($e->getTrace());
			}
			if ($photosetID === 0){
			    try{
			        $photosetID = $this->createPhotoset($photosetName, $photoID);
			    } catch (Exception $e){
					echo "Exception: ".$e->getMessage()."\n";
					if ($this->debug) print_r($e->getTrace());
	            }
			} else {
			    try{
  			    	$this->addFileToPhotoset($photosetID, $photoID);
			    } catch (Exception $e){
					echo "Exception: ".$e->getMessage()."\n";
					if ($this->debug) print_r($e->getTrace());
			    }
			}
		}	
		unset($photoList);
		unset($files);
		foreach($dirs as $dir){
			$realDir = $path."/".$dir;
			$this->batchUpload($realDir);	
		}	
	}

	public function batchChangePerms($path, $recursive, $isPublic, $isFriend, $isFamily){
		$path = preg_replace("/\/ *\//", "/", $path);
		if ($this->photosets == null){
			$this->photosets = $this->getPhotosetList($this->userid);
		}
		$photosetName=$this->transformPath2Photoset($path);
		if ($this->verbose) echo "flickr.batch.changeperms: $photosetName\n";
		$photosetID = 0;
		$photoList = array();
		foreach ($this->photosets as $photoset){
			if ($photoset["title"]["_content"] == $photosetName){
				$photosetID = $photoset["id"];
				$photoList = $this->getPhotosetPhotoList($photosetID, false);
				break;
			}
		}
		if ($this->verbose && count($photoList) > 0){
			echo "flickr.photos.setPerms: ";
			$perms = array();
			if ($isPublic) $perms[] = "public";
			if ($isFriend) $perms[] = "friend";
			if ($isFamily) $perms[] = "family";
			echo "(".join(", ", $perms).") ";			
		}
		foreach ($photoList as $photo){
			$photoID = $photo["id"];
			try{
				$this->setPhotoPerms($photoID, $isPublic, $isFriend, $isFamily);
				if ($this->verbose) echo ".";
			} catch(Exception $e){
				if ($this->verbose) echo "x";
			}
		}
		if ($this->verbose && count($photoList) > 0) echo "\n";
		if (!$recursive){
			return;
		}
        $dp = opendir($path);
		if (!$dp){
			throw new Exception("Path '$path' could not be opened.");
		}
		$dirs=array();
		while (false !== ($file = readdir($dp))) {
			if ($file == "." || $file == "..") continue;
			if (is_dir($path."/".$file)){
				$dir = $path."/".$file;
				$this->batchChangePerms($dir, $recursive, $isPublic,$isFriend, $isFamily);
			}
		}
	}
	
	public function getCollectionNao($id=""){
		$collectionURL = substr($this->baseURL, strpos($this->baseURL, "/", 7));
		$setURL = substr($collectionURL, 0, strpos($collectionURL, "/collections"))."/sets/";
		$collectionURL = str_replace("/", "\/", $collectionURL);
		$setURL = str_replace("/", "\/", $setURL);
		
		$fifo = array();
		$fifo[]=$id;
		$result = array();
		
		do{
			$id = array_shift($fifo);
			if ($this->verbose) echo ".";
			$url = $this->baseURL;			
			if ($id != ""){
				$url.="/$id/";
			}
			$rawContent = file_get_contents($url);
			$rawContent = str_replace("\n", "", $rawContent);
			preg_match_all("/href=\"$collectionURL([0-9]+)\/\"\s+title=\"([^\"]+)\"/i", $rawContent, $collectionMatches);
			print_r($collectionMatches);
			die();
			preg_match_all("/href=\"$setURL([0-9]+)\/\"/i", $rawContent, $setsMatches);
			unset($rawContent);
			
			$sets = array();
			if (count($setsMatches[1])>0){
				$keys = array_unique($setsMatches[1]);
				foreach ($keys as $setID){
						$sets[]=array("id"=>$setID);
				}
			}
			
			if (count($collectionMatches[1]) > 0){
				$keys = array_unique($collectionMatches[1]);
				foreach ($keys as $i=> $collectionID){
					if (!isset($result[$collectionID])){
						array_push($fifo, $collectionID);
					}
				}
			}
			if ($id==""){ 
				$id="0";
			}
			$result[$id] = array(
							"id"=>$collectionID, 
							"url"=>$this->baseURL."$collectionID/",
							"sets"=>$sets);
		}while(count($fifo)>0);
		
		return $result;
	}
	
	public function getCollection($id=""){
		if ($this->verbose) echo ".";
		$url = $this->baseURL."collections/";
		$relativeUrl = substr($this->baseURL, strpos($this->baseURL, "/", 7));
		$collectionURL = str_replace("/", "\/", $relativeUrl."collections/");
		$setURL = str_replace("/", "\/", $relativeUrl."sets/");
		if ($id != ""){
			$url.="/$id/";
		}
		$rawContent = file_get_contents($url);
		$rawContent = str_replace("\n", "", $rawContent);
		preg_match_all("/href=\"$collectionURL([0-9]+)\/\"\s+title=\"([^\"]+)\"/i", $rawContent, $collectionMatches);
		preg_match_all("/href=\"$setURL([0-9]+)\/\"\s+title=\"([^\"]+)\"/i", $rawContent, $setsMatches);
		unset($rawContent);
		
		$sets = array();
		if (count($setsMatches[1])>0){
				foreach ($setsMatches[1] as $i=>$setID){
						$sets[]=array(
							"id"=>$setID,
							"name"=>$setsMatches[2][$i]);
				}
		}
		
		$collections = array();
		if (isset($collectionMatches[1]) && count($collectionMatches[1]) > 0){
			foreach ($collectionMatches[1] as $i=> $collectionID){
				if (!isset($collections[$collectionID])){
					$collections[$collectionID] = array(
						"id"=>$collectionID, 
						"name"=>$collectionMatches[2][$i]
					);
					$collections[$collectionID] = array_merge($collections[$collectionID], $this->getCollection($collectionID));
						
				}
			}
		}
		return array(
			"collections"=>$collections, 
			"sets"=>$sets);
	}
	
	public function getAllCollections(){
		$userInfo = $this->getUserInfo();
		$this->baseURL = $userInfo["photosurl"]["_content"];
		if ($this->verbose) echo "flickr.collections.getAll ";
		$result = $this->getCollection();
		echo "\n";
		return array($result);
	}

	public static function showHelp($args){
		echo "Usage ".$args[0]." <command> [options]\n";
		echo "Commands:\n";
		echo "  upload <folder>\t Batch upload an entire folder\n";
		echo "  perms <perm> <folder>\t Change permission of an entire folder\n";
		echo "\t\t\t octal: 4(public) 2(friend) 1(family) ex: 3 (friend, family)\n";
		echo "  auth\t\t\t Generate flickr URL to control access to the user data\n";
		echo "  token\t\t\t Get Authentication token (frob is required)\n";
		echo "  userinfo\t\t\tShow user info\n";
		echo "  collections\t\t\tShow a list of collection with sets\n";
		echo "  status\n";
		echo "Options:\n";
		echo "  -k <key>\t\t Set api key\n";
		echo "  -s <secret>\t\t Set api secret\n";
		echo "  -f <frob>\t\t Set frob\n";
		echo "  -t <token>\t\t Set authentication token\n";
		echo "  -u <token>\t\t Flickr user id\n";
		echo "  -p <rootPath>\t\t Photos dir root path - used to create set (album) names\n";
		echo "  -c <file>\t\t Set configuration file\n";
		echo "  -v \t\t\t Verbose\n";
		echo "To get api key, go to http://www.flickr.com/services/api/keys/\n";
	}

        public function printAllSets(){
          	$this->photosets = $this->getPhotosetList($this->userid);
		foreach ($this->photosets as $photoset){
			echo ($photoset["title"]["_content"])."\n";
                }
  		// sort($this->photosets);
	}


	public static function display($var, $deep=0){
		if (is_array($var)){
			foreach($var as $k=>$v){
				echo str_repeat("\t", $deep);
				echo "$k: ";
				if (is_array($v)){
					if ( isset( $v["_content"] )){
						echo $v["_content"]."\n";
					} else {
						echo "\n";
						self::display($v, $deep + 1); 
					}
				} else {
					echo "$v\n";
				}
			}
		} else {
			echo str_repeat("\t", $deep);
			echo $var;
		}
	}

	public static function main($args){
		if (count($args) < 2){
			self::showHelp($args);
			exit(1);
		}	

		$options = getopt(self::SHORT_OPTS);

		if (isset($options["c"])){
			$confFile=$options["c"];
		} else {
			$confFile=self::CONF_FILE;
		}
		$conf=array();
		if (is_readable($confFile)){
			$conf = parse_ini_file($confFile);
		}
		if (isset($options["k"])){
			$conf["key"]=$options["k"];
		}
		if (isset($options["s"])){
			$conf["secret"]=$options["s"];
		}
		if (isset($options["f"])){
			$conf["frob"]=$options["f"];
		}
		if (isset($options["t"])){
			$conf["token"]=$options["t"];
		}
		if (isset($options["u"])){
			$conf["userid"]=$options["u"];
		}
		if (isset($options["p"])){
			$conf["rootpath"]=$options["p"];
		}
		if (isset($options["v"])){
			$conf["verbose"]=1;
		}
		$flickrClient = new FlickrClient($conf);
		try{	
			switch(strtolower($args[1])){
				case "upload":
					$path = $args[count($args)-1];
					if (!is_dir($path)){
						throw new Exception("$path is not a directory");
					}
					$flickrClient->batchUpload($path, true);
					break;
				case "perms":
					$perms = $args[count($args)-2];
					if (!is_numeric($perms)){
						throw new Exception("$perms is not valid. Use octal {4(public) 2(friend) 1(family)}");
					}
					$path = $args[count($args)-1];
					if (!is_dir($path)){
						throw new Exception("$path is not a directory");
					}
					$isPublic = $perms & 4;
					$isFriend = $perms & 2;
					$isFamily = $perms & 1;
					$flickrClient->batchChangePerms($path, true, $isPublic, $isFriend, $isFamily);
					break;
				case "fix":
					$path = $args[count($args)-1];
					if (!is_dir($path)){
                                                throw new Exception("$path is not a directory");
                                        }
					$flickrClient->fixSetName($path);
					break;
				case "userinfo":
					if (strlen($conf["userid"]) < 5){
						throw new Exception("Missing userid");
					}
					self::display($flickrClient->getUserInfo());
					break;
				case "photoinfo":
					$photoID = $args[count($args)-1];
					if (!is_numeric($photoID)){
						throw new Exception("$photoID is not a valid photoID.");
					}
					self::display($flickrClient->getPhotoInfo($photoID));
					break;
				case "photosetinfo":
					$photosetID = $args[count($args)-1];
                                        if (!is_numeric($photosetID)){
                                                throw new Exception("$photosetID is not a valid photoID.");
                                        }
					self::display($flickrClient->getPhotosetPhotoList($photosetID, true));
					break;
				case "auth":
					if (strlen($conf["key"]) < 32){
						throw new Exception("Missing API key");
					}
					if (strlen($conf["secret"]) < 16){
						throw new Exception("Missing Secret");
					}
					echo "Open this URL in a web browser:\n".$flickrClient->getURL("write")."\n\n";
					echo "Then run:\n${args[0]} token -f ".$flickrClient->frob." -k ${conf['key']} -s <secret>\n";
					break;
				case "token":
					if (!isset($conf["frob"])){
						throw new Exception("Missing frob");
					}
					echo "Authentication token:\t".$flickrClient->getToken()."\n";
					echo "Add this token to the configuration file or set as command line parameter (-t)\n";
					break;
				case "status":
					self::display($flickrClient->getUploadStatus());
					break;
				case "collections":
					self::display($flickrClient->getAllCollections());
					break;
				case "sets":
					$flickrClient->printAllSets();
					break;
				default:
					self::showHelp($args);
					exit(1);
			}
		}catch(Exception $e){
			echo $e->getMessage()."\n";
			exit(1);
		}
	}
}

FlickrClient::main($argv);
exit(0);
?>

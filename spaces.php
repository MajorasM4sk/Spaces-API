<?php
/*
Makes interacting with DigitalOcean Spaces super easy.
*/



/*
A helper function just to tidy everything up a bit.
*/
function Spaces($accessKey, $secretKey, $host = "digitaloceanspaces.com") { return new Spaces($accessKey, $secretKey, $host); }



/*
We're all Devs here, right?
If not, this creates a class that can be called to connect to your Spaces instaces.
*/
class SpacesConnect {
  private $accessKey;
  private $secretKey;
  private $host;



  /*
  Takes your secrets.
  */
  function __construct($accessKey, $secretKey, $host = "digitaloceanspaces.com") {
    $this->accessKey = $accessKey;
    $this->secretKey = $secretKey;
    $this->host = $host;

    //Load the underlying AWS class.
    if(!class_exists("Aws\S3\S3Client")) { require_once(dirname(__FILE__)."/aws/aws-autoloader.php"); }

    //Create an S3 instance.
    $this->s3 = new \Aws\S3\S3Client([
      "version" => "2006-03-01",
      "region" => "us-east-1",
      "endpoint" => "https://ams3.".$host,
      "credentials" => ["key" => $accessKey, "secret" => $secretKey],
      "ua_append" => "SociallyDev-Spaces-API/2"
    ]);
  }



  /*
  Lists all your Spaces.
  */
  function listSpaces() {
    return SpacesResult($this->s3->listBuckets())["Buckets"];
  }



  /*
  Creates a single Space instance.
  Automatically figures out the region if not provided.
  */
  function space($name, $region = "ams3") {
    return new Space($name, $region, $this->accessKey, $this->secretKey, $this->host);
  }



  /*
  Creates a new Space & returns a Space instance.
  */
  function create($name, $region, $privacy = "private") {
    $space = $this->name($name, $region);
    $space->create($privacy);
    return $space;
  }
}



/*
Handles single Space operations.
*/
class Space {



  /*
  Stores the arguments needed.
  Also creates another S3 instance for this region.
  */
  function __construct($name, $region, $accessKey, $secretKey, $host = "digitaloceanspaces.com") {
    $this->name = $name;
    //$this->region = $region;
    $this->s3 = new \Aws\S3\S3Client([
      "version" => "2006-03-01",
      "region" => $region,
      "endpoint" => "https://nyc3.digitaloceanspaces.com/",
      "credentials" => ["key" => $accessKey, "secret" => $secretKey],
      "ua_append" => "SociallyDev-Spaces-API/2"
    ]);
  }



  /*
  Creates this Space.
  */
  function create($privacy = "private") {
    if($privacy == "public") { $privacy = "public-read"; }

    return $this->s3->createBucket([
      "ACL" => $privacy,
      "Bucket" => $this->name
    ])->toArray()["@metadata"]["effectiveUri"];
  }



  /*
  Deletes this Space.
  */
  function destroy() {
    $this->s3->deleteMatchingObjects($this->name, "", "(.*?)");
    $this->s3->deleteBucket(["Bucket" => $this->name]);
    return true;
  }



  /*
  Downloads entire Space to a directory.
  */
  function downloadToDirectory($directory, $filesStartingAs = "") {
    $this->s3->downloadBucket($directory, $this->name, $filesStartingAs);
    return true;
  }



  /*
  Uploads an entire directory to Space.
  */
  function uploadDirectory($directory, $prependPrefix = "") {
    $this->s3->uploadDirectory($directory, $this->name, $prependPrefix);
    return true;
  }



  /*
  Uploads text.
  */
  function upload($text, $saveAs, $privacy = "private") {
    if($privacy == "public") { $privacy = "public-read"; }

    return SpacesResult($this->s3->upload($this->name, $saveAs, $text, $privacy));
  }



  /*
  Uploads a file.
  */
  function UploadFile($pathToFile, $access = "private", $save_as = "", $mime_type = "application/octet-stream") {
    if(empty($saveAs)) { $saveAs = $filePath; }

    $content = fopen($pathToFile, "r");
    
    $result = $this->s3->putObject(array(
      'Bucket'      => $this->name,
      'Key'         => $save_as,
      'Body'        => $content,
      'ACL'         => $access,
      'ContentType' => $mime_type
    ));

    $this->s3->waitUntil('ObjectExists', array(
        'Bucket' => $this->name,
        'Key'    => $save_as
    ));

    fclose($content);
    return SpacesResult($result);
  }

  /**
   * Copies an object to a destination and deletes the source object.
   * @return result of the CopyObject method
  */
  function Move($sourceKey, $destinationKey) {
    $result = $this->s3->copyObject([
      'Bucket'     => $this->name,
      'Key'        => $destinationKey,
      'CopySource' => $this->name.'/'.$sourceKey,
    ]);

    $this->s3->waitUntil('ObjectExists', array(
      'Bucket' => $this->name,
      'Key'    => $destinationKey
    ));
    $this->DeleteObject($sourceKey);
    return $this->ObjReturn($result->toArray());
  }


  /*
  Downloads a file.
  */
  function downloadFile($file, $saveTo = false) {
    if(!$saveTo) {
      //Directly return file content.
      return $this->s3->getObject(["Bucket" => $this->name, "Key" => $file])["Body"]->getContents();
    }
    //Save to a file on disk.
    return SpacesResult($this->s3->getObject(["Bucket" => $this->name, "Key" => $file, "SaveAs" => $saveTo]));
  }



  /*
  Copies a file.
  */
  function copyFile($filePath, $saveAs, $toSpace = "", $privacy = "private") {
    if(!$toSpace) { $toSpace = $this->name; }
    if($privacy == "public") { $privacy = "public-read"; }

    return SpacesResult($this->s3->copy($this->name, $filePath, $toSpace, $saveAs, $privacy));
  }



  /*
  Lists all files.
  */
  function listFiles($ofFolder = "", $autoIterate = true, $continueAfter = null) {
    $data = $this->s3->listObjectsV2(["Bucket" => $this->name, "Prefix" => $ofFolder, "MaxKeys" => 1000, "FetchOwner" => false, "ContinuationToken" => $continueAfter]);
    $result = SpacesResult($data);
    if($autoIterate && $data["NextContinuationToken"]) {
      $result["Contents"] = array_merge($result["Contents"], $this->listFiles($ofFolder, true, $data["NextContinuationToken"]));
    }

    if($autoIterate) { return $result["Contents"]; }
    return $result;
  }


  /*
    Lists all objects.
  */
  function ListObjects($of_directory = "") {
    $objects = $this->s3->getIterator('ListObjects', array(
        'Bucket' => $this->name,
        "Prefix" => $of_directory,
    ));
    $objectArray = array();
    foreach ($objects as $object) {
      $objectArray[] = $object;
    }
    return $this->ObjReturn($objectArray);      
  }


  /*
    INTERNAL FUNCTION - Returns a standardized object.
  */
  function ObjReturn($return) {
    $return = @json_decode(@json_encode($return), true);
    $return = $this->AWSTime($return);
    return $return;
  }


  /*
    INTERNAL FUNCTION - Converts all AWS time values to unix timestamps.
  */
  function AWSTime($obj) {
    $time_keys = ["LastModified", "CreationDate", "Expires", "last-modified", "date", "Expiration"];
    if(is_array($obj)) {
      foreach ($obj as $key => $value) {
        if(is_array($obj[$key])) {
          $obj[$key] = $this->AWSTime($obj[$key]);
        }
        else {
          foreach ($time_keys as $time_key) {
            if(array_key_exists($time_key, $obj) && !empty($obj[$time_key]) && !is_numeric($obj[$time_key])) {
                $obj[$time_key] = strtotime($obj[$time_key]);
            }
          }
        }
      }
    }
    return $obj;
  }


  /*
    Fetches an object's details.
  */
  function GetObject($file_name = "") {
    $result = $this->s3->getObject([
      'Bucket' => $this->name,
      'Key' => $file_name,
    ]);
    return $this->ObjReturn($result->toArray());
  }


  /*
  Checks if a file exists.
  */
  function fileExists($path) {
    return $this->s3->doesObjectExist($this->name, $path);
  }



  /*
  Gets info on a file.
  */
  function fileInfo($path) {
    return SpacesResult($this->s3->headObject([
      "Bucket" => $this->name,
      "Key" => $path
    ]));
  }



  /*
  Creates a non-signed view/download URL.
  */
  function url($path) {
    return $this->s3->getObjectUrl($this->name, $path);
  }



  /*
  Creates a signed view/download URL.
  */
  function signedURL($path, $validFor = "15 minutes") {
    return (string) $this->s3->createPresignedRequest($this->s3->getCommand("GetObject", ["Bucket" => $this->name, "Key" => $path]), $validFor)->getUri();
  }



  /*
  Deletes multiple files.
  */
  function deleteFolder($prefixOrPath) {
    $this->s3->deleteMatchingObjects($this->name, $prefixOrPath);
    return true;
  }



  /*
  Deletes a single file.
  */
  function deleteFile($path) {
    $this->s3->deleteObject([
      "Bucket" => $this->name,
      "Key" => $path
    ]);
    return true;
  }


  /*
    Deletes an object.
  */
  function DeleteObject($file_path = "", $recursive = false) {
    if ($recursive) return $this->ObjReturn($this->s3->deleteMatchingObjects(
        $this->name,
        $file_path
      ));
      return $this->ObjReturn($this->s3->deleteObject([
      'Bucket' => $this->name,
      'Key' => $file_path,
      ])->toArray());
  }


  /*
  Changes a file's ACL (Privacy).
  */
  function filePrivacy($file, $privacy) {
    if($privacy == "public") { $privacy = "public-read"; }
    $this->s3->putObjectAcl(["Bucket" => $this->name, "Key" => $file, "ACL" => $privacy]);
    return true;
  }



  /*
  Changes this Space's ACL (Privacy).
  */
  function privacy($privacy) {
    if($privacy == "public") { $privacy = "public-read"; }
    $this->s3->putBucketAcl(["Bucket" => $this->name, "ACL" => $privacy]);
    return true;
  }



  /*
  Gets this Space's CORS rules.
  */
  function getCORS() {
    return SpacesResult($this->s3->getBucketCors(["Bucket" => $this->name]))["CORSRules"];
  }



  /*
  Sets this Space's CORS rules.
  */
  function setCORS($rules = []) {
    foreach ($rules as $ogKey => $rule) {
      foreach ($rule as $key => $value) {
        if($key == "headers") { $rules[$ogKey]["AllowedHeaders"] = $value; }
        else if($key == "methods") { $rules[$ogKey]["AllowedMethods"] = $value; }
        else if($key == "origins") { $rules[$ogKey]["AllowedOrigins"] = $value; }
        else if($key == "exposeHeaders" || $key == "expose") { $rules[$ogKey]["ExposeHeaders"] = $value; }
        else if($key == "max") { $rules[$ogKey]["MaxAgeSeconds"] = $value; }
      }
    }

    $this->s3->putBucketCors(["Bucket" => $this->name, "CORSConfiguration" => ["CORSRules" => $rules]]);
    return true;
  }



  /*
  Gets bucket lifecycle rules.
  */
  function getLifecycleRules() {
    return $this->s3->getBucketLifecycle(["Bucket" => $this->name]);
  }



  /*
  Sets bucket lifecycle rules.
  NOTE: Currently not used as it kept throwing "MalformedXML error".
  */
  function setLifecycleRules($rules) {
    return $this->s3->putBucketLifecycle(["Bucket" => $this->name, "LifecycleConfiguration" => ["Rules" => $rules]]);
  }

}



/*
Handles creating a standard result.
*/
function SpacesResult($data) {
  if(gettype($data) == "object" && get_class($data) == "Aws\Result") { $data = $data->toArray(); }
  foreach ($data as $key => $value) {
    if(is_array($value)) {
      $data[$key] = SpacesResult($value);
      continue;
    }
    if(gettype($value) == "object" && get_class($value) == "Aws\Api\DateTimeResult") {
      $data[$key] = strtotime($value);
    }
  }
  return $data;
}
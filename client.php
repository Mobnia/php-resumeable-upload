#!/usr/bin/env php
<?php
/**
 * Created by PhpStorm.
 * User: teliov
 * Date: 8/11/16
 * Time: 5:47 PM
 */

function initUpload($path, $options){
    $ch = curl_init($options["url"]);

    curl_setopt($ch, CURLOPT_HEADER, [
        "Content-Type: application/json"
    ]);

    $data = [
        "filename" => pathinfo($path, PATHINFO_BASENAME),
        "file_size" => filesize($path),
        "chunk_size" => $options["chunk_size"],
        "checksum" => md5_file($path)
    ];

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));


    $response = curl_exec($ch);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $exec = substr($response, $header_size);

    $json = json_decode($exec, true);
    if (!$json || isset($json['error'])){
        $message = isset($json['error']) ? $json['error'] : "Error Connecting to Upload Server";
        throw  new Exception($message);
    }

    return $json;
}

function initResume($path, $id, $options){
    $url = rtrim($options['url'], "/") . "/" . $id;
    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_HEADER, [
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");

    $response = curl_exec($ch);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $exec = substr($response, $header_size);

    $json = json_decode($exec, true);
    if (!$json || isset($json['error'])){
        $message = isset($json['error']) ? $json['error'] : "Error Connecting to Upload Server";
        throw  new Exception($message);
    }

    $data = $json['data'];

    if (md5_file($path) != $data["checksum"]) {
        throw new Exception("File signature does not match server records");
    }

    return $json;
}

function uploadChunk($file, $chunkStart, $chunkEnd, $id, $fileSize, $options){

    fseek($file, $chunkStart-$fileSize, SEEK_END);
    $chunkSize = (($diff = $chunkEnd-$chunkStart) < 10240) ? 10240 : $diff;
    $chunk = fread($file, $chunkSize);

    $rangeHeader = implode(" ", ["bytes", implode("-", [$chunkStart, $chunkEnd, $fileSize])]);


    $url = rtrim($options['url'], "/") . "/" . $id;
    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/octet-stream",
        "Content-Range: " . $rangeHeader
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $chunk);


    $exec = curl_exec($ch);

    $json = json_decode($exec, true);
    if (!$json || isset($json['error'])){
        $message = isset($json['error']) ? $json['error'] : "Error Connecting to Upload Server";
        throw  new Exception($message);
    }

    return $json;
}

if (!count(debug_backtrace())) {
    $shortopts = "f:hr::c::";
    $longopts = ["help", "file::"];
    $options = getopt($shortopts, $longopts);

    if (isset($options["h"]) || isset($options["help"])) {
        echo "OPTIONS\n";
        echo "--help | -h Prints this help message\n";
        echo "--file | -f Specify file to upload\n";
        echo "-r Specify id of file to resume\n";
        exit();
    }

    if (!isset($options["f"]) && !isset($options["file"])) {
        echo "\nNo File Supplied\n";
        exit();
    }

    $filename = isset($options["f"]) ? $options["f"] : $options["file"];

    $path = realpath($filename);

    if (!file_exists($path) || !is_file($filename)) {
        echo "\nFile either does not exist or is a regular file\n";
        exit();
    }

    $uploadOptions = ["url" => "http://localhost:8081/uploads", "chunk_size" => 1024*1000];


    try {
        if (isset($options["r"]) && ($id=$options["r"])) {
            $uploadData = initResume($path, $id, $uploadOptions)["data"];
        } else {
            $uploadData = initUpload($path, $uploadOptions)["data"];
        }
    } catch (Exception $e) {
        echo $e->getMessage()."\n";
        exit();
    }

    if (isset($uploadData["completed_at"]) && $uploadData["completed_at"]) {
        echo "\nUpload has already been completed\n";
        exit();
    }


    $doUpload = true;
    $fileSize = filesize($path);
    $file = fopen($path, "r");

    echo("\nConnected to Uploader Server\n");

    while (true) {
        try {
            $uploadData = uploadChunk($file, $uploadData["chunk_start"],
                $uploadData["chunk_end"], $uploadData["id"], $fileSize, $uploadOptions)["data"];
        } catch (Exception $e) {
            echo $e->getMessage()."\n";
            break;
        }

        if ($uploadData["completed_at"] && $uploadData["completed_at"] != ""){
            echo "\nUpload Completed\n";
            break;
        }
    }

    exit();
}
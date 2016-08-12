<?php
/**
 * Created by PhpStorm.
 * User: teliov
 * Date: 8/10/16
 * Time: 4:32 PM
 */

require_once dirname(__FILE__)."/vendor/autoload.php";

$writeChunk = function($filename, $chunk){
    $UPLOAD_DIR = dirname(__FILE__)."/uploads_dir/";
    if (!is_dir($UPLOAD_DIR)){
        mkdir($UPLOAD_DIR, 0777, true);
    }
    $status = false;
    $file = fopen($UPLOAD_DIR.$filename, "a");
    if (!$file) return $status;

    if (!flock($file, LOCK_EX)) {
        return $status;
    }

    $status = fwrite($file, $chunk);
    fclose($file);
    return $status;
};

$validateChunk = function($upload, $detailsString){
    $details = explode(" ", $detailsString);
    if (count($details) != 2) return false;

    $rangeParts = explode("-", $details[1]);

    if (count($rangeParts) != 3) return false;

    if ($rangeParts[0] != $upload['chunk_start']) return false;

    if ($rangeParts[1] != $upload['chunk_end']) return false;

    if ($rangeParts[2] != $upload['file_size']) return false;

    return $rangeParts;
};

$errors = [
    "invalid_data" => "Request Contains invalid parameters",
    "sql_error" => "Error Querying the Database",
    "not_found" => "Upload not found",
    "upload_complete" => "Upload completed",
    "invalid_content_range" => "Invalid details in content range"
];

$createDB = function() {
    $sql = "create table uploads (id INTEGER PRIMARY KEY AUTOINCREMENT, filename VARCHAR(255), file_size VARCHAR(20),
chunk_size VARCHAR(20), local_filename VARCHAR(255), chunk_start VARCHAR(20), chunk_end VARCHAR(20), checksum VARCHAR(33), created_at TEXT,
updated_at TEXT DEFAULT NULL, completed_at TEXT DEFAULT NULL, url TEXT DEFAULT NULL);";
    $db = new SQLite3("uploads");
    $db->exec($sql);
    return $db;
};

if (!file_exists("uploads")){
    $db = $createDB();
} else {
    $db = new SQLite3("uploads");
}

$klein = new \Klein\Klein();

$klein->respond('POST', '/uploads/?', function($request, $response) use ($db, $errors){
    $data = json_decode($request->body(), true);
    if (!isset($data['filename']) || !isset($data['file_size']) || !isset($data['chunk_size']) || !isset($data['checksum'])) {
        $response->code(400);
        return $response->json([
            "error" => $errors['invalid_data']
        ]);
    }

    $ext = pathinfo($data["filename"], PATHINFO_EXTENSION);
    $uuid = uniqid("mobnia", true);
    $localFilename =  $ext ? $uuid . "." . $ext : $uuid;
    $next = ($data['chunk_size'] > $data['file_size']) ? $data['file_size'] : $data['chunk_size'];

    $obj = [
        "filename" => $data["filename"],
        "file_size" => $data["file_size"],
        "chunk_size" => $data['chunk_size'],
        "checksum" => $data["checksum"],
        "local_filename" => $localFilename,
        "chunk_start" => 0,
        "chunk_end" => $next,
        "created_at" => (new \DateTime())->format(\DateTime::ISO8601)
    ];
    $sql1 = "(";
    $sql2 = "(";
    foreach (array_keys($obj) as $key){
        $sql1 .= $key.",";
        $sql2 .= ":".$key.",";
    }
    $sql1 = rtrim($sql1, ",") . ")";
    $sql2 = rtrim($sql2, ",") . ")";

    $sql = "insert into uploads ".$sql1." values ".$sql2;
    $stmt = $db->prepare($sql);
    foreach ($obj as $key => $value) {
        $stmt->bindValue(":".$key, $value);
    }

    $ok = $stmt->execute();
    if (!$ok) {
        $response->code(500);
        return $response->json([
            "error" => $errors['sql_error']
        ]);
    }

    $id = $db->lastInsertRowID();
    $result = $db->querySingle("select * from uploads where id=$id", true);

    return $response->json(["data" =>$result]);
});

$klein->respond('GET', '/uploads/?', function($request, $response) use($errors, $db){
    $data = [];
    $res = $db->query("select * from uploads");
    while(($item = $res->fetchArray())){
        $data[] = $item;
    }
    return $response->json(["data" => $data]);
});

$klein->respond('PUT', '/uploads/[:id]/?', function($request, $response) use ($errors, $db, $validateChunk, $writeChunk){
    $headers = $request->headers()->all();
    if (!isset($headers['content-range'])) {
        $response->code(400);
        return $response->json([
            "error" => $errors['invalid_data']
        ]);
    }

    $id = $request->id;
    $upload = $db->querySingle("select * from uploads where id=$id", true);
    if ($upload === false) {
        $response->code(500);
        return $response->json($errors['sql_error']);
    } elseif ($upload === null || count($upload) == 0) {
        $response->code(404);
        return $response->json([
            "error" => $errors['not_found']
        ]);
    }

    if (isset($upload['completed_at']) && $upload['completed_at']) {
        $response->code(400);
        return $response->json([
            "error" => $errors['upload_complete']
        ]);
    }

    if (!($rangeParts = $validateChunk($upload, trim($headers['content-range'])))) {
        $response->code(400);
        return $response->json([
            "error" => $errors['invalid_content_range']
        ]);
    }

    $bytes = $writeChunk($upload["local_filename"], $request->body());

    $chunk_start = $upload['chunk_end'];
    $chunk_end = $upload['chunk_end'] + $bytes;
    if ($chunk_end > $upload['file_size']) $chunk_end = $upload['file_size'];

    $upload['chunk_start'] = $chunk_start;
    $upload['chunk_end'] = $chunk_end;

    $upload['updated_at'] = (new \DateTime())->format("Y-m-d H:i:s");

    if ($upload['chunk_end'] ==  $upload['chunk_start']) {
        $upload['completed_at'] = $upload['updated_at'];
    }

    unset($upload["id"]);
    $sql = "update uploads set ";
    foreach (array_keys($upload) as $key) {
        $sql .= $key."=:".$key.",";
    }
    $sql = rtrim($sql, ",");
    $sql .= " where id=:id";
    $stmt = $db->prepare($sql);
    foreach ($upload as $key => $value) {
        $stmt->bindValue(":".$key, $value);
    }
    $stmt->bindValue(":id", $id);

    $ok = $stmt->execute();
    if (!$ok) {
        //this should not happen
        $response->code(500);
        return $response->json([
            "error" => $errors['sql_error']
        ]);
    }

    $result = $db->querySingle("select * from uploads where id=$id", true);
    return $response->json(["data" =>$result]);
});

$klein->respond('GET', '/uploads/[:id]/?', function($request, $response) use ($errors, $db){
    $id = $request->id;
    $data = $db->querySingle("select * from uploads where id=$id", true);
    if ($data === false) {
        $response->code(500);
        return $response->json([
            "error" => $errors['sql_error']
        ]);
    } elseif ($data === null || count($data) == 0) {
        $response->code(404);
        return $response->json([
            "error" => $errors['not_found']
        ]);
    }

    return $response->json(["data" =>$data]);
});

$klein->respond('DELETE', '/uploads/[:id]/?', function($request, $response) use ($errors, $db){
    $id = $request->id;
    $ok = $db->exec("delete from uploads where id=$id");
    if ($ok) {
        return $response->json("ok");
    }else {
        $response->code(500);
        return $response->json([
            "error" => $errors['sql_error']
        ]);
    }
});

$klein->onHttpError(function($code, $obj){
    switch ($code) {
        case "404":
            $obj->response()->json(["error" => "Route not defined"]);
            break;
        case "405":
            $obj->response()->json(["error"=> "Method not allowed"]);
    }

    return;
});

$klein->dispatch();
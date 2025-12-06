<?php
// add_song.php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

// include DB connection; must set $conn (mysqli)
include 'database.php';
if (!isset($conn) || !$conn) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database connection missing"]);
    exit;
}

// helpers
function clean($v) { return is_string($v) ? trim($v) : $v; }
function respond($code, $payload) {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

// read input (json or form)
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$input = [];
if (strpos($contentType, 'application/json') !== false) {
    $raw = file_get_contents("php://input");
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $input = $decoded;
} else {
    $input = $_POST;
}

// normalize getter (camelCase or snake_case)
function getv($input, $keys, $default = null) {
    foreach ($keys as $k) {
        if (is_array($input) && array_key_exists($k, $input)) return $input[$k];
    }
    return $default;
}

// file upload dirs
$uploadDirs = [
  'image' => __DIR__ . '/public/img/',
  'audio' => __DIR__ . '/public/mp3/',
  'lyric' => __DIR__ . '/lyrics/',
];
foreach ($uploadDirs as $d) {
  if (!file_exists($d)) mkdir($d, 0755, true);
}

// handle uploaded file or base64 or filename (đã fix đường dẫn & tên file)
function handleFile($files, $fieldName, $fallback, $dirKey) {
    // nếu fieldName hoặc fallback là mảng -> lấy phần tử đầu tiên
    if (is_array($fieldName)) {
        $fieldName = reset($fieldName);
    }
    if (is_array($fallback)) {
        $fallback = reset($fallback);
    }

    // map thư mục đúng cấu trúc
    $dirMap = [
        'image' => __DIR__ . '/public/img/',
        'audio' => __DIR__ . '/public/mp3/',
        'lyric' => __DIR__ . '/lyrics/', 
    ];

    $dir = $dirMap[$dirKey] ?? __DIR__ . '/public/tmp/';
    if (!file_exists($dir)) mkdir($dir, 0755, true);

    // 1. real uploaded file
    if (!empty($files[$fieldName]) && !empty($files[$fieldName]['tmp_name']) && is_uploaded_file($files[$fieldName]['tmp_name'])) {
        $name = basename($files[$fieldName]['name']);
        $target = $dir . uniqid() . '_' . $name;
        move_uploaded_file($files[$fieldName]['tmp_name'], $target);
        return str_replace(__DIR__, '', $target);
    }

    // 2. fallback là base64
    if (is_string($fallback) && preg_match('/^data:.*base64,/', $fallback)) {
        $ext = ($dirKey === 'image') ? '.jpg' : (($dirKey === 'audio') ? '.mp3' : '.txt');
        $data = base64_decode(preg_replace('/^data:.*base64,/', '', $fallback));
        $target = $dir . uniqid() . $ext;
        file_put_contents($target, $data);
        return str_replace(__DIR__, '', $target);
    }

    // 3. fallback là file path tương đối
    if (is_string($fallback) && file_exists(__DIR__ . $fallback)) {
        return $fallback;
    }

    return null;
}



// extract fields
$song_name = clean(getv($input, ['song_name','songName'], ''));
$country = clean(getv($input, ['country'], ''));
$premium = clean(getv($input, ['premium'], ''));
$style = clean(getv($input, ['style'], ''));
$imageField = getv($input, ['image'], null);
$audioField = getv($input, ['audio','audioFile'], null);
$lyricField = getv($input, ['lyricFile','lyric','lyric_file'], null);

// authorsArray may be array or JSON-string
$authorsRaw = getv($input, ['authorsArray','authors','authorArray','author'], []);

if (is_string($authorsRaw)) {
    // Trường hợp gửi lên là JSON string
    $decoded = json_decode($authorsRaw, true);
    $authorsRaw = is_array($decoded) ? $decoded : [];
} elseif (is_array($authorsRaw)) {
    // Nếu là mảng 1 phần tử chứa chuỗi JSON
    if (count($authorsRaw) === 1 && is_string($authorsRaw[0]) && str_starts_with(trim($authorsRaw[0]), '[')) {
        $decoded = json_decode($authorsRaw[0], true);
        $authorsRaw = is_array($decoded) ? $decoded : [];
    }
} else {
    $authorsRaw = [];
}


// handle files
$files = $_FILES ?? [];
$imageSaved = handleFile($files, 'image', $imageField, 'image');
$audioSaved = handleFile($files, 'audio', $audioField, 'audio');
$lyricSaved = handleFile($files, 'lyricFile', $lyricField, 'lyric');


// validation
$errors = [];
if (empty($song_name)) $errors[] = "song_name required";
if (empty($audioSaved) && empty($audioField)) $errors[] = "audio required (file or filename or base64)";

if (!empty($errors)) respond(400, ["success"=>false, "errors"=>$errors]);

// generate song id (varchar in DB)
$songId = 'song_' . uniqid();

// start transaction
$conn->begin_transaction();

try {
    // Insert into song table
    // song has columns: id (varchar), song_name, style, premium(enum), img, audio, lyric, country
    $stmtSong = $conn->prepare("INSERT INTO song (id, song_name, style, premium, img, audio, lyric, country) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmtSong) throw new Exception("Prepare song failed: " . $conn->error);
    $stmtSong->bind_param("ssssssss", $songId, $song_name, $style, $premium, $imageSaved, $audioSaved, $lyricSaved, $country);
    if (!$stmtSong->execute()) throw new Exception("Insert song failed: " . $stmtSong->error);
    $stmtSong->close();

    // process authors: collect author_ids to link in ctbh
// process authors: collect author_ids to link in ctbh
$authorIds = [];
foreach ($authorsRaw as $aRaw) {
    if (is_string($aRaw)) {
        $authorObj = ['author_name' => $aRaw, 'is_new_author' => true];
    } elseif (is_array($aRaw)) {
        $authorObj = $aRaw;
    } else continue;

    $id_author = $authorObj['id_author'] ?? $authorObj['idAuthor'] ?? null;
    $author_name = $authorObj['author_name'] ?? $authorObj['authorName'] ?? null;
    $is_new_author = isset($authorObj['is_new_author']) ? (bool)$authorObj['is_new_author'] : (isset($authorObj['isNewAuthor']) ? (bool)$authorObj['isNewAuthor'] : false);
    $album = $authorObj['album'] ?? $authorObj['albumObj'] ?? null;
    $new_album = $authorObj['new_album'] ?? $authorObj['newAlbum'] ?? null;

    // CASE 1: Tác giả đã có sẵn (id_author hợp lệ)
    if (!empty($id_author) && is_numeric($id_author)) {
        $authorIds[] = (int)$id_author;

        // 👉 Nếu chọn album có sẵn thì thêm vào bảng ctalbum
        if (is_array($album) && !empty($album['id_album']) && is_numeric($album['id_album'])) {
            $id_album = (int)$album['id_album'];
            $stmtCtAlb = $conn->prepare("INSERT INTO ctalbum (id_album, id_song) VALUES (?, ?)");
            if (!$stmtCtAlb) throw new Exception("Prepare ctalbum failed: " . $conn->error);
            $stmtCtAlb->bind_param("is", $id_album, $songId);
            if (!$stmtCtAlb->execute()) throw new Exception("Insert into ctalbum failed: " . $stmtCtAlb->error);
            $stmtCtAlb->close();
        }

        // Nếu có new_album thì thêm mới album cho tác giả cũ
        if (is_array($new_album) && !empty($new_album['name'])) {
            $alb_name = $new_album['name'];
            $alb_img_field = $new_album['image'] ?? null;
            $alb_premium = $new_album['premium'] ?? null;
// Xử lý file ảnh album đúng cách
            $albumImgSaved = null;
            if (!empty($_FILES)) {
                foreach ($_FILES as $key => $file) {
                    if (is_array($file) && str_contains($key, 'album_img_') && $file['error'] === UPLOAD_ERR_OK) {
                        $targetDir = __DIR__ . '/public/img/';
                        if (!file_exists($targetDir)) mkdir($targetDir, 0755, true);

                        $fileName = uniqid() . '_' . basename($file['name']);
                        $targetFile = $targetDir . $fileName;

                        if (move_uploaded_file($file['tmp_name'], $targetFile)) {
                            $albumImgSaved = '/public/img/' . $fileName;
                        }
                        break; // chỉ cần 1 file
                    }
                }
            }

            $stmtAlb = $conn->prepare("INSERT INTO album (name, img, premium, id_author) VALUES (?, ?, ?, ?)");
            if (!$stmtAlb) throw new Exception("Prepare album (existing author) failed: " . $conn->error);
            $stmtAlb->bind_param("sssi", $alb_name, $albumImgSaved, $alb_premium, $id_author);
            if (!$stmtAlb->execute()) throw new Exception("Insert album (existing author) failed: " . $stmtAlb->error);
            $newAlbumId = $stmtAlb->insert_id;
            $stmtAlb->close();

            // Liên kết bài hát với album mới
            $stmtCtAlb2 = $conn->prepare("INSERT INTO ctalbum (id_album, id_song) VALUES (?, ?)");
            $stmtCtAlb2->bind_param("is", $newAlbumId, $songId);
            $stmtCtAlb2->execute();
            $stmtCtAlb2->close();
        }

        continue;
    }

    // CASE 2: Tác giả mới
    if ($is_new_author) {
        if (empty($author_name)) throw new Exception("New author missing name");

        // Tạo tác giả mới
        $stmtAuthor = $conn->prepare("INSERT INTO author (name) VALUES (?)");
        if (!$stmtAuthor) throw new Exception("Prepare insert author failed: " . $conn->error);
        $stmtAuthor->bind_param("s", $author_name);
        $stmtAuthor->execute();
        $newAuthorId = $stmtAuthor->insert_id;
        $stmtAuthor->close();

        // Nếu có album mới → thêm album và ctalbum
        if (is_array($new_album) && !empty($new_album['name'])) {
            $alb_name = $new_album['name'];
            $alb_img_field = $new_album['image'] ?? null;
            $alb_premium = $new_album['premium'] ?? null;
// Xử lý file ảnh album đúng cách
$albumImgSaved = null;
if (!empty($_FILES)) {
    foreach ($_FILES as $key => $file) {
        if (is_array($file) && str_contains($key, 'album_img_') && $file['error'] === UPLOAD_ERR_OK) {
            $targetDir = __DIR__ . '/public/img/';
            if (!file_exists($targetDir)) mkdir($targetDir, 0755, true);

            $fileName = uniqid() . '_' . basename($file['name']);
            $targetFile = $targetDir . $fileName;

            if (move_uploaded_file($file['tmp_name'], $targetFile)) {
                $albumImgSaved = '/public/img/' . $fileName;
            }
            break; // chỉ cần 1 file
        }
    }
}
            $stmtAlb = $conn->prepare("INSERT INTO album (name, img, premium, id_author) VALUES (?, ?, ?, ?)");
            if (!$stmtAlb) throw new Exception("Prepare album failed: " . $conn->error);
            $stmtAlb->bind_param("sssi", $alb_name, $albumImgSaved, $alb_premium, $newAuthorId);
            $stmtAlb->execute();
            $newAlbumId = $stmtAlb->insert_id;
            $stmtAlb->close();

            $stmtCtAlb = $conn->prepare("INSERT INTO ctalbum (id_album, id_song) VALUES (?, ?)");
            $stmtCtAlb->bind_param("is", $newAlbumId, $songId);
            $stmtCtAlb->execute();
            $stmtCtAlb->close();
        }

        $authorIds[] = $newAuthorId;
        continue;
    }

    // CASE 3: fallback tìm theo tên
    if (!empty($author_name)) {
        $stmtFind = $conn->prepare("SELECT id_author FROM author WHERE name = ? LIMIT 1");
        if ($stmtFind) {
            $stmtFind->bind_param("s", $author_name);
            $stmtFind->execute();
            $res = $stmtFind->get_result();
            if ($row = $res->fetch_assoc()) {
                $authorIds[] = (int)$row['id_author'];
                $stmtFind->close();
                continue;
            }
            $stmtFind->close();
        }
    }
}


    // insert into ctbh mapping (id_author, id_song) for each author id
    if (!empty($authorIds)) {
        $stmtCtBh = $conn->prepare("INSERT INTO ctbh (id_author, id_song) VALUES (?, ?)");
        if (!$stmtCtBh) throw new Exception("Prepare ctbh failed: " . $conn->error);
        foreach ($authorIds as $aid) {
            $stmtCtBh->bind_param("is", $aid, $songId);
            if (!$stmtCtBh->execute()) throw new Exception("Insert ctbh failed: " . $stmtCtBh->error);
        }
        $stmtCtBh->close();
    }

    // commit
    $conn->commit();

    respond(200, [
        "success" => true,
        "message" => "Song added",
        "song_id" => $songId,
        "saved" => ["image"=>$imageSaved, "audio"=>$audioSaved, "lyric"=>$lyricSaved],
        "author_ids" => $authorIds
    ]);
} catch (Exception $e) {
    $conn->rollback();
    respond(500, ["success"=>false, "message"=>"Database error: ".$e->getMessage()]);
}
?>

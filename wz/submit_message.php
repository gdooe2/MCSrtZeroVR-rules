<?php
require_once __DIR__ . '/admin/config.php';
require_once __DIR__ . '/admin/smtp.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
} else {
    $input = $_POST;
}

$name    = mb_substr(trim($input['name'] ?? ''), 0, 50);
$email   = mb_substr(trim($input['email'] ?? ''), 0, 100);
$subject = mb_substr(trim($input['subject'] ?? ''), 0, 50);
$message = mb_substr(trim($input['message'] ?? ''), 0, 2000);

if ($name === '' || $email === '' || $message === '') {
    echo json_encode(['success' => false, 'message' => '请填写所有必填项']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => '请输入有效的邮箱地址']);
    exit;
}

$rateLimitFile = __DIR__ . '/admin/data/rate_limit.json';
$clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$now = time();

$rateFp = fopen($rateLimitFile, 'c+');
if ($rateFp && flock($rateFp, LOCK_EX)) {
    $raw = stream_get_contents($rateFp);
    $rateData = $raw ? (json_decode($raw, true) ?: []) : [];

    $ipHits = [];
    foreach (($rateData[$clientIP] ?? []) as $t) {
        if (($now - $t) < 60) $ipHits[] = $t;
    }

    if (count($ipHits) >= 5) {
        flock($rateFp, LOCK_UN);
        fclose($rateFp);
        echo json_encode(['success' => false, 'message' => '提交过于频繁，请稍后再试']);
        exit;
    }

    $ipHits[] = $now;
    $rateData[$clientIP] = $ipHits;

    if (count($rateData) > 100) {
        foreach ($rateData as $ip => $timestamps) {
            $rateData[$ip] = array_values(array_filter($timestamps, fn($t) => ($now - $t) < 60));
            if (empty($rateData[$ip])) unset($rateData[$ip]);
        }
    }

    ftruncate($rateFp, 0);
    rewind($rateFp);
    fwrite($rateFp, json_encode($rateData));
    flock($rateFp, LOCK_UN);
    fclose($rateFp);
} else {
    if ($rateFp) fclose($rateFp);
}

$settings = loadSettings();

$emailLower = strtolower($email);
$blacklist = $settings['email_blacklist'] ?? [];
if (in_array($emailLower, array_map('strtolower', $blacklist))) {
    echo json_encode(['success' => false, 'message' => '您的邮箱已被限制提交，如有疑问请通过其他方式联系管理员']);
    exit;
}

if (!empty($settings['email_whitelist_enabled'])) {
    $whitelist = $settings['email_whitelist'] ?? [];
    if (!empty($whitelist)) {
        $emailDomain = strtolower(substr($email, strrpos($email, '@') + 1));
        $allowed = false;
        foreach ($whitelist as $suffix) {
            if ($emailDomain === strtolower(trim($suffix))) { $allowed = true; break; }
        }
        if (!$allowed) {
            echo json_encode(['success' => false, 'message' => '仅允许以下邮箱后缀提交：' . implode(', ', $whitelist)]);
            exit;
        }
    }
}

$allowedImgTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$maxImgSize = 5 * 1024 * 1024;
$uploadedImages = [];
$uploadDir = __DIR__ . '/admin/uploads/msg_images/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

for ($i = 0; $i < 3; $i++) {
    $fieldName = 'image_' . $i;
    if (empty($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) continue;
    $file = $_FILES[$fieldName];
    if ($file['size'] > $maxImgSize) continue;
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowedImgTypes)) continue;
    if (@getimagesize($file['tmp_name']) === false) continue;
    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        default      => 'jpg',
    };
    $filename = bin2hex(random_bytes(8)) . '.' . $ext;
    if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
        $uploadedImages[] = 'admin/uploads/msg_images/' . $filename;
    }
}

$messages = loadMessages();
$newMessage = [
    'id'         => uniqid('msg_'),
    'name'       => $name,
    'email'      => $email,
    'subject'    => $subject,
    'message'    => $message,
    'images'     => $uploadedImages,
    'created_at' => date('Y-m-d H:i:s'),
    'read'       => false,
    'replied'    => false
];
array_unshift($messages, $newMessage);
saveMessages($messages);

if (empty($settings['dnd_mode']) && !empty($settings['smtp_host'])) {
    $toEmail = !empty($settings['notification_email']) ? $settings['notification_email'] : $settings['smtp_user'];
    if ($toEmail) {
        $subjectMap = ['report' => '违规举报', 'bug' => 'Bug反馈', 'appeal' => '封禁申诉', 'suggestion' => '建议', 'other' => '其他'];
        $subjectLabel = $subjectMap[$subject] ?? $subject;
        $mailSubject = "[新消息] " . ($subjectLabel ?: '无主题');
        $mailBody = "收到来自 <b>" . htmlspecialchars($name) . "</b> (" . htmlspecialchars($email) . ") 的新消息：<br><br>" .
                   "<b>主题：</b> " . htmlspecialchars($subjectLabel) . "<br>" .
                   "<b>内容：</b><br>" . nl2br(htmlspecialchars($message));
        sendMailSMTP($toEmail, $mailSubject, $mailBody, $settings);
    }
}

echo json_encode(['success' => true]);

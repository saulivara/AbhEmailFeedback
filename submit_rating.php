<?php
// submit_rating.php
header('Content-Type: application/json');

require __DIR__ . '/config.php';

function client_ip(): string {
  foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $key) {
    if (!empty($_SERVER[$key])) {
      $val = $_SERVER[$key];
      if ($key === 'HTTP_X_FORWARDED_FOR') {
        $parts = explode(',', $val);
        return trim($parts[0]);
      }
      return $val;
    }
  }
  return '';
}

// Accept JSON or form-encoded
$ctype = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($ctype, 'application/json') !== false) {
  $input = json_decode(file_get_contents('php://input'), true) ?: [];
} else {
  $input = $_POST;
}

// Extract and validate
$rating     = isset($input['rating']) ? (int)$input['rating'] : 0;
$preference = $input['preference'] ?? '';
$comments   = trim($input['comments'] ?? '');

$allowedPrefs = ['more','less','stop'];
if ($rating < 1 || $rating > 5 || !in_array($preference, $allowedPrefs, true)) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Invalid input']);
  exit;
}

// Optional metadata
$campaign_uid   = substr(trim($input['campaign_uid'] ?? ''), 0, 64);
$subscriber_uid = substr(trim($input['subscriber_uid'] ?? ''), 0, 64);
$email          = substr(trim($input['email'] ?? ''), 0, 255);
$list_uid       = substr(trim($input['list_uid'] ?? ''), 0, 64);
$subject        = substr(trim($input['subject'] ?? ''), 0, 255);
$page_url       = trim($input['page_url'] ?? '');
$referrer       = trim($input['referrer'] ?? '');
$user_agent     = $_SERVER['HTTP_USER_AGENT'] ?? ($input['user_agent'] ?? '');
$tz             = substr(trim($input['tz'] ?? ''), 0, 64);
$ip_address     = client_ip();

try {
  $stmt = $pdo->prepare("
    INSERT INTO email_ratings
      (rating, preference, comments, campaign_uid, subscriber_uid, email, list_uid, subject, page_url, referrer, user_agent, tz, ip_address)
    VALUES
      (:rating, :preference, :comments, :campaign_uid, :subscriber_uid, :email, :list_uid, :subject, :page_url, :referrer, :user_agent, :tz, :ip_address)
  ");
  $stmt->execute([
    ':rating'        => $rating,
    ':preference'    => $preference,
    ':comments'      => $comments ?: null,
    ':campaign_uid'  => $campaign_uid ?: null,
    ':subscriber_uid'=> $subscriber_uid ?: null,
    ':email'         => $email ?: null,
    ':list_uid'      => $list_uid ?: null,
    ':subject'       => $subject ?: null,
    ':page_url'      => $page_url ?: null,
    ':referrer'      => $referrer ?: null,
    ':user_agent'    => $user_agent ?: null,
    ':tz'            => $tz ?: null,
    ':ip_address'    => $ip_address ?: null,
  ]);

  echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Insert failed']);
}
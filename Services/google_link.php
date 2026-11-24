<?php

function googleClientId(): string {
    static $id = null;
    if ($id === null) {
        $id = '';
        $file = __DIR__ . '/../config.local.php';
        if (is_file($file)) {
            $cfg = @include $file;
            if (is_array($cfg) && !empty($cfg['google_client_id']) && is_string($cfg['google_client_id'])) {
                $id = trim($cfg['google_client_id']);
            }
        }
    }
    return $id;
}

/** [verifiedEmail, error|null]. Google checks the signature and expiry; we check audience, issuer and email_verified. */
function googleVerifyIdToken(string $idToken): array {
    $clientId = googleClientId();
    if ($clientId === '') return ['', 'Google sign-in is not set up yet (missing google_client_id in config.local.php).'];
    if ($idToken === '' || strlen($idToken) > 4096) return ['', 'Google did not send a sign-in token. Please try again.'];

    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken);
    $body = null; $http = 0; $curlErr = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true]);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        $http = 200;
    }
    if ($body === false || $body === null) {
        error_log('google tokeninfo failed: ' . $curlErr);
        return ['', 'Could not reach Google to check the sign-in. Please try again.'];
    }
    $d = json_decode((string)$body, true);
    if ($http !== 200 || !is_array($d) || isset($d['error'])) return ['', 'Google sign-in could not be verified. Please try again.'];

    if (($d['aud'] ?? '') !== $clientId)                                   return ['', 'This sign-in was not issued for this system.'];
    if (!in_array($d['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)) return ['', 'Google sign-in could not be verified.'];
    if ((int)($d['exp'] ?? 0) < time())                                    return ['', 'The Google sign-in expired. Please try again.'];
    if (!in_array($d['email_verified'] ?? false, [true, 'true'], true))    return ['', 'That Google email is not verified.'];
    $email = strtolower(trim((string)($d['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))       return ['', 'Google did not return an email address.'];
    return [$email, null];
}
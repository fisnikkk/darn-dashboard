<?php
/**
 * Gmail API - Send email via Google Service Account (HTTPS, no SMTP needed)
 *
 * Uses JWT + OAuth2 to authenticate, then calls Gmail API REST endpoint.
 * Requires: service account JSON key + domain-wide delegation enabled.
 */
class GmailAPI {
    private $serviceAccountFile;
    private $senderEmail;
    private $accessToken;

    public function __construct($serviceAccountFile, $senderEmail) {
        $this->serviceAccountFile = $serviceAccountFile;
        $this->senderEmail = $senderEmail;
    }

    /**
     * Send an email with optional attachment
     */
    public function sendEmail($to, $subject, $body, $attachmentPath = null, $attachmentName = null, $senderName = 'Darn Group L.L.C') {
        $token = $this->getAccessToken();
        if (!$token) {
            throw new Exception('Nuk mund te merret access token nga Google');
        }

        // Build MIME message
        $mime = $this->buildMime($to, $subject, $body, $attachmentPath, $attachmentName, $senderName);

        // Base64url encode the message
        $encodedMessage = rtrim(strtr(base64_encode($mime), '+/', '-_'), '=');

        // Send via Gmail API
        $url = 'https://gmail.googleapis.com/gmail/v1/users/' . urlencode($this->senderEmail) . '/messages/send';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['raw' => $encodedMessage]),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return true;
        }

        $error = json_decode($response, true);
        $errorMsg = $error['error']['message'] ?? $response;
        throw new Exception('Gmail API gabim (' . $httpCode . '): ' . $errorMsg);
    }

    /**
     * Build MIME email message with optional attachment
     */
    private function buildMime($to, $subject, $body, $attachmentPath, $attachmentName, $senderName) {
        $boundary = 'boundary_' . md5(time());

        $headers = "From: {$senderName} <{$this->senderEmail}>\r\n";
        $headers .= "Reply-To: {$this->senderEmail}\r\n";
        $headers .= "To: {$to}\r\n";
        $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $headers .= "MIME-Version: 1.0\r\n";

        if ($attachmentPath && file_exists($attachmentPath)) {
            $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";

            $message = $headers . "\r\n";
            $message .= "--{$boundary}\r\n";
            $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $message .= chunk_split(base64_encode($body)) . "\r\n";

            // Attachment
            $fileData = file_get_contents($attachmentPath);
            $fname = $attachmentName ?: basename($attachmentPath);
            $message .= "--{$boundary}\r\n";
            $message .= "Content-Type: application/pdf; name=\"{$fname}\"\r\n";
            $message .= "Content-Disposition: attachment; filename=\"{$fname}\"\r\n";
            $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $message .= chunk_split(base64_encode($fileData)) . "\r\n";
            $message .= "--{$boundary}--";
        } else {
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $headers .= "Content-Transfer-Encoding: base64\r\n";
            $message = $headers . "\r\n" . chunk_split(base64_encode($body));
        }

        return $message;
    }

    /**
     * Get OAuth2 access token using service account JWT
     */
    private function getAccessToken() {
        if ($this->accessToken) return $this->accessToken;

        $sa = json_decode(file_get_contents($this->serviceAccountFile), true);
        if (!$sa || empty($sa['private_key'])) {
            throw new Exception('Service account JSON i pavlefshëm');
        }

        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $sa['client_email'],
            'sub' => $this->senderEmail,  // Impersonate this user (domain-wide delegation)
            'scope' => 'https://www.googleapis.com/auth/gmail.send',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        // Build JWT
        $headerB64 = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
        $claimsB64 = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
        $signingInput = $headerB64 . '.' . $claimsB64;

        // Sign with RSA
        $privateKey = openssl_pkey_get_private($sa['private_key']);
        if (!$privateKey) {
            throw new Exception('Private key i pavlefshëm');
        }
        openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $signatureB64 = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        $jwt = $signingInput . '.' . $signatureB64;

        // Exchange JWT for access token
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]),
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);
        if ($httpCode === 200 && !empty($data['access_token'])) {
            $this->accessToken = $data['access_token'];
            return $this->accessToken;
        }

        $errorMsg = $data['error_description'] ?? ($data['error'] ?? $response);
        throw new Exception('OAuth token gabim: ' . $errorMsg);
    }
}

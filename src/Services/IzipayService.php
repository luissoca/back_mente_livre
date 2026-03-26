<?php

namespace App\Services;

use Exception;

class IzipayService
{
    private $username;
    private $password;
    private $publicKey;
    private $hmacKey;
    private $endpoint;

    public function __construct() {
        // getenv() reads both $_ENV and the system environment (e.g. Vercel injected vars)
        $this->username  = $_ENV['IZIPAY_USERNAME']  ?? getenv('IZIPAY_USERNAME')  ?: '';
        $this->password  = $_ENV['IZIPAY_PASSWORD']  ?? getenv('IZIPAY_PASSWORD')  ?: '';
        $this->publicKey = $_ENV['IZIPAY_PUBLIC_KEY'] ?? getenv('IZIPAY_PUBLIC_KEY') ?: '';
        $this->hmacKey   = $_ENV['IZIPAY_HMAC_KEY']  ?? getenv('IZIPAY_HMAC_KEY')  ?: '';
        $this->endpoint  = $_ENV['IZIPAY_ENDPOINT']  ?? getenv('IZIPAY_ENDPOINT')  ?: 'https://api.micuentaweb.pe/api-payment/V4/';

        error_log('[IzipayService] username=' . $this->username . ' password_len=' . strlen($this->password) . ' endpoint=' . $this->endpoint);
    }

    /**
     * Generate form token for embedded payment
     */
    public function createPayment($amount, $currency, $orderId, $email, $metadata = []) {
        // Amount must be in cents (integer)
        $amountInt = (int) round($amount * 100);

        $payload = [
            "amount" => $amountInt,
            "currency" => $currency,
            "orderId" => $orderId,
            "customer" => [
                "email" => $email
            ],
            "metadata" => $metadata
        ];

        return $this->makeRequest('Charge/CreatePayment', $payload);
    }

    /**
     * Validate IPN signature
     */
    public function validateSignature($data, $signature) {
        // Check hash algorithm
        // Izipay uses HMAC-SHA256
        $calculatedSignature = $this->calculateHash($data);
        return hash_equals($calculatedSignature, $signature);
    }

    /**
     * Calculate HMAC-SHA256 hash of the data
     */
    private function calculateHash($data) {
        // Sort keys alphabetically
        // Join values with '+'
        // Key is the HMAC SHA256 key
        // Implementation depends on Izipay specific requirements for IPN
        // Usually it's: verify the KR-HASH header or kr-answer parameter
        
        // For simple debugging, let's assume we receive the answer object
        // and we need to check kr-answer-signature logic or similar.
        // Referencing standard Izipay/Lyra logic:
        
        $key = $this->hmacKey;
        // Verify if data is a string (primitive) or array
        if (is_array($data)) {
            // Flatten/Sort logic if needed, but usually IPN sends a specific string to hash
            // or we use the `kr-answer` JSON string.
            $data = json_encode($data); 
        }
        
        return base64_encode(hash_hmac('sha256', $data, $key, true));
    }
    
    /**
     * Check if the notification signature is valid (Kr-Hash handling)
     */
    public function checkHash($data, $key) {
        $supportedIndices = [
            'kr-answer-type',
            'kr-answer',
            'kr-hash-key',
            'kr-hash-algorithm',
        ];
        
        $krAnswer = $data['kr-answer'] ?? null;
        
        if (!$krAnswer) return false;
        
        // Decode kr-answer to object/array
        $answerObj = json_decode($krAnswer, true);
        
        // Identify which key to use (password or safe hmac key)
        // For simplicity, we use the HMAC key defined in env
        
        // Calculate hash of the answer
        $calculatedHash = hash_hmac('sha256', $krAnswer, $this->hmacKey);
        
        return $calculatedHash === ($data['kr-hash'] ?? '');
    }

    private function makeRequest($endpoint, $payload) {
        $url = rtrim($this->endpoint, '/') . '/' . $endpoint;
        
        $auth = base64_encode("{$this->username}:{$this->password}");

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                "Authorization: Basic $auth",
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($curl);
        $err      = curl_error($curl);
        // curl_close() deprecated since PHP 8.5 — omitted intentionally

        if ($err) {
            throw new Exception('cURL Error: ' . $err);
        }

        error_log('[IzipayService] Raw response from ' . $url . ': ' . $response);

        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            throw new Exception('Izipay Error: Invalid JSON response — ' . $response);
        }

        if (isset($decoded['status']) && $decoded['status'] === 'ERROR') {
            $msg = $decoded['errorMessage']
                ?? $decoded['answer']['errorMessage']
                ?? json_encode($decoded);
            throw new Exception('Izipay Error: ' . $msg);
        }

        return $decoded;
    }
}

<?php

namespace MailerPress\Services;

class MailerPressAPI
{
    private string $apiUrl;
    private string $apiKey;

    public function __construct(string $apiUrl, string $apiKey)
    {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->apiKey = $apiKey;
    }

    public function sendBatch(array $payload): array
    {
        $response = wp_remote_post($this->apiUrl . '/api/v1/send', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return $data ?: ['success' => false, 'error' => __('Invalid API response', 'mailerpress')];
    }
}

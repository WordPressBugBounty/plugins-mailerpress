<?php

declare(strict_types=1);

namespace MailerPress\Core\Esp\Mailchimp;

\defined('ABSPATH') || exit;

use MailerPress\Core\Interfaces\HttpClientInterface;

class HttpClient implements HttpClientInterface
{
    private mixed $apiKey;
    private string $baseUrl;

    public function delete(): void
    {
        // TODO: Implement delete() method.
    }

    public function getClient(): static
    {
        global $wpdb;
        $query = $wpdb->get_results("SELECT * FROM {$wpdb->options} WHERE ".$wpdb->options.".option_name = 'mailerpress_esp_config'");
        $result = is_serialized($query[0]->option_value)
            ? unserialize($query[0]->option_value, ['allowed_classes' => false])
            : $query[0]->option_value;
        $this->set_api_key($result['apiKey']);
        $this->set_base_url(
            \sprintf(
                'https://%s.api.mailchimp.com/3.0',
                substr($this->apiKey, strpos($this->apiKey, '-') + 1)
            )
        );

        return $this;
    }

    public function set_api_key(mixed $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    public function set_base_url(string $baseUrl): void
    {
        $this->baseUrl = $baseUrl;
    }

    public function get(
        string $endpoint,
        array $data = []
    ) {
        $request = wp_remote_get($this->baseUrl.$endpoint, [
            'method' => 'GET',
            'timeout' => 45,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'headers' => [
                'Authorization' => 'Basic '.base64_encode('user:'.$this->apiKey),
            ],
            'body' => $data,
            'cookies' => [],
        ]);

        if (empty(wp_remote_retrieve_body($request))) {
            return null;
        }

        return json_decode(wp_remote_retrieve_body($request), false);
    }

    public function post(
        string $endpoint,
        array $data = []
    ) {
        $request = wp_remote_post($this->baseUrl.$endpoint, [
            'timeout' => 60,
            'redirection' => 5,
            'blocking' => true,
            'headers' => [
                'Authorization' => 'Basic '.base64_encode('user:'.$this->apiKey),
                'Content-Type' => 'application/json; charset=utf-8',
            ],
            'body' => wp_json_encode($data),
            'cookies' => [],
        ]);

        return json_decode(wp_remote_retrieve_body($request), false);
    }

    public function put(): void
    {
        // TODO: Implement put() method.
    }
}

<?php

declare(strict_types=1);

namespace AFS;

final class ApiClient
{
    /**
     * @param array<string, mixed> $body
     * @return array{ok: bool, code: int, body: array<string, mixed>|null, error: string}
     */
    public function post(string $path, array $body, bool $authenticated = true): array
    {
        $base = Options::api_base_url();
        if ($base === '') {
            return [
                'ok' => false,
                'code' => 0,
                'body' => null,
                'error' => 'API base URL is not configured.',
            ];
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'AroundFormStats/' . AFS_VERSION . '; WordPress/' . get_bloginfo('version'),
        ];

        if ($authenticated) {
            $token = Options::site_token();
            if ($token === '') {
                return [
                    'ok' => false,
                    'code' => 0,
                    'body' => null,
                    'error' => 'Site is not connected (missing API token).',
                ];
            }
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $timeout = str_contains($path, '/submissions/history') ? 60 : 15;

        $response = wp_remote_post($base . $path, [
            'timeout' => $timeout,
            'headers' => $headers,
            'body' => wp_json_encode($body),
            'data_format' => 'body',
        ]);

        if (is_wp_error($response)) {
            $error = $response->get_error_message();
            Options::update([
                'last_error' => $error,
                'last_error_at' => gmdate('c'),
            ]);

            return [
                'ok' => false,
                'code' => 0,
                'body' => null,
                'error' => $error,
            ];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            $decoded = null;
        }

        if ($code < 200 || $code >= 300) {
            $error = is_array($decoded) && isset($decoded['message'])
                ? (string) $decoded['message']
                : 'HTTP ' . $code;

            Options::update([
                'last_error' => $error,
                'last_error_at' => gmdate('c'),
            ]);

            return [
                'ok' => false,
                'code' => $code,
                'body' => $decoded,
                'error' => $error,
            ];
        }

        Options::update([
            'last_success_at' => gmdate('c'),
            'last_error' => '',
            'last_error_at' => '',
        ]);

        return [
            'ok' => true,
            'code' => $code,
            'body' => $decoded,
            'error' => '',
        ];
    }
}

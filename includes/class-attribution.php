<?php

declare(strict_types=1);

namespace AFS;

/**
 * Privacy-safe attribution helpers: host + UTM source only (never full query strings with PII).
 */
final class Attribution
{
    /**
     * @param mixed $form
     * @return array{referrer_host: string, utm_source: string|null}
     */
    public static function from_quform($form): array
    {
        $raw_url = self::find_referrer_url($form);
        $host = self::host_from_url($raw_url);

        $site_host = self::host_from_url(home_url('/'));
        if ($host !== '' && $site_host !== '' && $host === $site_host) {
            $host = '';
        }

        $utm = self::find_utm_source($form, $raw_url);

        /**
         * Filter attribution metadata before it is queued.
         *
         * @param array{referrer_host: string, utm_source: string|null} $attribution
         * @param mixed                                                 $form
         * @param string                                                $raw_url
         */
        $filtered = apply_filters('afs_submission_attribution', [
            'referrer_host' => $host,
            'utm_source' => $utm,
        ], $form, $raw_url);

        if (! is_array($filtered)) {
            return [
                'referrer_host' => $host,
                'utm_source' => $utm,
            ];
        }

        $filtered_host = isset($filtered['referrer_host'])
            ? self::host_from_url((string) $filtered['referrer_host']) ?: self::sanitize_host((string) $filtered['referrer_host'])
            : $host;
        if ($filtered_host !== '' && $site_host !== '' && $filtered_host === $site_host) {
            $filtered_host = '';
        }

        return [
            'referrer_host' => $filtered_host,
            'utm_source' => isset($filtered['utm_source'])
                ? self::sanitize_utm((string) $filtered['utm_source'])
                : $utm,
        ];
    }

    public static function host_from_url(?string $url): string
    {
        if ($url === null) {
            return '';
        }

        $url = trim($url);
        if ($url === '' || strcasecmp($url, 'Not set') === 0) {
            return '';
        }

        if (strpos($url, '://') === false && strpos($url, '.') !== false) {
            $url = 'https://' . $url;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return '';
        }

        return self::sanitize_host($host);
    }

    public static function sanitize_host(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        if (strpos($host, 'www.') === 0) {
            $host = substr($host, 4);
        }

        return substr($host, 0, 255);
    }

    public static function sanitize_utm(?string $utm): ?string
    {
        if ($utm === null) {
            return null;
        }

        $utm = strtolower(trim($utm));
        if ($utm === '') {
            return null;
        }

        $utm = preg_replace('/[^a-z0-9_\-.]/', '', $utm) ?? $utm;

        return $utm !== '' ? substr($utm, 0, 100) : null;
    }

    public static function utm_from_url(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $query = parse_url($url, PHP_URL_QUERY);
        if (! is_string($query) || $query === '') {
            return null;
        }

        parse_str($query, $params);
        if (! isset($params['utm_source']) || ! is_string($params['utm_source'])) {
            return null;
        }

        return self::sanitize_utm($params['utm_source']);
    }

    /**
     * Normalize a raw referring URL into privacy-safe host + optional utm_source.
     *
     * @return array{referrer_host: string, utm_source: string|null}
     */
    public static function from_raw_url(?string $url, ?string $site_host = null): array
    {
        $host = self::host_from_url($url);
        if ($site_host === null) {
            $site_host = self::host_from_url(home_url('/'));
        }
        if ($host !== '' && $site_host !== '' && $host === $site_host) {
            $host = '';
        }

        return [
            'referrer_host' => $host,
            'utm_source' => self::utm_from_url($url),
        ];
    }

    /**
     * @param mixed $form
     */
    private static function find_referrer_url($form): string
    {
        $candidates = [];

        if (is_object($form)) {
            foreach (['referring_url', 'referrer', 'external_referrer', 'afs_referrer'] as $key) {
                $value = self::form_value($form, $key);
                if ($value !== '') {
                    $candidates[] = $value;
                }
            }

            if (method_exists($form, 'getElements')) {
                try {
                    $elements = $form->getElements();
                    if (is_iterable($elements)) {
                        foreach ($elements as $element) {
                            $name = '';
                            if (is_object($element)) {
                                if (method_exists($element, 'getName')) {
                                    $name = strtolower((string) $element->getName());
                                } elseif (method_exists($element, 'config')) {
                                    $name = strtolower((string) $element->config('label'));
                                }
                            }

                            if ($name === '' || (
                                strpos($name, 'referr') === false
                                && strpos($name, 'referer') === false
                            )) {
                                continue;
                            }

                            $value = '';
                            if (is_object($element) && method_exists($element, 'getValue')) {
                                $raw = $element->getValue();
                                $value = is_scalar($raw) ? (string) $raw : '';
                            }

                            if ($value !== '' && (strpos($value, 'http') === 0 || strpos($value, '.') !== false)) {
                                $candidates[] = $value;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // ignore Quform API differences
                }
            }
        }

        if (! empty($_SERVER['HTTP_REFERER']) && is_string($_SERVER['HTTP_REFERER'])) {
            $candidates[] = $_SERVER['HTTP_REFERER'];
        }

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if (self::host_from_url($candidate) !== '') {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * @param mixed $form
     */
    private static function find_utm_source($form, string $referrer_url): ?string
    {
        $utm = self::sanitize_utm(self::form_value($form, 'utm_source'));
        if ($utm !== null) {
            return $utm;
        }

        if (isset($_GET['utm_source']) && is_string($_GET['utm_source'])) {
            $utm = self::sanitize_utm($_GET['utm_source']);
            if ($utm !== null) {
                return $utm;
            }
        }

        if ($referrer_url !== '') {
            $query = parse_url($referrer_url, PHP_URL_QUERY);
            if (is_string($query) && $query !== '') {
                parse_str($query, $params);
                if (isset($params['utm_source']) && is_string($params['utm_source'])) {
                    return self::sanitize_utm($params['utm_source']);
                }
            }
        }

        return null;
    }

    /**
     * @param mixed $form
     */
    private static function form_value($form, string $key): string
    {
        if (! is_object($form)) {
            return '';
        }

        foreach (['getValueText', 'getValue'] as $method) {
            if (! method_exists($form, $method)) {
                continue;
            }

            try {
                $value = $form->{$method}($key);
                if (is_scalar($value) && (string) $value !== '') {
                    return (string) $value;
                }
            } catch (\Throwable $e) {
                // try next
            }
        }

        return '';
    }
}

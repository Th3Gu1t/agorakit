<?php

namespace App\Setup;

/**
 * The few HTTP requests the update of the setup page makes, to GitHub.
 *
 * It runs before Laravel is configured, so it uses curl when PHP has it, and
 * PHP's own HTTP streams otherwise. Redirections are followed to HTTPS only:
 * GitHub sends release downloads to another host of its own.
 */
class HttpClient
{
    private const USER_AGENT = 'Agorakit setup page';

    public function __construct(private int $timeout = 300)
    {
    }

    /**
     * Whether this server can make HTTPS requests at all.
     */
    public static function available(): bool
    {
        return function_exists('curl_init')
            || (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN) && in_array('https', stream_get_wrappers(), true));
    }

    /**
     * Where an address redirects to, without following it, or null if it does not redirect.
     *
     * @throws UpdateException when the address cannot be reached
     */
    public function location(string $url): ?string
    {
        [$status, $headers] = $this->request($url, null, false);
        if ($status >= 300 && $status < 400 && isset($headers['location'])) {
            return $headers['location'];
        }
        if ($status >= 400) {
            throw new UpdateException('GitHub answered :status for :url.', ['status' => $status, 'url' => $url]);
        }

        return null;
    }

    /**
     * Saves what an address serves to a file, following redirections.
     *
     * @throws UpdateException when the download fails
     */
    public function download(string $url, string $file): void
    {
        $handle = @fopen($file, 'wb');
        if ($handle === false) {
            throw new UpdateException('Cannot write :file.', ['file' => $file]);
        }
        try {
            [$status] = $this->request($url, $handle, true);
        } catch (UpdateException $e) {
            fclose($handle);
            @unlink($file);
            throw $e;
        }
        fclose($handle);
        if ($status !== 200) {
            @unlink($file);
            throw new UpdateException('GitHub answered :status for :url.', ['status' => $status, 'url' => $url]);
        }
    }

    /**
     * @param resource|null $sink where the body goes; null to ignore it
     * @return array{int, array<string, string>} the status and the headers of the last response
     */
    protected function request(string $url, $sink, bool $follow): array
    {
        return function_exists('curl_init')
            ? $this->curl($url, $sink, $follow)
            : $this->stream($url, $sink, $follow);
    }

    /**
     * @param resource|null $sink
     * @return array{int, array<string, string>}
     */
    private function curl(string $url, $sink, bool $follow): array
    {
        $headers = [];
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_FAILONERROR => false,
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$headers): int {
                if (preg_match('#^HTTP/\S+\s#', $line)) {
                    // a new response, after a redirection
                    $headers = [];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
        ]);
        if (defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
            curl_setopt($curl, CURLOPT_REDIR_PROTOCOLS_STR, 'https');
        } else {
            curl_setopt($curl, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        }
        if ($sink !== null) {
            curl_setopt($curl, CURLOPT_FILE, $sink);
        } else {
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        }

        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($ok === false) {
            throw new UpdateException('Cannot reach :url: :error', ['url' => $url, 'error' => $error]);
        }

        return [$status, $headers];
    }

    /**
     * @param resource|null $sink
     * @return array{int, array<string, string>}
     */
    private function stream(string $url, $sink, bool $follow): array
    {
        $context = stream_context_create([
            'http' => [
                'follow_location' => $follow ? 1 : 0,
                'max_redirects' => 5,
                'timeout' => $this->timeout,
                'user_agent' => self::USER_AGENT,
                'ignore_errors' => true,
            ],
        ]);

        $source = @fopen($url, 'rb', false, $context);
        if ($source === false) {
            $error = error_get_last()['message'] ?? '';
            throw new UpdateException('Cannot reach :url: :error', ['url' => $url, 'error' => $error]);
        }
        $meta = stream_get_meta_data($source);
        if ($sink !== null) {
            stream_copy_to_stream($source, $sink);
        }
        fclose($source);

        // the headers of every response, the redirections included: keep the last one
        $status = 0;
        $headers = [];
        foreach ($meta['wrapper_data'] ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match)) {
                $status = (int) $match[1];
                $headers = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }
        if ($follow && isset($headers['location']) && !str_starts_with($headers['location'], 'https://')) {
            throw new UpdateException('Cannot reach :url: :error', ['url' => $url, 'error' => 'redirected outside HTTPS']);
        }

        return [$status, $headers];
    }
}

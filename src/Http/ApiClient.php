<?php

declare(strict_types=1);

namespace Flute\Sdk\Http;

use Flute\Sdk\Auth\TokenManager;
use Flute\Sdk\Exceptions\FluteApiException;
use Flute\Sdk\Exceptions\FluteAuthException;
use Flute\Sdk\Exceptions\FluteNetworkException;
use Flute\Sdk\Exceptions\RedactedHttpException;
use Flute\Sdk\Internal\Redact;
use Flute\Sdk\Version;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

/**
 * Internal request pipeline: bearer attach, send, error mapping, 401 retry-once.
 *
 * @internal
 */
final class ApiClient
{
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly TokenManager $tokenManager,
        private readonly string $apiBaseUrl,
        private readonly int $timeoutSeconds,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>|null
     *
     * @throws FluteApiException
     * @throws FluteAuthException
     * @throws FluteNetworkException
     */
    public function get(string $path, array $query = [], array $headers = []): ?array
    {
        return $this->send('GET', $path, null, $query, $headers);
    }

    /**
     * Like get(), for endpoints whose contract includes a JSON body: an empty
     * 2xx fails closed instead of hydrating an all-null DTO.
     *
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     *
     * @throws FluteApiException
     * @throws FluteAuthException
     * @throws FluteNetworkException
     */
    public function getJson(string $path, array $query = [], array $headers = []): array
    {
        return $this->sendExpectingJson('GET', $path, null, $query, $headers);
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>|null
     *
     * @throws FluteApiException
     * @throws FluteAuthException
     * @throws FluteNetworkException
     */
    public function post(
        string $path,
        #[\SensitiveParameter] ?array $json = null,
        array $query = [],
        array $headers = [],
    ): ?array {
        return $this->send('POST', $path, $json, $query, $headers);
    }

    /**
     * Like post(), for endpoints whose contract includes a JSON body: an empty
     * 2xx fails closed instead of hydrating an all-null DTO.
     *
     * @param array<string, mixed>|null $json
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     *
     * @throws FluteApiException
     * @throws FluteAuthException
     * @throws FluteNetworkException
     */
    public function postJson(
        string $path,
        #[\SensitiveParameter] ?array $json = null,
        array $query = [],
        array $headers = [],
    ): array {
        return $this->sendExpectingJson('POST', $path, $json, $query, $headers);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>|null
     *
     * @throws FluteApiException
     * @throws FluteAuthException
     * @throws FluteNetworkException
     */
    public function delete(string $path, array $query = [], array $headers = []): ?array
    {
        return $this->send('DELETE', $path, null, $query, $headers);
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>|null
     */
    private function send(
        string $method,
        string $path,
        #[\SensitiveParameter] ?array $json,
        array $query,
        array $headers,
    ): ?array {
        $response = $this->sendWithAuthRetry($method, $path, $json, $query, $headers);
        $body = (string) $response->getBody();
        if ($body === '') {
            return null;
        }

        return $this->decodeJsonBody($body, $response->getStatusCode());
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    private function sendExpectingJson(
        string $method,
        string $path,
        #[\SensitiveParameter] ?array $json,
        array $query,
        array $headers,
    ): array {
        $response = $this->sendWithAuthRetry($method, $path, $json, $query, $headers);
        $body = (string) $response->getBody();
        $status = $response->getStatusCode();
        if ($body === '') {
            /*
             * An empty 2xx on a body-expecting endpoint is a broken success
             * (truncation, misbehaving proxy). Fail closed rather than hand back
             * an all-null DTO the caller cannot distinguish from real data.
             */
            throw new FluteApiException(
                message: sprintf(
                    'Flute returned HTTP %d with an empty body where a JSON response was expected.',
                    $status,
                ),
                statusCode: $status,
            );
        }

        return $this->decodeJsonBody($body, $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonBody(string $body, int $status): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            /*
             * A non-empty 2xx body that is not a JSON object/array is a broken
             * success (proxy error page, truncation). Fail closed rather than
             * hand back an empty DTO the caller cannot distinguish from real data.
             * The body is not echoed — it may itself carry sensitive values.
             */
            throw new FluteApiException(
                message: sprintf('Flute returned HTTP %d with a body that is not valid JSON.', $status),
                statusCode: $status,
            );
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     */
    private function sendWithAuthRetry(
        string $method,
        string $path,
        #[\SensitiveParameter] ?array $json,
        array $query,
        array $headers,
    ): ResponseInterface {
        try {
            return $this->sendOnce($method, $path, $json, $query, $headers);
        } catch (BadResponseException $e) {
            if ($e->getResponse()->getStatusCode() !== 401) {
                throw $this->mapApiException($e, $method, $path);
            }
        }

        // One reactive retry with a fresh token.
        $this->tokenManager->clear();

        try {
            return $this->sendOnce($method, $path, $json, $query, $headers);
        } catch (BadResponseException $e) {
            if ($e->getResponse()->getStatusCode() === 401) {
                throw new FluteAuthException(
                    'Request rejected with HTTP 401 after token refresh. Verify credentials and permissions.',
                    0,
                    RedactedHttpException::from($method, $path, 401),
                );
            }

            throw $this->mapApiException($e, $method, $path);
        }
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     */
    private function sendOnce(
        string $method,
        string $path,
        #[\SensitiveParameter] ?array $json,
        array $query,
        array $headers,
    ): ResponseInterface {
        $token = $this->tokenManager->getAccessToken();

        $options = [
            RequestOptions::HEADERS => array_merge([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'User-Agent' => 'flute-php-sdk/' . Version::VERSION,
            ], $headers),
            RequestOptions::TIMEOUT => $this->timeoutSeconds,
            RequestOptions::HTTP_ERRORS => true,
            RequestOptions::ALLOW_REDIRECTS => false,
        ];
        if ($query !== []) {
            $options[RequestOptions::QUERY] = $query;
        }
        if ($json !== null) {
            $options[RequestOptions::JSON] = $json;
        }

        try {
            $response = $this->httpClient->request($method, $this->apiBaseUrl . $path, $options);
            /*
             * Guarantee error mapping for any non-2xx even when the client's
             * stack lacks the http_errors middleware. Redirects are refused,
             * so a 3xx is an error here, never a transparent hop.
             */
            $status = $response->getStatusCode();
            if ($status >= 300) {
                $request = new Request($method, $this->buildUri($path, $query));
                /*
                 * RequestException::create() yields a plain RequestException for
                 * 3xx; synthesize BadResponseException so mapApiException applies.
                 */
                throw $status >= 400
                    ? RequestException::create($request, $response)
                    : new BadResponseException(
                        sprintf('Unexpected HTTP %d redirect response.', $status),
                        $request,
                        $response,
                    );
            }

            return $response;
        } catch (BadResponseException $e) {
            throw $e;
        } catch (GuzzleException $e) {
            /*
             * The raw Guzzle message can carry the full request URI, query
             * values, or transport diagnostics. Keep the public message generic;
             * method and path live only on the redacted previous (safe to log).
             */
            throw new FluteNetworkException(
                'HTTP request failed before a response was received.',
                0,
                RedactedHttpException::from($method, $path, null),
            );
        }
    }

    /**
     * URI for the synthesized debug request. No headers or body are attached to
     * this Request object. getPrevious() on mapped exceptions returns a
     * RedactedHttpException carrying only method, path, and status — never the
     * signed request, so the getPrevious() chain is safe to log. (The exception's
     * own getTrace() args are covered separately by #[\SensitiveParameter] on the
     * card-data/credential parameters.)
     *
     * @param array<string, mixed> $query
     */
    private function buildUri(string $path, array $query): string
    {
        $uri = $this->apiBaseUrl . $path;
        if ($query !== []) {
            $uri .= '?' . http_build_query($query);
        }

        return $uri;
    }

    private function mapApiException(
        #[\SensitiveParameter] BadResponseException $e,
        string $method,
        string $path,
    ): FluteApiException {
        $response = $e->getResponse();
        $status = $response->getStatusCode();

        /** @var array<string, mixed> $envelope */
        $envelope = [];
        /** @var mixed $decoded */
        $decoded = json_decode((string) $response->getBody(), true);
        if (is_array($decoded)) {
            foreach ($decoded as $key => $value) {
                if (is_string($key)) {
                    $envelope[strtolower($key)] = $value;
                }
            }
        }

        /*
         * Gateway text is server-controlled; scrub PANs and opaque tokens in
         * case it echoes a submitted value, so messages never carry card data
         * or secrets. Short numeric runs stay readable for diagnostics.
         */
        $title = is_string($envelope['title'] ?? null) ? Redact::message($envelope['title']) : null;
        $details = is_string($envelope['details'] ?? null) ? Redact::message($envelope['details']) : null;
        if ($title !== null) {
            $message = $details !== null && $details !== $title ? $title . ': ' . $details : $title;
        } elseif ($details !== null) {
            $message = sprintf('Flute API request failed with HTTP %d: %s', $status, $details);
        } else {
            $message = sprintf('Flute API request failed with HTTP %d.', $status);
        }

        $retryAfter = $status === 429
            ? $this->parseRetryAfter($response->getHeaderLine('Retry-After'))
            : null;

        return new FluteApiException(
            message: $message,
            statusCode: $status,
            errorCode: is_string($envelope['errorcode'] ?? null) ? $envelope['errorcode'] : null,
            correlationId: is_string($envelope['correlationid'] ?? null) ? $envelope['correlationid'] : null,
            retryAfterSeconds: $retryAfter,
            errorDetails: Redact::details($this->normalizeErrorDetails($envelope['errors'] ?? null)),
            previous: RedactedHttpException::from($method, $path, $status),
        );
    }

    /**
     * Parse a Retry-After header (RFC 7231): either delta-seconds or an
     * HTTP-date. Date-derived delays are relative to now and clamped to zero so
     * an already-past date never yields a negative wait. Returns null when the
     * header is absent or unparseable.
     */
    private function parseRetryAfter(string $header): ?int
    {
        $header = trim($header);
        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return (int) $header;
        }

        /*
         * A spec delta-seconds is pure digits (handled above). Every RFC-7231
         * HTTP-date carries a time separator; a bare signed/decimal number has
         * none, so route it to null rather than let strtotime misparse it.
         */
        if (!str_contains($header, ':')) {
            return null;
        }

        $timestamp = strtotime($header);
        if ($timestamp === false) {
            return null;
        }

        return max(0, $timestamp - time());
    }

    /**
     * @return array<string, list<string>>
     */
    private function normalizeErrorDetails(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $field => $messages) {
            if (!is_string($field)) {
                continue;
            }
            if (is_string($messages)) {
                $normalized[$field] = [$messages];
                continue;
            }
            if (is_array($messages)) {
                $strings = array_values(array_filter($messages, 'is_string'));
                if ($strings !== []) {
                    $normalized[$field] = $strings;
                }
            }
        }

        return $normalized;
    }
}

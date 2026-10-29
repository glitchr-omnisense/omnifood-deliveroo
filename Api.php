<?php

namespace Omnifood\Deliveroo;

use Omnifood\Exception\InvalidConfigException;
use Omnifood\Exception\ProviderException;
use Omnifood\Exception\UnauthorizedException;
use Omnifood\Model\Token;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Deliveroo Partner Platform (api.developers.deliveroo.com, or its
 * sandbox), thin: JSON calls with a Bearer token from Deliveroo's OAuth
 * (client credentials, HTTP Basic), which lives 5 minutes and has no
 * refresh token: a new one is asked for when it dies. Deliveroo's errors,
 * {"error": {"code", "message"}}, as Omnifood's; 401 and 403 as
 * UnauthorizedException.
 */
final class Api
{
    public const PLATFORM = 'deliveroo';
    public const BASE_URI = 'https://api.developers.deliveroo.com';
    public const TOKEN_URL = 'https://auth.developers.deliveroo.com/oauth2/token';
    public const SANDBOX_BASE_URI = 'https://api-sandbox.developers.deliveroo.com';
    public const SANDBOX_TOKEN_URL = 'https://auth-sandbox.developers.deliveroo.com/oauth2/token';

    private readonly HttpClientInterface $http;

    private ?Token $token = null;

    public function __construct(
        private readonly ?string $clientId = null,
        private readonly ?string $clientSecret = null,
        private readonly string $baseUri = self::BASE_URI,
        private readonly string $tokenUrl = self::TOKEN_URL,
        ?HttpClientInterface $http = null,
    ) {
        $this->http = $http ?? HttpClient::create();
    }

    /**
     * @param array<string, scalar|null>       $query
     * @param array<mixed>|object|null $json
     *
     * @return array<mixed> the answer, [] when it has none (204)
     */
    public function request(string $method, string $path, array $query = [], array|object|null $json = null): array
    {
        $options = ['headers' => ['Authorization' => 'Bearer '.$this->token()->accessToken, 'Accept' => 'application/json']];
        if ($query = array_filter($query, static fn ($v) => null !== $v && '' !== $v)) {
            $options['query'] = $query;
        }
        if (null !== $json) {
            $options['json'] = $json;
        }

        return $this->send($method, rtrim($this->baseUri, '/').'/'.ltrim($path, '/'), $options);
    }

    public function token(): Token
    {
        if (null === $this->token || $this->token->isExpired(new \DateTimeImmutable('+20 seconds'))) {
            $this->refresh();
        }

        return $this->token;
    }

    public function heldToken(): ?Token
    {
        return $this->token;
    }

    public function refresh(): Token
    {
        if (!$this->clientId || !$this->clientSecret) {
            throw new InvalidConfigException('The "deliveroo" platform needs: client_id, client_secret.');
        }
        $data = $this->send('POST', $this->tokenUrl, [
            'auth_basic' => [$this->clientId, $this->clientSecret],
            'body' => ['grant_type' => 'client_credentials'],
        ]);
        if (!isset($data['access_token'])) {
            throw new ProviderException(self::PLATFORM, 'No token in Deliveroo\'s answer.');
        }

        return $this->token = new Token((string) $data['access_token'], isset($data['expires_in']) ? new \DateTimeImmutable('+'.(int) $data['expires_in'].' seconds') : null);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    private function send(string $method, string $url, array $options): array
    {
        try {
            $response = $this->http->request($method, $url, $options);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (HttpExceptionInterface $e) {
            throw new ProviderException(self::PLATFORM, 'Deliveroo could not be reached: '.$e->getMessage(), null, null, $e);
        }
        $data = '' === $content ? [] : json_decode($content, true);
        if ($status < 400 && null === $data && \JSON_ERROR_NONE !== json_last_error()) {
            // A proxy's or a maintenance page: not an empty answer.
            throw new ProviderException(self::PLATFORM, \sprintf('An answer that is not JSON (HTTP %d).', $status), $status);
        }
        $data = \is_array($data) ? $data : [];
        if ($status >= 400) {
            $error = \is_array($data['error'] ?? null) ? $data['error'] : [];
            // The token endpoint answers as RFC 6749: {"error": "invalid_client"}.
            $code = isset($error['code']) ? (string) $error['code'] : (\is_string($data['error'] ?? null) ? $data['error'] : null);
            $message = (string) ($error['message'] ?? $data['message'] ?? $code ?? \sprintf('HTTP %d.', $status));

            throw 401 === $status || 403 === $status
                ? new UnauthorizedException(self::PLATFORM, $message, $status, $code)
                : new ProviderException(self::PLATFORM, 429 === $status ? 'Too many calls, try again later: '.$message : $message, $status, $code);
        }

        return $data;
    }
}

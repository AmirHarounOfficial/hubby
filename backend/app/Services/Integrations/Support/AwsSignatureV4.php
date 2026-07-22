<?php

namespace App\Services\Integrations\Support;

use Psr\Http\Message\RequestInterface;

/**
 * AWS Signature Version 4 request signing (defect #8).
 *
 * Amazon SP-API production calls must be SigV4-signed with the app's IAM credentials in addition
 * to the LWA access token. This implements the full algorithm against a PSR-7 request so it can be
 * dropped into Laravel's `withRequestMiddleware()` — the signature covers the method, path, query,
 * a defined set of headers, and the body, none of which are known until the request is built.
 *
 * Reference: https://docs.aws.amazon.com/general/latest/gr/sigv4_signing.html
 *
 * Deliberately dependency-free and pure (the timestamp is injected) so it is exhaustively testable
 * against AWS's published test vectors.
 */
class AwsSignatureV4
{
    private const ALGORITHM = 'AWS4-HMAC-SHA256';

    public function __construct(
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly string $region,
        private readonly string $service = 'execute-api',
        private readonly ?string $sessionToken = null,
    ) {
    }

    /**
     * Return a new request carrying the SigV4 `Authorization`, `x-amz-date` (and, for temporary
     * credentials, `x-amz-security-token`) headers. The input request is not mutated.
     *
     * @param  \DateTimeInterface  $now  signing instant — injected so the result is deterministic.
     */
    public function sign(RequestInterface $request, \DateTimeInterface $now): RequestInterface
    {
        $amzDate = $this->format($now, 'Ymd\THis\Z');
        $dateStamp = $this->format($now, 'Ymd');

        // The Host header must be signed. Guzzle normally sets it from the URI, but sign defensively.
        $request = $request
            ->withHeader('host', $request->getUri()->getHost())
            ->withHeader('x-amz-date', $amzDate);

        if ($this->sessionToken !== null && $this->sessionToken !== '') {
            $request = $request->withHeader('x-amz-security-token', $this->sessionToken);
        }

        $payloadHash = hash('sha256', (string) $request->getBody());
        // Rewind: reading the body above moves the pointer, and Guzzle will read it again to send.
        $request->getBody()->rewind();

        [$canonicalHeaders, $signedHeaders] = $this->canonicalHeaders($request);

        $canonicalRequest = implode("\n", [
            strtoupper($request->getMethod()),
            $this->canonicalPath($request),
            $this->canonicalQuery($request),
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $credentialScope = "{$dateStamp}/{$this->region}/{$this->service}/aws4_request";

        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($dateStamp));

        $authorization = self::ALGORITHM
            . " Credential={$this->accessKey}/{$credentialScope}"
            . ", SignedHeaders={$signedHeaders}"
            . ", Signature={$signature}";

        return $request->withHeader('Authorization', $authorization);
    }

    /** Lowercase, trimmed, alphabetically ordered header block + the signed-headers list. */
    private function canonicalHeaders(RequestInterface $request): array
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $lower = strtolower($name);
            // Collapse internal whitespace and trim, per the spec.
            $headers[$lower] = preg_replace('/\s+/', ' ', trim(implode(',', $values)));
        }

        ksort($headers);

        $canonical = '';
        foreach ($headers as $name => $value) {
            $canonical .= "{$name}:{$value}\n";
        }

        return [$canonical, implode(';', array_keys($headers))];
    }

    /** Each path segment is URI-encoded; the separators are not. Empty path signs as "/". */
    private function canonicalPath(RequestInterface $request): string
    {
        $path = $request->getUri()->getPath();
        if ($path === '' || $path === '/') {
            return '/';
        }

        $segments = array_map(
            fn (string $segment) => $this->encode(rawurldecode($segment)),
            explode('/', $path)
        );

        return implode('/', $segments);
    }

    /** Query params sorted by key (then value), each key and value URI-encoded. */
    private function canonicalQuery(RequestInterface $request): string
    {
        $query = $request->getUri()->getQuery();
        if ($query === '') {
            return '';
        }

        $pairs = [];
        foreach (explode('&', $query) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            // Re-encode from the decoded value to get canonical (upper-hex, spec-compliant) output.
            $pairs[] = [$this->encode(rawurldecode($key)), $this->encode(rawurldecode($value))];
        }

        usort($pairs, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return implode('&', array_map(fn ($p) => "{$p[0]}={$p[1]}", $pairs));
    }

    /** Derive the date/region/service scoped signing key. */
    private function signingKey(string $dateStamp): string
    {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $this->service, $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    /** RFC 3986 encoding, which (unlike rawurlencode on older PHP) leaves ~ unescaped. */
    private function encode(string $value): string
    {
        return str_replace('%7E', '~', rawurlencode($value));
    }

    private function format(\DateTimeInterface $now, string $format): string
    {
        return (new \DateTimeImmutable('@' . $now->getTimestamp()))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format($format);
    }
}

<?php

namespace Tests\Unit;

use App\Services\Integrations\Support\AwsSignatureV4;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

/**
 * SigV4 signer (defect #8). The headline test is AWS's own published `get-vanilla` vector — if the
 * algorithm is wrong by a single byte, the signature won't match, so this is a real correctness
 * check rather than a shape assertion.
 */
class AwsSignatureV4Test extends TestCase
{
    // AWS SigV4 test-suite credentials (docs.aws.amazon.com/general/latest/gr/sigv4-signed-request-examples.html)
    private const KEY = 'AKIDEXAMPLE';
    private const SECRET = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

    private function signedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2015-08-30 12:36:00', new \DateTimeZone('UTC'));
    }

    public function test_matches_the_aws_get_vanilla_reference_vector(): void
    {
        $signer = new AwsSignatureV4(self::KEY, self::SECRET, 'us-east-1', 'service');
        $signed = $signer->sign(new Request('GET', 'https://example.amazonaws.com/'), $this->signedAt());

        $this->assertSame(
            'AWS4-HMAC-SHA256 '
            . 'Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, '
            . 'SignedHeaders=host;x-amz-date, '
            . 'Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
            $signed->getHeaderLine('Authorization')
        );
        $this->assertSame('20150830T123600Z', $signed->getHeaderLine('x-amz-date'));
    }

    public function test_query_parameter_order_does_not_change_the_signature(): void
    {
        $signer = new AwsSignatureV4(self::KEY, self::SECRET, 'us-east-1', 'service');
        $now = $this->signedAt();

        $a = $signer->sign(new Request('GET', 'https://example.amazonaws.com/?B=2&A=1'), $now);
        $b = $signer->sign(new Request('GET', 'https://example.amazonaws.com/?A=1&B=2'), $now);

        // Canonicalisation sorts the query, so both orderings sign identically.
        $this->assertSame($a->getHeaderLine('Authorization'), $b->getHeaderLine('Authorization'));
    }

    public function test_session_token_is_signed_when_present(): void
    {
        $signer = new AwsSignatureV4(self::KEY, self::SECRET, 'us-east-1', 'execute-api', 'FQoGZXIvYXdzTEMP==');
        $signed = $signer->sign(new Request('GET', 'https://sellingpartnerapi-na.amazon.com/orders/v0/orders'), $this->signedAt());

        $this->assertSame('FQoGZXIvYXdzTEMP==', $signed->getHeaderLine('x-amz-security-token'));
        // Temporary-credential header must be part of what was signed, or Amazon rejects it.
        $this->assertStringContainsString('x-amz-security-token', $signed->getHeaderLine('Authorization'));
    }

    public function test_the_access_token_header_is_covered_by_the_signature(): void
    {
        $signer = new AwsSignatureV4(self::KEY, self::SECRET, 'us-east-1', 'execute-api');
        $request = (new Request('GET', 'https://sellingpartnerapi-na.amazon.com/orders/v0/orders'))
            ->withHeader('x-amz-access-token', 'Atza|lwa-token');

        $signed = $signer->sign($request, $this->signedAt());

        $this->assertStringContainsString('x-amz-access-token', $signed->getHeaderLine('Authorization'));
    }

    public function test_the_body_is_rewound_so_guzzle_can_resend_it(): void
    {
        $signer = new AwsSignatureV4(self::KEY, self::SECRET, 'us-east-1', 'execute-api');
        $request = new Request('POST', 'https://sellingpartnerapi-na.amazon.com/feeds', [], '{"hello":"world"}');

        $signed = $signer->sign($request, $this->signedAt());

        $this->assertSame('{"hello":"world"}', (string) $signed->getBody());
    }
}

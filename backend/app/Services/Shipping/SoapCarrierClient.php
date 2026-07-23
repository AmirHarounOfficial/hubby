<?php

namespace App\Services\Shipping;

use Illuminate\Support\Facades\Http;
use SimpleXMLElement;

/**
 * A minimal SOAP-over-HTTP client shared by the SOAP carriers (Aramex/SMSA/Naqel — spec 04 §6.1).
 *
 * Deliberately NOT PHP's ext-soap SoapClient: that uses its own transport, which can't be faked in
 * tests and hides the wire format. Instead we POST a hand-built SOAP envelope through Laravel's Http
 * client — so every call is Http::fake-able, redactable, and inspectable — and parse the response
 * with SimpleXML. One investment, three carriers.
 */
class SoapCarrierClient
{
    /**
     * POST a SOAP 1.1 request and return the parsed response body as a SimpleXMLElement.
     *
     * @param string $bodyXml the operation element XML (namespaces included by the caller)
     * @throws \RuntimeException on transport failure or a SOAP Fault
     */
    public function call(string $endpoint, string $soapAction, string $bodyXml, int $timeout = 30): SimpleXMLElement
    {
        $envelope = '<?xml version="1.0" encoding="utf-8"?>'
            .'<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            .'<soap:Body>'.$bodyXml.'</soap:Body></soap:Envelope>';

        $response = Http::timeout($timeout)
            ->withHeaders([
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction' => $soapAction,
            ])
            ->withBody($envelope, 'text/xml')
            ->post($endpoint);

        if ($response->failed()) {
            throw new \RuntimeException('SOAP transport error: HTTP '.$response->status());
        }

        return $this->parse($response->body());
    }

    /** Parse a SOAP response, stripping namespaces so xpath/property access is simple. */
    public function parse(string $xml): SimpleXMLElement
    {
        $clean = preg_replace('/(<\/?)[a-zA-Z0-9]+:/', '$1', $xml); // drop namespace prefixes
        $doc = @simplexml_load_string($clean);

        if ($doc === false) {
            throw new \RuntimeException('SOAP response was not valid XML.');
        }

        $fault = $doc->xpath('//Fault');
        if (! empty($fault)) {
            $reason = (string) ($fault[0]->faultstring ?? $fault[0]->Reason->Text ?? 'unknown SOAP fault');
            throw new \RuntimeException('SOAP fault: '.$reason);
        }

        // Return the first child of <Body> — the operation response element.
        $body = $doc->xpath('//Body/*');

        return $body[0] ?? $doc;
    }
}

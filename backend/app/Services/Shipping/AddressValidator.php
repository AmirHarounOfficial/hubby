<?php

namespace App\Services\Shipping;

use App\Models\CarrierAccount;

/**
 * Three-tier address validation (spec 04 §4.8), applied in order:
 *   1. Structural (always, local): required fields per destination country, phone → E.164, Arabic-Indic
 *      digits (٠١٢٣) → ASCII, whitespace cleanup.
 *   2. City normalization (always, local): CityNormalizer → canonical key.
 *   3. Carrier validation (optional): the carrier's own validateAddress() where exposed.
 *
 * Returns the normalized address plus notes[] with severities; an `error` note blocks a label
 * purchase unless the merchant explicitly overrides.
 */
class AddressValidator
{
    /** Required fields by destination country (spec §4.8 tier 1). */
    private const REQUIRED = [
        'SA' => ['name', 'phone', 'city', 'district'],
        'AE' => ['name', 'phone', 'city', 'district'], // "area" maps to district
        'EG' => ['name', 'phone', 'city', 'state'],    // "governorate" maps to state
        '*' => ['name', 'phone', 'city'],
    ];

    public function __construct(private readonly CityNormalizer $cities)
    {
    }

    /**
     * @param array<string,mixed> $address
     * @return array{is_valid:bool, normalized:array<string,mixed>, notes:array<int,array{field?:string,severity:string,message:string}>}
     */
    public function validate(array $address, ?int $organizationId = null, ?CarrierAccount $carrierAccount = null): array
    {
        $notes = [];
        $country = strtoupper((string) ($address['country_code'] ?? 'SA'));
        $n = $address;

        // --- Tier 1: structural ---
        $n['phone'] = $this->normalizePhone($address['phone'] ?? null, $country);
        $n['phone_alt'] = $this->normalizePhone($address['phone_alt'] ?? null, $country);
        $n['postal_code'] = $this->arabicDigitsToAscii((string) ($address['postal_code'] ?? '')) ?: null;
        foreach (['name', 'company', 'line1', 'line2', 'city', 'district', 'state'] as $f) {
            if (isset($n[$f]) && is_string($n[$f])) {
                $n[$f] = preg_replace('/\s+/u', ' ', trim($n[$f])) ?: null;
            }
        }

        $required = self::REQUIRED[$country] ?? self::REQUIRED['*'];
        foreach ($required as $field) {
            if (empty($n[$field])) {
                $notes[] = ['field' => $field, 'severity' => 'error', 'message' => "Missing required field: {$field}"];
            }
        }

        // --- Tier 2: city normalization ---
        $city = $this->cities->normalize($n['city'] ?? null, $country, $organizationId);
        $n['city_normalized'] = $city['canonical'];
        if (! empty($n['city']) && ! $city['matched']) {
            $notes[] = ['field' => 'city', 'severity' => 'warning', 'message' => 'City not recognised — the carrier may reject or mis-route it.'];
        }

        // --- Tier 3: carrier validation (optional) ---
        if ($carrierAccount) {
            $carrier = CarrierFactory::make($carrierAccount->carrier_code);
            if ($carrier->supports('address_validation')) {
                try {
                    $result = $carrier->validateAddress($carrierAccount, $n);
                    foreach ($result['notes'] ?? [] as $note) {
                        $notes[] = $note + ['severity' => 'warning'];
                    }
                    $n = array_merge($n, $result['normalized'] ?? []);
                } catch (\Throwable $e) {
                    // carrier validation is advisory; never block on its transport failure
                }
            }
        }

        $n['is_validated'] = ! $this->hasError($notes);
        $n['validation_source'] = $carrierAccount ? 'carrier:'.$carrierAccount->carrier_code : 'internal';
        $n['validation_notes'] = $notes;

        return ['is_valid' => ! $this->hasError($notes), 'normalized' => $n, 'notes' => $notes];
    }

    private function hasError(array $notes): bool
    {
        foreach ($notes as $note) {
            if (($note['severity'] ?? '') === 'error') {
                return true;
            }
        }

        return false;
    }

    /** Convert Arabic-Indic (٠-٩) and Persian (۰-۹) digits to ASCII. */
    public function arabicDigitsToAscii(string $s): string
    {
        return strtr($s, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }

    /** Best-effort E.164 for the destination country (Gulf + Egypt). */
    public function normalizePhone(?string $phone, string $country): ?string
    {
        if (! $phone) {
            return null;
        }
        $digits = preg_replace('/[^\d+]/', '', $this->arabicDigitsToAscii($phone));
        if ($digits === '' || $digits === '+') {
            return null;
        }
        if (str_starts_with($digits, '+')) {
            return $digits;
        }

        $cc = ['SA' => '966', 'AE' => '971', 'EG' => '20', 'KW' => '965', 'QA' => '974', 'BH' => '973', 'OM' => '968'][$country] ?? null;
        if (! $cc) {
            return $digits;
        }
        $digits = ltrim($digits, '0'); // local trunk 0
        if (str_starts_with($digits, $cc)) {
            return '+'.$digits;
        }

        return '+'.$cc.$digits;
    }
}

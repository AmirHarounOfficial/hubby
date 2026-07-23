<?php

namespace App\Http\Controllers;

use App\Models\CarrierAccount;
use App\Services\Shipping\AddressValidator;
use Illuminate\Http\Request;

/**
 * Address validation (spec 04 §4.8). The merchant validates a ship-to address before buying a label
 * so structural errors and unrecognised cities surface early — carriers reject or mis-route on bad
 * city strings, and this is where the Gulf-specific normalization earns its keep.
 */
class AddressController extends Controller
{
    public function __construct(private readonly AddressValidator $validator)
    {
    }

    public function validateAddress(Request $request)
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'phone_alt' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'line1' => ['nullable', 'string', 'max:255'],
            'line2' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'carrier_account_id' => ['nullable', 'integer'],
        ]);

        $account = null;
        if (! empty($data['carrier_account_id'])) {
            $account = CarrierAccount::where('organization_id', $request->header('X-Organization-Id'))
                ->find($data['carrier_account_id']);
        }

        $result = $this->validator->validate($data, (int) $request->header('X-Organization-Id'), $account);

        return response()->json($result);
    }
}

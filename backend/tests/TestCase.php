<?php

namespace Tests;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create a well-formed organization owned by the given user.
     *
     * Mirrors the registration flow (slug + owner_id are required columns), so
     * tests don't each have to remember them.
     */
    protected function makeOrganization(User $owner, string $name = 'Test Org'): Organization
    {
        return Organization::create([
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::random(5),
            'owner_id' => $owner->id,
        ]);
    }
}

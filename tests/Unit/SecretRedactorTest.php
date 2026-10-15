<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\SecretRedactor;
use Tests\TestCase;

class SecretRedactorTest extends TestCase
{
    public function test_it_masks_secret_keys_recursively(): void
    {
        $input = [
            'username' => 'admin',
            'password' => 'hunter2',
            'nested' => [
                'community' => 'public',
                'safe' => 'keep-me',
                'snmp_auth_password' => 'top',
            ],
        ];

        $out = SecretRedactor::scrub($input);

        $this->assertSame('admin', $out['username']);
        $this->assertSame(SecretRedactor::MASK, $out['password']);
        $this->assertSame(SecretRedactor::MASK, $out['nested']['community']);
        $this->assertSame('keep-me', $out['nested']['safe']);
        $this->assertSame(SecretRedactor::MASK, $out['nested']['snmp_auth_password']);
    }

    public function test_it_matches_hyphen_and_case_variants(): void
    {
        $out = SecretRedactor::scrub(['Private-Key' => 'xxx', 'API_KEY' => 'yyy']);

        $this->assertSame(SecretRedactor::MASK, $out['Private-Key']);
        $this->assertSame(SecretRedactor::MASK, $out['API_KEY']);
    }
}

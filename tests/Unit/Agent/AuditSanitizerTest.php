<?php

use App\Agent\AuditSanitizer;

it('redacts secrets, drops encrypted blobs and replaces frame images', function () {
    expect(AuditSanitizer::sanitize([
        'api_key' => 'sk-secret',
        'headers' => ['Authorization' => 'Bearer sk-secret', 'apiKey' => 'x'],
        'encrypted_content' => 'gAAAA',
        'rawContent' => ['anything'],
        'image_url' => 'data:image/jpeg;base64,AAAA',
        'items' => [['text' => 'keep me', 'count' => 3, 'ok' => true, 'none' => null]],
    ]))->toBe([
        'api_key' => '[redacted]',
        'headers' => ['Authorization' => '[redacted]', 'apiKey' => '[redacted]'],
        'image_url' => '[frame stored on device]',
        'items' => [['text' => 'keep me', 'count' => 3, 'ok' => true, 'none' => null]],
    ]);
});

<?php

use App\Support\SafeHttp\SafeHttpClient;

it('never passes the cURL options Guzzle 8 rejects, which made every research page fail to load', function () {
    $options = (new SafeHttpClient)->requestOptions('www.example.ac.uk', '93.184.216.34');

    expect($options['protocols'])->toBe(['https'])
        ->and(array_keys($options['curl']))->toBe([CURLOPT_RESOLVE])
        ->and($options['curl'][CURLOPT_RESOLVE])->toBe(['www.example.ac.uk:443:93.184.216.34'])
        ->and($options['allow_redirects'])->toBeFalse();

    foreach (['CURLOPT_PROTOCOLS', 'CURLOPT_PROTOCOLS_STR', 'CURLOPT_REDIR_PROTOCOLS', 'CURLOPT_REDIR_PROTOCOLS_STR'] as $name) {
        if (defined($name)) {
            expect($options['curl'])->not->toHaveKey(constant($name));
        }
    }
});

it('pins IPv6 addresses in brackets', function () {
    expect((new SafeHttpClient)->requestOptions('example.org', '2606:2800:220:1:248:1893:25c8:1946')['curl'][CURLOPT_RESOLVE])
        ->toBe(['example.org:443:[2606:2800:220:1:248:1893:25c8:1946]']);
});

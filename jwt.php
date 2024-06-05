<?php



function nouveauJeton($societe)
{

// Create token header as a JSON string
    $private_key = <<<EOD
-----BEGIN PRIVATE KEY-----

-----END PRIVATE KEY-----
EOD;
    $header = json_encode(['typ' => 'JWT', 'alg' => 'RS256']);

    // Create token payload as a JSON string
    $exp = strtotime("+1 day");
    $now = strtotime("now");
    $payload = json_encode([
        'param1 '=> "01",
        'param2 '=> "02",
        'nbf' => $now,
        'iat' => $now,
        'exp' => $exp
    ]);


    // Encode Header to Base64Url String
    $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));

    // Encode Payload to Base64Url String
    $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));


    $signature = "";
    $algo = "SHA256";
    openssl_sign($base64UrlHeader . "." . $base64UrlPayload, $signature, $private_key, $algo);

    // Encode Signature to Base64Url String
    $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

    // Create JWT
    $jwt = $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    return $jwt;
}

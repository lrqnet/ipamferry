<?php

declare(strict_types=1);

use App\Domain\Migration\PhpIpamClient;
use App\Domain\Migration\SourceNormalizer;
use App\Domain\Migration\SqlDumpParser;
use Illuminate\Contracts\Console\Kernel;

function assertSourceSecurity(string $contents): void
{
    foreach (['lab-snmp-community-do-not-export', 'lab-snmp-password-do-not-export', 'sensitive-value-excluded'] as $marker) {
        if (str_contains($contents, $marker)) {
            throw new RuntimeException('Sensitive laboratory value leaked into normalized source snapshots.');
        }
    }
    if (preg_match_all('/"([^"]+)"\s*:/', $contents, $matches) === false) {
        throw new RuntimeException('Unable to inspect normalized source snapshot fields.');
    }
    foreach ($matches[1] as $field) {
        if (preg_match('/^(?:snmp_.*|permissions?|users?(?:name|groups?)?|api(?:_|-)?(?:key|token|secret)|vault(?:_|-).*)$/i', $field) === 1) {
            throw new RuntimeException("Sensitive laboratory field {$field} leaked into normalized source snapshots.");
        }
    }
}

$root = (string) (getenv('IPAMFERRY_APP_PATH') ?: dirname(__DIR__, 2));
require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$token = (string) getenv('IPAMFERRY_LAB_READ_TOKEN');
$dumpPath = (string) getenv('IPAMFERRY_LAB_DUMP_PATH');
if ($token === '' || ! is_readable($dumpPath)) {
    throw new RuntimeException('The laboratory read token and dump are required.');
}

$parser = app(SqlDumpParser::class);
$parsed = $parser->parseFile($dumpPath);
$dumpInventory = [
    'instance' => ['kind' => 'phpipam_dump', 'fingerprint' => hash_file('sha256', $dumpPath)],
    'objects' => $parser->toInventoryObjects($parsed),
    'custom_fields' => $parser->customFieldDefinitions($parsed),
    'warnings' => $parsed['_warnings'] ?? [],
];
$apiInventory = (new PhpIpamClient('https://phpipam-proxy:8443', 'ipamferry-read', $token))->inventory();
$normalizer = app(SourceNormalizer::class);
$apiSource = $normalizer->normalize($apiInventory);
$dumpSource = $normalizer->normalize($dumpInventory);
assertSourceSecurity(json_encode([$apiSource, $dumpSource], JSON_THROW_ON_ERROR));

fwrite(STDOUT, "IPAMFERRY_LAB_SOURCE_SECURITY_SUCCESS\n");

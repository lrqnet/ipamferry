<?php

namespace App\Domain\Migration;

use Generator;
use InvalidArgumentException;
use RuntimeException;

class SqlDumpParser
{
    private const DATA_TABLES = [
        'customers',
        'ipaddresses',
        'sections',
        'subnets',
        'devices',
        'vlans',
        'vlanDomains',
        'vrf',
        'nameservers',
        'deviceTypes',
        'ipTags',
        'firewallZones',
        'firewallZoneMapping',
        'firewallZoneSubnet',
        'scanAgents',
        'nat',
        'racks',
        'rackContents',
        'locations',
        'pstnPrefixes',
        'pstnNumbers',
        'circuitProviders',
        'circuits',
        'circuitsLogical',
        'circuitsLogicalMapping',
        'circuitTypes',
        'routing_bgp',
        'routing_subnets',
    ];

    private const SENSITIVE_OR_SYSTEM_TABLES = [
        'instructions',
        'logs',
        'requests',
        'settings',
        'settingsMail',
        'userGroups',
        'users',
        'lang',
        'api',
        'apiLock',
        'changelog',
        'widgets',
        'loginAttempts',
        'usersAuthMethod',
        'php_sessions',
        'vaults',
        'vaultItems',
        'passkeys',
        'nominatim',
        'nominatim_cache',
    ];

    private const BUILT_IN_COLUMNS = [
        'sections' => [
            'id', 'name', 'description', 'masterSection', 'permissions', 'strictMode', 'subnetOrdering',
            'order', 'editDate', 'showSubnet', 'showVLAN', 'showVRF', 'showSupernetOnly', 'DNS',
        ],
        'subnets' => [
            'id', 'subnet', 'mask', 'sectionId', 'description', 'linked_subnet', 'firewallAddressObject',
            'vrfId', 'masterSubnetId', 'allowRequests', 'vlanId', 'showName', 'device', 'permissions',
            'pingSubnet', 'discoverSubnet', 'resolveDNS', 'DNSrecursive', 'DNSrecords', 'nameserverId',
            'scanAgent', 'customer_id', 'isFolder', 'isFull', 'isPool', 'state', 'threshold', 'location',
            'editDate', 'lastScan', 'lastDiscovery',
        ],
        'ipaddresses' => [
            'id', 'subnetId', 'ip_addr', 'ip', 'ip_addr_v6', 'is_gateway', 'description', 'hostname',
            'mac', 'owner', 'state', 'tag', 'switch', 'deviceId', 'location', 'port', 'note', 'lastSeen',
            'excludePing', 'PTRignore', 'PTR', 'firewallAddressObject', 'editDate', 'customer_id', 'NAT_address',
        ],
        'devices' => [
            'id', 'hostname', 'ip_addr', 'type', 'description', 'sections', 'snmp_community', 'snmp_version',
            'snmp_port', 'snmp_timeout', 'snmp_queries', 'snmp_v3_sec_level', 'snmp_v3_auth_protocol',
            'snmp_v3_auth_pass', 'snmp_v3_priv_protocol', 'snmp_v3_priv_pass', 'snmp_v3_ctx_name',
            'snmp_v3_ctx_engine_id', 'rack', 'rack_start', 'rack_size', 'rack_deep', 'location', 'editDate',
        ],
        'vlans' => ['vlanId', 'domainId', 'name', 'number', 'description', 'editDate', 'customer_id'],
        'vlanDomains' => ['id', 'name', 'description', 'permissions'],
        'vrf' => ['vrfId', 'name', 'rd', 'description', 'sections', 'editDate', 'customer_id'],
    ];

    private const CUSTOM_FIELD_SOURCE_TYPES = [
        'sections' => 'section',
        'subnets' => 'prefix',
        'ipaddresses' => 'ip_address',
        'devices' => 'device',
        'vlans' => 'vlan',
        'vlanDomains' => 'vlan_group',
        'vrf' => 'vrf',
    ];

    public function parse(string $sql): array
    {
        if (strlen($sql) > config('ipamferry.dump_max_bytes')) {
            throw new InvalidArgumentException('Dump exceeds the configured size limit.');
        }

        $stream = fopen('php://temp/maxmemory:10485760', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Unable to allocate a temporary dump stream.');
        }
        fwrite($stream, $sql);
        rewind($stream);

        try {
            return $this->parseStream($stream);
        } finally {
            fclose($stream);
        }
    }

    public function parseFile(string $path): array
    {
        $size = filesize($path);
        if ($size === false || $size > config('ipamferry.dump_max_bytes')) {
            throw new InvalidArgumentException('Dump exceeds the configured size limit.');
        }

        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new InvalidArgumentException('Unable to read the uploaded dump.');
        }

        try {
            return $this->parseStream($stream);
        } finally {
            fclose($stream);
        }
    }

    public function toInventoryObjects(array $parsed): array
    {
        $mapped = [
            'customers' => $parsed['customers'] ?? [],
            'sections' => $parsed['sections'] ?? [],
            'subnets' => $parsed['subnets'] ?? [],
            'addresses' => $parsed['ipaddresses'] ?? [],
            'vlans' => $parsed['vlans'] ?? [],
            'vrfs' => $parsed['vrf'] ?? [],
            'l2domains' => $parsed['vlanDomains'] ?? [],
            'devices' => $parsed['devices'] ?? [],
            'locations' => $parsed['locations'] ?? [],
            'racks' => $parsed['racks'] ?? [],
            'device_types' => $parsed['deviceTypes'] ?? [],
            'tags' => $parsed['ipTags'] ?? [],
            'nat' => $parsed['nat'] ?? [],
            'nameservers' => $parsed['nameservers'] ?? [],
            'scan_agents' => $parsed['scanAgents'] ?? [],
            'firewall_zones' => $parsed['firewallZones'] ?? [],
            'firewall_zone_mappings' => $parsed['firewallZoneMapping'] ?? [],
            'firewall_zone_subnets' => $parsed['firewallZoneSubnet'] ?? [],
            'rack_contents' => $parsed['rackContents'] ?? [],
            'pstn_prefixes' => $parsed['pstnPrefixes'] ?? [],
            'pstn_numbers' => $parsed['pstnNumbers'] ?? [],
            'circuit_providers' => $parsed['circuitProviders'] ?? [],
            'circuit_types' => $parsed['circuitTypes'] ?? [],
            'circuits' => $parsed['circuits'] ?? [],
            'logical_circuits' => $parsed['circuitsLogical'] ?? [],
            'logical_circuit_mappings' => $parsed['circuitsLogicalMapping'] ?? [],
            'routing_bgp' => $parsed['routing_bgp'] ?? [],
            'routing_subnets' => $parsed['routing_subnets'] ?? [],
        ];

        return $mapped;
    }

    public function customFieldDefinitions(array $parsed): array
    {
        $catalog = [];
        foreach ($parsed['_schema_definitions'] ?? [] as $table => $definitions) {
            if (! isset(self::BUILT_IN_COLUMNS[$table], self::CUSTOM_FIELD_SOURCE_TYPES[$table])
                || ! is_array($definitions)
            ) {
                continue;
            }

            foreach ($definitions as $definition) {
                $name = is_array($definition) ? ($definition['name'] ?? null) : null;
                if (! is_string($name)
                    || in_array($name, self::BUILT_IN_COLUMNS[$table], true)
                    || $this->isSensitiveField($name)
                ) {
                    continue;
                }

                $catalog[self::CUSTOM_FIELD_SOURCE_TYPES[$table]][] = [
                    'name' => $name,
                    'source_table' => $table,
                    'sql_type' => $definition['sql_type'] ?? null,
                    'data_type' => $this->mappingType((string) ($definition['sql_type'] ?? '')),
                    'nullable' => $definition['nullable'] ?? true,
                ];
            }
        }

        ksort($catalog);

        return $catalog;
    }

    private function parseStream($stream): array
    {
        $result = array_fill_keys(self::DATA_TABLES, []);
        $schemas = [];
        $schemaDefinitions = [];
        $warnings = [];
        $rows = 0;
        $maximumRows = (int) config('ipamferry.dump_max_rows');

        foreach ($this->statements($stream) as $statement) {
            if ($statement === '') {
                continue;
            }

            if (preg_match('/^(?:SET|LOCK TABLES|UNLOCK TABLES|START TRANSACTION|COMMIT)\b/i', $statement)
                || preg_match('/^CREATE DATABASE(?: IF NOT EXISTS)?\s+`?[A-Za-z0-9_]+`?(?:\s+.*)?$/is', $statement)
                || preg_match('/^USE\s+`?[A-Za-z0-9_]+`?$/i', $statement)
            ) {
                continue;
            }

            if (preg_match('/^DROP TABLE(?: IF EXISTS)?\s+`?([A-Za-z0-9_]+)`?/i', $statement, $match)) {
                $this->classifyTable($match[1], $warnings);

                continue;
            }

            if (preg_match('/^CREATE TABLE(?: IF NOT EXISTS)?\s+`?([A-Za-z0-9_]+)`?\s*\((.*)\)\s*.*$/is', $statement, $match)) {
                $classification = $this->classifyTable($match[1], $warnings);
                if ($classification === 'data') {
                    $schemaDefinitions[$match[1]] = $this->schemaDefinitions($match[2]);
                    $schemas[$match[1]] = array_column($schemaDefinitions[$match[1]], 'name');
                }

                continue;
            }

            if (preg_match('/^ALTER TABLE\s+`?([A-Za-z0-9_]+)`?/i', $statement, $match)) {
                $this->classifyTable($match[1], $warnings);

                continue;
            }

            if (! preg_match('/^INSERT INTO\s+`?([A-Za-z0-9_]+)`?\s*(?:\((.*?)\))?\s*VALUES\s*(.+)$/is', $statement, $match)) {
                throw new InvalidArgumentException('The dump contains an unsupported SQL statement.');
            }

            $table = $match[1];
            if ($this->classifyTable($table, $warnings) !== 'data') {
                continue;
            }

            $columns = trim((string) $match[2]) !== ''
                ? array_map(fn (string $column): string => trim($column, " `\t\n\r\0\x0B"), explode(',', $match[2]))
                : ($schemas[$table] ?? []);
            if ($columns === []) {
                throw new InvalidArgumentException("INSERT for {$table} has no column list and its CREATE TABLE schema was not found first.");
            }

            foreach ($this->tuples($match[3]) as $tuple) {
                $values = $this->values($tuple);
                if (count($values) !== count($columns)) {
                    throw new InvalidArgumentException("Malformed INSERT for {$table}: column and value counts differ.");
                }
                $result[$table][] = array_combine($columns, $values);
                $rows++;
                if ($rows > $maximumRows) {
                    throw new InvalidArgumentException('Dump exceeds the configured row limit.');
                }
            }
        }

        $result['_schema'] = $schemas;
        $result['_schema_definitions'] = $schemaDefinitions;
        $result['_warnings'] = array_values(array_unique($warnings));

        return $result;
    }

    private function statements($stream): Generator
    {
        $buffer = '';
        $quote = null;
        $escaped = false;
        $lineComment = false;
        $blockComment = false;
        $carry = '';

        while (! feof($stream)) {
            $chunk = $carry.fread($stream, 65536);
            if ($chunk === '') {
                break;
            }
            $isLast = feof($stream);
            $length = strlen($chunk);
            $processLength = $isLast ? $length : max(0, $length - 1);
            $carry = $isLast ? '' : $chunk[$length - 1];

            for ($index = 0; $index < $processLength; $index++) {
                $character = $chunk[$index];
                $next = $chunk[$index + 1] ?? '';

                if ($lineComment) {
                    if ($character === "\n") {
                        $lineComment = false;
                        $buffer .= "\n";
                    }

                    continue;
                }

                if ($blockComment) {
                    if ($character === '*' && $next === '/') {
                        $blockComment = false;
                        $index++;
                    }

                    continue;
                }

                if ($quote !== null) {
                    $buffer .= $character;
                    if ($escaped) {
                        $escaped = false;

                        continue;
                    }
                    if ($character === '\\') {
                        $escaped = true;

                        continue;
                    }
                    if ($character === $quote) {
                        if ($next === $quote) {
                            $buffer .= $next;
                            $index++;
                        } else {
                            $quote = null;
                        }
                    }

                    continue;
                }

                if ($character === '-' && $next === '-') {
                    $lineComment = true;
                    $index++;

                    continue;
                }
                if ($character === '#') {
                    $lineComment = true;

                    continue;
                }
                if ($character === '/' && $next === '*') {
                    $blockComment = true;
                    $index++;

                    continue;
                }
                if (in_array($character, ["'", '"', '`'], true)) {
                    $quote = $character;
                    $buffer .= $character;

                    continue;
                }
                if ($character === ';') {
                    yield trim($buffer);
                    $buffer = '';

                    continue;
                }

                $buffer .= $character;
            }
        }

        if ($carry !== '') {
            $buffer .= $carry;
        }
        if (trim($buffer) !== '') {
            yield trim($buffer);
        }
    }

    private function tuples(string $input): Generator
    {
        $depth = 0;
        $quote = null;
        $escaped = false;
        $buffer = '';
        $length = strlen($input);

        for ($index = 0; $index < $length; $index++) {
            $character = $input[$index];
            if ($quote !== null) {
                $buffer .= $character;
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === $quote) {
                    if (($input[$index + 1] ?? '') === $quote) {
                        $buffer .= $input[++$index];
                    } else {
                        $quote = null;
                    }
                }

                continue;
            }

            if (in_array($character, ["'", '"'], true)) {
                $quote = $character;
                $buffer .= $character;
            } elseif ($character === '(') {
                if ($depth > 0) {
                    $buffer .= $character;
                }
                $depth++;
            } elseif ($character === ')') {
                $depth--;
                if ($depth < 0) {
                    throw new InvalidArgumentException('Malformed VALUES tuple.');
                }
                if ($depth === 0) {
                    yield $buffer;
                    $buffer = '';
                } else {
                    $buffer .= $character;
                }
            } elseif ($depth > 0) {
                $buffer .= $character;
            } elseif (! in_array($character, [',', ' ', "\t", "\r", "\n"], true)) {
                throw new InvalidArgumentException('Unexpected data between VALUES tuples.');
            }
        }

        if ($depth !== 0 || $quote !== null) {
            throw new InvalidArgumentException('Unterminated VALUES tuple.');
        }
    }

    private function values(string $tuple): array
    {
        $values = [];
        $buffer = '';
        $quote = null;
        $escaped = false;
        $length = strlen($tuple);

        for ($index = 0; $index < $length; $index++) {
            $character = $tuple[$index];
            if ($quote !== null) {
                $buffer .= $character;
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === $quote) {
                    if (($tuple[$index + 1] ?? '') === $quote) {
                        $buffer .= $tuple[++$index];
                    } else {
                        $quote = null;
                    }
                }

                continue;
            }

            if (in_array($character, ["'", '"'], true)) {
                $quote = $character;
                $buffer .= $character;
            } elseif ($character === ',') {
                $values[] = $this->literal($buffer);
                $buffer = '';
            } else {
                $buffer .= $character;
            }
        }

        if ($quote !== null) {
            throw new InvalidArgumentException('Unterminated quoted SQL value.');
        }
        $values[] = $this->literal($buffer);

        return $values;
    }

    /** @return list<array{name: string, sql_type: string, nullable: bool}> */
    private function schemaDefinitions(string $definition): array
    {
        $columns = [];
        $buffer = '';
        $quote = null;
        $escaped = false;
        $depth = 0;
        $length = strlen($definition);

        $appendDefinition = static function (string $item) use (&$columns): void {
            if (preg_match('/^\s*`([^`]+)`\s+([A-Za-z]+(?:\s*\([^)]*\))?)(.*)$/is', $item, $match) === 1) {
                $columns[] = [
                    'name' => $match[1],
                    'sql_type' => mb_strtolower(preg_replace('/\s+/', '', $match[2])),
                    'nullable' => preg_match('/\bNOT\s+NULL\b/i', $match[3]) !== 1,
                ];
            }
        };

        for ($index = 0; $index < $length; $index++) {
            $character = $definition[$index];
            if ($quote !== null) {
                $buffer .= $character;
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === $quote) {
                    if (($definition[$index + 1] ?? '') === $quote) {
                        $buffer .= $definition[++$index];
                    } else {
                        $quote = null;
                    }
                }

                continue;
            }

            if (in_array($character, ["'", '"', '`'], true)) {
                $quote = $character;
                $buffer .= $character;
            } elseif ($character === '(') {
                $depth++;
                $buffer .= $character;
            } elseif ($character === ')') {
                $depth--;
                $buffer .= $character;
            } elseif ($character === ',' && $depth === 0) {
                $appendDefinition($buffer);
                $buffer = '';
            } else {
                $buffer .= $character;
            }
        }

        $appendDefinition($buffer);

        return $columns;
    }

    private function mappingType(string $sqlType): string
    {
        return match (true) {
            preg_match('/^(?:bool|boolean|bit\\(1\\)|binary\\(1\\)|tinyint\\(1\\))/', $sqlType) === 1 => 'boolean',
            preg_match('/^(?:tinyint|smallint|mediumint|int|integer|bigint)/', $sqlType) === 1 => 'integer',
            preg_match('/^(?:decimal|numeric|float|double)/', $sqlType) === 1 => 'decimal',
            preg_match('/^json/', $sqlType) === 1 => 'json',
            preg_match('/^(?:text|mediumtext|longtext)/', $sqlType) === 1 => 'longtext',
            default => 'text',
        };
    }

    private function isSensitiveField(string $field): bool
    {
        return preg_match('/(?:password|passwd|(?:^|[_-])pass(?:$|[_-])|token|secret|(?:api|access|private)[_-]?key|credential|community)/i', $field) === 1;
    }

    private function literal(string $literal): ?string
    {
        $literal = trim($literal);
        if (strcasecmp($literal, 'NULL') === 0) {
            return null;
        }

        if (strlen($literal) >= 2 && in_array($literal[0], ["'", '"'], true) && $literal[strlen($literal) - 1] === $literal[0]) {
            $quote = $literal[0];
            $value = substr($literal, 1, -1);
            $value = str_replace($quote.$quote, $quote, $value);

            return preg_replace_callback('/\\\\([0bnrtZ\\\\\'"])/', fn (array $match): string => match ($match[1]) {
                '0' => "\0",
                'b' => "\x08",
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                'Z' => "\x1A",
                default => $match[1],
            }, $value);
        }

        return $literal;
    }

    private function classifyTable(string $table, array &$warnings): string
    {
        if (in_array($table, self::DATA_TABLES, true)) {
            return 'data';
        }
        if (in_array($table, self::SENSITIVE_OR_SYSTEM_TABLES, true)) {
            return 'ignored';
        }

        $warnings[] = "Unknown dump table {$table} was ignored by the safety whitelist.";

        return 'unknown';
    }
}

<?php

declare(strict_types=1);

[$host, $database, $username, $databasePassword, $adminPassword, $readToken, $seedToken] = array_slice($argv, 1);

$pdo = new PDO("mysql:host={$host};dbname={$database};charset=utf8mb4", $username, $databasePassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

function columns(PDO $pdo, string $table): array
{
    $statement = $pdo->query("SHOW COLUMNS FROM `{$table}`");

    return array_fill_keys(array_map(static fn (array $column): string => $column['Field'], $statement->fetchAll(PDO::FETCH_ASSOC)), true);
}

function hasTable(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare('SHOW TABLES LIKE ?');
    $statement->execute([$table]);

    return $statement->fetchColumn() !== false;
}

function varcharLength(PDO $pdo, string $table, string $column): ?int
{
    $statement = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $statement->execute([$column]);
    $definition = $statement->fetch(PDO::FETCH_ASSOC);
    if (! is_array($definition) || ! preg_match('/^varchar\\((\\d+)\\)$/i', (string) ($definition['Type'] ?? ''), $matches)) {
        return null;
    }

    return (int) $matches[1];
}

function supportsFourByteUtf8(PDO $pdo, string $table, string $column): bool
{
    $statement = $pdo->prepare(
        'SELECT CHARACTER_SET_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
    );
    $statement->execute([$table, $column]);

    return strtolower((string) $statement->fetchColumn()) === 'utf8mb4';
}

function addColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    if (isset(columns($pdo, $table)[$column])) {
        return;
    }

    // The laboratory owns these fixed identifiers. Keeping them literal avoids
    // treating any corpus value as SQL while exercising phpIPAM custom fields.
    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
}

function insert(PDO $pdo, string $table, array $values): ?int
{
    if (! hasTable($pdo, $table)) {
        return null;
    }

    $available = columns($pdo, $table);
    $values = array_filter($values, static fn ($value, string $column): bool => isset($available[$column]), ARRAY_FILTER_USE_BOTH);
    if ($values === []) {
        return null;
    }

    $names = array_keys($values);
    $identifiers = implode(', ', array_map(static fn (string $name): string => "`{$name}`", $names));
    $placeholders = implode(', ', array_fill(0, count($names), '?'));
    $statement = $pdo->prepare("INSERT INTO `{$table}` ({$identifiers}) VALUES ({$placeholders})");
    $statement->execute(array_values($values));

    return (int) $pdo->lastInsertId();
}

function update(PDO $pdo, string $table, array $values, string $where, array $bindings): void
{
    if (! hasTable($pdo, $table)) {
        return;
    }

    $available = columns($pdo, $table);
    $values = array_filter($values, static fn ($value, string $column): bool => isset($available[$column]), ARRAY_FILTER_USE_BOTH);
    if ($values === []) {
        return;
    }

    $assignments = implode(', ', array_map(static fn (string $name): string => "`{$name}` = ?", array_keys($values)));
    $statement = $pdo->prepare("UPDATE `{$table}` SET {$assignments} WHERE {$where}");
    $statement->execute([...array_values($values), ...$bindings]);
}

// phpIPAM custom fields are database columns. Add a representative set before
// seeding so both the official API and a real mysqldump expose the same schema.
addColumn($pdo, 'subnets', 'lab_cf_text', 'varchar(255) NULL');
addColumn($pdo, 'subnets', 'lab_cf_integer', 'int NULL');
addColumn($pdo, 'subnets', 'lab_cf_boolean', 'tinyint(1) NULL');
addColumn($pdo, 'subnets', 'lab_cf_date', 'date NULL');
addColumn($pdo, 'subnets', 'lab_cf_url', 'varchar(255) NULL');
addColumn($pdo, 'subnets', 'lab_cf_json', 'json NULL');
addColumn($pdo, 'subnets', 'lab_cf_selection', 'varchar(32) NULL');

$pdo->beginTransaction();

try {
    $primaryFamily = getenv('IPAMFERRY_LAB_PRIMARY_FAMILY') ?: 'ipv4';
    if (! in_array($primaryFamily, ['ipv4', 'ipv6'], true)) {
        throw new RuntimeException('IPAMFERRY_LAB_PRIMARY_FAMILY must be ipv4 or ipv6.');
    }
    update($pdo, 'users', [
        'username' => 'lab-admin',
        'password' => password_hash($adminPassword, PASSWORD_DEFAULT),
        'real_name' => 'IpamFerry Lab Administrator',
        'email' => 'lab-admin@example.test',
        'passChange' => 'No',
    ], 'id = 1', []);
    update($pdo, 'settings', ['api' => 1], 'id = 1', []);

    insert($pdo, 'api', ['app_id' => 'ipamferry-read', 'app_code' => $readToken, 'app_permissions' => 1, 'app_comment' => 'Read-only migration discovery application', 'app_security' => 'ssl_code', 'app_lock_type' => 'Disabled']);
    insert($pdo, 'api', ['app_id' => 'ipamferry-seed', 'app_code' => $seedToken, 'app_permissions' => 2, 'app_comment' => 'Laboratory seeding application', 'app_security' => 'ssl_code', 'app_lock_type' => 'Disabled']);

    update($pdo, 'sections', ['name' => 'Lab Core', 'description' => 'Synthetic laboratory section'], 'id = 1', []);
    $edgeSection = insert($pdo, 'sections', ['name' => 'Lab Edge', 'description' => 'Synthetic edge section', 'permissions' => '{"2":2}', 'strictMode' => 1, 'subnetOrdering' => 'subnet']) ?? 1;
    $blueVrf = insert($pdo, 'vrf', ['name' => 'LAB-BLUE', 'rd' => '65000:100', 'description' => 'Private laboratory VRF']) ?? 0;
    $greenVrf = insert($pdo, 'vrf', ['name' => 'LAB-GREEN', 'rd' => '65000:200', 'description' => 'Private laboratory VRF']) ?? 0;
    $campusDomain = insert($pdo, 'vlanDomains', ['name' => 'LAB-CAMPUS', 'description' => 'Synthetic L2 domain']) ?? 0;
    $edgeDomain = insert($pdo, 'vlanDomains', ['name' => 'LAB-EDGE', 'description' => 'Synthetic L2 domain']) ?? 0;
    $blueVlan = insert($pdo, 'vlans', ['domainId' => $campusDomain, 'name' => 'LAB-USERS', 'number' => 120, 'description' => 'Synthetic users VLAN']) ?? 0;
    $greenVlan = insert($pdo, 'vlans', ['domainId' => $edgeDomain, 'name' => 'LAB-USERS', 'number' => 120, 'description' => 'Repeated VID in separate domain']) ?? 0;
    insert($pdo, 'vlans', ['domainId' => $campusDomain, 'name' => 'LAB-VLAN-ONE', 'number' => 1, 'description' => 'Lowest valid VLAN ID']) ?? 0;
    insert($pdo, 'vlans', ['domainId' => $edgeDomain, 'name' => 'LAB-VLAN-MAX', 'number' => 4094, 'description' => 'Highest valid VLAN ID']) ?? 0;
    $supportsEmoji = supportsFourByteUtf8($pdo, 'vlans', 'name');
    insert($pdo, 'vlans', [
        'domainId' => $campusDomain,
        'name' => $supportsEmoji ? 'VLAN Ñandú 🚢' : 'VLAN Ñandú',
        'number' => 121,
        'description' => 'Unicode VLAN name',
    ]) ?? 0;
    insert($pdo, 'vlans', ['domainId' => 0, 'name' => 'LAB-UNGROUPED', 'number' => 122, 'description' => 'VLAN without L2 domain']) ?? 0;
    insert($pdo, 'vlans', ['domainId' => $campusDomain, 'name' => 'LAB-VID-ZERO', 'number' => 0, 'description' => 'Reserved VLAN ID must be preserved']) ?? 0;
    insert($pdo, 'vlans', ['domainId' => $campusDomain, 'name' => 'LAB-VID-4095', 'number' => 4095, 'description' => 'Out-of-range VLAN ID must be preserved']) ?? 0;
    $unicodeDescription = "Comentários IPv6 — Bogotá\r\nsecond line with \"quotes\" & symbols";
    $descriptionAtSharedBoundary = str_repeat('D', min(varcharLength($pdo, 'ipaddresses', 'description') ?? 100, 200));
    $longComment = "# Migrated operator note\n**Unicode** ".($supportsEmoji ? '🚢 ' : '').'<em>safe</em> / \\" '.str_repeat('comment-data-', 13);
    $blueSubnet = insert($pdo, 'subnets', ['subnet' => '10.120.0.0', 'mask' => 24, 'sectionId' => 1, 'description' => 'Lab IPv4 Blue', 'vrfId' => $blueVrf, 'masterSubnetId' => 0, 'allowRequests' => 0, 'vlanId' => $blueVlan, 'showName' => 1, 'permissions' => '{"2":2}', 'isFolder' => 0]) ?? 0;
    update($pdo, 'subnets', [
        'lab_cf_text' => 'Unicode text Ñandú',
        'lab_cf_integer' => 42,
        'lab_cf_boolean' => 1,
        'lab_cf_date' => '2026-07-26',
        'lab_cf_url' => 'https://example.test/ipamferry',
        'lab_cf_json' => '{"environment":"lab","enabled":true}',
        'lab_cf_selection' => 'production',
    ], 'id = ?', [$blueSubnet]);
    $greenSubnet = insert($pdo, 'subnets', ['subnet' => '10.120.0.0', 'mask' => 24, 'sectionId' => $edgeSection, 'description' => 'Lab IPv4 Green', 'vrfId' => $greenVrf, 'masterSubnetId' => 0, 'allowRequests' => 0, 'vlanId' => $greenVlan, 'showName' => 1, 'permissions' => '{"2":2}', 'isFolder' => 0]) ?? 0;
    $ipv6Subnet = insert($pdo, 'subnets', ['subnet' => '42540766411630763492952998153969205248', 'mask' => 64, 'sectionId' => 1, 'description' => 'Lab IPv6 Blue', 'vrfId' => $blueVrf, 'masterSubnetId' => 0, 'allowRequests' => 0, 'showName' => 1, 'permissions' => '{"2":2}', 'isFolder' => 0]) ?? 0;
    $poolSubnet = insert($pdo, 'subnets', ['subnet' => '10.121.0.0', 'mask' => 24, 'sectionId' => 1, 'description' => 'Lab pool', 'vrfId' => $blueVrf, 'masterSubnetId' => 0, 'allowRequests' => 0, 'showName' => 1, 'permissions' => '{"2":2}', 'isFolder' => 0, 'isPool' => 1]) ?? 0;
    $blueChildSubnet = insert($pdo, 'subnets', ['subnet' => '175636480', 'mask' => 25, 'sectionId' => 1, 'description' => 'Lab IPv4 Blue child hierarchy', 'vrfId' => $blueVrf, 'masterSubnetId' => $blueSubnet, 'allowRequests' => 0, 'showName' => 1, 'permissions' => '{"2":2}', 'isFolder' => 0]) ?? 0;
    $fullSubnet = insert($pdo, 'subnets', ['subnet' => '175702272', 'mask' => 24, 'sectionId' => 1, 'description' => 'Lab utilized prefix', 'vrfId' => $blueVrf, 'masterSubnetId' => 0, 'allowRequests' => 0, 'showName' => 1, 'permissions' => '{"2":2}', 'isFolder' => 0, 'isFull' => 1]) ?? 0;
    $folderSubnet = insert($pdo, 'subnets', ['subnet' => '0', 'mask' => 0, 'sectionId' => 1, 'description' => 'Lab phpIPAM folder preserved', 'vrfId' => $blueVrf, 'masterSubnetId' => 0, 'allowRequests' => 0, 'showName' => 1, 'permissions' => '{"2":2}', 'isFolder' => 1]) ?? 0;
    $ipv4PointToPoint = insert($pdo, 'subnets', ['subnet' => '175636736', 'mask' => 31, 'sectionId' => 1, 'description' => 'IPv4 point-to-point /31', 'vrfId' => $blueVrf, 'masterSubnetId' => 0, 'allowRequests' => 0, 'showName' => 1, 'permissions' => '{"2":2}', 'isFolder' => 0]) ?? 0;
    $ipv4HostRoute = insert($pdo, 'subnets', ['subnet' => '175636738', 'mask' => 32, 'sectionId' => 1, 'description' => $descriptionAtSharedBoundary, 'vrfId' => $blueVrf, 'masterSubnetId' => 0, 'allowRequests' => 0, 'showName' => 1, 'permissions' => '{"2":2}', 'isFolder' => 0]) ?? 0;
    $ipv6PointToPoint = insert($pdo, 'subnets', ['subnet' => '42540766411630763511399742227678756864', 'mask' => 127, 'sectionId' => 1, 'description' => $unicodeDescription, 'vrfId' => $blueVrf, 'masterSubnetId' => 0, 'allowRequests' => 0, 'showName' => 1, 'permissions' => '{"2":2}', 'isFolder' => 0]) ?? 0;
    $ipv6FourAddress = insert($pdo, 'subnets', ['subnet' => '42540766411630763548293230375097860099', 'mask' => 126, 'sectionId' => 1, 'description' => 'IPv6 /126 host bits canonicalize', 'vrfId' => $blueVrf, 'masterSubnetId' => 0, 'allowRequests' => 0, 'showName' => 1, 'permissions' => '{"2":2}', 'isFolder' => 0]) ?? 0;
    $ipv6HostRoute = insert($pdo, 'subnets', ['subnet' => '42540766411630763529846486301388308480', 'mask' => 128, 'sectionId' => 1, 'description' => 'IPv6 host route /128', 'vrfId' => $blueVrf, 'masterSubnetId' => 0, 'allowRequests' => 0, 'showName' => 1, 'permissions' => '{"2":2}', 'isFolder' => 0]) ?? 0;
    $ipv4Default = insert($pdo, 'subnets', ['subnet' => '0', 'mask' => 0, 'sectionId' => $edgeSection, 'description' => 'IPv4 default route /0', 'vrfId' => $greenVrf, 'masterSubnetId' => 0, 'allowRequests' => 0, 'showName' => 1, 'permissions' => '{"2":2}', 'isFolder' => 0]) ?? 0;
    $ipv4Half = insert($pdo, 'subnets', ['subnet' => '2147483649', 'mask' => 1, 'sectionId' => $edgeSection, 'description' => 'IPv4 host bits canonicalize to /1', 'vrfId' => $greenVrf, 'masterSubnetId' => $ipv4Default, 'allowRequests' => 0, 'showName' => 1, 'permissions' => '{"2":2}', 'isFolder' => 0]) ?? 0;
    $ipv4Thirty = insert($pdo, 'subnets', ['subnet' => '3325256707', 'mask' => 30, 'sectionId' => $edgeSection, 'description' => 'IPv4 host bits canonicalize to /30', 'vrfId' => $greenVrf, 'masterSubnetId' => 0, 'allowRequests' => 0, 'showName' => 1, 'permissions' => '{"2":2}', 'isFolder' => 0]) ?? 0;
    $ipv6LinkLocal = insert($pdo, 'subnets', ['subnet' => '338288524927261089654018896841347699252', 'mask' => 64, 'sectionId' => $edgeSection, 'description' => 'IPv6 link-local host bits', 'vrfId' => $greenVrf, 'masterSubnetId' => 0, 'allowRequests' => 0, 'showName' => 1, 'permissions' => '{"2":2}', 'isFolder' => 0]) ?? 0;
    $ipv6Ula = insert($pdo, 'subnets', ['subnet' => '336389205813283084610353874626578262989', 'mask' => 64, 'sectionId' => $edgeSection, 'description' => 'IPv6 ULA host bits', 'vrfId' => $greenVrf, 'masterSubnetId' => 0, 'allowRequests' => 0, 'showName' => 1, 'permissions' => '{"2":2}', 'isFolder' => 0]) ?? 0;
    $ipv6Multicast = insert($pdo, 'subnets', ['subnet' => '339275061330382706903439691146046996481', 'mask' => 64, 'sectionId' => $edgeSection, 'description' => 'IPv6 multicast host bits', 'vrfId' => $greenVrf, 'masterSubnetId' => 0, 'allowRequests' => 0, 'showName' => 1, 'permissions' => '{"2":2}', 'isFolder' => 0]) ?? 0;

    $campus = insert($pdo, 'locations', ['name' => 'Lab Campus', 'description' => 'Synthetic campus', 'address' => '1 Example Lane', 'lat' => '4.7110', 'long' => '-74.0721']) ?? 0;
    $room = insert($pdo, 'locations', ['name' => 'Lab Room A', 'description' => 'Synthetic room', 'address' => '1 Example Lane', 'lat' => '4.7111', 'long' => '-74.0722']) ?? 0;
    $rack = insert($pdo, 'racks', ['name' => 'LAB-RACK-01', 'size' => 42, 'description' => 'Synthetic rack', 'location' => $campus]) ?? 0;
    $deviceType = insert($pdo, 'deviceTypes', ['tname' => 'Lab Router', 'tdescription' => 'Synthetic device type']) ?? 1;
    $device = insert($pdo, 'devices', ['hostname' => 'lab-rtr-01', 'ip_addr' => $primaryFamily === 'ipv6' ? '2001:db8:120::2' : '10.120.0.2', 'type' => $deviceType, 'description' => 'Synthetic router', 'snmp_community' => 'lab-snmp-community-do-not-export', 'snmp_v3_auth_pass' => 'lab-snmp-password-do-not-export', 'rack' => $rack, 'rack_start' => 1, 'rack_size' => 2, 'rack_deep' => 0, 'location' => $campus]) ?? 0;
    $customer = insert($pdo, 'customers', ['title' => 'Example Tenant', 'address' => '1 Example Lane', 'city' => 'Example City', 'state' => 'Example State', 'contact_person' => 'Example Contact', 'contact_phone' => '+1 555 0100', 'contact_mail' => 'contact@example.test', 'note' => 'Synthetic customer', 'status' => 'Active']) ?? 0;
    insert($pdo, 'customers', [
        'title' => 'Invalid Contact Tenant',
        'address' => "2 Example Lane\nFloor 3\nBogotá",
        'city' => 'Example City',
        'state' => 'Example State',
        'contact_person' => 'Invalid Contact',
        'contact_phone' => '+1 555 0101',
        'contact_mail' => 'invalid contact@example.test',
        'note' => 'Contact must remain preserved, while the tenant can migrate',
        'status' => 'Active',
    ]);
    $blueGatewayAddress = insert($pdo, 'ipaddresses', ['subnetId' => $blueSubnet, 'ip_addr' => '175636481', 'is_gateway' => 1, 'description' => 'Gateway', 'hostname' => 'gw.lab.example.test', 'mac' => '02:00:00:00:12:01', 'state' => 2, 'switch' => $device, 'port' => 'ge-0/0/0', 'customer_id' => $customer]) ?? 0;
    $blueRouterAddress = insert($pdo, 'ipaddresses', ['subnetId' => $blueSubnet, 'ip_addr' => '175636482', 'description' => 'Router interface', 'hostname' => 'lab-rtr-01.lab.example.test', 'mac' => '02:00:00:00:12:02', 'state' => 2, 'switch' => $device, 'port' => 'ge-0/0/1', 'customer_id' => $customer]) ?? 0;
    insert($pdo, 'ipaddresses', ['subnetId' => $blueSubnet, 'ip_addr' => '175636483', 'description' => 'No port warning', 'hostname' => 'orphan.lab.example.test', 'mac' => 'invalid-mac', 'state' => 2, 'switch' => $device, 'customer_id' => $customer]);
    insert($pdo, 'ipaddresses', ['subnetId' => $blueSubnet, 'ip_addr' => '175636485', 'description' => 'Invalid DNS name must be preserved', 'hostname' => 'invalid host_name.example.test', 'state' => 2, 'customer_id' => $customer]);
    $natOutsideAddress = insert($pdo, 'ipaddresses', ['subnetId' => $poolSubnet, 'ip_addr' => '175702018', 'description' => 'Safe static NAT outside address', 'hostname' => 'nat-outside.lab.example.test', 'state' => 2, 'customer_id' => $customer]) ?? 0;
    $natManyInside = insert($pdo, 'ipaddresses', ['subnetId' => $blueSubnet, 'ip_addr' => '175636484', 'description' => 'NAT many-to-many inside candidate', 'state' => 2, 'customer_id' => $customer]) ?? 0;
    $natManyOutsideA = insert($pdo, 'ipaddresses', ['subnetId' => $poolSubnet, 'ip_addr' => '175702019', 'description' => 'NAT many-to-many outside A', 'state' => 2, 'customer_id' => $customer]) ?? 0;
    $natManyOutsideB = insert($pdo, 'ipaddresses', ['subnetId' => $poolSubnet, 'ip_addr' => '175702020', 'description' => 'NAT many-to-many outside B', 'state' => 2, 'customer_id' => $customer]) ?? 0;
    $greenRouterAddress = insert($pdo, 'ipaddresses', ['subnetId' => $greenSubnet, 'ip_addr' => '175636482', 'description' => 'Same host in green VRF', 'state' => 2, 'customer_id' => $customer]) ?? 0;
    insert($pdo, 'ipaddresses', ['subnetId' => $ipv6Subnet, 'ip_addr' => '42540766411630763492952998153969205250', 'description' => 'IPv6 host', 'hostname' => 'v6.lab.example.test', 'state' => 2, 'customer_id' => $customer, ...($primaryFamily === 'ipv6' ? ['switch' => $device, 'port' => 'ge-0/0/2'] : [])]);
    insert($pdo, 'ipaddresses', ['subnetId' => $ipv4PointToPoint, 'ip_addr' => '175636736', 'description' => 'IPv4 /31 first endpoint', 'state' => 2, 'customer_id' => $customer]);
    insert($pdo, 'ipaddresses', ['subnetId' => $ipv4PointToPoint, 'ip_addr' => '175636737', 'description' => 'IPv4 /31 second endpoint', 'state' => 2, 'customer_id' => $customer]);
    insert($pdo, 'ipaddresses', ['subnetId' => $ipv4HostRoute, 'ip_addr' => '175636738', 'description' => $descriptionAtSharedBoundary, 'note' => $longComment, 'state' => 2, 'customer_id' => $customer]);
    insert($pdo, 'ipaddresses', ['subnetId' => $ipv6PointToPoint, 'ip_addr' => '42540766411630763511399742227678756864', 'description' => 'IPv6 /127 first endpoint', 'state' => 2, 'customer_id' => $customer]);
    insert($pdo, 'ipaddresses', ['subnetId' => $ipv6PointToPoint, 'ip_addr' => '42540766411630763511399742227678756865', 'description' => $unicodeDescription, 'state' => 2, 'customer_id' => $customer]);
    insert($pdo, 'ipaddresses', ['subnetId' => $ipv6FourAddress, 'ip_addr' => '42540766411630763548293230375097860099', 'description' => 'IPv6 /126 fourth endpoint', 'state' => 2, 'customer_id' => $customer]);
    insert($pdo, 'ipaddresses', ['subnetId' => $ipv6HostRoute, 'ip_addr' => '42540766411630763529846486301388308480', 'description' => 'IPv6 host route /128', 'state' => 2, 'customer_id' => $customer]);
    insert($pdo, 'ipaddresses', ['subnetId' => $ipv4Default, 'ip_addr' => '0', 'description' => 'IPv4 default address', 'state' => 2, 'customer_id' => $customer]);
    insert($pdo, 'ipaddresses', ['subnetId' => $ipv4Half, 'ip_addr' => '2147483649', 'description' => 'IPv4 /1 host bit address', 'state' => 2, 'customer_id' => $customer]);
    insert($pdo, 'ipaddresses', ['subnetId' => $ipv4Thirty, 'ip_addr' => '3325256707', 'description' => 'IPv4 /30 host bit address', 'state' => 2, 'customer_id' => $customer]);
    insert($pdo, 'ipaddresses', ['subnetId' => $ipv6LinkLocal, 'ip_addr' => '338288524927261089654018896841347699252', 'description' => 'IPv6 link-local address', 'state' => 2, 'customer_id' => $customer]);
    insert($pdo, 'ipaddresses', ['subnetId' => $ipv6Ula, 'ip_addr' => '336389205813283084610353874626578262989', 'description' => 'IPv6 ULA address', 'state' => 2, 'customer_id' => $customer]);
    insert($pdo, 'ipaddresses', ['subnetId' => $ipv6Multicast, 'ip_addr' => '339275061330382706903439691146046996481', 'description' => 'IPv6 multicast address', 'state' => 2, 'customer_id' => $customer]);

    insert($pdo, 'nameservers', ['name' => 'Lab NS', 'namesrv1' => '192.0.2.53;2001:db8::53', 'description' => 'Preservation example', 'permissions' => '2']);
    $provider = insert($pdo, 'circuitProviders', ['name' => 'Example Carrier', 'description' => 'Synthetic provider']) ?? 0;
    $circuitType = insert($pdo, 'circuitTypes', ['ctname' => 'Synthetic Transit']) ?? 1;
    insert($pdo, 'circuits', ['cid' => 'LAB-CIR-001', 'provider' => $provider, 'type' => $circuitType, 'capacity' => '1000', 'status' => 'Active', 'location1' => $campus, 'location2' => $room, 'location_a' => $campus, 'location_b' => $room, 'comment' => 'Complete laboratory circuit']);
    insert($pdo, 'circuits', ['cid' => 'LAB-CIR-INCOMPLETE', 'provider' => $provider, 'type' => $circuitType, 'capacity' => '100', 'status' => 'Reserved', 'location1' => $campus, 'location_a' => $campus, 'comment' => 'Incomplete termination']);
    insert($pdo, 'routing_bgp', ['local_as' => 65000, 'local_address' => '10.120.0.2', 'peer_name' => 'LAB-PEER', 'peer_as' => 65001, 'peer_address' => '10.120.0.254', 'bgp_type' => 'external', 'vrf_id' => $blueVrf, 'description' => 'Private BGP preservation example']);
    insert($pdo, 'firewallZones', ['generator' => 0, 'length' => 24, 'padding' => 0, 'zone' => 'LAB-FW', 'indicator' => 'LAB', 'description' => 'Preserved firewall zone', 'permissions' => '{"2":2}']);
    insert($pdo, 'scanAgents', ['name' => 'LAB-SCAN', 'description' => 'Sensitive configuration excluded', 'type' => 'api', 'code' => 'sensitive-value-excluded', 'last_access' => date('Y-m-d H:i:s')]);
    insert($pdo, 'nat', ['name' => 'LAB-NAT-STATIC', 'type' => 'static', 'src' => $blueRouterAddress, 'dst' => '198.51.100.2', 'device' => $device, 'description' => 'Static one-to-one candidate', 'policy' => 'No']);
    insert($pdo, 'nat', ['name' => 'LAB-NAT-1TO1', 'type' => 'static', 'src' => $blueRouterAddress, 'dst' => $natOutsideAddress, 'device' => $device, 'description' => 'Confirmed same-VRF static one-to-one candidate', 'policy' => 'No']);
    insert($pdo, 'nat', ['name' => 'LAB-NAT-PAT', 'type' => 'source', 'src' => $blueGatewayAddress, 'dst' => '198.51.100.3', 'src_port' => '443', 'dst_port' => '8443', 'device' => $device, 'description' => 'PAT preservation candidate', 'policy' => 'Yes']);
    insert($pdo, 'nat', ['name' => 'LAB-NAT-CROSS-VRF', 'type' => 'static', 'src' => $blueRouterAddress, 'dst' => $greenRouterAddress, 'device' => $device, 'description' => 'Cross-VRF candidate must remain preserved', 'policy' => 'No']);
    insert($pdo, 'nat', ['name' => 'LAB-NAT-MANY-A', 'type' => 'static', 'src' => $natManyInside, 'dst' => $natManyOutsideA, 'device' => $device, 'description' => 'Shared inside NAT candidate A', 'policy' => 'No']);
    insert($pdo, 'nat', ['name' => 'LAB-NAT-MANY-B', 'type' => 'static', 'src' => $natManyInside, 'dst' => $natManyOutsideB, 'device' => $device, 'description' => 'Shared inside NAT candidate B', 'policy' => 'No']);
    $pdo->commit();
} catch (Throwable $exception) {
    $pdo->rollBack();
    throw $exception;
}

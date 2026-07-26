CREATE TABLE `customers` (
  `id` int NOT NULL,
  `title` varchar(100) NOT NULL,
  `description` text,
  `contact_person` varchar(100),
  `contact_mail` varchar(255),
  `contact_phone` varchar(64),
  `address` text
);
INSERT INTO `customers` (`id`, `title`, `description`, `contact_person`, `contact_mail`, `contact_phone`, `address`) VALUES
  (1, 'IpamFerry E2E Tenant', 'Expanded migration tenant', 'Network Team', 'network@example.test', '+1 555 0100', 'Bogota');

CREATE TABLE `locations` (
  `id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text,
  `address` text
);
INSERT INTO `locations` (`id`, `name`, `description`, `address`) VALUES
  (1, 'IpamFerry E2E Site', 'Expanded migration site', 'Bogota');

CREATE TABLE `racks` (
  `id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text,
  `location` int,
  `size` int
);
INSERT INTO `racks` (`id`, `name`, `description`, `location`, `size`) VALUES
  (1, 'E2E-RACK-01', 'Expanded migration rack', 1, 42);

CREATE TABLE `deviceTypes` (
  `id` int NOT NULL,
  `tname` varchar(100) NOT NULL,
  `tdescription` text
);
INSERT INTO `deviceTypes` (`id`, `tname`, `tdescription`) VALUES
  (1, 'Router', 'Expanded migration role');

CREATE TABLE `devices` (
  `id` int NOT NULL,
  `hostname` varchar(100) NOT NULL,
  `ip_addr` varchar(64),
  `type` int,
  `description` text,
  `rack` int,
  `rack_start` int,
  `rack_size` int,
  `rack_deep` tinyint,
  `location` int
);
INSERT INTO `devices` (`id`, `hostname`, `ip_addr`, `type`, `description`, `rack`, `rack_start`, `rack_size`, `rack_deep`, `location`) VALUES
  (1, 'e2e-router-01', '10.23.0.10', 1, 'Expanded migration device', 1, 40, 1, 0, 1);

CREATE TABLE `subnets` (
  `id` int NOT NULL,
  `subnet` varchar(64) NOT NULL,
  `mask` int NOT NULL,
  `description` text,
  `customer_id` int,
  `isFolder` tinyint,
  `isFull` tinyint,
  `isPool` tinyint,
  `state` int,
  `location` int
);
INSERT INTO `subnets` (`id`, `subnet`, `mask`, `description`, `customer_id`, `isFolder`, `isFull`, `isPool`, `state`, `location`) VALUES
  (1, '10.23.0.0', 24, 'Inside prefix', 1, 0, 0, 0, 2, 1),
  (2, '203.0.113.0', 24, 'Outside prefix', 1, 0, 0, 0, 2, 1);

CREATE TABLE `ipaddresses` (
  `id` int NOT NULL,
  `subnetId` int NOT NULL,
  `ip_addr` varchar(64) NOT NULL,
  `is_gateway` tinyint,
  `description` text,
  `hostname` varchar(255),
  `mac` varchar(17),
  `state` int,
  `tag` int,
  `deviceId` int,
  `location` int,
  `port` varchar(100),
  `customer_id` int,
  `NAT_address` int
);
INSERT INTO `ipaddresses` (`id`, `subnetId`, `ip_addr`, `is_gateway`, `description`, `hostname`, `mac`, `state`, `tag`, `deviceId`, `location`, `port`, `customer_id`, `NAT_address`) VALUES
  (101, 1, '10.23.0.10', 0, 'Inside address', 'e2e-router-01.example.test', '02:00:00:00:00:01', 2, 2, 1, 1, 'eth0', 1, NULL),
  (102, 2, '203.0.113.10', 0, 'Outside address', 'edge.example.test', NULL, 2, 2, NULL, 1, NULL, 1, 101);

CREATE TABLE `circuitProviders` (
  `id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text
);
INSERT INTO `circuitProviders` (`id`, `name`, `description`) VALUES
  (1, 'IpamFerry E2E Carrier', 'Expanded migration provider');

CREATE TABLE `circuitTypes` (
  `id` int NOT NULL,
  `ctname` varchar(64) NOT NULL,
  `ctcolor` varchar(7)
);
INSERT INTO `circuitTypes` (`id`, `ctname`, `ctcolor`) VALUES
  (1, 'Internet Transit', '#38bdf8');

CREATE TABLE `circuits` (
  `id` int NOT NULL,
  `cid` varchar(100) NOT NULL,
  `provider` int NOT NULL,
  `type` int NOT NULL,
  `comment` text,
  `location1` int,
  `location2` int,
  `status` varchar(32)
);
INSERT INTO `circuits` (`id`, `cid`, `provider`, `type`, `comment`, `location1`, `location2`, `status`) VALUES
  (1, 'IPAMFERRY-E2E-CIRCUIT', 1, 1, 'Expanded migration circuit', 1, 1, 'active');

CREATE TABLE `routing_bgp` (
  `id` int NOT NULL,
  `local_as` bigint NOT NULL,
  `remote_as` bigint NOT NULL,
  `description` text
);
INSERT INTO `routing_bgp` (`id`, `local_as`, `remote_as`, `description`) VALUES
  (1, 64512, 64513, 'BGP session preserved while ASNs are migrated');

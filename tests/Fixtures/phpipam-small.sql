CREATE TABLE `vrf` (
  `vrfId` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `rd` varchar(32) DEFAULT NULL,
  `description` text
);
INSERT INTO `vrf` (`vrfId`, `name`, `rd`, `description`) VALUES
  (4242, 'IpamFerry E2E VRF', '64512:4242', 'Created by the isolated end-to-end test');

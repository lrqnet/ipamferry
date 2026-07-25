<?php

namespace Tests\Unit;

use App\Domain\Migration\SqlDumpParser;
use InvalidArgumentException;
use Tests\TestCase;

class SqlDumpParserTest extends TestCase
{
    public function test_it_parses_standard_mysqldump_rows_with_or_without_insert_columns(): void
    {
        $sql = <<<'SQL'
        CREATE DATABASE IF NOT EXISTS `phpipam`;
        USE `phpipam`;
        CREATE TABLE `subnets` (
          `id` int NOT NULL,
          `subnet` varchar(255),
          `mask` varchar(3),
          `description` text
        ) ENGINE=InnoDB;
        INSERT INTO `subnets` VALUES
          ('1','167772160','24','Core; network'),
          ('2','167772416','24','User\'s (east), floor');
        INSERT INTO `subnets` (`id`,`subnet`,`mask`,`description`) VALUES ('3','167772672','24',NULL);
        SQL;

        $data = (new SqlDumpParser)->parse($sql);

        self::assertCount(3, $data['subnets']);
        self::assertSame('Core; network', $data['subnets'][0]['description']);
        self::assertSame("User's (east), floor", $data['subnets'][1]['description']);
        self::assertNull($data['subnets'][2]['description']);
    }

    public function test_it_ignores_sensitive_and_unknown_tables_but_reports_unknown_data(): void
    {
        $sql = <<<'SQL'
        CREATE TABLE `users` (`id` int, `password` text);
        INSERT INTO `users` VALUES ('1','secret-value');
        CREATE TABLE `plugin_private` (`id` int, `value` text);
        INSERT INTO `plugin_private` VALUES ('1','not-imported');
        CREATE TABLE `vrf` (`vrfId` int, `name` text, `rd` text);
        INSERT INTO `vrf` VALUES ('4','Blue','65000:4');
        SQL;

        $data = (new SqlDumpParser)->parse($sql);

        self::assertArrayNotHasKey('users', $data);
        self::assertArrayNotHasKey('plugin_private', $data);
        self::assertSame('Blue', $data['vrf'][0]['name']);
        self::assertStringContainsString('plugin_private', implode(' ', $data['_warnings']));
        self::assertStringNotContainsString('secret-value', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function test_it_rejects_executable_or_unsupported_sql(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SqlDumpParser)->parse("UPDATE `subnets` SET `description` = 'changed';");
    }

    public function test_it_handles_statements_larger_than_the_stream_chunk(): void
    {
        $description = str_repeat('safe-data-', 8000);
        $sql = "INSERT INTO `subnets` (`id`,`description`) VALUES ('1','{$description}');";

        $data = (new SqlDumpParser)->parse($sql);

        self::assertSame($description, $data['subnets'][0]['description']);
    }

    public function test_it_enforces_the_configured_row_limit(): void
    {
        config()->set('ipamferry.dump_max_rows', 1);
        $this->expectException(InvalidArgumentException::class);

        (new SqlDumpParser)->parse("INSERT INTO `subnets` (`id`) VALUES ('1'),('2');");
    }

    public function test_it_catalogs_dump_custom_fields_without_sensitive_columns(): void
    {
        $sql = <<<'SQL'
        CREATE TABLE `subnets` (
          `id` int NOT NULL,
          `subnet` varchar(255),
          `mask` varchar(3),
          `rack_code` varchar(32) NOT NULL,
          `legacy_weight` int NULL,
          `api_secret` text
        );
        SQL;

        $parser = new SqlDumpParser;
        $parsed = $parser->parse($sql);
        $catalog = $parser->customFieldDefinitions($parsed);

        self::assertSame('rack_code', $catalog['prefix'][0]['name']);
        self::assertSame('text', $catalog['prefix'][0]['data_type']);
        self::assertFalse($catalog['prefix'][0]['nullable']);
        self::assertSame('integer', $catalog['prefix'][1]['data_type']);
        self::assertStringNotContainsString('api_secret', json_encode($catalog, JSON_THROW_ON_ERROR));
    }
}

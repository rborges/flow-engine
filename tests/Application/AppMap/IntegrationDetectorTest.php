<?php

namespace Tests\Application\AppMap;

use FlowEngine\Application\AppMap\IntegrationDetector;
use FlowEngine\Domain\Flow\Flow;
use FlowEngine\Domain\Flow\Node;
use PHPUnit\Framework\TestCase;

final class IntegrationDetectorTest extends TestCase
{
    public function test_it_extracts_http_metadata(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'flow-engine-detector-' . uniqid();
        mkdir($base . DIRECTORY_SEPARATOR . 'src', 0777, true);

        $file = $base . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'A.php';
        file_put_contents(
            $file,
            "<?php\nclass A { public function run(){ \$u='https://api.example.com/v1/users?active=1'; return \$u; } }\n"
        );

        $flow = new Flow([
            new Node('A', 'run', $file, 2, 'php'),
        ], []);

        $calls = (new IntegrationDetector())->detect($flow, $base, [$file]);
        self::assertNotEmpty($calls);

        $http = array_values(array_filter($calls, fn($c) => $c->type === 'http'));
        self::assertNotEmpty($http);

        $first = $http[0]->toArray();
        self::assertSame('api.example.com', $first['metadata']['host']);
        self::assertSame('/v1/users', $first['metadata']['path']);
        self::assertSame('https', $first['metadata']['scheme']);
    }

    public function test_it_extracts_dart_api_constant_calls(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'flow-engine-detector-dart-' . uniqid();
        mkdir($base . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'constants', 0777, true);
        mkdir($base . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'datasources', 0777, true);

        file_put_contents(
            $base . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'constants' . DIRECTORY_SEPARATOR . 'api_constants.dart',
            <<<'DART'
class ApiConstants {
  static const String baseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://localhost:8000/v1',
  );
  static const String userMe = '$baseUrl/users/me';
}
DART
        );

        $file = $base . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'datasources' . DIRECTORY_SEPARATOR . 'user_remote_data_source.dart';
        file_put_contents(
            $file,
            <<<'DART'
import 'package:dio/dio.dart';
import 'package:the_singer/core/constants/api_constants.dart';

class UserRemoteDataSourceImpl {
  Future<void> load(Dio dio) async {
    await dio.get(ApiConstants.userMe);
  }
}
DART
        );

        $flow = new Flow([
            new Node('lib.data.datasources.user_remote_data_source', 'module', $file, 1, 'dart'),
        ], []);

        $calls = (new IntegrationDetector())->detect($flow, $base, [$file]);
        self::assertNotEmpty($calls);

        $http = array_values(array_filter($calls, fn($c) => $c->type === 'http'));
        self::assertNotEmpty($http);
        self::assertSame('localhost', $http[0]->metadata['host']);
        self::assertSame('/v1/users/me', $http[0]->metadata['path']);
    }

    public function test_only_real_calls_survive_license_header_and_docblocks(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'flow-engine-detector-noise-' . uniqid();
        mkdir($base, 0777, true);
        $file = $base . DIRECTORY_SEPARATOR . 'Client.php';
        file_put_contents($file, <<<'PHP'
<?php
/**
 * Licensed under the GPL: https://www.gnu.org/licenses/gpl-3.0.html
 */
class Client
{
    /**
     * @see https://docs.example.com/orders
     */
    public function orders()
    {
        // fallback: https://legacy.example.com
        return file_get_contents('https://api.example.com/orders');
    }

    public function logo()
    {
        return '<img src="https://static.example.com/logo.png">';
    }
}
PHP);

        $flow = new Flow([
            new Node('Client', 'orders', $file, 10, 'php'),
            new Node('Client', 'logo', $file, 16, 'php'),
        ], []);

        $http = array_values(array_filter(
            (new IntegrationDetector())->detect($flow, $base, [$file]),
            fn($c) => $c->type === 'http'
        ));

        self::assertSame(
            [['https://api.example.com/orders', 13, 'http'], ['https://static.example.com/logo.png', 18, 'asset']],
            array_map(fn($c) => [$c->target, $c->fromLine, $c->metadata['kind']], $http)
        );
    }
}

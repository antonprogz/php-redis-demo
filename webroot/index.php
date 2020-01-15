<?php


//echo phpinfo();

require __DIR__ . '/../vendor/autoload.php';

$sentinels = [
    'tcp://sentinel1:26379?timeout=0.100',
    'tcp://sentinel2:26379?timeout=0.100',
    'tcp://sentinel3:26379?timeout=0.100',
];

$client = new Predis\Client($sentinels, [
    'aggregate' => function () {
        return function ($sentinels, $options) {
            return new \Predis\Connection\Aggregate\SentinelReplication(
                $options->service,
                $sentinels,
                $options->connections,
                new class extends \Predis\Replication\ReplicationStrategy {
                    protected function getReadOnlyOperations()
                    {
                        return [];
                    }
                }
            );
        };
    },
    'service' => 'mymaster',
]);

$handler = new \demo\LockingHandler($client);
$handler->register();

session_start();
if (!isset($_SESSION['count'])) {
    $_SESSION['count'] = 0;
} else {
    $_SESSION['count']++;
}

echo $_SESSION['count'];
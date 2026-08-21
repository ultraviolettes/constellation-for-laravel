<?php

namespace Ultraviolettes\Constellation\Http;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use Ultraviolettes\Constellation\Constellation;

final class SnapshotClient
{
    public function __construct(
        private readonly Http $http,
        private readonly Config $config,
        private readonly Application $app,
    ) {}

    /**
     * Neither ->retry() nor ->throw(): status codes are handled explicitly by
     * the job, and HTTP-level retries would multiply the real attempts without
     * going through the abandon logic (S27).
     *
     * @throws ConnectionException
     */
    public function send(string $body, string $idempotencyKey): Response
    {
        return $this->http
            // Read at call time, never carried in the job payload (S26).
            ->withToken((string) $this->config->get('constellation.token'))
            ->withHeaders([
                'Idempotency-Key' => $idempotencyKey,
                'Accept' => 'application/json',
            ])
            ->withUserAgent(Constellation::userAgent($this->app->version(), PHP_VERSION))
            ->connectTimeout((int) $this->config->get('constellation.http.connect_timeout', 5))
            ->timeout((int) $this->config->get('constellation.http.timeout', 10))
            ->withBody($body, 'application/json')
            ->post((string) $this->config->get('constellation.endpoint'));
    }
}

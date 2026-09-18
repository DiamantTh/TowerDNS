<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Services\HealthStatusService;

final readonly class HealthHandler implements RequestHandlerInterface
{
    public function __construct(private HealthStatusService $health) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path    = $request->getUri()->getPath();
        $payload = str_ends_with($path, '/ready') ? $this->health->readiness() : $this->health->check();

        $status = match ($payload['status']) {
            'ok'       => 200,
            'degraded' => 200,
            'fail'     => 503,
            default    => 503,
        };

        return new JsonResponse($payload, $status);
    }
}

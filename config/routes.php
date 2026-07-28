<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Koh\SyliusMcp\Controller\McpServerCardController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('koh_mcp_server_card', '/.well-known/mcp/server-card.json')
        ->controller(McpServerCardController::class)
        ->methods(['GET', 'HEAD']);
};

<?php

use App\Mcp\Servers\BalinsaServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::oauthRoutes();

Mcp::web('/mcp', BalinsaServer::class)->middleware('auth:api');

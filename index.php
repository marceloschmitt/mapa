<?php
declare(strict_types=1);

require 'src/bootstrap.php';

use Mapa\Core\Router;

$router = new Router();
$registerRoutes = require 'src/routes.php';
$registerRoutes($router);
$router->dispatch();

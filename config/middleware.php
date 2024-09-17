<?php
    use Slim\App;
    use Slim\Middleware\ErrorMiddleware;
    use Middlewares\TrailingSlash;

    return function (App $app) {
        // Parse json, form data and xml
        $app->addBodyParsingMiddleware();

        // Add the Slim built-in routing middleware
        $app->addRoutingMiddleware();

        // Add the trailing slash middleware
        $app->add(new TrailingSlash());

        // Add the Discord authentication middleware
        $app->add(new \App\Middleware\Auth\Discord($app->getContainer()));

        // Add the BackgroundTask middleware
        //$app->add(new \App\Middleware\BackgroundTask($app->getContainer()));

        // Catch exceptions and errors
        $app->add(ErrorMiddleware::class);
    };
?>
<?php
    use Laminas\Cache\Service\StorageAdapterFactoryInterface;
    use Monolog\Logger;
    use Psr\Container\ContainerInterface;
    use Psr\Log\LoggerInterface;
    use Slim\App;
    use Slim\Factory\AppFactory;
    use Slim\Middleware\ErrorMiddleware;

    use App\Utils\BackgroundTaskManager;
    use Laminas\Cache\Storage\Adapter\Apcu;
    use Monolog\Handler\StreamHandler;
    use Monolog\Processor\UidProcessor;

    return [
        'settings' => function () {
            return require __DIR__ . '/settings.php';
        },

        App::class => function (ContainerInterface $container) {
            AppFactory::setContainer($container);

            return AppFactory::create();
        },

        'TaskScheduler' => function () {
            return new BackgroundTaskManager();
        },

        StorageAdapterFactoryInterface::class => function (ContainerInterface $container) {
            $config = $container->get('settings')['cache'];
            return new Apcu($config['options']);
        },

        LoggerInterface::class => function (ContainerInterface $container) {
            $settings = $container->get('settings')['logger'];
            $name = $settings['name'];
            $logger = new Logger($name);

            $processor = new UidProcessor();
            $logger->pushProcessor($processor);

            $handler = new StreamHandler($settings['path'], $settings['level']);
            $logger->pushHandler($handler);

            return $logger;
        },

        ErrorMiddleware::class => function (ContainerInterface $container) {
            $app = $container->get(App::class);
            $settings = $container->get('settings')['error'];

            return new ErrorMiddleware(
                $app->getCallableResolver(),
                $app->getResponseFactory(),
                (bool)$settings['display_error_details'],
                (bool)$settings['log_errors'],
                (bool)$settings['log_error_details'],
                $container->get(LoggerInterface::class)
            );
        },

    ];
?>
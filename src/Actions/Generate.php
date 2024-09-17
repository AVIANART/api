<?php
    namespace App\Actions;

    use Psr\Container\ContainerInterface;
    use Slim\Exception\HttpNotFoundException;
    use Slim\Http\ServerRequest as Request;
    use Slim\Http\Interfaces\ResponseInterface as Response;

    use App\Utils\Z3DR;
    use Exception;
    use Laminas\Cache\Psr\CacheItemPool\CacheItemPoolDecorator;
    use Laminas\Cache\Service\StorageAdapterFactoryInterface;

    class Generate {
        private $container;
        private $namespace;
        private $preset;
        private $hash;

        private $z3dr;

        public function __construct(ContainerInterface $container) {
            $this->container = $container;
            $this->z3dr = new Z3DR($container);
        }

        private function generatePreset($namespace, $preset) {
            $this->z3dr->rollSeed("DR", $this->hash, [$namespace, $preset]);
        }

        public function fetchBranches(Request $request, Response $response, array $args): Response {
            $pool = new CacheItemPoolDecorator($this->container->get(StorageAdapterFactoryInterface::class));
            $branches = $pool->getItem('branches');
            if(!$branches->isHit()) {
                $branches->set($this->z3dr->getBranches($this->namespace, $this->preset));
                $pool->save($branches);
            }
            return $response->withJson($branches->get());
        }

        public function generate(Request $request, Response $response, array $args): Response {
            $this->hash = $this->z3dr->generateUniqueHash();
            $this->namespace = ($args['namespace'] ?? false) ? $args['namespace'] : "avianart";
            $this->preset = $args['preset'];

            $this->container->get('TaskScheduler')->addTask(function() {
                $this->generatePreset($this->namespace, $this->preset);
            });

            return $response->withJson(array("status" => "generating", "hash" => $this->hash, "attempts" => 1));
        }
    }
?>
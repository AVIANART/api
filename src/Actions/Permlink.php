<?php
    namespace App\Actions;

    use Psr\Container\ContainerInterface;
    use Slim\Http\ServerRequest as Request;
    use Slim\Http\Interfaces\ResponseInterface as Response;

    use App\Utils\Z3DR;

    /*
     * @OA\Info(title="Handles anything involving permlinks", version="0.1")
     */
    class Permlink {
        private $container;
        private $z3dr;

        public function __construct(ContainerInterface $container) {
            $this->container = $container;
            $this->z3dr = new Z3DR($container);
        }

        /**
         * @OA\Get(
         *     path="/v1/perm/{hash}",
         *     @OA\Response(response="200", description="Get a seed by its hash"),
         *     @OA\Parameter(name="hash", in="path", description="Hash of the seed", required=true, @OA\Schema(type="string")),
         * )
         */
        public function getSeed(Request $request, Response $response, array $args): Response {
            $hash = $args['hash'];
            return $response->withJson($this->z3dr->fetchSeed($hash));
        }
    }
?>
<?php
    namespace App\Actions;

    use Psr\Container\ContainerInterface;
    use Slim\Exception\HttpNotFoundException;
    use Slim\Http\ServerRequest as Request;
    use Slim\Http\Interfaces\ResponseInterface as Response;

    use \App\Utils\S3;
    use Exception;
    use Laminas\Cache\Psr\CacheItemPool\CacheItemPoolDecorator;
    use Laminas\Cache\Service\StorageAdapterFactoryInterface;
    use Psr\Log\LoggerInterface;

    /*
     * @OA\Info(title="Handles anything involving presets", version="0.1")
     */
    class Presets {
        private $container;
        private $s3;

        public function __construct(ContainerInterface $container) {
            $this->container = $container;
            $this->s3 = new S3($container);
        }

        private function fetchPresets(String $prefix="avianart") {
            $pool = new CacheItemPoolDecorator($this->container->get(StorageAdapterFactoryInterface::class));
            $presets = $pool->getItem('presets_' . $prefix);
            if(!$presets->isHit()) {
                $presets->set($this->s3->listContents($this->container->get('settings')['s3']['presetsBucket'], $prefix . "/", ".yaml"));
                $pool->save($presets);
            }
            return $presets->get();
        }

        private function fetchGeneratorPresets(String $prefix="avianart") {
            $pool = new CacheItemPoolDecorator($this->container->get(StorageAdapterFactoryInterface::class));
            $presets = $pool->getItem('presets_generate_' . $prefix);
            if(!$presets->isHit()) {
                $rawPresets = $this->s3->listContents($this->container->get('settings')['s3']['presetsBucket'], $prefix . "/", ".yaml");
                $presetsData = [];
                foreach($rawPresets as $key=>$value) {
                    $handle = $this->s3->getObject($this->container->get('settings')['s3']['presetsBucket'], $value['Key']);
                    $data = yaml_parse($handle->getContents());
                    $key = $value['Key'];
                    $name = array_key_exists('seed_name', $data['meta']) ? $data['meta']['seed_name'] : explode("||",$data['meta']['user_notes'])[0];
                    $description = array_key_exists('seed_notes', $data['meta']) ? $data['meta']['seed_notes'] : explode("||",$data['meta']['user_notes'])[1];
                    $category = array_key_exists('category', $data['meta']) ? $data['meta']['category'] : "misc";
                    $presetsData[] = array(
                        'key' => $key,
                        'name' => $name,
                        'description' => $description,
                        'branch' => array_key_exists('branch', $data['meta']) ? $data['meta']['branch'] : "DRUnstable",
                        'slug' => str_replace([$prefix."/", ".yaml"],"",$value['Key']),
                        'category' => $category,
                    );
                }
                $presets->set($presetsData);
                $pool->save($presets);
            }
            return $presets->get();
        }

        private function isAuthenticated(Request $request) {
            return $request->getAttribute('userPrefix');
        }

        /**
         * @OA\Get(
         *     path="/v1/preset/{namespace}/{preset}",
         *     @OA\Response(response="200", description="Get a specific preset"),
         *     @OA\Parameter(name="namespace", in="path", description="Namespace to use", required=true, @OA\Schema(type="string")),
         *     @OA\Parameter(name="preset", in="path", description="Name of the preset to retrieve", required=true, @OA\Schema(type="string")),
         * ),
         * @OA\Get(
         *     path="/v1/preset/{preset}",
         *     @OA\Response(response="200", description="Get a specific preset"),
         *     @OA\Parameter(name="preset", in="path", description="Name of the preset to retrieve", required=true, @OA\Schema(type="string")),
         * )
         */
        public function getPreset(Request $request, Response $response, array $args): Response {
            $pool = new CacheItemPoolDecorator($this->container->get(StorageAdapterFactoryInterface::class));      

            if($args['preset'] ?? false) {
                $preset = $pool->getItem('presets_' . $args['namespace'] . "_" . $args['preset']);
                $path = $args['namespace'] . "/" . $args['preset'];
            }
            else {
                $preset = $pool->getItem('presets_' . $args['namespace']);
                $path = "avianart/" . $args['namespace'];
            }

            if(!$preset->isHit()) {
                $handle = $this->s3->getObject($this->container->get('settings')['s3']['presetsBucket'], $path . ".yaml");
                if($handle ?? false) {
                    $rawData = $handle->getContents();
                    $data = yaml_parse($rawData);
                    $name = array_key_exists('seed_name', $data['meta']) ? $data['meta']['seed_name'] : explode("||",$data['meta']['user_notes'])[0];
                    $description = array_key_exists('seed_notes', $data['meta']) ? $data['meta']['seed_notes'] : explode("||",$data['meta']['user_notes'])[1];
                    $category = array_key_exists('category', $data['meta']) ? $data['meta']['category'] : "misc";
                    $presetData = array(
                        'preset' => array(
                            'name' => $name,
                            'description' => $description,
                            'branch' => array_key_exists('branch', $data['meta']) ? $data['meta']['branch'] : "DRUnstable",
                            'slug' => array_key_exists('preset', $args) ? $args['preset'] : $args['namespace'],
                            'category' => $category,
                            'data' => $rawData
                        )
                    );
                    $preset->set($presetData);
                    $preset->expiresAfter(60 * 30);
                    $pool->save($preset);
                } else {
                    throw new HttpNotFoundException($request);
                }
            }
            
            return $response->withJson($preset->get());
        }

        /**
         * @OA\Get(
         *     path="/v1/presets/{namespace}",
         *     @OA\Response(response="200", description="Get all presets in a namespace"),
         *     @OA\Parameter(name="namespace", in="path", description="Namespace to get presets for. Defaults to 'avianart'", required=false, allowEmptyValue=true, @OA\Schema(type="string"))
         * ),
         * @OA\Get(
         *     path="/v1/presets/me/",
         *     @OA\Response(response="200", description="Get the user's presets. -- Requires authentication through discord.")
         * ),
         * @OA\Put(
         *     path="/v1/presets/me/",
         *     @OA\Response(response="200", description="Create a new preset under user's prefix. -- Requires authentication through discord.")
         * )
         */
        public function getPresets(Request $request, Response $response, array $args): Response {
            $this->container->get(LoggerInterface::class)->info('Presets requested', ['args' => $args]);
            if($args['prefix'] ?? false) {
                if($args['prefix'] == "me") {
                    if(!$this->isAuthenticated($request))
                        return $response->withHeader('Location', '/v1/oauth2/discord');
                    return $response->withJson(array('presets' => $this->fetchGeneratorPresets($request->getAttribute('userPrefix'))));
                }
                if($args['prefix'] == "avianart_generate") {
                    return $response->withJson(array('presets' => $this->fetchGeneratorPresets()));
                }
                try {
                    return $response->withJson(array('presets' => $this->fetchGeneratorPresets($args['prefix'])));
                } catch(Exception $e) {
                    throw new HttpNotFoundException($request, $e->getMessage());
                }
            }
            return $response->withJson(array('presets' => $this->fetchGeneratorPresets()));
        }

        public function createPreset(Request $request, Response $response, array $args): Response {
            if(!$this->isAuthenticated($request))
                return $response->withHeader('Location', '/v1/oauth2/discord');

            $data = $request->getParsedBody();
            $this->s3->putObject($this->container->get('settings')['s3']['presetsBucket'], $data['name'], json_encode($data['data']));
            return $response->withJson(array('message' => 'Preset created'));
        }
    }

?>
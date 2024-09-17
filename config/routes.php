<?php

    use Laminas\Cache\Psr\CacheItemPool\CacheItemPoolDecorator;
    use Laminas\Cache\Service\StorageAdapterFactory;
    use Laminas\Cache\Service\StorageAdapterFactoryInterface;
    use Slim\App;

    use Slim\Http\ServerRequest as Request;
    use Slim\Http\Interfaces\ResponseInterface as Response;
    use Slim\Routing\RouteCollectorProxy;

    return function (App $app) {
        $app->options('/{routes:.+}', function ($request, $response, $args) {
            return $response;
        });
        
        $app->add(function ($request, $handler) {
            $response = $handler->handle($request);
            return $response
                    ->withHeader('Access-Control-Allow-Origin', $this->get('settings')['debug'] ? "*" : $this->get('settings')['domain'])
                    ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
                    ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
        });        

        $app->group('/v1', function (RouteCollectorProxy $group) {
            $group->get('/', function (Request $request, Response $response, array $args) {
                $data = array('message' => '');
                return $response->withJson($data);
            });

            $group->get('/docs', function (Request $request, Response $response, array $args) {
                $body = '<!DOCTYPE html>
                <html lang="en">
                <head>
                    <meta charset="utf-8" />
                    <meta name="viewport" content="width=device-width, initial-scale=1" />
                    <meta name="description" content="SwaggerUI" />
                    <title>SwaggerUI</title>
                    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5.11.0/swagger-ui.css" />
                </head>
                <body>
                <div id="swagger-ui"></div>
                <script src="https://unpkg.com/swagger-ui-dist@5.11.0/swagger-ui-bundle.js" crossorigin></script>
                <script>
                    window.onload = () => {
                        window.ui = SwaggerUIBundle({
                            url: \'/v1/docs/generate\',
                            dom_id: \'#swagger-ui\',
                        });
                    };
                </script>
                </body>
                </html>';
                return $response->write($body);
            });

            $group->get('/docs/generate', function (Request $request, Response $response, array $args) {
                $pool = new CacheItemPoolDecorator($this->get(StorageAdapterFactoryInterface::class));
                $docs = $pool->getItem('openapi_json');
                if(!$docs->isHit()) {
                    $docs->set(\OpenApi\Generator::scan([$this->get('settings')['root'] . "/src/"]));
                    $pool->save($docs);
                }
                return $response->withJson($docs->get());
            });

            $group->get('/logout', function (Request $request, Response $response, array $args) {
                session_start();
                session_destroy();
                return $response->withHeader('Location', '/v1/');
            });

            $group->get('/oauth2/discord[/{code}]', function (Request $request, Response $response, array $args) {
                if($request->getAttribute('userData') ?? false) {
                    // Redirect to the user's presets
                    return $response->withHeader('Location', '/v1/presets/me');
                }

                if($request->getAttribute('error') ?? false) {
                    // Give them an error and give up
                    $data = array('error' => $request->getAttribute('error'));
                    return $response->withJson($data);
                }

                $query = http_build_query([
                    'client_id' => $this->get('settings')['discord']['clientId'],
                    'redirect_uri' => $this->get('settings')['discord']['redirectUri'],
                    'response_type' => 'code',
                    'scope' => implode("+", $this->get('settings')['discord']['scopes'])
                ]);

                return $response->withHeader('Location', 'https://discord.com/api/oauth2/authorize?' . $query);
            });

            $group->post('/generate/{namespace}[/{preset}]', \App\Actions\Generate::class . ':generate');
            $group->get('/generate/branches', \App\Actions\Generate::class . ':fetchBranches');

            $group->get('/perm/{hash}', \App\Actions\Permlink::class . ':getSeed');
            
            $group->get('/tabs[/{branch}]', \App\Actions\Tabs::class);

            $group->group('/presets', function (RouteCollectorProxy $presets) {
                $presets->get('/{namespace}[/{preset}]', \App\Actions\Presets::class . ':getPreset');

                $presets->get('[/{prefix}]', \App\Actions\Presets::class . ':getPresets');

                $presets->put('/me[/{preset}]', \App\Actions\Presets::class . ':createPreset');
            });
        });
    };
?>
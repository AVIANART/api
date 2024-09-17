<?php
    namespace App\Middleware\Auth;

    use Exception;
    use Psr\Container\ContainerInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Server\MiddlewareInterface;
    use Slim\Http\ServerRequest as Request;
    use Psr\Http\Server\RequestHandlerInterface as Handler;

    /*
     * @OA\Info(title="Discord Authentication Middleware", version="0.1")
     */
    class Discord implements MiddlewareInterface {
        private $token;
        private $userData;
        private $settings;

        public function __construct(ContainerInterface $container) {
            $this->settings = $container->get('settings');
            session_start();
            if($_SESSION["discord_token"] ?? false)
                $this->token = $_SESSION["discord_token"];
        }

        public function process(ServerRequestInterface $request, Handler $handler): ResponseInterface {
            return $handler->handle($request);
        }

        public function __invoke(Request $request, Handler $handler) {
            $path = explode("/", $request->getUri()->getPath());
            if(!isset($_SESSION["discord_token"])) {
                if(str_starts_with($request->getUri()->getPath(), "/v1/oauth2/discord") && isset($path[4])) {
                    try {
                        $this->token = $this->fetchToken($path[4]);
                    } catch(Exception $e) {
                        $request = $request->withAttribute('error', 'Failed to fetch access token');
                    }
                }
            } else {
                $this->token = $_SESSION["discord_token"];
            }
            
            if($this->token ?? false) {
                // Add the prefix to the request attributes along with the full userData object
                try {
                    $request = $request->withAttribute('userPrefix', $this->fetchUserPrefix());
                    $request = $request->withAttribute('userData', $this->userData);
                } catch(Exception $e) {
                    $request = $request->withAttribute('error', 'Failed to fetch user data');
                }
            }
            // Pass the request to the next middleware
            return $handler->handle($request);
        }

        private function fetchToken($code) {
            // Exchange the code for an access token
            $tokenUrl = 'https://discord.com/api/oauth2/token';
            $data = [
                'client_id' => $this->settings['discord']['clientId'],
                'client_secret' => $this->settings['discord']['clientSecret'],
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->settings['discord']['redirectUri'],
                'scope' => implode("+", $this->settings['discord']['scopes']),
            ];

            $curl = curl_init($tokenUrl);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

            $response = json_decode(curl_exec($curl), true);
            curl_close($curl);

            if($response['access_token'] ?? false) {
                $_SESSION["discord_token"] = $response['access_token'];
                $this->token = $_SESSION["discord_token"];
                return $response['access_token'];
            } else {
                throw new Exception('Failed to fetch access token');
            }
        }

        private function fetchUserPrefix() {
            // Fetch the user's prefix from the Discord API using the token
            // Use the access token to fetch user data

            if($this->userData ?? false) {
                return $this->userData['username'];
            }

            $userUrl = 'https://discord.com/api/v10/users/@me';
            $curl = curl_init($userUrl);
            curl_setopt($curl, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $this->token,
            ]);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

            $response = curl_exec($curl);
            $userData = json_decode($response, true);

            if(isset($userData['username'])) {
                $this->userData = $userData;
                return $userData['username'];
            } else {
                throw new Exception('Failed to fetch access token');
            }
        }
    }

?>
<?php

namespace App\Actions;

    /**
     *  @OA\Info(
     *      title="Avianart API",
     *      version="1.0.0",
     *      description="Roll seeds, populate gui tabs, create/manage presets, and more!",
     *      @OA\Contact(
     *          email="cody@hiimcody1.com"
     *      )
     *  ),
     *  @OA\Server(
     *      description="AVIANART API",
     *      url="http://localhost:8080/"
     *  ),
     *  @OA\Get(
     *     path="/v1/docs/",
     *     @OA\Response(response="200", description="User-friendly API docs"),
     *     @OA\PathItem (),
     *  ),
     *  @OA\Get(
     *     path="/v1/docs/generate",
     *     @OA\Response(response="200", description="Generate API docs json"),
     *  ),
     *  @OA\Post(
     *     path="/v1/generate/{namespace}/{preset}",
     *     @OA\Response(response="200", description="Generate a preset"),
     *     @OA\Parameter(name="namespace", in="path", description="Namespace to use", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(name="preset", in="path", description="Name of the preset to generate", required=true, @OA\Schema(type="string")),
     *  ),
     *  @OA\Get(
     *     path="/v1/generate/{preset}",
     *     @OA\Response(response="200", description="Generate a preset"),
     *     @OA\Parameter(name="preset", in="path", description="Name of the preset to generate", required=true, @OA\Schema(type="string")),
     *  ),
     *  @OA\Get(
     *     path="/v1/logout/",
     *     @OA\Response(response="200", description="Logout of authenticated session"),
     *  ),
     *  @OA\Get(
     *     path="/v1/oauth2/discord/{code}",
     *     @OA\Response(response="200", description="Authenticate with Discord"),
     *     @OA\Parameter(name="code", in="path", description="Discord OAuth2 code", required=false, allowEmptyValue=true, @OA\Schema(type="string")),
     *  ),
     */
    class Avianart {

    }

?>
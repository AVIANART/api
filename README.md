# AVIANART API

AVIANART's RESTful API for rolling DR/OWR seeds, fetching available settings for a branch, and managing namespace presets.

ALttP Randomizer branches supported:

- [Veetorp Randomizer](https://github.com/sporchia/alttp_vt_randomizer) (Limited to presets)
- [Aerinon's Door Randomizer](https://github.com/aerinon/ALttPDoorRandomizer)
- [Codemann's Overworld Randomizer](https://github.com/codemann8/ALttPDoorRandomizer)
- [Karafruit's Overworld Randomizer](https://github.com/ardnaxelarak/ALttPDoorRandomizer)

This software utilizes:

- [PHP Slim](https://github.com/slimphp/Slim) for routing.
- [Swagger-PHP](https://github.com/zircote/swagger-php) for APIDocs.
- [AWS SDK](https://github.com/aws/aws-sdk-php) for seed/preset storage.
- [Monolog](https://github.com/Seldaek/monolog) for logging.
- [Laminas Cache](https://github.com/laminas/laminas-cache) for caching.

## Installation

This project requires PHP and Composer. To install, follow these steps:

1. Clone the repository
2. Run `composer install`

## License

This repository is provided under the [MIT License](LICENSE).

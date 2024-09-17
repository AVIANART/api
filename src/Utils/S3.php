<?php
    namespace App\Utils;

    use Aws\S3\S3Client;
    use Aws\Exception\AwsException;
    use Exception;
    use Psr\Container\ContainerInterface;

    class S3 {
        private $client;
        private $settings;

        public function __construct(ContainerInterface $container) {
            $this->settings = $container->get('settings');
            $this->client = new S3Client($this->settings['s3']['clientConfig']);
        }

        public function listContents(String $bucket, String $prefix="", String $suffix="") {
            $objects = $this->client->listObjectsV2([
                'Bucket' => $bucket,
                'Prefix' => $prefix
            ]);

            if($suffix !== "") {
                if($objects['Contents'] === null)
                    throw new Exception("Invalid Prefix!");
                $objects['Contents'] = array_filter($objects['Contents'], function($object) use ($suffix) {
                    return substr($object['Key'], -strlen($suffix)) === $suffix;
                });
            }

            return $objects['Contents'];
        }

        public function generateUrl(String $bucket, String $key) {
            return $this->client->getObjectUrl($bucket, $key);
        }

        public function doesObjectExist(String $bucket, String $key) {
            return $this->client->doesObjectExist($bucket, $key);
        }

        public function getObject(String $bucket, String $key) {
            $object = $this->client->getObject([
                'Bucket' => $bucket,
                'Key' => $key
            ]);

            return $object['Body'];
        }

        public function putObject(String $bucket, String $key, String $data) {
            return $this->client->putObject([
                'Bucket' => $bucket,
                'Key' => $key,
                'Body' => $data
            ]);
        }

    }
?>
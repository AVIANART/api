<?php
    namespace App\Utils;
    require_once("unique.php");

    use Psr\Container\ContainerInterface;

    use App\Utils\S3;
    use App\Utils\Process;
    use Exception;
    use Psr\Log\LoggerInterface;

    class Z3DR extends Process {
        private $container;
        private $s3;
        private $branch;

        private $hash;
        private $tries = 0;
        private $startTime;

        private $filePath;

        public function __construct(ContainerInterface $container) {
            parent::__construct($container->get('settings')['z3r']['pythonPath'], [], true);

            $this->container = $container;
            $this->s3 = new S3($container);
        }

        public function fetchBranchVersion(string $branch) {
            $this->setBranch($branch);
            //preg_match("/__version__ = '([0-9a-z\.\-]+)'/", file_get_contents($this->branch['path'] . "/Main.py"), $versionRaw);
            //$version = $versionRaw[1];
            $version = trim(shell_exec('python3 -c "import Main; exec(\'try: import OverworldShuffle \nexcept ImportError: OverworldShuffle = {}\'); print(\'DR: \' + Main.__version__ + \'\nOWR: \' + OverworldShuffle.__version__) if hasattr(OverworldShuffle, \'__version__\') else print(\'DR: \' + Main.__version__)"'));
            if($version == null || $version == "")
                return "Unknown";
            return $version;
        }

        public function getBranches(string $namespace, string $preset) {
            $branches = array();
            foreach($this->container->get('settings')['z3dr']['branches'] as $branch=>$path) {
                $branches[$branch] = $this->fetchBranchVersion($branch);
            }
            return $branches;
        }

        public function rollSeed(string $branch, string $hash, SeedSettings $settings, int $maxTries=10) {
            $this->startTime = time();
            $this->setBranch($branch);
            $this->hash = $hash;
            $this->args = array_merge($this->args, $settings);
            $this->filePath = $this->container->get('settings')['z3dr']['tempPath'].$this->hash;
            while($this->tries < $maxTries) {
                if($this->start()) {
                    $result = $this->handlePipes();
                    $stdout=$result[0];
                    $stderr=$result[1];
                    $returnCode = $this->stop();
                    unlink($this->filePath);
                    file_put_contents($this->filePath, json_encode(array("status" => "postgen", "hash" => $this->hash, "starttime" => $this->startTime, "message" => "Generation Complete, setting up metadata", "returnCode" => $returnCode)));
                    if($returnCode==0) {
                        //Everything good, log the console and store the generated json
                        $seed = json_decode($stdout, true);
                        
                        
                        $seed['meta']['startgen'] = $this->startTime;
                        $seed['meta']['gentime'] = time();
                        $seed['hash'] = $this->hash;
                        try {
                            $baseRomUrl = $this->ensureBaseRom($this->branch['path'] . "/data/base2current.bps");
                            if(!$baseRomUrl) {
                                file_put_contents($this->filePath,json_encode(array("status" => "failure", "hash" => $this->hash, "starttime" => $this->startTime, "gentime" => time(), "message" => "Failed to store")));
                                $this->container->get(LoggerInterface::class)->error("Error while saving baserom, no error returned");
                                return;
                            }
                        } catch(Exception $e) {
                            file_put_contents($this->filePath,json_encode(array("status" => "failure", "hash" => $this->hash, "starttime" => $this->startTime, "gentime" => time(), "message" => "Failed to store")));
                            $this->container->get(LoggerInterface::class)->error("Error while saving baserom:",$e->getMessage());
                            return;
                        }

                        $seed['basepatch']['bps'] = $baseRomUrl;   //Store baserom URL, we put it on S3 above as well

                        // This shouldn't happen anymore
                        if(@isset($this->args['enemizercli']))
                            $seed['size']=4;
                        
                        unlink($this->filePath);
                        
                        //For MMMM
                        if($this->args['hide_meta']) {
                            $seed['meta']['mystery'] = true;
                        }

                        if(isset($seed['spoiler']['meta']['user_notes'])) {
                            $seed['spoiler']['meta']['seed_name'] = json_decode(stripslashes($seed['spoiler']['meta']['user_notes']), true)['name'];
                            $seed['spoiler']['meta']['seed_notes'] = json_decode(stripslashes($seed['spoiler']['meta']['user_notes']), true)['notes'];
                            unset($seed['spoiler']['meta']['user_notes']);
                        }

                        file_put_contents($this->filePath, json_encode($seed));
                        $this->storeSeed($seed);
                        return $seed;
                        break;
                    }
                }
                $this->container->get(LoggerInterface::class)->warn("Failed to generate seed, retrying", ["hash" => $this->hash, "tries" => $this->tries, "stderr" => $stderr, "stdout" => $stdout]);
                $this->tries++;
            }
        }

        public function fetchSeed(string $unsafeHash) {
            $hash = Z3DR::sanitizeHash($unsafeHash);

            //Check this first since we'll be refreshing frequently during generation and it would be rude to hit the VT API that often for no reason
            if(file_exists($this->container->get('settings')['z3r']['tempPath'] . $hash)) {
                // Exists on disk, likely still generating
                $seed = json_decode(file_get_contents($this->container->get('settings')['z3r']['tempPath'] . $hash), true);
                return $this->formatSpoiler($seed);
            } 

            $vt = @file_get_contents("https://alttpr.com/api/h/$hash");
            if(Z3DR::isJson($vt)) {
                $seed = json_decode(file_get_contents("https://alttpr.com/hash/$hash"), true);
                $data['vt']=true;
                $baserom = $this->ensureBaseRom("https://alttpr.com" . json_decode($vt, true)['bpsLocation']);
                $data['basepatch']['bps'] = $baserom;
                return $this->formatSpoiler($seed);
            } elseif($this->s3->doesObjectExist($this->container->get('settings')['s3']['seedsBucket'], "seeds/" . $hash . ".json")) {
                $seed = json_decode(file_get_contents($this->container->get('settings')['s3']['seedsPubUrl'] . "seeds/" . $hash . ".json"), true);
                return $this->formatSpoiler($seed);
            } else {
                // Does not exist
                return null;
            }
        }

        public function generateUniqueHash() {
            $randomHash = alphaID(random_int(0,9007199254740992));
            while($this->fetchSeed($randomHash))
                $randomHash = alphaID(random_int(0,9007199254740992));
            return $randomHash;
        }

        private function setBranch(string $branch) {
            if(array_key_exists($branch, $this->container->get('settings')['z3dr']['branches']))
                $this->branch = ["name" => $branch, "path" => $this->container->get('settings')['z3dr']['branches'][$branch]];
            else
                throw new Exception("Invalid branch specified!");
        }

        private function storeSeed(string $seed) {
            $key = 'seeds/'.$this->hash.".json";
            $result = $this->s3->putObject($this->container->get('settings')['s3']['seedsBucket'], $key, $seed);
    
            if ($result['@metadata']['statusCode'] === 200) {
                return true;
            } else {
                return false;
            }
        }

        private function formatSpoiler($seed) {
            if(isset($seed['vt'])) {
                $seed['meta']['startgen'] = time();
                $seed['meta']['gentime'] = time();
                if($seed['spoiler']['meta']['tournament'] == true)
                    $seed['meta']['race'] == true;
            } else {
                if(array_key_exists("spoiler", $seed)) {
                    if(!@isset($seed['legacy']))
                        $seed["spoiler"] = json_decode(stripslashes($seed['spoiler']), true);
                    $newSpoiler = Array();
                    foreach($seed["spoiler"]["meta"] as $spoilerKey=>$spoilerEntry) {
                        if(is_array($spoilerEntry)) {
                            if(count($spoilerEntry) < 2)
                                $newSpoiler[$spoilerKey] = $spoilerEntry[1];
                            else
                                $newSpoiler[$spoilerKey] = $spoilerEntry;
                        } else
                            $newSpoiler[$spoilerKey] = $spoilerEntry;
                    }
                    $seed["spoiler"]["meta"] = $newSpoiler;
                    // TODO Handle this differently since we are going to be supporting multis
                    $seed["spoiler"]["meta"]["hash"] = $seed["spoiler"]["Hashes"]["Player 1 (Team 1)"];
                    if(array_key_exists("race",$seed["spoiler"]["meta"]) && ($seed["spoiler"]["meta"]["race"] !== "No" && $seed["spoiler"]["meta"]["race"] !== false) && $seed["spoiler"]["meta"]["race"] !== 0) {
                        //Race seed, strip out the spoiler manually
                        unset($seed["spoiler"]["meta"]["seed"]);
                        $meta = $seed["spoiler"]["meta"];
                        $seed["spoiler"] = Array(
                            "meta" => $meta
                        );
                    }

                    if(array_key_exists("mystery",$seed["meta"])) {
                        //TODO Mystery seed, strip out remaining meta and spoilers
                        $meta = array();
                        $meta["version"] = $seed["spoiler"]["meta"]["version"];
                        $meta["logic"] = "?";
                        $meta["mode"] = "?";
                        $meta["accessibility"] = "?";
                        $meta["weapons"] = "?";
                        $meta["goal"] = "?";
                        $meta["seed_name"] = array_key_exists("seed_name", $seed["spoiler"]["meta"]) ? $seed['spoiler']['meta']['seed_name'] : @json_decode($seed['spoiler']['meta']['notes'], true)["name"];
                        $meta["seed_notes"] = array_key_exists("seed_notes", $seed["spoiler"]["meta"]) ? $seed['spoiler']['meta']['seed_notes'] : @json_decode($seed['spoiler']['meta']['notes'], true)["notes"];
                        
                        if($meta["seed_name"] == null)
                            unset($meta["seed_name"]);
                        if($meta["seed_notes"] == null)
                            unset($meta["seed_notes"]);

                        $meta["user_notes"] = $seed["spoiler"]["meta"]["user_notes"];
                        $meta["race"] = true;
                        $meta["hash"] = $seed["spoiler"]["meta"]["hash"];
                        $seed["spoiler"] = Array(
                            "meta" => $meta
                        );
                    }
                }
                if(@isset($seed["meta"]["gentime"]))
                    $seed["spoiler"]["meta"]["gentime"] = $seed["meta"]["gentime"];

                if(str_starts_with( $seed['hash'],"v1"))
                    $seed["type"] = "legacy";
                else
                    $seed["type"] = "dr";

                //TODO Should this baserom be listed here or a config?
                if(!isset($seed['basepatch'])) { //Old seed, use the old basepatch from June 2023
                    $seed["basepatch"] = Array(
                        "bps" => $this->container->get('settings')['s3']['seedsPubUrl'] . "/baserom/4c476f8109f0cd2e9d14d0d31c2b707d0a179455.bps"
                    );
                }
            }
            return $seed;
        }

        public function handlePipes(?string $outputFile = null, ?string $errorFile = null) {
            while (true) {
                $read = [$this->pipes[1], $this->pipes[2]];
                $write = [];
                $except = [];

                if (stream_select($read, $write, $except, 0) === false) {
                    // An error occurred
                    break;
                }

                // Update output and error from STDOUT and STDERR
                foreach ($read as $stream) {
                    $data = fgets($stream);
                    if ($data !== false) {
                        if ($stream === $this->pipes[1]) {
                            $this->output .= $data;
                            if ($outputFile) {
                                file_put_contents($outputFile, $data, FILE_APPEND);
                            }
                        } elseif ($stream === $this->pipes[2]) {
                            $this->error .= $data;
                            //Log data to the tempfile
                            if(str_contains($data,"The following items could not be reached:") === false && str_contains($data,"ALttP Door Randomizer") === false && str_contains($data, "Seed:") === false)
                                echo file_put_contents($this->filePath, json_encode(array("status" => "generating", "attempts" => $this->tries+1,"hash" => $this->hash, "starttime" => $this->startTime, "message" => $data)));
                            if ($errorFile) {
                                file_put_contents($errorFile, $data, FILE_APPEND);
                            }
                        }
                    }
                }

                if (feof($this->pipes[1]) && feof($this->pipes[2])) {
                    // Both STDOUT and STDERR have closed
                    break;
                }

                usleep(10000); // Sleep for 10 milliseconds to avoid CPU overload
            }
            return array($this->output, $this->error);
        }

        private function storeBaseRom($sha1,$bps) {
            $key = "baserom/".$sha1.".bps";
    
            $result = $this->s3->putObject($this->container->get('settings')['s3']['seedsBucket'], $key, $bps);
    
            if ($result['@metadata']['statusCode'] === 200) {
                return true;
            } else {
                return false;
            }
        }

        private function ensureBaseRom($baseromURL) {
            $baserom = file_get_contents($baseromURL);
            $sha1 = sha1($baserom);
            $key = "baserom/" . $sha1 . ".bps";
            if(!$this->s3->doesObjectExist($this->container->get('settings')['s3']['seedsBucket'], $key)) {
                if($this->storeBaseRom($sha1,$baserom))
                    return $this->container->get('settings')['s3']['seedsBucket'] . $key;
                return false;
            }
            return $this->container->get('settings')['s3']['seedsBucket'] . $key;
        }

        public static function isJson($string) {
            json_decode($string);
            return json_last_error() === JSON_ERROR_NONE;
        }
    
        public static function sanitizeHash($unsafeHash) {
            return preg_replace("/[^a-zA-Z0-9]+/", "", $unsafeHash);
        }
    }

    class SeedSettings {

    }
?>
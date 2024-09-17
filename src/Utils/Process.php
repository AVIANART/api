<?php
    namespace App\Utils;

    use Exception;

    class Process {
        protected $processPath;
        protected $args;

        /*
        * @var bool
        * @description Whether or not the process pipes should be blocking
        */
        protected $runInBackground;

        protected $process;
        protected $pipes;

        /*
        * @var string
        * @description All lines the process has output to STDOUT
        */
        protected $output = "";

        /*
        * @var string
        * @description All lines the process has output to STDERR
        */
        protected $error = "";

        public function __construct(string $processPath, array $args = [], bool $runInBackground=true) {
            $this->processPath = $processPath;
            $this->args = $args;
            $this->runInBackground = $runInBackground;
        }

        public function start(): bool {
            $resource = $this->process = proc_open($this->processPath, $this->args, $this->pipes);
            if($resource) {
                if($this->runInBackground) {
                    // We won't be writing inputs if we are running in the background
                    fclose($this->pipes[0]);
                    stream_set_blocking($this->pipes[1], false);
                    stream_set_blocking($this->pipes[2], false);
                }
                return true;
            }
            return false;
        }

        public function stop(): int {
            return proc_close($this->process);
        }

        /*
        * @description Collects the output from the process, optionally outputting it to a file
        */
        public function handlePipes(string $outputFile = null, string $errorFile = null) {
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
        }
    }
?>
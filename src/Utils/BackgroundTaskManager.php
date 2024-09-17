<?php
    namespace App\Utils;

    use Exception;

    class BackgroundTaskManager {
        private $tasks;

        private function handleTasks() {
            foreach($this->tasks as $task) {
                $task();
            }
        }

        public function addTask($task) {
            $this->tasks[] = $task;
        }

        public function run() {
            ignore_user_abort(true);
            try {
                fastcgi_finish_request();
            } catch (Exception $e) {
                
            } finally {

            }
            
            $this->handleTasks();
            exit();
        }
    }
?>
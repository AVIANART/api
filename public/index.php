<?php
    (require __DIR__ . '/../config/bootstrap.php')->run();

    //Run tasks
    $container->get('TaskScheduler')->run();
?>
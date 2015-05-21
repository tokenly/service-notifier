<?php

namespace App;

use App\Log;
use Dotenv;

/**
* Environment
*/
class Environment
{
    
    public static function init($base_path) {
        define('BASE_PATH', realpath($base_path));

        Dotenv::load(BASE_PATH);

        Log::initMonolog(getenv('LOG_PATH'));
    }

 
}

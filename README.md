php-daemon
=======================

A PHP based daemon application library.

### Install

```php
composer require minhsieh/php-daemon
```


### Usage

```php
<?php
require "vendor/autoload.php";

use Minhsieh\PHPDaemon\Daemon;

$d = new Daemon("demo_job");

function handle($pno){
    echo $pno." is now running";
    sleep(rand(1,3));
}

$d->setPidFilePath("path/to/store/pidfile");
$d->setProcessNum(2);
$d->setBossSleep(60*60);
$d->setHandle(handle());
$d->run();
```

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

use Daemon\PHPDaemon;

$d = new PHPDaemon;

function handle($pno){
    echo $pno." is now running";
    sleep(rand(1,3));
}

$d->setPidFile("path/to/store/pidfile");
$d->setProcessNum(2);
$d->setHandle(handle());
$d->run();
```

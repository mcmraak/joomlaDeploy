# deploy.php

PHP-script for deployment or transfer Joomla using FTP

## Usage

Fill the array $_config

```php
# Configuration
$_config = array(
    'remoteHost' => 'http://domain.com', // Project domain http://domain.com
    'ftpHost' => '127.0.0.1',
    'ftpRemoteDir' => '', // Empty or "www/domain.com"
    'ftpUser' => 'ftpuser',
    'ftpPass' => 'ftppassword',
    'mysqlHost' => 'localhost',
    'mysqlDB' => 'myslqdb',
    'mysqlUser' => 'mysqluser',
    'mysqlPass' => 'mysqlpass',
    'ignoreFiles' => array(// ignore files
        '\.git',
		'\.idea',
        'nbproject',
        'deploy\.php',
        'cache.',
        'logs.',
        'tmp.'
    )
);
```

#### Get help
php deploy.php -help

#### Testing connections
php deploy.php -test

## License

This software is licenced under the [LGPL 2.1](http://www.gnu.org/licenses/lgpl-2.1.html). Please read LICENSE for information on the
software availability and distribution.

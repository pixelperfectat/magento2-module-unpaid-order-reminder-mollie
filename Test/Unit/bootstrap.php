<?php
declare(strict_types=1);

use Magento\Framework\Code\Generator\Io;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\TestFramework\Unit\Autoloader\ExtensionAttributesGenerator;
use Magento\Framework\TestFramework\Unit\Autoloader\ExtensionAttributesInterfaceGenerator;
use Magento\Framework\TestFramework\Unit\Autoloader\FactoryGenerator;
use Magento\Framework\TestFramework\Unit\Autoloader\GeneratedClassesAutoloader;

// vendor/ may be this module's own (CI) or the shop's (when symlinked inside local-repository).
$localVendor = __DIR__ . '/../../vendor/autoload.php';
$shopVendor  = __DIR__ . '/../../../../../vendor/autoload.php';
if (is_file($localVendor)) {
    require $localVendor;
} elseif (is_file($shopVendor)) {
    require $shopVendor;
} else {
    $dir = __DIR__;
    while ($dir !== dirname($dir)) {
        if (is_file($dir . '/vendor/autoload.php')) {
            require $dir . '/vendor/autoload.php';
            break;
        }
        $dir = dirname($dir);
    }
}

// The base autoloader does not know this module's namespace; register it (PSR-4 root = module root).
$moduleRoot = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($moduleRoot): void {
    $prefix = 'PixelPerfect\\UnpaidOrderReminderMollie\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = $moduleRoot . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$generationDirectory = sys_get_temp_dir() . '/pixelperfect-unpaid-order-reminder-mollie-generated';
if (!is_dir($generationDirectory)) {
    mkdir($generationDirectory, 0775, true);
}

$generatedCodeAutoloader = new GeneratedClassesAutoloader(
    [
        new FactoryGenerator(),
        new ExtensionAttributesGenerator(),
        new ExtensionAttributesInterfaceGenerator(),
    ],
    new Io(new File(), $generationDirectory)
);

spl_autoload_register([$generatedCodeAutoloader, 'load']);

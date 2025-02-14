<?php
namespace PoP\ComponentModel\Facades\ModulePath;

use PoP\ComponentModel\ModulePath\ModulePathHelpersInterface;
use PoP\Root\Container\ContainerBuilderFactory;

class ModulePathHelpersFacade
{
    public static function getInstance(): ModulePathHelpersInterface
    {
        return ContainerBuilderFactory::getInstance()->get('module_path_helpers');
    }
}

<?php
namespace PoP\ComponentModel\Facades\AttachableExtensions;

use PoP\ComponentModel\AttachableExtensions\AttachableExtensionManagerInterface;
use PoP\Root\Container\ContainerBuilderFactory;

class AttachableExtensionManagerFacade
{
    public static function getInstance(): AttachableExtensionManagerInterface
    {
        return ContainerBuilderFactory::getInstance()->get('attachable_extension_manager');
    }
}

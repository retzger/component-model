<?php
namespace PoP\ComponentModel\DataStructure;

class DefaultDataStructureFormatter extends AbstractJSONDataStructureFormatter
{
    public const NAME = 'default';

    public static function getName() {
        return self::NAME;
    }
}

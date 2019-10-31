<?php
namespace PoP\ComponentModel\DirectiveResolvers;

use PoP\ComponentModel\FieldResolvers\FieldResolverInterface;

abstract class AbstractSchemaDirectiveResolver extends AbstractDirectiveResolver implements SchemaDirectiveResolverInterface
{
    public function getSchemaDefinitionResolver(FieldResolverInterface $fieldResolver): ?SchemaDirectiveResolverInterface
    {
        return $this;
    }
    public function getSchemaDirectiveDescription(FieldResolverInterface $fieldResolver): ?string
    {
        return null;
    }
    public function getSchemaDirectiveDeprecationDescription(FieldResolverInterface $fieldResolver): ?string
    {
        return null;
    }
    public function getSchemaDirectiveArgs(FieldResolverInterface $fieldResolver): array
    {
        return [];
    }
}

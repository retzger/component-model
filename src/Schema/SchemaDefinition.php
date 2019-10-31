<?php
namespace PoP\ComponentModel\Schema;

class SchemaDefinition {
    // Field/Directive Argument Names
    const ARGNAME_NAME = 'name';
    const ARGNAME_TYPE = 'type';
    const ARGNAME_DESCRIPTION = 'description';
    const ARGNAME_MANDATORY = 'mandatory';
    const ARGNAME_ENUMVALUES = 'enumValues';
    const ARGNAME_DEPRECATED = 'deprecated';
    const ARGNAME_DEPRECATEDDESCRIPTION = 'deprecatedDescription';
    const ARGNAME_ARGS = 'args';
    const ARGNAME_RELATIONAL = 'relational';
    const ARGNAME_FIELDS = 'fields';
    const ARGNAME_RESOLVER = 'resolver';
    const ARGNAME_BASERESOLVER = 'baseResolver';
    const ARGNAME_RESOLVERID = 'resolverId';
    const ARGNAME_RECURSION = 'recursion';
    const ARGNAME_CONVERTIBLE = 'convertible';
    const ARGNAME_RESOLVERSBYOBJECTNATURE = 'resolversByObjectNature';
    const ARGNAME_DIRECTIVES = 'directives';

    // Field/Directive Argument Types
    const TYPE_MIXED = 'mixed';
    const TYPE_ID = 'id';
    const TYPE_STRING = 'string';
    const TYPE_INT = 'int';
    const TYPE_FLOAT = 'float';
    const TYPE_BOOL = 'bool';
    const TYPE_DATE = 'date';
    const TYPE_TIME = 'time';
    const TYPE_ARRAY = 'array';
    const TYPE_OBJECT = 'object';
    const TYPE_URL = 'url';
    const TYPE_EMAIL = 'email';
    const TYPE_IP = 'ip';
    const TYPE_ENUM = 'enum';
}

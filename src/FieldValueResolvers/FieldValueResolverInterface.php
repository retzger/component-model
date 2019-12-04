<?php
namespace PoP\ComponentModel\FieldValueResolvers;
use PoP\ComponentModel\TypeResolvers\TypeResolverInterface;

interface FieldValueResolverInterface
{
    /**
     * Get an array with the fieldNames that this fieldValueResolver resolves
     *
     * @return array
     */
    public static function getFieldNamesToResolve(): array;
    /**
     * Get an instance of the object defining the schema for this fieldValueResolver
     *
     * @param TypeResolverInterface $typeResolver
     * @param string $fieldName
     * @param array $fieldArgs
     * @return void
     */
    public function getSchemaDefinitionResolver(TypeResolverInterface $typeResolver);
    public function getSchemaDefinitionForField(TypeResolverInterface $typeResolver, string $fieldName, array $fieldArgs = []): array;

    /**
     * Indicates if the fieldValueResolver can process this combination of fieldName and fieldArgs
     * It is required to support a multiverse of fields: different fieldValueResolvers can resolve the field, based on the required version (passed through $fieldArgs['branch'])
     *
     * @param string $fieldName
     * @param array $fieldArgs
     * @return boolean
     */
    public function resolveCanProcess(TypeResolverInterface $typeResolver, string $fieldName, array $fieldArgs = []): bool;
    public function resolveSchemaValidationErrorDescription(TypeResolverInterface $typeResolver, string $fieldName, array $fieldArgs = []): ?string;
    public function resolveValue(TypeResolverInterface $typeResolver, $resultItem, string $fieldName, array $fieldArgs = [], ?array $variables = null, ?array $expressions = null, array $options = []);
    public function resolveFieldDefaultDataloaderClass(TypeResolverInterface $typeResolver, string $fieldName, array $fieldArgs = []): ?string;
    public function resolveSchemaValidationWarningDescription(TypeResolverInterface $typeResolver, string $fieldName, array $fieldArgs = []): ?string;
    public function resolveCanProcessResultItem(TypeResolverInterface $typeResolver, $resultItem, string $fieldName, array $fieldArgs = []): bool;
    public function enableOrderedSchemaFieldArgs(TypeResolverInterface $typeResolver, string $fieldName): bool;
    public function getValidationErrorDescription(TypeResolverInterface $typeResolver, $resultItem, string $fieldName, array $fieldArgs = []): ?string;
}

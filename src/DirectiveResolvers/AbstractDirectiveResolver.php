<?php
namespace PoP\ComponentModel\DirectiveResolvers;
use League\Pipeline\StageInterface;
use PoP\ComponentModel\FieldResolvers\AbstractFieldResolver;
use PoP\ComponentModel\DirectivePipeline\DirectivePipelineUtils;
use PoP\ComponentModel\Facades\Schema\FieldQueryInterpreterFacade;
use PoP\ComponentModel\AttachableExtensions\AttachableExtensionTrait;
use PoP\ComponentModel\FieldResolvers\FieldResolverInterface;

abstract class AbstractDirectiveResolver implements DirectiveResolverInterface, StageInterface
{
    use AttachableExtensionTrait, DirectiveValidatorTrait;

    protected $directive;
    function __construct($directive) {
        $this->directive = $directive;
    }

    public static function getClassesToAttachTo(): array
    {
        // By default, be attached to all fieldResolvers
        return [
            AbstractFieldResolver::class,
        ];
    }

    /**
     * Indicate to what fieldNames this directive can be applied.
     * Returning an empty array means all of them
     *
     * @return array
     */
    public static function getFieldNamesToApplyTo(): array
    {
        // By default, apply to all fieldNames
        return [];
    }

    public function __invoke($payload)
    {
        // 1. Extract the arguments from the payload
        list(
            $fieldResolver,
            $resultIDItems,
            $idsDataFields,
            $dbItems,
            $dbErrors,
            $dbWarnings,
            $schemaErrors,
            $schemaWarnings,
            $schemaDeprecations
        ) = DirectivePipelineUtils::extractArgumentsFromPayload($payload);

        // 2. Validate operation
        $this->validateDirective(
            $fieldResolver,
            $resultIDItems,
            $idsDataFields,
            $dbItems,
            $dbErrors,
            $dbWarnings,
            $schemaErrors,
            $schemaWarnings,
            $schemaDeprecations
        );

        // 3. Execute operation
        $this->resolveDirective(
            $fieldResolver,
            $resultIDItems,
            $idsDataFields,
            $dbItems,
            $dbErrors,
            $dbWarnings,
            $schemaErrors,
            $schemaWarnings,
            $schemaDeprecations
        );

        // 4. Re-create the payload from the modified variables
        return DirectivePipelineUtils::convertArgumentsToPayload(
            $fieldResolver,
            $resultIDItems,
            $idsDataFields,
            $dbItems,
            $dbErrors,
            $dbWarnings,
            $schemaErrors,
            $schemaWarnings,
            $schemaDeprecations
        );
    }

    public function validateDirective(FieldResolverInterface $fieldResolver, array &$resultIDItems, array &$idsDataFields, array &$dbItems, array &$dbErrors, array &$dbWarnings, array &$schemaErrors, array &$schemaWarnings, array &$schemaDeprecations)
    {
        // Check that the directive can be applied to all provided fields
        $this->validateAndFilterFieldsForDirective($idsDataFields, $schemaWarnings);
    }

    /**
     * Check that the directive can be applied to all provided fields
     *
     * @param array $idsDataFields
     * @param array $schemaWarnings
     * @return void
     */
    protected function validateAndFilterFieldsForDirective(array &$idsDataFields, array &$schemaWarnings)
    {
        $directiveSupportedFieldNames = $this->getFieldNamesToApplyTo();

        // If this function returns an empty array, then it supports all fields, then do nothing
        if (!$directiveSupportedFieldNames) {
            return;
        }

        // Check if all fields are supported by this directive
        $failedDataFields = [];
        foreach ($idsDataFields as $id => $data_fields) {
            // If any fieldName failed, remove it from the list of fields to execute for this directive
            if ($unsupportedFieldNames = array_diff($data_fields['direct'], $directiveSupportedFieldNames)) {
                $data_fields['direct'] = array_diff(
                    $data_fields['direct'],
                    $unsupportedFieldNames
                );
                $failedDataFields = array_values(array_unique(array_merge(
                    $failedDataFields,
                    $unsupportedFieldNames
                )));
            }
        }
        // Give a warning message for all failed fields
        if ($failedDataFields) {
            $translationAPI = TranslationAPIFacade::getInstance();
            $fieldQueryInterpreter = FieldQueryInterpreterFacade::getInstance();
            $directiveName = $this->getDirectiveName();
            $failedDataFieldOutputKeys = array_map(
                [$fieldQueryInterpreter, 'getFieldOutputKey'],
                $failedDataFields
            );
            $schemaWarnings[$directiveName][] = sprintf(
                $translationAPI->__('Directive \'%s\' cannot support the following field(s), so it has not been executed on them: \'%s\'. (The only supported fields are: \'%s\')', 'component-model'),
                $directiveName,
                implode($translationAPI->__('\', \''), $failedDataFieldOutputKeys),
                implode($translationAPI->__('\', \''), $directiveSupportedFieldNames)
            );
        }
    }
}

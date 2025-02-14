<?php
namespace PoP\ComponentModel\DirectiveResolvers;
use PoP\FieldQuery\QueryHelpers;
use League\Pipeline\StageInterface;
use PoP\ComponentModel\Environment;
use PoP\ComponentModel\Feedback\Tokens;
use PoP\ComponentModel\Schema\SchemaHelpers;
use PoP\ComponentModel\Schema\SchemaDefinition;
use PoP\Translation\Facades\TranslationAPIFacade;
use PoP\ComponentModel\TypeResolvers\FieldSymbols;
use PoP\ComponentModel\TypeResolvers\PipelinePositions;
use PoP\ComponentModel\TypeResolvers\TypeResolverInterface;
use PoP\ComponentModel\DirectivePipeline\DirectivePipelineUtils;
use PoP\ComponentModel\Facades\Schema\FieldQueryInterpreterFacade;
use PoP\ComponentModel\AttachableExtensions\AttachableExtensionTrait;

abstract class AbstractDirectiveResolver implements DirectiveResolverInterface, SchemaDirectiveResolverInterface, StageInterface
{
    use AttachableExtensionTrait, RemoveIDsDataFieldsDirectiveResolverTrait;

    const MESSAGE_EXPRESSIONS = 'expressions';

    protected $directive;
    protected $directiveArgsForSchema = [];
    protected $directiveArgsForResultItems = [];
    protected $nestedDirectivePipelineData;
    function __construct($directive = null) {
        // If the directive is not provided, then it directly the directive name
        // This allows to instantiate the directive through the DependencyInjection component
        $this->directive = $directive ?? $this->getDirectiveName();
    }

    /**
     * If a directive does not operate over the resultItems, then it must not allow to add fields or dynamic values in the directive arguments
     * Otherwise, it can lead to errors, since the field would never be transformed/casted to the expected type
     * Eg: <cacheControl(maxAge:id())>
     *
     * @return bool
     */
    protected function disableDynamicFieldsFromDirectiveArgs(): bool
    {
        return false;
    }

    public function dissectAndValidateDirectiveForSchema(TypeResolverInterface $typeResolver, array &$fieldDirectiveFields, array &$variables, array &$schemaErrors, array &$schemaWarnings, array &$schemaDeprecations): array
    {
        $translationAPI = TranslationAPIFacade::getInstance();
        $fieldQueryInterpreter = FieldQueryInterpreterFacade::getInstance();

        // If it has nestedDirectives, extract them and validate them
        $nestedFieldDirectives = $fieldQueryInterpreter->getFieldDirectives($this->directive, false);
        if ($nestedFieldDirectives) {
            $nestedDirectiveSchemaErrors = $nestedDirectiveSchemaWarnings = $nestedDirectiveSchemaDeprecations = [];
            $nestedFieldDirectives = QueryHelpers::splitFieldDirectives($nestedFieldDirectives);
            // Support repeated fields by adding a counter next to them
            if (count($nestedFieldDirectives) != count(array_unique($nestedFieldDirectives))) {
                // Find the repeated fields, and add a counter next to them
                $expandedNestedFieldDirectives = [];
                $counters = [];
                foreach ($nestedFieldDirectives as $nestedFieldDirective) {
                    if (!isset($counters[$nestedFieldDirective])) {
                        $expandedNestedFieldDirectives[] = $nestedFieldDirective;
                        $counters[$nestedFieldDirective] = 1;
                    } else {
                        $expandedNestedFieldDirectives[] = $nestedFieldDirective.FieldSymbols::REPEATED_DIRECTIVE_COUNTER_SEPARATOR.$counters[$nestedFieldDirective];
                        $counters[$nestedFieldDirective]++;
                    }
                }
                $nestedFieldDirectives = $expandedNestedFieldDirectives;
            }
            // Each nested directive will deal with the same fields as the current directive
            $nestedFieldDirectiveFields = $fieldDirectiveFields;
            foreach ($nestedFieldDirectives as $nestedFieldDirective) {
                $nestedFieldDirectiveFields[$nestedFieldDirective] = $fieldDirectiveFields[$this->directive];
            }
            $this->nestedDirectivePipelineData = $typeResolver->resolveDirectivesIntoPipelineData($nestedFieldDirectives, $nestedFieldDirectiveFields, true, $variables, $nestedDirectiveSchemaErrors, $nestedDirectiveSchemaWarnings, $nestedDirectiveSchemaDeprecations);
            foreach ($nestedDirectiveSchemaDeprecations as $nestedDirectiveSchemaDeprecation) {
                $schemaDeprecations[] = [
                    Tokens::PATH => array_merge([$this->directive], $nestedDirectiveSchemaDeprecation[Tokens::PATH]),
                    Tokens::MESSAGE => $nestedDirectiveSchemaDeprecation[Tokens::MESSAGE],
                ];
            }
            foreach ($nestedDirectiveSchemaWarnings as $nestedDirectiveSchemaWarning) {
                $schemaWarnings[] = [
                    Tokens::PATH => array_merge([$this->directive], $nestedDirectiveSchemaWarning[Tokens::PATH]),
                    Tokens::MESSAGE => $nestedDirectiveSchemaWarning[Tokens::MESSAGE],
                ];
            }
            // If there is any error, then we also can't proceed with the current directive
            if ($nestedDirectiveSchemaErrors) {
                foreach ($nestedDirectiveSchemaErrors as $nestedDirectiveSchemaError) {
                    $schemaErrors[] = [
                        Tokens::PATH => array_merge([$this->directive], $nestedDirectiveSchemaError[Tokens::PATH]),
                        Tokens::MESSAGE => $nestedDirectiveSchemaError[Tokens::MESSAGE],
                    ];
                }
                $schemaErrors[] = [
                    Tokens::PATH => [$this->directive],
                    Tokens::MESSAGE => $translationAPI->__('This directive can\'t be executed due to errors from its nested directives', 'component-model'),
                ];
                return [
                    null, // $validDirective
                    // null, // $directiveName <= null because no need to find out which one it is
                    // null, // $directiveArgs <= null because no need to find out which one it is
                ];
            }
        }

        // First validate schema (eg of error in schema: ?query=posts<include(if:this-field-doesnt-exist())>)
        list(
            $validDirective,
            $directiveName,
            $directiveArgs,
            $directiveSchemaErrors,
            $directiveSchemaWarnings,
            $directiveSchemaDeprecations
        ) = $fieldQueryInterpreter->extractDirectiveArgumentsForSchema($this, $typeResolver, $this->directive, $variables, $this->disableDynamicFieldsFromDirectiveArgs());

        // Store the args, they may be used in `resolveDirective`
        $this->directiveArgsForSchema = $directiveArgs;

        // If there were errors, warning or deprecations, integrate them into the feedback objects
        $schemaErrors = array_merge(
            $schemaErrors,
            $directiveSchemaErrors
        );
        // foreach ($directiveSchemaErrors as $directiveSchemaError) {
        //     $schemaErrors[] = [
        //         Tokens::PATH => array_merge([$this->directive], $directiveSchemaError[Tokens::PATH]),
        //         Tokens::MESSAGE => $directiveSchemaError[Tokens::MESSAGE],
        //     ];
        // }
        $schemaWarnings = array_merge(
            $schemaWarnings,
            $directiveSchemaWarnings
        );
        // foreach ($directiveSchemaWarnings as $directiveSchemaWarning) {
        //     $schemaWarnings[] = [
        //         Tokens::PATH => array_merge([$this->directive], $directiveSchemaWarning[Tokens::PATH]),
        //         Tokens::MESSAGE => $directiveSchemaWarning[Tokens::MESSAGE],
        //     ];
        // }
        $schemaDeprecations = array_merge(
            $schemaDeprecations,
            $directiveSchemaDeprecations
        );
        // foreach ($directiveSchemaDeprecations as $directiveSchemaDeprecation) {
        //     $schemaDeprecations[] = [
        //         Tokens::PATH => array_merge([$this->directive], $directiveSchemaDeprecation[Tokens::PATH]),
        //         Tokens::MESSAGE => $directiveSchemaDeprecation[Tokens::MESSAGE],
        //     ];
        // }
        return [
            $validDirective,
            $directiveName,
            $directiveArgs,
        ];
    }

    /**
     * By default, do nothing
     *
     * @param TypeResolverInterface $typeResolver
     * @param array $directiveArgs
     * @param array $schemaErrors
     * @param array $schemaWarnings
     * @param array $schemaDeprecations
     * @return array
     */
    public function validateDirectiveArgumentsForSchema(TypeResolverInterface $typeResolver, array $directiveArgs, array &$schemaErrors, array &$schemaWarnings, array &$schemaDeprecations): array
    {
        return $directiveArgs;
    }

    public function dissectAndValidateDirectiveForResultItem(TypeResolverInterface $typeResolver, $resultItem, array &$variables, array &$expressions, array &$dbErrors, array &$dbWarnings): array
    {
        $fieldQueryInterpreter = FieldQueryInterpreterFacade::getInstance();
        list(
            $validDirective,
            $directiveName,
            $directiveArgs,
            $nestedDBErrors,
            $nestedDBWarnings
        ) = $fieldQueryInterpreter->extractDirectiveArgumentsForResultItem($this, $typeResolver, $resultItem, $this->directive, $variables, $expressions);

        // Store the args, they may be used in `resolveDirective`
        $this->directiveArgsForResultItems[$typeResolver->getId($resultItem)] = $directiveArgs;

        if ($nestedDBWarnings || $nestedDBErrors) {
            foreach ($nestedDBErrors as $id => $fieldOutputKeyErrorMessages) {
                $dbErrors[$id] = array_merge(
                    $dbErrors[$id] ?? [],
                    $fieldOutputKeyErrorMessages
                );
            }
            foreach ($nestedDBWarnings as $id => $fieldOutputKeyWarningMessages) {
                $dbWarnings[$id] = array_merge(
                    $dbWarnings[$id] ?? [],
                    $fieldOutputKeyWarningMessages
                );
            }
        }
        return [
            $validDirective,
            $directiveName,
            $directiveArgs,
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

    /**
     * By default, the directiveResolver instance can process the directive
     * This function can be overriden to force certain value on the directive args before it can be executed
     *
     * @param TypeResolverInterface $typeResolver
     * @param string $directiveName
     * @param array $directiveArgs
     * @return boolean
     */
    public function resolveCanProcess(TypeResolverInterface $typeResolver, string $directiveName, array $directiveArgs = [], string $field, array &$variables): bool
    {
        return true;
    }

    public function resolveSchemaValidationErrorDescription(TypeResolverInterface $typeResolver, string $directiveName, array $directiveArgs = []): ?string
    {
        // Iterate all the mandatory fieldArgs and, if they are not present, throw an error
        if ($schemaDirectiveArgs = $this->getSchemaDirectiveArgs($typeResolver)) {
            if ($mandatoryArgs = SchemaHelpers::getSchemaMandatoryFieldArgs($schemaDirectiveArgs)) {
                if ($maybeError = $this->validateNotMissingDirectiveArguments(
                    SchemaHelpers::getSchemaFieldArgNames($mandatoryArgs),
                    $directiveName,
                    $directiveArgs
                )) {
                    return $maybeError;
                }
            }
        }
        return null;
    }

    protected function validateNotMissingDirectiveArguments(array $directiveArgumentProperties, string $directiveName, array $directiveArgs = []): ?string
    {
        if ($missing = SchemaHelpers::getMissingFieldArgs($directiveArgumentProperties, $directiveArgs)) {
            $translationAPI = TranslationAPIFacade::getInstance();
            return count($missing) == 1 ?
                sprintf(
                    $translationAPI->__('Directive argument \'%s\' cannot be empty, so directive \'%s\' has been ignored', 'pop-component-model'),
                    $missing[0],
                    $directiveName
                ) :
                sprintf(
                    $translationAPI->__('Directive arguments \'%s\' cannot be empty, so directive \'%s\' has been ignored', 'pop-component-model'),
                    implode($translationAPI->__('\', \''), $missing),
                    $directiveName
                );
        }
        return null;
    }

    protected function getExpressionsForResultItem($id, array &$variables, array &$messages)
    {
        // Create a custom $variables containing all the properties from $dbItems for this resultItem
        // This way, when encountering $propName in a fieldArg in a fieldResolver, it can resolve that value
        // Otherwise it can't, since the fieldResolver doesn't have access to either $dbItems
        return array_merge(
            $variables,
            $messages[self::MESSAGE_EXPRESSIONS][(string)$id] ?? []
        );
    }

    protected function addExpressionForResultItem($id, $key, $value, array &$messages)
    {
        return $messages[self::MESSAGE_EXPRESSIONS][(string)$id][$key] = $value;
    }

    protected function getExpressionForResultItem($id, $key, array &$messages)
    {
        return $messages[self::MESSAGE_EXPRESSIONS][(string)$id][$key];
    }

    /**
     * By default, place the directive after the ResolveAndMerge directive, so the property will be in $dbItems by then
     *
     * @return void
     */
    public function getPipelinePosition(): string
    {
        return PipelinePositions::BACK;
    }

    /**
     * By default, a directive can be executed multiple times in the field (eg: <translate(en,es),translate(es,en)>)
     *
     * @return boolean
     */
    public function canExecuteMultipleTimesInField(): bool
    {
        return true;
    }

    // /**
    //  * Indicate if the directive needs to be passed $idsDataFields filled with data to be able to execute
    //  * Because most commonly it will need, the default value is `true`
    //  *
    //  * @return void
    //  */
    // public function needsIDsDataFieldsToExecute(): bool
    // {
    //     return true;
    // }

    // /**
    //  * Indicate that there is data in variable $idsDataFields
    //  *
    //  * @param array $idsDataFields
    //  * @return boolean
    //  */
    // protected function hasIDsDataFields(array &$idsDataFields): bool
    // {
    //     foreach ($idsDataFields as $id => &$data_fields) {
    //         if ($data_fields['direct']) {
    //             // If there's data-fields to fetch for any ID, that's it, there's data
    //             return true;
    //         }
    //     }
    //     // If we reached here, there is no data
    //     return false;
    // }

    public function enableOrderedSchemaDirectiveArgs(TypeResolverInterface $typeResolver): bool
    {
        if ($schemaDefinitionResolver = $this->getSchemaDefinitionResolver($typeResolver)) {
            return $schemaDefinitionResolver->enableOrderedSchemaDirectiveArgs($typeResolver);
        }
        return true;
    }

    public function getSchemaDirectiveArgs(TypeResolverInterface $typeResolver): array
    {
        if ($schemaDefinitionResolver = $this->getSchemaDefinitionResolver($typeResolver)) {
            return $schemaDefinitionResolver->getSchemaDirectiveArgs($typeResolver);
        }
        return [];
    }

    public function getSchemaDirectiveDeprecationDescription(TypeResolverInterface $typeResolver): ?string
    {
        if ($schemaDefinitionResolver = $this->getSchemaDefinitionResolver($typeResolver)) {
            return $schemaDefinitionResolver->getSchemaDirectiveDeprecationDescription($typeResolver);
        }
        return null;
    }

    public function getSchemaDirectiveExpressions(TypeResolverInterface $typeResolver): array
    {
        if ($schemaDefinitionResolver = $this->getSchemaDefinitionResolver($typeResolver)) {
            return $schemaDefinitionResolver->getSchemaDirectiveExpressions($typeResolver);
        }
        return [];
    }

    public function getSchemaDirectiveDescription(TypeResolverInterface $typeResolver): ?string
    {
        if ($schemaDefinitionResolver = $this->getSchemaDefinitionResolver($typeResolver)) {
            return $schemaDefinitionResolver->getSchemaDirectiveDescription($typeResolver);
        }
        return null;
    }

    public function isGlobal(TypeResolverInterface $typeResolver): bool
    {
        if ($schemaDefinitionResolver = $this->getSchemaDefinitionResolver($typeResolver)) {
            return $schemaDefinitionResolver->isGlobal($typeResolver);
        }
        return false;
    }

    public function __invoke($payload)
    {
        // 1. Extract the arguments from the payload
        // $pipelineIDsDataFields is an array containing all stages of the pipe
        // The one corresponding to the current stage is at the head. Take it out from there, and keep passing down the rest of the array to the next stages
        list(
            $typeResolver,
            $pipelineIDsDataFields,
            $resultIDItems,
            $convertibleDBKeyIDs,
            $dbItems,
            $previousDBItems,
            $variables,
            $messages,
            $dbErrors,
            $dbWarnings,
            $schemaErrors,
            $schemaWarnings,
            $schemaDeprecations
        ) = DirectivePipelineUtils::extractArgumentsFromPayload($payload);

        // Extract the head, keep passing down the rest
        $idsDataFields = $pipelineIDsDataFields[0];
        array_shift($pipelineIDsDataFields);

        // // 2. Validate operation
        // $this->validateDirective(
        //     $typeResolver,
        //     $idsDataFields,
        //     $pipelineIDsDataFields,
        //     $resultIDItems,
        //     $dbItems,
        //     $previousDBItems,
        //     $variables,
        //     $messages,
        //     $dbErrors,
        //     $dbWarnings,
        //     $schemaErrors,
        //     $schemaWarnings,
        //     $schemaDeprecations
        // );

        // 2. Execute operation.
        // // First check that if the validation took away the elements, and so the directive can't execute anymore
        // // For instance, executing ?query=posts.id|title<default,translate(from:en,to:es)> will fail after directive "default", so directive "translate" must not even execute
        // if (!$this->needsIDsDataFieldsToExecute() || $this->hasIDsDataFields($idsDataFields)) {
        $this->resolveDirective(
            $typeResolver,
            $idsDataFields,
            $pipelineIDsDataFields,
            $resultIDItems,
            $convertibleDBKeyIDs,
            $dbItems,
            $previousDBItems,
            $variables,
            $messages,
            $dbErrors,
            $dbWarnings,
            $schemaErrors,
            $schemaWarnings,
            $schemaDeprecations
        );
        // }

        // 3. Re-create the payload from the modified variables
        return DirectivePipelineUtils::convertArgumentsToPayload(
            $typeResolver,
            $pipelineIDsDataFields,
            $resultIDItems,
            $convertibleDBKeyIDs,
            $dbItems,
            $previousDBItems,
            $variables,
            $messages,
            $dbErrors,
            $dbWarnings,
            $schemaErrors,
            $schemaWarnings,
            $schemaDeprecations
        );
    }

    // public function validateDirective(TypeResolverInterface $typeResolver, array &$idsDataFields, array &$succeedingPipelineIDsDataFields, array &$resultIDItems, array &$dbItems, array &$previousDBItems, array &$variables, array &$messages, array &$dbErrors, array &$dbWarnings, array &$schemaErrors, array &$schemaWarnings, array &$schemaDeprecations)
    // {
    //     // Check that the directive can be applied to all provided fields
    //     $this->validateAndFilterFieldsForDirective($idsDataFields, $schemaErrors, $schemaWarnings);
    // }

    // /**
    //  * Check that the directive can be applied to all provided fields
    //  *
    //  * @param array $idsDataFields
    //  * @param array $schemaErrors
    //  * @return void
    //  */
    // protected function validateAndFilterFieldsForDirective(array &$idsDataFields, array &$schemaErrors, array &$schemaWarnings)
    // {
    //     $directiveSupportedFieldNames = $this->getFieldNamesToApplyTo();

    //     // If this function returns an empty array, then it supports all fields, then do nothing
    //     if (!$directiveSupportedFieldNames) {
    //         return;
    //     }

    //     // Check if all fields are supported by this directive
    //     $fieldQueryInterpreter = FieldQueryInterpreterFacade::getInstance();
    //     $failedFields = [];
    //     foreach ($idsDataFields as $id => &$data_fields) {
    //         // Get the fieldName for each field
    //         $nameFields = [];
    //         foreach ($data_fields['direct'] as $field) {
    //             $nameFields[$fieldQueryInterpreter->getFieldName($field)] = $field;
    //         }
    //         // If any fieldName failed, remove it from the list of fields to execute for this directive
    //         if ($unsupportedFieldNames = array_diff(array_keys($nameFields), $directiveSupportedFieldNames)) {
    //             $unsupportedFields = array_map(
    //                 function($fieldName) use ($nameFields) {
    //                     return $nameFields[$fieldName];
    //                 },
    //                 $unsupportedFieldNames
    //             );
    //             $failedFields = array_values(array_unique(array_merge(
    //                 $failedFields,
    //                 $unsupportedFields
    //             )));
    //         }
    //     }
    //     // Give an error message for all failed fields
    //     if ($failedFields) {
    //         $translationAPI = TranslationAPIFacade::getInstance();
    //         $directiveName = $this->getDirectiveName();
    //         $failedFieldNames = array_map(
    //             [$fieldQueryInterpreter, 'getFieldName'],
    //             $failedFields
    //         );
    //         if (count($failedFields) == 1) {
    //             $message = $translationAPI->__('Directive \'%s\' doesn\'t support field \'%s\' (the only supported field names are: \'%s\')', 'component-model');
    //         } else {
    //             $message = $translationAPI->__('Directive \'%s\' doesn\'t support fields \'%s\' (the only supported field names are: \'%s\')', 'component-model');
    //         }
    //         $failureMessage = sprintf(
    //             $message,
    //             $directiveName,
    //             implode($translationAPI->__('\', \''), $failedFieldNames),
    //             implode($translationAPI->__('\', \''), $directiveSupportedFieldNames)
    //         );
    //         $this->processFailure($failureMessage, $failedFields, $idsDataFields, $schemaErrors, $schemaWarnings);
    //     }
    // }


    /**
     * Depending on environment configuration, either show a warning, or show an error and remove the fields from the directive pipeline for further execution
     *
     * @param array $schemaErrors
     * @param array $schemaWarnings
     * @return void
     */
    protected function processFailure(string $failureMessage, array $failedFields = [], array &$idsDataFields, array &$succeedingPipelineIDsDataFields, array &$schemaErrors, array &$schemaWarnings)
    {
        $allFieldsFailed = empty($failedFields);
        if ($allFieldsFailed) {
            // Remove all fields
            $idsDataFieldsToRemove = $idsDataFields;
            // Calculate which fields are being removed, to add to the error
            foreach ($idsDataFields as $id => &$data_fields) {
                $failedFields = array_merge(
                    $failedFields,
                    $data_fields['direct']
                );
            }
            $failedFields = array_values(array_unique($failedFields));
        } else {
            $idsDataFieldsToRemove = [];
            // Calculate which fields to remove
            foreach ($idsDataFields as $id => &$data_fields) {
                $idsDataFieldsToRemove[(string)$id]['direct'] = array_intersect(
                    $data_fields['direct'],
                    $failedFields
                );
            }
        }
        // If the failure must be processed as an error, we must also remove the fields from the directive pipeline
        $removeFieldIfDirectiveFailed = Environment::removeFieldIfDirectiveFailed();
        if ($removeFieldIfDirectiveFailed) {
            $this->removeIDsDataFields($idsDataFieldsToRemove, $succeedingPipelineIDsDataFields);
        }

        // Show the failureMessage either as error or as warning
        // $fieldQueryInterpreter = FieldQueryInterpreterFacade::getInstance();
        $translationAPI = TranslationAPIFacade::getInstance();
        $directiveName = $this->getDirectiveName();
        // $failedFieldNames = array_map(
        //     [$fieldQueryInterpreter, 'getFieldName'],
        //     $failedFields
        // );
        if ($removeFieldIfDirectiveFailed) {
            if (count($failedFields) == 1) {
                $message = $translationAPI->__('%s. Field \'%s\' has been removed from the directive pipeline', 'component-model');
            } else {
                $message = $translationAPI->__('%s. Fields \'%s\' have been removed from the directive pipeline', 'component-model');
            }
            $schemaErrors[] = [
                Tokens::PATH => [implode($translationAPI->__('\', \''), $failedFields), $this->directive],
                Tokens::MESSAGE => sprintf(
                    $message,
                    $failureMessage,
                    implode($translationAPI->__('\', \''), $failedFields)
                ),
            ];
        } else {
            if (count($failedFields) == 1) {
                $message = $translationAPI->__('%s. Execution of directive \'%s\' has been ignored on field \'%s\'', 'component-model');
            } else {
                $message = $translationAPI->__('%s. Execution of directive \'%s\' has been ignored on fields \'%s\'', 'component-model');
            }
            $schemaWarnings[] = [
                Tokens::PATH => [implode($translationAPI->__('\', \''), $failedFields), $this->directive],
                Tokens::MESSAGE => sprintf(
                    $message,
                    $failureMessage,
                    $directiveName,
                    implode($translationAPI->__('\', \''), $failedFields)
                ),
            ];
        }
    }

    public function getSchemaDefinitionResolver(TypeResolverInterface $typeResolver): ?SchemaDirectiveResolverInterface
    {
        return null;
    }

    public function skipAddingToSchemaDefinition() {
        return false;
    }

    public function getSchemaDefinitionForDirective(TypeResolverInterface $typeResolver): array
    {
        $directiveName = $this->getDirectiveName();
        $schemaDefinition = [
            SchemaDefinition::ARGNAME_NAME => $directiveName,
            SchemaDefinition::ARGNAME_DIRECTIVE_PIPELINE_POSITION => $this->getPipelinePosition(),
            SchemaDefinition::ARGNAME_DIRECTIVE_CAN_EXECUTE_MULTIPLE_TIMES => $this->canExecuteMultipleTimesInField(),
            // SchemaDefinition::ARGNAME_DIRECTIVE_NEEDS_DATA_TO_EXECUTE => $this->needsIDsDataFieldsToExecute(),
        ];
        if ($limitedToFields = $this::getFieldNamesToApplyTo()) {
            $schemaDefinition[SchemaDefinition::ARGNAME_DIRECTIVE_LIMITED_TO_FIELDS] = $limitedToFields;
        }
        if ($schemaDefinitionResolver = $this->getSchemaDefinitionResolver($typeResolver)) {
            if ($description = $schemaDefinitionResolver->getSchemaDirectiveDescription($typeResolver)) {
                $schemaDefinition[SchemaDefinition::ARGNAME_DESCRIPTION] = $description;
            }
            if ($expressions = $schemaDefinitionResolver->getSchemaDirectiveExpressions($typeResolver)) {
                $schemaDefinition[SchemaDefinition::ARGNAME_DIRECTIVE_EXPRESSIONS] = $expressions;
            }
            if ($deprecationDescription = $schemaDefinitionResolver->getSchemaDirectiveDeprecationDescription($typeResolver)) {
                $schemaDefinition[SchemaDefinition::ARGNAME_DEPRECATED] = true;
                $schemaDefinition[SchemaDefinition::ARGNAME_DEPRECATEDDESCRIPTION] = $deprecationDescription;
            }
            if ($args = $schemaDefinitionResolver->getSchemaDirectiveArgs($typeResolver)) {
                $schemaDefinition[SchemaDefinition::ARGNAME_ARGS] = $args;
            }
        }
        $this->addSchemaDefinitionForDirective($schemaDefinition);
        return $schemaDefinition;
    }

    /**
     * Function to override
     */
    protected function addSchemaDefinitionForDirective(array &$schemaDefinition)
    {
    }
}

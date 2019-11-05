<?php
namespace PoP\ComponentModel\FieldResolvers;
use PoP\ComponentModel\ErrorUtils;
use PoP\ComponentModel\DataloaderInterface;
use PoP\FieldQuery\FieldQueryUtils;
use League\Pipeline\PipelineBuilder;
use PoP\ComponentModel\Schema\SchemaDefinition;
use PoP\Translation\Facades\TranslationAPIFacade;
use PoP\ComponentModel\Facades\Instances\InstanceManagerFacade;
use PoP\ComponentModel\Facades\Schema\FeedbackMessageStoreFacade;
use PoP\ComponentModel\Facades\Schema\FieldQueryInterpreterFacade;
use PoP\ComponentModel\DirectivePipeline\DirectivePipelineDecorator;
use PoP\ComponentModel\DirectiveResolvers\ValidateDirectiveResolver;
use PoP\ComponentModel\AttachableExtensions\AttachableExtensionGroups;
use PoP\ComponentModel\DirectiveResolvers\ResolveValueAndMergeDirectiveResolver;
use PoP\ComponentModel\Facades\AttachableExtensions\AttachableExtensionManagerFacade;

abstract class AbstractFieldResolver implements FieldResolverInterface
{
    /**
     * Cache of which fieldValueResolvers will process the given field
     *
     * @var array
     */
    protected $fieldValueResolvers = [];
    // protected $fieldDirectiveResolvers = [];
    protected $schemaDefinition;
    protected $fieldNamesToResolve;
    protected $directiveNameClasses;
    protected $safeVars;

    private $fieldDirectiveIDsFields = [];
    private $directiveResultSet = [];
    private $fieldDirectivePipelineInstanceCache = [];
    private $fieldDirectiveInstanceCache = [];
    private $fieldDirectivesFromFieldCache = [];
    private $dissectedFieldForSchemaCache = [];
    private $fieldResolverSchemaIdsCache = [];
    private $directiveResolverInstanceCache = [];

    public function getFieldNamesToResolve(): array
    {
        if (is_null($this->fieldNamesToResolve)) {
            $this->fieldNamesToResolve = $this->calculateFieldNamesToResolve();
        }
        return $this->fieldNamesToResolve;
    }

    public function getDirectiveNameClasses(): array
    {
        if (is_null($this->directiveNameClasses)) {
            $this->directiveNameClasses = $this->calculateFieldDirectiveNameClasses();
        }
        return $this->directiveNameClasses;
    }

    protected function getFieldDirectivePipeline(string $fieldDirectives, array &$schemaErrors, array &$schemaWarnings, array &$schemaDeprecations): DirectivePipelineDecorator
    {
        // Pipeline cache
        if (is_null($this->fieldDirectivePipelineInstanceCache[$fieldDirectives])) {
            $translationAPI = TranslationAPIFacade::getInstance();
            $fieldQueryInterpreter = FieldQueryInterpreterFacade::getInstance();
            $pipelineBuilder = new PipelineBuilder();
            $directiveNameClasses = $this->getDirectiveNameClasses();
            // Initialize with the default values, adding "validate" and "merge" if not there yet
            $directiveSet = $fieldQueryInterpreter->extractFieldDirectives($fieldDirectives);

            /**
            * The pipeline must always have directives:
            * 1. Validate: to validate that the schema, fieldNames, etc are supported, and filter them out if not
            * 2. ResolveAndMerge: to resolve the field and place the data into the DB object
            * All other directives are placed somewhere in the pipeline, using these 2 directives as anchors.
            * There are 3 positions:
            * 1. At the beginning, before the Validate pipeline
            * 2. In the middle, between the Validate and Resolve directives
            * 3. At the end, after the ResolveAndMerge directive
            */
            $directivesByPosition = [
                PipelinePositions::FRONT => [],
                PipelinePositions::MIDDLE => [],
                PipelinePositions::BACK => [],
            ];
            // Place the 2 mandatory directives at the beginning of the list, then they will be added to their needed position in the pipeline
            array_unshift(
                $directiveSet,
                $fieldQueryInterpreter->listFieldDirective(ValidateDirectiveResolver::getDirectiveName()),
                $fieldQueryInterpreter->listFieldDirective(ResolveValueAndMergeDirectiveResolver::getDirectiveName())
            );
            // Count how many times each directive is added
            $directiveCount = [];
            foreach ($directiveSet as $directive) {
                $directiveName = $fieldQueryInterpreter->getDirectiveName($directive);
                $fieldDirective = $fieldQueryInterpreter->convertDirectiveToFieldDirective($directive);
                if (is_null($this->fieldDirectiveInstanceCache[$fieldDirective])) {
                    $directiveArgs = $fieldQueryInterpreter->extractStaticDirectiveArguments($fieldDirective);
                    $directiveClasses = $directiveNameClasses[$directiveName];
                    // If there is no directive with this name, show an error and skip it
                    if (is_null($directiveClasses)) {
                        $schemaErrors[$fieldDirective][] = sprintf(
                            $translationAPI->__('No DirectiveResolver resolves directive with name \'%s\'', 'pop-component-model'),
                            $directiveName
                        );
                        continue;
                    }

                    // Check that at least one class which deals with this directiveName can satisfy the directive (for instance, validating that a required directiveArg is present)
                    $directiveResolverInstance = null;
                    foreach ($directiveClasses as $directiveClass) {
                        // Get the instance from the cache if it exists, or create it if not
                        if (is_null($this->directiveResolverInstanceCache[$directiveClass][$fieldDirective])) {
                            $this->directiveResolverInstanceCache[$directiveClass][$fieldDirective] = new $directiveClass($fieldDirective);
                        }
                        $maybeDirectiveResolverInstance = $this->directiveResolverInstanceCache[$directiveClass][$fieldDirective];

                        // Check if this instance can process the directive
                        if ($maybeDirectiveResolverInstance->resolveCanProcess($this, $directiveName, $directiveArgs)) {
                            $directiveResolverInstance = $maybeDirectiveResolverInstance;
                            break;
                        }
                    }
                    if (is_null($directiveResolverInstance)) {
                        $schemaErrors[$fieldDirective][] = sprintf(
                            $translationAPI->__('No DirectiveResolver processes directive with name \'%s\' and arguments \'%s\'', 'pop-component-model'),
                            $directiveName,
                            json_encode($directiveArgs)
                        );
                        continue;
                    }

                    // Validate schema (eg of error in schema: ?query=posts<include(if:this-field-doesnt-exist())>)
                    list(
                        $validFieldDirective,
                        $directiveName,
                        $directiveArgs,
                    ) = $directiveResolverInstance->dissectAndValidateDirectiveForSchema($this, $schemaErrors, $schemaWarnings, $schemaDeprecations);
                    // Check that the directive is a valid one (eg: no schema errors)
                    if (is_null($validFieldDirective)) {
                        $schemaErrors[$fieldDirective][] = $translationAPI->__('This directive can\'t be processed due to previous errors', 'pop-component-model');
                        continue;
                    }

                    // Validate against the directiveResolver
                    if ($maybeError = $directiveResolverInstance->resolveSchemaValidationErrorDescription($this, $directiveName, $directiveArgs)) {
                        $schemaErrors[$fieldDirective][] = $maybeError;
                        continue;
                    }

                    // Directive is valid so far. Assign the instance to the cache
                    $this->fieldDirectiveInstanceCache[$fieldDirective] = $directiveResolverInstance;
                }
                $directiveResolverInstance = $this->fieldDirectiveInstanceCache[$fieldDirective];

                // Validate if the directive can be executed multiple times
                $directiveCount[$directiveName] = isset($directiveCount[$directiveName]) ? $directiveCount[$directiveName] + 1 : 1;
                if ($directiveCount[$directiveName] > 1 && !$directiveResolverInstance->canExecuteMultipleTimesInField()) {
                    $schemaErrors[$fieldDirective][] = sprintf(
                        $translationAPI->__('Directive \'%s\' can be executed only once within a field, so the current execution (number %s) has been ignored', 'pop-component-model'),
                        $fieldDirective,
                        $directiveCount[$directiveName]
                    );
                    continue;
                }

                // Check for deprecations
                if ($deprecationDescription = $directiveResolverInstance->getSchemaDirectiveDeprecationDescription($this)) {
                    $schemaDeprecations[$fieldDirective][] = $deprecationDescription;
                }

                // Directive is valid. Add it as a pipeline stage, in its required position
                $directivesByPosition[$directiveResolverInstance->getPipelinePosition()][] = $directiveResolverInstance;
            }
            // Add all the directives into the pipeline
            foreach ($directivesByPosition as $position => $directiveResolverInstances) {
                foreach ($directiveResolverInstances as $directiveResolverInstance) {
                    $pipelineBuilder->add($directiveResolverInstance);
                }
            }

            // Build the pipeline
            $this->fieldDirectivePipelineInstanceCache[$fieldDirectives] = new DirectivePipelineDecorator($pipelineBuilder->build());
        }
        return $this->fieldDirectivePipelineInstanceCache[$fieldDirectives];
    }

    /**
     * By default, do nothing
     *
     * @param string $field
     * @param array $fieldArgs
     * @param array $schemaErrors
     * @param array $schemaWarnings
     * @param array $schemaDeprecations
     * @return array
     */
    public function validateFieldArgumentsForSchema(string $field, array $fieldArgs, array &$schemaErrors, array &$schemaWarnings, array &$schemaDeprecations): array
    {
        return $fieldArgs;
    }

    public function fillResultItems(DataloaderInterface $dataloader, array $ids_data_fields, array &$dbItems, array &$dbErrors, array &$dbWarnings, array &$schemaErrors, array &$schemaWarnings, array &$schemaDeprecations, array &$previousDBItems, array &$variables)
    {
        // Obtain the data for the required object IDs
        $resultIDItems = $dataloader->getData(array_keys($ids_data_fields));

        // Enqueue the items
        $this->enqueueFillingResultItemsFromIDs($ids_data_fields, $resultIDItems);

        // Process them
        $this->processFillingResultItemsFromIDs($dataloader, $dbItems, $dbErrors, $dbWarnings, $schemaErrors, $schemaWarnings, $schemaDeprecations, $previousDBItems, $variables);
    }

    public function enqueueFillingResultItemsFromIDs(array $ids_data_fields, array &$resultIDItems)
    {
        // Collect all combinations of ID/data-fields for each directive
        $fieldQueryInterpreter = FieldQueryInterpreterFacade::getInstance();
        foreach ($ids_data_fields as $id => $data_fields) {
            foreach ($data_fields['direct'] as $field) {
                if (is_null($this->fieldDirectivesFromFieldCache[$field])) {
                    $this->fieldDirectivesFromFieldCache[$field] = $fieldQueryInterpreter->getFieldDirectives($field) ?? '';
                }
                $fieldDirectives = $this->fieldDirectivesFromFieldCache[$field];
                $this->directiveResultSet[$fieldDirectives][$id] = $resultIDItems[$id];
                $this->fieldDirectiveIDsFields[$fieldDirectives][$id]['direct'][] = $field;
                $this->fieldDirectiveIDsFields[$fieldDirectives][$id]['conditional'] = $this->fieldDirectiveIDsFields[$fieldDirectives][$id]['conditional'] ?? [];
            }
            foreach ($data_fields['conditional'] as $field => $conditionalFields) {
                $this->fieldDirectiveIDsFields[$fieldDirectives][$id]['conditional'][$field] = array_merge_recursive(
                    $this->fieldDirectiveIDsFields[$fieldDirectives][$id]['conditional'][$field] ?? [],
                    $conditionalFields
                );
            }
        }
    }

    protected function processFillingResultItemsFromIDs(DataloaderInterface $dataloader, array &$dbItems, array &$dbErrors, array &$dbWarnings, array &$schemaErrors, array &$schemaWarnings, array &$schemaDeprecations, array &$previousDBItems, array &$variables)
    {
        // Iterate while there are directives with data to be processed
        while (!empty($this->fieldDirectiveIDsFields)) {
            // Move the pointer to the first element, and get it
            reset($this->fieldDirectiveIDsFields);
            $fieldDirectives = key($this->fieldDirectiveIDsFields);
            $idsDataFields = $this->fieldDirectiveIDsFields[$fieldDirectives];
            $directiveResultSet = $this->directiveResultSet[$fieldDirectives];

            // Remove the directive element from the array, so it doesn't process it anymore
            unset($this->fieldDirectiveIDsFields[$fieldDirectives]);
            unset($this->directiveResultSet[$fieldDirectives]);

            // If no ids to execute, then skip
            if (empty($idsDataFields)) {
                continue;
            }

            // From the fieldDirectiveName get the class that processes it. If null, the users passed a wrong name through the API, so show an error
            $directivePipeline = $this->getFieldDirectivePipeline($fieldDirectives, $schemaErrors, $schemaWarnings, $schemaDeprecations);
            $directivePipeline->resolveDirectivePipeline(
                $dataloader,
                $this,
                $directiveResultSet,
                $idsDataFields,
                $dbItems,
                $dbErrors,
                $dbWarnings,
                $schemaErrors,
                $schemaWarnings,
                $schemaDeprecations,
                $previousDBItems,
                $variables
            );
        }
    }

    protected function dissectFieldForSchema(string $field): ?array
    {
        if (!isset($this->dissectedFieldForSchemaCache[$field])) {
            $this->dissectedFieldForSchemaCache[$field] = $this->doDissectFieldForSchema($field);
        }
        return $this->dissectedFieldForSchemaCache[$field];
    }

    protected function doDissectFieldForSchema(string $field): ?array
    {
        $fieldQueryInterpreter = FieldQueryInterpreterFacade::getInstance();
        return $fieldQueryInterpreter->extractFieldArgumentsForSchema($this, $field);
    }

    public function resolveSchemaValidationErrorDescriptions(string $field): ?array
    {
        // Get the value from a fieldValueResolver, from the first one that resolves it
        if ($fieldValueResolvers = $this->getFieldValueResolversForField($field)) {
            list(
                $field,
                $fieldName,
                $fieldArgs,
                $schemaErrors,
            ) = $this->dissectFieldForSchema($field);
            if ($maybeError = $fieldValueResolvers[0]->resolveSchemaValidationErrorDescription($this, $fieldName, $fieldArgs)) {
                $schemaErrors[] = $maybeError;
            }
            return $schemaErrors;
        }

        // If we reach here, no fieldValueResolver processes this field, which is an error
        $translationAPI = TranslationAPIFacade::getInstance();
        $fieldQueryInterpreter = FieldQueryInterpreterFacade::getInstance();
        $fieldName = $fieldQueryInterpreter->getFieldName($field);
        return [
            sprintf(
                $translationAPI->__('No FieldValueResolver resolves field \'%s\'', 'pop-component-model'),
                $fieldName
            ),
        ];
    }

    public function resolveSchemaValidationWarningDescriptions(string $field): ?array
    {
        // Get the value from a fieldValueResolver, from the first one that resolves it
        if ($fieldValueResolvers = $this->getFieldValueResolversForField($field)) {
            list(
                $field,
                $fieldName,
                $fieldArgs,
                $schemaErrors,
                $schemaWarnings,
            ) = $this->dissectFieldForSchema($field);
            if ($maybeWarning = $fieldValueResolvers[0]->resolveSchemaValidationWarningDescription($this, $fieldName, $fieldArgs)) {
                $schemaWarnings[] = $maybeWarning;
            }
            return $schemaWarnings;
        }

        return null;
    }

    public function getSchemaDeprecationDescriptions(string $field): ?array
    {
        // Get the value from a fieldValueResolver, from the first one that resolves it
        if ($fieldValueResolvers = $this->getFieldValueResolversForField($field)) {
            list(
                $field,
                $fieldName,
                $fieldArgs,
                $schemaErrors,
                $schemaWarnings,
                $schemaDeprecations,
            ) = $this->dissectFieldForSchema($field);
            if ($maybeDeprecation = $fieldValueResolvers[0]->getSchemaFieldDeprecationDescription($this, $fieldName, $fieldArgs)) {
                $schemaDeprecations[] = $maybeDeprecation;
            }
            return $schemaDeprecations;
        }

        return null;
    }

    public function getSchemaFieldArgs(string $field): array
    {
        // Get the value from a fieldValueResolver, from the first one that resolves it
        if ($fieldValueResolvers = $this->getFieldValueResolversForField($field)) {
            $fieldQueryInterpreter = FieldQueryInterpreterFacade::getInstance();
            $fieldName = $fieldQueryInterpreter->getFieldName($field);
            return $fieldValueResolvers[0]->getSchemaFieldArgs($this, $fieldName);
        }

        return [];
    }

    public function enableOrderedSchemaFieldArgs(string $field): bool
    {
        // Get the value from a fieldValueResolver, from the first one that resolves it
        if ($fieldValueResolvers = $this->getFieldValueResolversForField($field)) {
            $fieldQueryInterpreter = FieldQueryInterpreterFacade::getInstance();
            $fieldName = $fieldQueryInterpreter->getFieldName($field);
            return $fieldValueResolvers[0]->enableOrderedSchemaFieldArgs($this, $fieldName);
        }

        return false;
    }

    public function resolveFieldDefaultDataloaderClass(string $field): ?string
    {
        // Get the value from a fieldValueResolver, from the first one that resolves it
        if ($fieldValueResolvers = $this->getFieldValueResolversForField($field)) {
            list(
                $field,
                $fieldName,
                $fieldArgs,
            ) = $this->dissectFieldForSchema($field);
            return $fieldValueResolvers[0]->resolveFieldDefaultDataloaderClass($this, $fieldName, $fieldArgs);
        }

        return null;
    }

    public function resolveValue($resultItem, string $field, ?array $variables = null)
    {
        $fieldQueryInterpreter = FieldQueryInterpreterFacade::getInstance();
        // Get the value from a fieldValueResolver, from the first one who can deliver the value
        // (The fact that they resolve the fieldName doesn't mean that they will always resolve it for that specific $resultItem)
        if ($fieldValueResolvers = $this->getFieldValueResolversForField($field)) {
            // Important: $validField becomes $field: remove all invalid fieldArgs before executing `resolveValue` on the fieldValueResolver
            list(
                $field,
                $fieldName,
                $fieldArgs,
            ) = $this->dissectFieldForSchema($field);

            // Once again, the $validField becomes the $field
            list(
                $field,
                $fieldName,
                $fieldArgs,
                $dbErrors,
                $dbWarnings
            ) = $fieldQueryInterpreter->extractFieldArgumentsForResultItem($this, $resultItem, $field, $variables);
            // Store the warnings to be read if needed
            if ($dbWarnings) {
                $feedbackMessageStore = FeedbackMessageStoreFacade::getInstance();
                $feedbackMessageStore->addDBWarnings($dbWarnings);
            }
            if ($dbErrors) {
                return ErrorUtils::getNestedDBErrorsFieldError($dbErrors, $fieldName);
            }
            // Before resolving the fieldArgValues which are fields:
            // Calculate $validateSchemaOnResultItem: if any value containes a field, then we must perform the schemaValidation on the item, such as checking that all mandatory fields are there
            // For instance: After resolving a field and being casted it may be incorrect, so the value is invalidated, and after the schemaValidation the proper error is shown
            // Also need to check for variables, since these must be resolved too
            // For instance: ?query=posts(limit:3),post(id:$id).id|title&id=112
            $validateSchemaOnResultItem = FieldQueryUtils::isAnyFieldArgumentValueAFieldOrVariable(
                array_values(
                    $fieldQueryInterpreter->extractFieldArguments($this, $field)
                )
            );
            foreach ($fieldValueResolvers as $fieldValueResolver) {
                // Also send the fieldResolver along, as to get the id of the $resultItem being passed
                if ($fieldValueResolver->resolveCanProcessResultItem($this, $resultItem, $fieldName, $fieldArgs)) {
                    if ($validateSchemaOnResultItem) {
                        if ($maybeError = $fieldValueResolver->resolveSchemaValidationErrorDescription($this, $fieldName, $fieldArgs)) {
                            return ErrorUtils::getValidationFailedError($fieldName, $fieldArgs, $maybeError);
                        }
                    }
                    if ($validationErrorDescription = $fieldValueResolver->getValidationErrorDescription($this, $resultItem, $fieldName, $fieldArgs)) {
                        return ErrorUtils::getValidationFailedError($fieldName, $fieldArgs, $validationErrorDescription);
                    }
                    return $fieldValueResolver->resolveValue($this, $resultItem, $fieldName, $fieldArgs);
                }
            }
            return ErrorUtils::getNoFieldValueResolverProcessesFieldError($this->getId($resultItem), $fieldName, $fieldArgs);
        }

        // Return an error to indicate that no fieldValueResolver processes this field, which is different than returning a null value.
        // Needed for compatibility with Dataloader_ConvertiblePostList (so that data-fields aimed for another post_type are not retrieved)
        $fieldName = $fieldQueryInterpreter->getFieldName($field);
        return ErrorUtils::getNoFieldError($fieldName);
    }

    protected function getFieldResolverSchemaId(string $class): string
    {
        if (!isset($this->fieldResolverSchemaIdsCache[$class])) {
            $this->fieldResolverSchemaIdsCache[$class] = $this->doGetFieldResolverSchemaId($class);

            // Log how the hash and the class are related
            $feedbackMessageStore = FeedbackMessageStoreFacade::getInstance();
            $translationAPI = TranslationAPIFacade::getInstance();
            $feedbackMessageStore->addLogEntry(
                sprintf(
                    $translationAPI->__('Field resolver with ID \'%s\' corresponds to class \'%s\'', 'pop-component-model'),
                    $this->fieldResolverSchemaIdsCache[$class],
                    $class
                )
            );
        }
        return $this->fieldResolverSchemaIdsCache[$class];
    }

    protected function doGetFieldResolverSchemaId(string $class): string
    {
        return hash('md5', $class);
    }

    public function getSchemaDefinition(array $fieldArgs = [], array $options = []): array
    {
        // Stop recursion
        $class = get_called_class();
        if (in_array($class, $options['processed'])) {
            return [
                SchemaDefinition::ARGNAME_RESOLVERID => $this->getFieldResolverSchemaId($class),
                SchemaDefinition::ARGNAME_RECURSION => true,
            ];
        }

        $options['processed'][] = $class;
        if (is_null($this->schemaDefinition)) {
            $this->schemaDefinition = [
                SchemaDefinition::ARGNAME_RESOLVERID => $this->getFieldResolverSchemaId($class),
            ];
            $this->addSchemaDefinition($fieldArgs, $options);
        }

        return $this->schemaDefinition;
    }

    protected function addSchemaDefinition(array $schemaFieldArgs = [], array $options = [])
    {
        $instanceManager = InstanceManagerFacade::getInstance();

        // Only in the root we output the operators and helpers
        $isRoot = $options['is-root'];
        unset($options['is-root']);

        // Add the directives
        $this->schemaDefinition[SchemaDefinition::ARGNAME_DIRECTIVES] = [];
        $directiveNameClasses = $this->getDirectiveNameClasses();
        foreach ($directiveNameClasses as $directiveName => $directiveClasses) {
            foreach ($directiveClasses as $directiveClass) {
                $directiveResolverInstance = $instanceManager->getInstance($directiveClass);
                // $directiveResolverInstance = new $directiveClass($directiveName);
                $isGlobal = $directiveResolverInstance->isGlobal($this);
                if (!$isGlobal || ($isGlobal && $isRoot)) {
                    $directiveSchemaDefinition = $directiveResolverInstance->getSchemaDefinitionForDirective($this);
                    if ($isGlobal) {
                        $this->schemaDefinition[SchemaDefinition::ARGNAME_GLOBAL_DIRECTIVES][] = $directiveSchemaDefinition;
                    } else {
                        $this->schemaDefinition[SchemaDefinition::ARGNAME_DIRECTIVES][] = $directiveSchemaDefinition;
                    }
                }
            }
        }

        // Remove all fields which are not resolved by any unit
        $this->schemaDefinition[SchemaDefinition::ARGNAME_FIELDS] = [];
        $this->calculateAllFieldValueResolvers();
        foreach (array_filter($this->fieldValueResolvers) as $field => $fieldValueResolvers) {
            // Copy the properties from the schemaFieldArgs to the fieldArgs, in particular "deep"
            list(
                $field,
                $fieldName,
                $fieldArgs,
            ) = $this->dissectFieldForSchema($field);
            if (!is_null($field)) {
                // Get the documentation from the first element
                $fieldValueResolver = $fieldValueResolvers[0];
                $isOperatorOrHelper = $fieldValueResolver->isOperatorOrHelper($this, $fieldName);
                if (!$isOperatorOrHelper || ($isOperatorOrHelper && $isRoot)) {
                    $fieldArgs = array_merge(
                        $schemaFieldArgs,
                        $fieldArgs
                    );
                    $fieldSchemaDefinition = $fieldValueResolver->getSchemaDefinitionForField($this, $fieldName, $fieldArgs);
                    // Add subfield schema if it is deep, and this fieldResolver has not been processed yet
                    if ($fieldArgs['deep']) {
                        // If this field is relational, then add its own schema
                        if ($fieldDataloaderClass = $this->resolveFieldDefaultDataloaderClass($field)) {
                            // Append subfields' schema
                            $fieldDataloader = $instanceManager->getInstance($fieldDataloaderClass);
                            if ($fieldResolverClass = $fieldDataloader->getFieldResolverClass()) {
                                $fieldResolver = $instanceManager->getInstance($fieldResolverClass);
                                $fieldSchemaDefinition[SchemaDefinition::ARGNAME_RESOLVER] = $fieldResolver->getSchemaDefinition($fieldArgs, $options);
                            }
                        }
                    }

                    if ($isOperatorOrHelper) {
                        $this->schemaDefinition[SchemaDefinition::ARGNAME_OPERATORS_AND_HELPERS][] = $fieldSchemaDefinition;
                    } else {
                        $this->schemaDefinition[SchemaDefinition::ARGNAME_FIELDS][] = $fieldSchemaDefinition;
                    }
                }
            }
        }
    }

    protected function calculateAllFieldValueResolvers()
    {
        $fieldQueryInterpreter = FieldQueryInterpreterFacade::getInstance();
        $attachableExtensionManager = AttachableExtensionManagerFacade::getInstance();

        // Iterate classes from the current class towards the parent classes until finding fieldResolver that satisfies processing this field
        $class = get_called_class();
        do {
            foreach ($attachableExtensionManager->getExtensionClasses($class, AttachableExtensionGroups::FIELDVALUERESOLVERS) as $extensionClass => $extensionPriority) {
                // Process the fields which have not been processed yet
                foreach (array_diff($extensionClass::getFieldNamesToResolve(), array_unique(array_map([$fieldQueryInterpreter, 'getFieldName'], array_keys($this->fieldValueResolvers)))) as $fieldName) {
                    // Watch out here: no fieldArgs!!!! So this deals with the base case (static), not with all cases (runtime)
                    $this->getFieldValueResolversForField($fieldName);
                }
            }
            // Otherwise, continue iterating for the class parents
        } while ($class = get_parent_class($class));
    }

    protected function getFieldValueResolversForField(string $field): array
    {
        // Calculate the fieldValueResolver to process this field if not already in the cache
        // If none is found, this value will be set to NULL. This is needed to stop attempting to find the fieldValueResolver
        if (!isset($this->fieldValueResolvers[$field])) {
            $this->fieldValueResolvers[$field] = $this->calculateFieldValueResolversForField($field);
        }

        return $this->fieldValueResolvers[$field];
    }

    public function hasFieldValueResolversForField(string $field): bool
    {
        return !empty($this->getFieldValueResolversForField($field));
    }

    protected function calculateFieldValueResolversForField(string $field): array
    {
        // Important: here we CAN'T use `dissectFieldForSchema` to get the fieldArgs, because it will attempt to validate them
        // To validate them, the fieldQueryInterpreter needs to know the schema, so it once again calls functions from this fieldResolver
        // Generating an infinite loop
        // Then, just to find out which fieldValueResolvers will process this field, crudely obtain the fieldArgs, with NO schema-based validation!
        // list(
        //     $field,
        //     $fieldName,
        //     $fieldArgs,
        // ) = $this->dissectFieldForSchema($field);
        $fieldQueryInterpreter = FieldQueryInterpreterFacade::getInstance();
        $fieldName = $fieldQueryInterpreter->getFieldName($field);
        $fieldArgs = $fieldQueryInterpreter->extractStaticFieldArguments($field);

        $instanceManager = InstanceManagerFacade::getInstance();
        $attachableExtensionManager = AttachableExtensionManagerFacade::getInstance();

        // Iterate classes from the current class towards the parent classes until finding fieldResolver that satisfies processing this field
        $class = get_called_class();
        $fieldValueResolvers = [];
        do {
            // All the Units and their priorities for this class level
            $classFieldResolverPriorities = [];
            $classFieldValueResolvers = [];

            // Important: do array_reverse to enable more specific hooks, which are initialized later on in the project, to be the chosen ones (if their priority is the same)
            foreach (array_reverse($attachableExtensionManager->getExtensionClasses($class, AttachableExtensionGroups::FIELDVALUERESOLVERS)) as $extensionClass => $extensionPriority) {
                // Check if this fieldValueResolver can process this field, and if its priority is bigger than the previous found instance attached to the same class
                if (in_array($fieldName, $extensionClass::getFieldNamesToResolve())) {
                    // Check that the fieldValueResolver can handle the field based on other parameters (eg: "version" in the fieldArgs)
                    $fieldValueResolver = $instanceManager->getInstance($extensionClass);
                    if ($fieldValueResolver->resolveCanProcess($this, $fieldName, $fieldArgs)) {
                        $classFieldResolverPriorities[] = $extensionPriority;
                        $classFieldValueResolvers[] = $fieldValueResolver;
                    }
                }
            }
            // Sort the found units by their priority, and then add to the stack of all units, for all classes
            // Higher priority means they execute first!
            array_multisort($classFieldResolverPriorities, SORT_DESC, SORT_NUMERIC, $classFieldValueResolvers);
            $fieldValueResolvers = array_merge(
                $fieldValueResolvers,
                $classFieldValueResolvers
            );
            // Continue iterating for the class parents
        } while ($class = get_parent_class($class));

        // Return all the units that resolve the fieldName
        return $fieldValueResolvers;
    }

    protected function calculateFieldDirectiveNameClasses(): array
    {
        $attachableExtensionManager = AttachableExtensionManagerFacade::getInstance();

        $directiveNameClasses = [];

        // Iterate classes from the current class towards the parent classes until finding fieldResolver that satisfies processing this field
        $class = get_called_class();
        do {
            // Important: do array_reverse to enable more specific hooks, which are initialized later on in the project, to be the chosen ones (if their priority is the same)
            $extensionClassPriorities = array_reverse($attachableExtensionManager->getExtensionClasses($class, AttachableExtensionGroups::FIELDDIRECTIVERESOLVERS));
            // Order them by priority: higher priority are evaluated first
            $extensionClasses = array_keys($extensionClassPriorities);
            $extensionPriorities = array_values($extensionClassPriorities);
            array_multisort($extensionPriorities, SORT_DESC, SORT_NUMERIC, $extensionClasses);
            // Add them to the results. We keep the list of all resolvers, so that if the first one cannot process the directive (eg: through `resolveCanProcess`, the next one can do it)
            foreach ($extensionClasses as $extensionClass) {
                $directiveName = $extensionClass::getDirectiveName();
                $directiveNameClasses[$directiveName][] = $extensionClass;
            }
            // Continue iterating for the class parents
        } while ($class = get_parent_class($class));

        return $directiveNameClasses;
    }

    protected function calculateFieldNamesToResolve(): array
    {
        $attachableExtensionManager = AttachableExtensionManagerFacade::getInstance();

        // The ID is mandatory, since under this key is the data stored in the database object
        $ret = ['id'];

        // Iterate classes from the current class towards the parent classes until finding fieldResolver that satisfies processing this field
        $class = get_called_class();
        do {
            foreach ($attachableExtensionManager->getExtensionClasses($class, AttachableExtensionGroups::FIELDVALUERESOLVERS) as $extensionClass => $extensionPriority) {
                $ret = array_merge(
                    $ret,
                    $extensionClass::getFieldNamesToResolve()
                );
            }
            // Continue iterating for the class parents
        } while ($class = get_parent_class($class));

        return array_values(array_unique($ret));
    }
}

<?php
namespace PoP\ComponentModel\DirectiveResolvers;

use PoP\ComponentModel\GeneralUtils;
use PoP\Translation\Facades\TranslationAPIFacade;
use PoP\ComponentModel\TypeResolvers\PipelinePositions;
use PoP\ComponentModel\TypeResolvers\TypeResolverInterface;
use PoP\ComponentModel\Facades\Schema\FeedbackMessageStoreFacade;
use PoP\ComponentModel\Facades\Schema\FieldQueryInterpreterFacade;
use PoP\ComponentModel\Feedback\Tokens;

class ResolveValueAndMergeDirectiveResolver extends AbstractGlobalDirectiveResolver
{
    const DIRECTIVE_NAME = 'resolveValueAndMerge';
    public static function getDirectiveName(): string {
        return self::DIRECTIVE_NAME;
    }

    /**
     * This directive must be the first one of the group at the back
     *
     * @return void
     */
    public function getPipelinePosition(): string
    {
        return PipelinePositions::BACK;
    }

    /**
     * Resolving the value can be executed only once (and it is mandatory!)
     *
     * @return boolean
     */
    public function canExecuteMultipleTimesInField(): bool
    {
        return false;
    }

    public function resolveDirective(TypeResolverInterface $typeResolver, array &$idsDataFields, array &$succeedingPipelineIDsDataFields, array &$resultIDItems, array &$convertibleDBKeyIDs, array &$dbItems, array &$previousDBItems, array &$variables, array &$messages, array &$dbErrors, array &$dbWarnings, array &$schemaErrors, array &$schemaWarnings, array &$schemaDeprecations)
    {
        // Iterate data, extract into final results
        if ($resultIDItems) {
            $this->resolveValueForResultItems($typeResolver, $resultIDItems, $idsDataFields, $dbItems, $previousDBItems, $variables, $messages, $dbErrors, $dbWarnings, $schemaErrors, $schemaWarnings, $schemaDeprecations);
        }
    }

    protected function resolveValueForResultItems(TypeResolverInterface $typeResolver, array &$resultIDItems, array &$idsDataFields, array &$dbItems, array &$previousDBItems, array &$variables, array &$messages, array &$dbErrors, array &$dbWarnings, array &$schemaErrors, array &$schemaWarnings, array &$schemaDeprecations)
    {
        $translationAPI = TranslationAPIFacade::getInstance();
        $fieldQueryInterpreter = FieldQueryInterpreterFacade::getInstance();
        $enqueueFillingResultItemsFromIDs = [];
        foreach (array_keys($idsDataFields) as $id) {
            // Obtain its ID and the required data-fields for that ID
            $resultItem = $resultIDItems[$id];
            // It could be that the object is NULL. For instance: a post has a location stored a meta value, and the corresponding location object was deleted, so the ID is pointing to a non-existing object
            // In that case, simply return a dbError, and set the result as an empty array
            if (is_null($resultItem)) {
                $dbErrors[(string)$id][] = [
                    Tokens::PATH => ['id'],
                    Tokens::MESSAGE => sprintf(
                        $translationAPI->__('Corrupted data: Object with ID \'%s\' doesn\'t exist', 'component-model'),
                        $id
                    ),
                ];
                // This is currently pointing to NULL and returning this entry in the database. Remove it
                // (this will also avoid errors in the Engine, which expects this result to be an array and can't be null)
                unset($dbItems[(string)$id]);
                continue;
            }

            $expressions = $this->getExpressionsForResultItem($id, $variables, $messages);
            $this->resolveValuesForResultItem($typeResolver, $id, $resultItem, $idsDataFields[(string)$id]['direct'], $dbItems, $previousDBItems, $variables, $expressions, $dbErrors, $dbWarnings);

            // Add the conditional data fields
            // If the conditionalDataFields are empty, we already reached the end of the tree. Nothing else to do
            foreach (array_filter($idsDataFields[$id]['conditional']) as $conditionDataField => $conditionalDataFields) {
                // Check if the condition field has value `true`
                // All 'conditional' fields must have their own key as 'direct', then simply look for this element on $dbItems
                $conditionFieldOutputKey = $fieldQueryInterpreter->getFieldOutputKey($conditionDataField);
                if (isset($dbItems[$id]) && array_key_exists($conditionFieldOutputKey, $dbItems[$id])) {
                    $conditionSatisfied = (bool)$dbItems[$id][$conditionFieldOutputKey];
                } else {
                    $conditionSatisfied = false;
                }
                if ($conditionSatisfied) {
                    $enqueueFillingResultItemsFromIDs[(string)$id]['direct'] = array_unique(array_merge(
                        $enqueueFillingResultItemsFromIDs[(string)$id]['direct'] ?? [],
                        array_keys($conditionalDataFields)
                    ));
                    foreach ($conditionalDataFields as $nextConditionDataField => $nextConditionalDataFields) {
                        $enqueueFillingResultItemsFromIDs[(string)$id]['conditional'][$nextConditionDataField] = array_merge_recursive(
                            $enqueueFillingResultItemsFromIDs[(string)$id]['conditional'][$nextConditionDataField] ?? [],
                            $nextConditionalDataFields
                        );
                    }
                }
            }
        }
        // Enqueue items for the next iteration
        if ($enqueueFillingResultItemsFromIDs) {
            $typeResolver->enqueueFillingResultItemsFromIDs($enqueueFillingResultItemsFromIDs);
        }
    }

    protected function resolveValuesForResultItem(TypeResolverInterface $typeResolver, $id, $resultItem, array $dataFields, array &$dbItems, array &$previousDBItems, array &$variables, array &$expressions, array &$dbErrors, array &$dbWarnings)
    {
        foreach ($dataFields as $field) {
            $this->resolveValueForResultItem($typeResolver, $id, $resultItem, $field, $dbItems, $previousDBItems, $variables, $expressions, $dbErrors, $dbWarnings);
        }
    }

    protected function resolveValueForResultItem(TypeResolverInterface $typeResolver, $id, $resultItem, string $field, array &$dbItems, array &$previousDBItems, array &$variables, array &$expressions, array &$dbErrors, array &$dbWarnings)
    {
        // Get the value, and add it to the database
        $value = $this->resolveFieldValue($typeResolver, $id, $resultItem, $field, $previousDBItems, $variables, $expressions, $dbWarnings);
        $this->addValueForResultItem($typeResolver, $id, $field, $value, $dbItems, $dbErrors);
    }

    protected function resolveFieldValue(TypeResolverInterface $typeResolver, $id, $resultItem, string $field, array &$previousDBItems, array &$variables, array &$expressions, array &$dbWarnings)
    {
        $value = $typeResolver->resolveValue($resultItem, $field, $variables, $expressions);
        // Merge the dbWarnings, if any
        $feedbackMessageStore = FeedbackMessageStoreFacade::getInstance();
        if ($resultItemDBWarnings = $feedbackMessageStore->retrieveAndClearResultItemDBWarnings($id)) {
            $dbWarnings[$id] = array_merge(
                $dbWarnings[$id] ?? [],
                $resultItemDBWarnings
            );
        }

        return $value;
    }

    protected function addValueForResultItem(TypeResolverInterface $typeResolver, $id, string $field, $value, array &$dbItems, array &$dbErrors)
    {
        // The dataitem can contain both rightful values and also errors (eg: when the field doesn't exist, or the field validation fails)
        // Extract the errors and add them on the other array
        if (GeneralUtils::isError($value)) {
            // Extract the error message
            $error = $value;
            foreach ($error->getErrorMessages() as $errorMessage) {
                $dbErrors[(string)$id][] = [
                    Tokens::PATH => [$field],
                    Tokens::MESSAGE => $errorMessage,
                ];
            }
        } else {
            // If there is an alias, store the results under this. Otherwise, on the fieldName+fieldArgs
            $fieldOutputKey = FieldQueryInterpreterFacade::getInstance()->getFieldOutputKey($field);
            $dbItems[(string)$id][$fieldOutputKey] = $value;
        }
    }

    public function getSchemaDirectiveDescription(TypeResolverInterface $typeResolver): ?string
    {
        $translationAPI = TranslationAPIFacade::getInstance();
        return $translationAPI->__('Resolve the value of the field and merge it into results. This directive is already included by the engine, since its execution is mandatory', 'component-model');
    }
}

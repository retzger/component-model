<?php
namespace PoP\ComponentModel\Schema;

use PoP\ComponentModel\Feedback\Tokens;

class FeedbackMessageStore extends \PoP\FieldQuery\FeedbackMessageStore implements FeedbackMessageStoreInterface
{
    protected $schemaWarnings = [];
    protected $schemaErrors = [];
    protected $dbWarnings = [];
    protected $logEntries = [];

    public function addDBWarnings(array $dbWarnings)
    {
        foreach ($dbWarnings as $resultItemID => $resultItemWarnings) {
            $this->dbWarnings[$resultItemID] = array_merge(
                $this->dbWarnings[$resultItemID] ?? [],
                $resultItemWarnings
            );
        }
    }
    public function addSchemaWarnings(array $schemaWarnings)
    {
        $this->schemaWarnings = array_merge(
            $this->schemaWarnings,
            $schemaWarnings
        );
    }
    public function retrieveAndClearResultItemDBWarnings($resultItemID): ?array
    {
        $resultItemDBWarnings = $this->dbWarnings[$resultItemID];
        unset($this->dbWarnings[$resultItemID]);
        return $resultItemDBWarnings;
    }

    public function addSchemaError(string $dbKey, string $field, string $error)
    {
        $this->schemaErrors[$dbKey][] = [
            Tokens::PATH => [$field],
            Tokens::MESSAGE => $error,
        ];
    }
    public function retrieveAndClearSchemaErrors(): array
    {
        $schemaErrors = $this->schemaErrors ?? [];
        $this->schemaErrors = null;
        return $schemaErrors;
    }
    public function retrieveAndClearSchemaWarnings(): array
    {
        $schemaWarnings = $this->schemaWarnings ?? [];
        $this->schemaWarnings = null;
        return $schemaWarnings;
    }
    public function getSchemaErrorsForField(string $dbKey, string $field): ?array
    {
        return $this->schemaErrors[$dbKey][$field];
    }

    public function maybeAddLogEntry(string $entry): void {
        if (!in_array($entry, $this->logEntries)) {
            $this->logEntries[] = $entry;
        }
    }

    public function getLogEntries(): array {
        return $this->logEntries;
    }
}

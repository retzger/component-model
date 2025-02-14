<?php
namespace PoP\ComponentModel\ModuleProcessors;

interface DataloadQueryArgsSchemaFilterInputModuleProcessorInterface
{
    public function getSchemaFilterInputType(array $module): ?string;
    public function getSchemaFilterInputDescription(array $module): ?string;
    public function getSchemaFilterInputDeprecationDescription(array $module): ?string;
    public function getSchemaFilterInputMandatory(array $module): bool;
}

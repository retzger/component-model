<?php
namespace PoP\ComponentModel\ModuleFilters;

use PoP\ComponentModel\ModuleFilters\ModuleFilterInterface;
use PoP\ComponentModel\ModulePath\ModulePathHelpersInterface;
use PoP\ComponentModel\ModulePath\ModulePathManagerInterface;

class ModuleFilterManager implements ModuleFilterManagerInterface
{
    public const URLPARAM_MODULEFILTER = 'modulefilter';

    protected $selected_filter_name;
    protected $selected_filter;
    protected $modulefilters = [];
    protected $initialized = false;

    // From the moment in which a module is not excluded, every module from then on must also be included
    protected $not_excluded_ancestor_module;
    protected $not_excluded_module_sets;
    protected $not_excluded_module_sets_as_string;

    // When targeting modules in pop-engine.php (eg: when doing ->get_dbobjectids()) those modules are already and always included, so no need to check for their ancestors or anything
    protected $neverExclude = false;

    // Services
    protected $modulePathManager;
    protected $modulePathHelpers;

    public function __construct(
        ModulePathManagerInterface $modulePathManager,
        ModulePathHelpersInterface $modulePathHelpers
    ) {
        $this->modulePathManager = $modulePathManager;
        $this->modulePathHelpers = $modulePathHelpers;
    }

    protected function init()
    {
        // Lazy initialize so that we can inject all the moduleFilters before checking the selected one
        $this->selected_filter_name = $this->selected_filter_name ?? $this->getSelectedModuleFilterName();
        if ($this->selected_filter_name) {
            $this->selected_filter = $this->modulefilters[$this->selected_filter_name];

            // Initialize only if we are intending to filter modules. This way, passing modulefilter=somewrongpath will return an empty array, meaning to not render anything
            $this->not_excluded_module_sets = $this->not_excluded_module_sets_as_string = array();
        }
        $this->initialized = true;
    }

    public function add(ModuleFilterInterface ...$moduleFilters)
    {
        foreach ($moduleFilters as $moduleFilter) {
            $this->modulefilters[$moduleFilter->getName()] = $moduleFilter;
        }
    }

    /**
     * The selected filter can be set from outside by the engine
     */
    public function setSelectedModuleFilterName(string $selectedModuleFilterName)
    {
        $this->selected_filter_name = $selectedModuleFilterName;
    }

    public function getSelectedModuleFilterName(): ?string
    {
        if ($this->selected_filter_name) {
            return $this->selected_filter_name;
        } elseif ($selectedModuleFilterName = $_REQUEST[self::URLPARAM_MODULEFILTER]) {

            // Only valid if there's a corresponding moduleFilter
            if (in_array($selectedModuleFilterName, array_keys($this->modulefilters))) {
                return $selectedModuleFilterName;
            }
        }

        return null;
    }

    public function getNotExcludedModuleSets()
    {
        // It shall be used for requestmeta.rendermodules, to know from which modules the client must start rendering
        return $this->not_excluded_module_sets;
    }

    public function neverExclude($neverExclude)
    {
        $this->neverExclude = $neverExclude;
    }

    public function excludeModule(array $module, array &$props)
    {
        if (!$this->initialized) {
            $this->init();
        }
        if ($this->selected_filter_name) {
            if ($this->neverExclude) {
                return false;
            }
            if (!is_null($this->not_excluded_ancestor_module)) {
                return false;
            }

            return $this->selected_filter->excludeModule($module, $props);
        }

        return false;
    }

    public function removeExcludedSubmodules(array $module, $submodules)
    {
        if (!$this->initialized) {
            $this->init();
        }
        if ($this->selected_filter_name) {
            if ($this->neverExclude) {
                return $submodules;
            }

            return $this->selected_filter->removeExcludedSubmodules($module, $submodules);
        }

        return $submodules;
    }

    /**
     * The `prepare` function advances the modulepath one level down, when interating into the submodules, and then calling `restore` the value goes one level up again
     */
    public function prepareForPropagation(array $module, array &$props)
    {
        if (!$this->initialized) {
            $this->init();
        }
        if ($this->selected_filter_name) {
            if (!$this->neverExclude && is_null($this->not_excluded_ancestor_module) && $this->excludeModule($module, $props) === false) {
                // Set the current module as the one which is not excluded.
                $module_propagation_current_path = $this->modulePathManager->getPropagationCurrentPath();
                $module_propagation_current_path[] = $module;

                $this->not_excluded_ancestor_module = $this->modulePathHelpers->stringifyModulePath($module_propagation_current_path);

                // Add it to the list of not-excluded modules
                if (!in_array($this->not_excluded_ancestor_module, $this->not_excluded_module_sets_as_string)) {
                    $this->not_excluded_module_sets_as_string[] = $this->not_excluded_ancestor_module;
                    $this->not_excluded_module_sets[] = $module_propagation_current_path;
                }
            }

            $this->selected_filter->prepareForPropagation($module, $props);
        }

        // Add the module to the path
        $this->modulePathManager->prepareForPropagation($module, $props);
    }
    public function restoreFromPropagation(array $module, array &$props)
    {
        if (!$this->initialized) {
            $this->init();
        }

        // Remove the module from the path
        $this->modulePathManager->restoreFromPropagation($module, $props);

        if ($this->selected_filter_name) {
            if (!$this->neverExclude && !is_null($this->not_excluded_ancestor_module) && $this->excludeModule($module, $props) === false) {
                $module_propagation_current_path = $this->modulePathManager->getPropagationCurrentPath();
                $module_propagation_current_path[] = $module;

                // If the current module was set as the one not excluded, then reset it
                if ($this->not_excluded_ancestor_module == $this->modulePathHelpers->stringifyModulePath($module_propagation_current_path)) {
                    $this->not_excluded_ancestor_module = null;
                }
            }

            $this->selected_filter->restoreFromPropagation($module, $props);
        }
    }
}

<?php
namespace PoP\ComponentModel\Engine;

use Exception;
use PoP\ComponentModel\Utils;
use PoP\ComponentModel\Engine_Vars;
use PoP\ComponentModel\GeneralUtils;
use PoP\ComponentModel\DataloadUtils;
use PoP\Hooks\Facades\HooksAPIFacade;
use PoP\ComponentModel\Modules\ModuleUtils;
use PoP\ComponentModel\Configuration\Request;
use PoP\ComponentModel\DataQueryManagerFactory;
use PoP\Translation\Facades\TranslationAPIFacade;
use PoP\ComponentModel\Server\Utils as ServerUtils;
use PoP\ComponentModel\CheckpointProcessorManagerFactory;
use PoP\ComponentModel\Facades\Cache\PersistentCacheFacade;
use PoP\ComponentModel\TypeResolvers\TypeResolverInterface;
use PoP\ComponentModel\TypeResolvers\ConvertibleTypeHelpers;
use PoP\ComponentModel\ModuleProcessors\DataloadingConstants;
use PoP\ComponentModel\Facades\Instances\InstanceManagerFacade;
use PoP\ComponentModel\Facades\ModelInstance\ModelInstanceFacade;
use PoP\ComponentModel\Facades\Schema\FeedbackMessageStoreFacade;
use PoP\ComponentModel\Facades\ModulePath\ModulePathHelpersFacade;
use PoP\ComponentModel\Facades\ModulePath\ModulePathManagerFacade;
use PoP\ComponentModel\Facades\Schema\FieldQueryInterpreterFacade;
use PoP\ComponentModel\TypeResolvers\ConvertibleTypeResolverInterface;
use PoP\ComponentModel\Facades\ModuleFilters\ModuleFilterManagerFacade;
use PoP\ComponentModel\Facades\DataStructure\DataStructureManagerFacade;
use PoP\ComponentModel\Settings\SiteConfigurationProcessorManagerFactory;
use PoP\ComponentModel\Facades\ModuleProcessors\ModuleProcessorManagerFacade;

class Engine implements EngineInterface
{
    public const CACHETYPE_IMMUTABLEDATASETSETTINGS = 'static-datasetsettings';
    public const CACHETYPE_STATICDATAPROPERTIES = 'static-data-properties';
    public const CACHETYPE_STATEFULDATAPROPERTIES = 'stateful-data-properties';
    public const CACHETYPE_PROPS = 'props';

    public $data;
    public $helperCalculations;
    public $model_props;
    public $props;
    protected $nocache_fields;
    protected $moduledata;
    protected $typeResolver_ids_data_fields;
    protected $dbdata;
    protected $backgroundload_urls;
    protected $extra_routes;
    protected $cachedsettings;
    protected $outputData;

    public function getOutputData()
    {
        return $this->outputData;
    }

    public function addBackgroundUrl($url, $targets)
    {
        $this->backgroundload_urls[$url] = $targets;
    }

    public function getEntryModule(): array
    {
        $siteconfiguration = SiteConfigurationProcessorManagerFactory::getInstance()->getProcessor();
        if (!$siteconfiguration) {
            throw new Exception('There is no Site Configuration. Hence, we can\'t continue.');
        }

        $fullyQualifiedModule = $siteconfiguration->getEntryModule();
        if (!$fullyQualifiedModule) {
            throw new Exception(sprintf('No entry module for this request (%s)', fullUrl()));
        }

        return $fullyQualifiedModule;
    }

    public function sendEtagHeader()
    {
        // ETag is needed for the Service Workers
        // Also needed to use together with the Control-Cache header, to know when to refetch data from the server: https://developers.google.com/web/fundamentals/performance/optimizing-content-efficiency/http-caching
        if (HooksAPIFacade::getInstance()->applyFilters('\PoP\ComponentModel\Engine:outputData:addEtagHeader', true)) {
            // The same page will have different hashs only because of those random elements added each time,
            // such as the unique_id and the current_time. So remove these to generate the hash
            $differentiators = array(
                POP_CONSTANT_UNIQUE_ID,
                POP_CONSTANT_CURRENTTIMESTAMP,
                POP_CONSTANT_RAND,
                POP_CONSTANT_TIME,
            );
            $commoncode = str_replace($differentiators, '', json_encode($this->data));

            // Also replace all those tags with content that, even if it's different, should not alter the output
            // Eg: comments-count. Because adding a comment does not delete the cache, then the comments-count is allowed
            // to be shown stale. So if adding a new comment, there's no need for the user to receive the
            // "This page has been updated, click here to refresh it." notification
            // Because we already got the JSON, then remove entries of the type:
            // "userpostactivity-count":1, (if there are more elements after)
            // and
            // "userpostactivity-count":1
            // Comment Leo 22/10/2017: ?module=settings doesn't have 'nocache-fields'
            if ($this->nocache_fields) {
                $commoncode = preg_replace('/"('.implode('|', $this->nocache_fields).')":[0-9]+,?/', '', $commoncode);
            }

            // Allow plug-ins to replace their own non-needed content (eg: thumbprints, defined in Core)
            $commoncode = HooksAPIFacade::getInstance()->applyFilters('\PoP\ComponentModel\Engine:etag_header:commoncode', $commoncode);
            header("ETag: ".hash('md5', $commoncode));
        }
    }

    public function getExtraRoutes()
    {
        // The extra URIs must be cached! That is because we will change the requested URI in $vars, upon which the hook to inject extra URIs (eg: for page INITIALFRAMES) will stop working
        if (!is_null($this->extra_routes)) {
            return $this->extra_routes;
        }

        $this->extra_routes = array();

        // The API cannot use getExtraRoutes()!!!!! Because the fields can't be applied to different resources! (Eg: author/leo/ and author/leo/?route=posts)
        $vars = Engine_Vars::getVars();
        if ($vars['scheme'] == POP_SCHEME_API) {
            return $this->extra_routes;
        }

        if (ServerUtils::enableExtraRoutesByParams()) {
            $this->extra_routes = $_REQUEST[GD_URLPARAM_EXTRAROUTES] ?? array();
            $this->extra_routes = is_array($this->extra_routes) ? $this->extra_routes : array($this->extra_routes);
        }

        // Enable to add extra URLs in a fixed manner
        $this->extra_routes = HooksAPIFacade::getInstance()->applyFilters(
            '\PoP\ComponentModel\Engine:getExtraRoutes',
            $this->extra_routes
        );

        return $this->extra_routes;
    }

    public function listExtraRouteVars()
    {
        if ($has_extra_routes = !empty($this->getExtraRoutes())) {
            $model_instance_id = ModelInstanceFacade::getInstance()->getModelInstanceId();
            $current_uri = removeDomain(Utils::getCurrentUrl());
        }

        return array($has_extra_routes, $model_instance_id, $current_uri);
    }

    public function generateData()
    {
        HooksAPIFacade::getInstance()->doAction('\PoP\ComponentModel\Engine:beginning');

        // Process the request and obtain the results
        $this->data = $this->helperCalculations = array();
        $this->processAndGenerateData();

        // See if there are extra URIs to be processed in this same request
        if ($extra_routes = $this->getExtraRoutes()) {
            // Combine the response for each extra URI together with the original response, merging all JSON objects together, but under each's URL/model_instance_id

            // To obtain the nature for each URI, we use a hack: change the current URI and create a new WP object, which will process the query_vars and from there obtain the nature
            // First make a backup of the current URI to set it again later
            $vars = &Engine_Vars::$vars;
            $current_route = $vars['route'];

            // Process each extra URI, and merge its results with all others
            foreach ($extra_routes as $route) {
                // Reset $vars so that it gets created anew
                $vars['route'] = $route;

                // Process the request with the new $vars and merge it with all other results
                $this->processAndGenerateData();
            }

            // Set the previous values back
            $vars['route'] = $current_route;
        }

        // Add session/site meta
        $this->addSharedMeta();

        // If any formatter is passed, then format the data accordingly
        $this->formatData();

        // Keep only the data that is needed to be sent, and encode it as JSON
        $this->calculateOutuputData();

        // Send the ETag-header
        $this->sendEtagHeader();
    }

    protected function formatData()
    {
        $dataStructureManager = DataStructureManagerFacade::getInstance();
        $formatter = $dataStructureManager->getDataStructureFormatter();
        $this->data = $formatter->getFormattedData($this->data);
    }

    public function calculateOutuputData()
    {
        $this->outputData = $this->getEncodedDataObject($this->data);
    }

    // Allow PoPWebPlatform_Engine to override this function
    protected function getEncodedDataObject($data)
    {
        // Comment Leo 14/09/2018: Re-enable here:
        // if (true) {
        //     unset($data['combinedstatedata']);
        // }
        return $data;
    }

    public function getModelPropsModuletree(array $module)
    {
        if ($useCache = ServerUtils::useCache()) {
            $cachemanager = PersistentCacheFacade::getInstance();
            $useCache = !is_null($cachemanager);
        }
        $moduleprocessor_manager = ModuleProcessorManagerFacade::getInstance();

        $processor = $moduleprocessor_manager->getProcessor($module);

        // Important: cannot use it if doing POST, because the request may have to be handled by a different block than the one whose data was cached
        // Eg: doing GET on /add-post/ will show the form BLOCK_ADDPOST_CREATE, but doing POST on /add-post/ will bring the action ACTION_ADDPOST_CREATE
        // First check if there's a cache stored
        if ($useCache) {
            $model_props = $cachemanager->getCacheByModelInstance(self::CACHETYPE_PROPS);
        }

        // If there is no cached one, or not using the cache, generate the props and cache it
        if (!$model_props) {
            $model_props = array();
            $processor->initModelPropsModuletree($module, $model_props, array(), array());

            if ($useCache) {
                $cachemanager->storeCacheByModelInstance(self::CACHETYPE_PROPS, $model_props);
            }
        }

        return $model_props;
    }

    // Notice that $props is passed by copy, this way the input $model_props and the returned $immutable_plus_request_props are different objects
    public function addRequestPropsModuletree(array $module, array $props)
    {
        $moduleprocessor_manager = ModuleProcessorManagerFacade::getInstance();
        $processor = $moduleprocessor_manager->getProcessor($module);

        // The input $props is the model_props. We add, on object, the mutableonrequest props, resulting in a "static + mutableonrequest" props object
        $processor->initRequestPropsModuletree($module, $props, array(), array());

        return $props;
    }

    protected function processAndGenerateData()
    {
        $vars = Engine_Vars::getVars();

        // Externalize logic into function so it can be overridden by PoP Web Platform Engine
        $dataoutputitems = $vars['dataoutputitems'];

        // From the state we know if to process static/staful content or both
        $datasources = $vars['datasources'];

        // Get the entry module based on the application configuration and the nature
        $module = $this->getEntryModule();

        // Save it to be used by the children class
        // Static props are needed for both static/mutableonrequest operations, so build it always
        $this->model_props = $this->getModelPropsModuletree($module);

        // If only getting static content, then no need to add the mutableonrequest props
        if ($datasources == GD_URLPARAM_DATASOURCES_ONLYMODEL) {
            $this->props = $this->model_props;
        } else {
            $this->props = $this->addRequestPropsModuletree($module, $this->model_props);
        }

        // Allow for extra operations (eg: calculate resources)
        HooksAPIFacade::getInstance()->doAction(
            '\PoP\ComponentModel\Engine:helperCalculations',
            array(&$this->helperCalculations),
            $module,
            array(&$this->props)
        );

        $data = [];
        if (in_array(GD_URLPARAM_DATAOUTPUTITEMS_DATASETMODULESETTINGS, $dataoutputitems)) {
            $data = array_merge(
                $data,
                $this->getModuleDatasetSettings($module, $this->model_props, $this->props)
            );
        }

        // Comment Leo 20/01/2018: we must first initialize all the settings, and only later add the data.
        // That is because calculating the data may need the values from the settings. Eg: for the resourceLoader,
        // calculating $loadingframe_resources needs to know all the Handlebars templates from the sitemapping as to generate file "resources.js",
        // which is done through an action, called through getData()
        // Data = dbobjectids (data-ids) + feedback + database
        if (in_array(GD_URLPARAM_DATAOUTPUTITEMS_MODULEDATA, $dataoutputitems)
            || in_array(GD_URLPARAM_DATAOUTPUTITEMS_DATABASES, $dataoutputitems)
        ) {
            $data = array_merge(
                $data,
                $this->getModuleData($module, $this->model_props, $this->props)
            );

            if (in_array(GD_URLPARAM_DATAOUTPUTITEMS_DATABASES, $dataoutputitems)) {
                $data = array_merge(
                    $data,
                    $this->getDatabases()
                );
            }
        }

        list($has_extra_routes, $model_instance_id, $current_uri) = $this->listExtraRouteVars();

        if (in_array(GD_URLPARAM_DATAOUTPUTITEMS_META, $dataoutputitems)
        ) {
            // Also add the request, session and site meta.
            // IMPORTANT: Call these methods after doing ->getModuleData, since the background_urls and other info is calculated there and printed here
            if ($requestmeta = $this->getRequestMeta()) {
                $data['requestmeta'] = $has_extra_routes ? array($current_uri => $requestmeta) : $requestmeta;
            }
        }

        // Comment Leo 14/09/2018: Re-enable here:
        // // Combine the statelessdata and mutableonrequestdata objects
        // if ($data['modulesettings']) {

        //     $data['modulesettings']['combinedstate'] = array_merge_recursive(
        //         $data['modulesettings']['immutable'] ?? array()
        //         $data['modulesettings']['mutableonmodel'] ?? array()
        //         $data['modulesettings']['mutableonrequest'] ?? array(),
        //     );
        // }
        // if ($data['moduledata']) {

        //     $data['moduledata']['combinedstate'] = array_merge_recursive(
        //         $data['moduledata']['immutable'] ?? array()
        //         $data['moduledata']['mutableonmodel'] ?? array()
        //         $data['moduledata']['mutableonrequest'] ?? array(),
        //     );
        // }
        // if ($data['datasetmoduledata']) {

        //     $data['datasetmoduledata']['combinedstate'] = array_merge_recursive(
        //         $data['datasetmoduledata']['immutable'] ?? array()
        //         $data['datasetmoduledata']['mutableonmodel'] ?? array()
        //         $data['datasetmoduledata']['mutableonrequest'] ?? array(),
        //     );
        // }

        // Do array_replace_recursive because it may already contain data from doing 'extra-uris'
        $this->data = array_replace_recursive(
            $this->data,
            $data
        );
    }

    protected function addSharedMeta()
    {
        $vars = Engine_Vars::getVars();

        // Externalize logic into function so it can be overridden by PoP Web Platform Engine
        $dataoutputitems = $vars['dataoutputitems'];

        if (in_array(GD_URLPARAM_DATAOUTPUTITEMS_META, $dataoutputitems)
        ) {
            // Also add the request, session and site meta.
            // IMPORTANT: Call these methods after doing ->getModuleData, since the background_urls and other info is calculated there and printed here
            // If it has extra-uris, pass along this information, so that the client can fetch the setting from under $model_instance_id ("mutableonmodel") and $uri ("mutableonrequest")
            if ($this->getExtraRoutes()) {
                $this->data['requestmeta'][POP_JS_MULTIPLEROUTES] = true;
            }
            if ($sitemeta = $this->getSiteMeta()) {
                $this->data['sitemeta'] = $sitemeta;
            }

            if (in_array(GD_URLPARAM_DATAOUTPUTITEMS_SESSION, $dataoutputitems)) {
                if ($sessionmeta = $this->getSessionMeta()) {
                    $this->data['sessionmeta'] = $sessionmeta;
                }
            }
        }
    }

    public function getModuleDatasetSettings(array $module, $model_props, array &$props)
    {
        if ($useCache = ServerUtils::useCache()) {
            $cachemanager = PersistentCacheFacade::getInstance();
            $useCache = !is_null($cachemanager);
        }
        $moduleprocessor_manager = ModuleProcessorManagerFacade::getInstance();

        $ret = array();

        $processor = $moduleprocessor_manager->getProcessor($module);

        // From the state we know if to process static/staful content or both
        $vars = Engine_Vars::getVars();
        $dataoutputmode = $vars['dataoutputmode'];

        // Templates: What modules must be executed after call to loadMore is back with data:
        // CB: list of modules to merge
        $this->cachedsettings = false;

        // First check if there's a cache stored
        if ($useCache) {
            $immutable_datasetsettings = $cachemanager->getCacheByModelInstance(self::CACHETYPE_IMMUTABLEDATASETSETTINGS);
        }

        // If there is no cached one, generate the configuration and cache it
        $this->cachedsettings = false;
        if ($immutable_datasetsettings) {
            $this->cachedsettings = true;
        } else {
            $immutable_datasetsettings = $processor->getImmutableSettingsDatasetmoduletree($module, $model_props);

            if ($useCache) {
                $cachemanager->storeCacheByModelInstance(self::CACHETYPE_IMMUTABLEDATASETSETTINGS, $immutable_datasetsettings);
            }
        }

        // If there are multiple URIs, then the results must be returned under the corresponding $model_instance_id for "mutableonmodel", and $url for "mutableonrequest"
        list($has_extra_routes, $model_instance_id, $current_uri) = $this->listExtraRouteVars();

        if ($dataoutputmode == GD_URLPARAM_DATAOUTPUTMODE_SPLITBYSOURCES) {
            if ($immutable_datasetsettings) {
                $ret['datasetmodulesettings']['immutable'] = $immutable_datasetsettings;
            }
        } elseif ($dataoutputmode == GD_URLPARAM_DATAOUTPUTMODE_COMBINED) {
            // If everything is combined, then it belongs under "mutableonrequest"
            if ($combined_datasetsettings = $immutable_datasetsettings) {
                $ret['datasetmodulesettings'] = $has_extra_routes ? array($current_uri => $combined_datasetsettings) : $combined_datasetsettings;
            }
        }

        return $ret;
    }

    public function getRequestMeta()
    {
        $meta = array(
            POP_CONSTANT_ENTRYMODULE => $this->getEntryModule()[1],
            POP_UNIQUEID => POP_CONSTANT_UNIQUE_ID,
            GD_URLPARAM_URL => Utils::getCurrentUrl(),
            'modelinstanceid' => ModelInstanceFacade::getInstance()->getModelInstanceId(),
        );

        if ($this->backgroundload_urls) {
            $meta[GD_URLPARAM_BACKGROUNDLOADURLS] = $this->backgroundload_urls;
        };

        // Starting from what modules must do the rendering. Allow for empty arrays (eg: modulepaths[]=somewhatevervalue)
        $modulefilter_manager = ModuleFilterManagerFacade::getInstance();
        $not_excluded_module_sets = $modulefilter_manager->getNotExcludedModuleSets();
        if (!is_null($not_excluded_module_sets)) {
            // Print the settings id of each module. Then, a module can feed data to another one by sharing the same settings id (eg: self::MODULE_BLOCK_USERAVATAR_EXECUTEUPDATE and PoP_UserAvatarProcessors_Module_Processor_UserBlocks::MODULE_BLOCK_USERAVATAR_UPDATE)
            $filteredsettings = array();
            foreach ($not_excluded_module_sets as $modules) {
                $filteredsettings[] = array_map(
                    [ModuleUtils::class, 'getModuleOutputName'],
                    $modules
                );
            }

            $meta['filteredmodules'] = $filteredsettings;
        }

        // Any errors? Send them back
        if (Utils::$errors) {
            if (count(Utils::$errors) > 1) {
                $meta[GD_URLPARAM_ERROR] = TranslationAPIFacade::getInstance()->__('Ops, there were some errors:', 'pop-engine').implode('<br/>', Utils::$errors);
            } else {
                $meta[GD_URLPARAM_ERROR] = TranslationAPIFacade::getInstance()->__('Ops, there was an error: ', 'pop-engine').Utils::$errors[0];
            }
        }

        return HooksAPIFacade::getInstance()->applyFilters(
            '\PoP\ComponentModel\Engine:request-meta',
            $meta
        );
    }

    public function getSessionMeta()
    {
        return HooksAPIFacade::getInstance()->applyFilters(
            '\PoP\ComponentModel\Engine:session-meta',
            array()
        );
    }

    public function getSiteMeta()
    {
        $meta = array();
        if (Utils::fetchingSite()) {
            $vars = Engine_Vars::getVars();
            $meta[GD_URLPARAM_VERSION] = $vars['version'];
            $meta[GD_URLPARAM_DATAOUTPUTMODE] = $vars['dataoutputmode'];
            $meta[GD_URLPARAM_DATABASESOUTPUTMODE] = $vars['dboutputmode'];

            if ($vars['format']) {
                $meta[GD_URLPARAM_SETTINGSFORMAT] = $vars['format'];
            }
            if ($vars['mangled']) {
                $meta[Request::URLPARAM_MANGLED] = $vars['mangled'];
            }
            if (ServerUtils::enableConfigByParams() && $vars['config']) {
                $meta[POP_URLPARAM_CONFIG] = $vars['config'];
            }
            if ($vars['stratum']) {
                $meta[GD_URLPARAM_STRATUM] = $vars['stratum'];
            }

            // Tell the front-end: are the results from the cache? Needed for the editor, to initialize it since WP will not execute the code
            if (!is_null($this->cachedsettings)) {
                $meta['cachedsettings'] = $this->cachedsettings;
            };
        }
        return HooksAPIFacade::getInstance()->applyFilters(
            '\PoP\ComponentModel\Engine:site-meta',
            $meta
        );
    }

    private function combineIdsDatafields(&$typeResolver_ids_data_fields, $typeResolver_class, $ids, $data_fields, $conditional_data_fields = [])
    {
        $typeResolver_ids_data_fields[$typeResolver_class] = $typeResolver_ids_data_fields[$typeResolver_class] ?? array();
        foreach ($ids as $id) {
            // Make sure to always add the 'id' data-field, since that's the key for the dbobject in the client database
            $typeResolver_ids_data_fields[$typeResolver_class][(string)$id]['direct'] = $typeResolver_ids_data_fields[$typeResolver_class][(string)$id]['direct'] ?? array('id');
            $typeResolver_ids_data_fields[$typeResolver_class][(string)$id]['direct'] = array_values(array_unique(array_merge(
                $typeResolver_ids_data_fields[$typeResolver_class][(string)$id]['direct'],
                $data_fields ?? array()
            )));
            // The conditional data fields have the condition data fields, as key, and the list of conditional data fields to load if the condition one is successful, as value
            $typeResolver_ids_data_fields[$typeResolver_class][(string)$id]['conditional'] = $typeResolver_ids_data_fields[$typeResolver_class][(string)$id]['conditional'] ?? array();
            foreach ($conditional_data_fields as $conditionDataField => $conditionalDataFields) {
                $typeResolver_ids_data_fields[$typeResolver_class][(string)$id]['conditional'][$conditionDataField] = array_merge(
                    $typeResolver_ids_data_fields[$typeResolver_class][(string)$id]['conditional'][$conditionDataField] ?? [],
                    $conditionalDataFields
                );
            }
        }
    }

    private function doAddDatasetToDatabase(&$database, $database_key, $dataitems)
    {
        // Save in the database under the corresponding database-key (this way, different dataloaders, like 'list-users' and 'author',
        // can both save their results under database key 'users'
        if (!$database[$database_key]) {
            $database[$database_key] = $dataitems;
        } else {
            $dbKey = $database_key;
            // array_merge_recursive doesn't work as expected (it merges 2 hashmap arrays into an array, so then I manually do a foreach instead)
            foreach ($dataitems as $id => $dbobject_values) {
                if (!$database[$dbKey][(string)$id]) {
                    $database[$dbKey][(string)$id] = array();
                }

                $database[$dbKey][(string)$id] = array_merge(
                    $database[$dbKey][(string)$id],
                    $dbobject_values
                );
            }
        }
    }

    private function addDatasetToDatabase(&$database, TypeResolverInterface $typeResolver, string $dbKey, $dataitems)
    {
        // Do not create the database key entry when there are no items, or it produces an error when deep merging the database object in the webplatform with that from the response
        if (!$dataitems) {
            return;
        }

        $isConvertibleTypeResolver = $typeResolver instanceof ConvertibleTypeResolverInterface;
        if ($isConvertibleTypeResolver) {
            $instanceManager = InstanceManagerFacade::getInstance();
            // Get the actual type for each entity, and add the entry there
            $convertedTypeResolverClassDataItems = $convertedTypeResolverClassDBKeys = [];
            foreach ($dataitems as $resultItemID => $resultItem) {
                // The ID will contain the type. Remove it
                list(
                    $dbKey,
                    $resultItemID
                ) = ConvertibleTypeHelpers::extractDBObjectTypeAndID($resultItemID);
                $convertedTypeResolverClass = $typeResolver->getTypeResolverClassForResultItem($resultItemID);
                $convertedTypeResolverClassDataItems[$convertedTypeResolverClass][$resultItemID] = $resultItem;
                $convertedTypeResolverClassDBKeys[$convertedTypeResolverClass] = $dbKey;
            }
            foreach ($convertedTypeResolverClassDataItems as $convertedTypeResolverClass => $convertedDataItems) {
                $convertedTypeResolver = $instanceManager->getInstance($convertedTypeResolverClass);
                $convertedDBKey = $convertedTypeResolverClassDBKeys[$convertedTypeResolverClass];
                $this->addDatasetToDatabase($database, $convertedTypeResolver, $convertedDBKey, $convertedDataItems);
            }
        } else {
            $this->doAddDatasetToDatabase($database, $dbKey, $dataitems);
        }
    }

    private function getResultItemIDConvertedTypeResolvers(TypeResolverInterface $typeResolver, array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $resultItemIDConvertedTypeResolvers = [];
        $isConvertibleTypeResolver = $typeResolver instanceof ConvertibleTypeResolverInterface;
        if ($isConvertibleTypeResolver) {
            $instanceManager = InstanceManagerFacade::getInstance();
            $convertedTypeResolverClassDataItems = [];
            foreach ($ids as $resultItemID) {
                $convertedTypeResolverClass = $typeResolver->getTypeResolverClassForResultItem($resultItemID);
                $convertedTypeResolverClassDataItems[$convertedTypeResolverClass][] = $resultItemID;
            }
            foreach ($convertedTypeResolverClassDataItems as $convertedTypeResolverClass => $resultItemIDs) {
                $convertedTypeResolver = $instanceManager->getInstance($convertedTypeResolverClass);
                $convertedResultItemIDConvertedTypeResolvers = $this->getResultItemIDConvertedTypeResolvers(
                    $convertedTypeResolver,
                    $resultItemIDs
                );
                foreach ($convertedResultItemIDConvertedTypeResolvers as $convertedResultItemID => $convertedTypeResolver) {
                    $resultItemIDConvertedTypeResolvers[(string)$convertedResultItemID] = $convertedTypeResolver;
                }
            }
        } else {
            foreach ($ids as $resultItemID) {
                $resultItemIDConvertedTypeResolvers[(string)$resultItemID] = $typeResolver;
            }
        }
        return $resultItemIDConvertedTypeResolvers;
    }

    protected function getInterreferencedModuleFullpaths(array $module, array &$props)
    {
        $paths = array();
        $this->addInterreferencedModuleFullpaths($paths, array(), $module, $props);
        return $paths;
    }

    private function addInterreferencedModuleFullpaths(&$paths, $module_path, array $module, array &$props)
    {
        $moduleprocessor_manager = ModuleProcessorManagerFacade::getInstance();
        $processor = $moduleprocessor_manager->getProcessor($module);
        $moduleFullName = ModuleUtils::getModuleFullName($module);

        $modulefilter_manager = ModuleFilterManagerFacade::getInstance();

        // If modulepaths is provided, and we haven't reached the destination module yet, then do not execute the function at this level
        if (!$modulefilter_manager->excludeModule($module, $props)) {
            // If the current module loads data, then add its path to the list
            if ($interreferenced_modulepath = $processor->getDataFeedbackInterreferencedModulepath($module, $props)) {
                $referenced_modulepath = ModulePathHelpersFacade::getInstance()->stringifyModulePath($interreferenced_modulepath);
                $paths[$referenced_modulepath] = $paths[$referenced_modulepath] ?? array();
                $paths[$referenced_modulepath][] = array_merge(
                    $module_path,
                    array(
                        $module
                    )
                );
            }
        }

        $submodule_path = array_merge(
            $module_path,
            array(
                $module,
            )
        );

        // Propagate to its inner modules
        $submodules = $processor->getAllSubmodules($module);
        $submodules = $modulefilter_manager->removeExcludedSubmodules($module, $submodules);

        // This function must be called always, to register matching modules into requestmeta.filtermodules even when the module has no submodules
        $modulefilter_manager->prepareForPropagation($module, $props);
        foreach ($submodules as $submodule) {
            $this->addInterreferencedModuleFullpaths($paths, $submodule_path, $submodule, $props[$moduleFullName][POP_PROPS_SUBMODULES]);
        }
        $modulefilter_manager->restoreFromPropagation($module, $props);
    }

    protected function getDataloadingModuleFullpaths(array $module, array &$props)
    {
        $paths = array();
        $this->addDataloadingModuleFullpaths($paths, array(), $module, $props);
        return $paths;
    }

    private function addDataloadingModuleFullpaths(&$paths, $module_path, array $module, array &$props)
    {
        $moduleprocessor_manager = ModuleProcessorManagerFacade::getInstance();
        $processor = $moduleprocessor_manager->getProcessor($module);
        $moduleFullName = ModuleUtils::getModuleFullName($module);

        $modulefilter_manager = ModuleFilterManagerFacade::getInstance();

        // If modulepaths is provided, and we haven't reached the destination module yet, then do not execute the function at this level
        if (!$modulefilter_manager->excludeModule($module, $props)) {
            // If the current module loads data, then add its path to the list
            if ($processor->moduleLoadsData($module)) {
                $paths[] = array_merge(
                    $module_path,
                    array(
                        $module
                    )
                );
            }
        }

        $submodule_path = array_merge(
            $module_path,
            array(
                $module,
            )
        );

        // Propagate to its inner modules
        $submodules = $processor->getAllSubmodules($module);
        $submodules = $modulefilter_manager->removeExcludedSubmodules($module, $submodules);

        // This function must be called always, to register matching modules into requestmeta.filtermodules even when the module has no submodules
        $modulefilter_manager->prepareForPropagation($module, $props);
        foreach ($submodules as $submodule) {
            $this->addDataloadingModuleFullpaths($paths, $submodule_path, $submodule, $props[$moduleFullName][POP_PROPS_SUBMODULES]);
        }
        $modulefilter_manager->restoreFromPropagation($module, $props);
    }

    protected function assignValueForModule(&$array, $module_path, array $module, $key, $value)
    {
        $array_pointer = &$array;
        foreach ($module_path as $submodule) {
            // Notice that when generating the array for the response, we don't use $module anymore, but $moduleOutputName
            $submoduleOutputName = ModuleUtils::getModuleOutputName($submodule);

            // If the path doesn't exist, create it
            if (!isset($array_pointer[$submoduleOutputName][GD_JS_SUBMODULES])) {
                $array_pointer[$submoduleOutputName][GD_JS_SUBMODULES] = array();
            }

            // The pointer is the location in the array where the value will be set
            $array_pointer = &$array_pointer[$submoduleOutputName][GD_JS_SUBMODULES];
        }

        $moduleOutputName = ModuleUtils::getModuleOutputName($module);
        $array_pointer[$moduleOutputName][$key] = $value;
    }

    public function validateCheckpoints($checkpoints)
    {
        $checkpointprocessor_manager = CheckpointProcessorManagerFactory::getInstance();

        // Iterate through the list of all checkpoints, process all of them, if any produces an error, already return it
        foreach ($checkpoints as $checkpoint) {
            $result = $checkpointprocessor_manager->getProcessor($checkpoint)->process($checkpoint);
            if (GeneralUtils::isError($result)) {
                return $result;
            }
        }

        return true;
    }

    protected function getModulePathKey($module_path, array $module)
    {
        $moduleFullName = ModuleUtils::getModuleFullName($module);
        return $moduleFullName.'-'.implode('.', $module_path);
    }

    public function maybeGetDBObjectIDOrIDsForConvertibleTypeResolver(string $typeResolverClass, $dbObjectIDOrIDs)
    {
        $instanceManager = InstanceManagerFacade::getInstance();
        $typeResolver = $instanceManager->getInstance($typeResolverClass);
        $isConvertibleTypeResolver = $typeResolver instanceof ConvertibleTypeResolverInterface;
        if ($isConvertibleTypeResolver) {
            $resultItemIDConvertedTypeResolvers = $this->getResultItemIDConvertedTypeResolvers($typeResolver, is_array($dbObjectIDOrIDs) ? $dbObjectIDOrIDs : [$dbObjectIDOrIDs]);
            $typeDBObjectIDOrIDs = [];
            foreach ($resultItemIDConvertedTypeResolvers as $resultItemID => $convertedTypeResolver) {
                $typeDBObjectIDOrIDs[] = ConvertibleTypeHelpers::getDBObjectComposedTypeAndID(
                    $convertedTypeResolver,
                    $resultItemID
                );
            }
            if (!is_array($dbObjectIDOrIDs)) {
                $typeDBObjectIDOrIDs = $typeDBObjectIDOrIDs[0];
            }
            return $typeDBObjectIDOrIDs;
        }
        return null;
    }

    // This function is not private, so it can be accessed by the automated emails to regenerate the html for each user
    public function getModuleData($root_module, $root_model_props, $root_props)
    {
        $instanceManager = InstanceManagerFacade::getInstance();
        if ($useCache = ServerUtils::useCache()) {
            $cachemanager = PersistentCacheFacade::getInstance();
            $useCache = !is_null($cachemanager);
        }
        $moduleprocessor_manager = ModuleProcessorManagerFacade::getInstance();

        $root_processor = $moduleprocessor_manager->getProcessor($root_module);

        // From the state we know if to process static/staful content or both
        $vars = Engine_Vars::getVars();
        $datasources = $vars['datasources'];
        $dataoutputmode = $vars['dataoutputmode'];
        $dataoutputitems = $vars['dataoutputitems'];
        $add_meta = in_array(GD_URLPARAM_DATAOUTPUTITEMS_META, $dataoutputitems);

        $immutable_moduledata = $mutableonmodel_moduledata = $mutableonrequest_moduledata = array();
        $immutable_datasetmoduledata = $mutableonmodel_datasetmoduledata = $mutableonrequest_datasetmoduledata = array();
        if ($add_meta) {
            $immutable_datasetmodulemeta = $mutableonmodel_datasetmodulemeta = $mutableonrequest_datasetmodulemeta = array();
        }
        $this->dbdata = array();

        // Save all the BACKGROUND_LOAD urls to send back to the browser, to load immediately again (needed to fetch non-cacheable data-fields)
        $this->backgroundload_urls = array();

        // Load under global key (shared by all pagesections / blocks)
        $this->typeResolverClass_ids_data_fields = array();

        // Allow PoP UserState to add the lazy-loaded userstate data triggers
        HooksAPIFacade::getInstance()->doAction(
            '\PoP\ComponentModel\Engine:getModuleData:start',
            $root_module,
            array(&$root_model_props),
            array(&$root_props),
            array(&$this->helperCalculations),
            $this
        );

        // First check if there's a cache stored
        if ($useCache) {
            $immutable_data_properties = $cachemanager->getCacheByModelInstance(self::CACHETYPE_STATICDATAPROPERTIES);
            $mutableonmodel_data_properties = $cachemanager->getCacheByModelInstance(self::CACHETYPE_STATEFULDATAPROPERTIES);
        }

        // If there is no cached one, generate the props and cache it
        if (!$immutable_data_properties) {
            $immutable_data_properties = $root_processor->getImmutableDataPropertiesDatasetmoduletree($root_module, $root_model_props);
            $mutableonmodel_data_properties = $root_processor->getMutableonmodelDataPropertiesDatasetmoduletree($root_module, $root_model_props);
            if ($useCache) {
                $cachemanager->storeCacheByModelInstance(self::CACHETYPE_STATICDATAPROPERTIES, $immutable_data_properties);
                $cachemanager->storeCacheByModelInstance(self::CACHETYPE_STATEFULDATAPROPERTIES, $mutableonmodel_data_properties);
            }
        }

        $model_data_properties = array_merge_recursive(
            $immutable_data_properties,
            $mutableonmodel_data_properties
        );

        if ($datasources == GD_URLPARAM_DATASOURCES_ONLYMODEL) {
            $root_data_properties = $model_data_properties;
        } else {
            $mutableonrequest_data_properties = $root_processor->getMutableonrequestDataPropertiesDatasetmoduletree($root_module, $root_props);
            $root_data_properties = array_merge_recursive(
                $model_data_properties,
                $mutableonrequest_data_properties
            );
        }

        // Get the list of all modules which calculate their data feedback using another module's results
        $interreferenced_modulefullpaths = $this->getInterreferencedModuleFullpaths($root_module, $root_props);

        // Get the list of all modules which load data, as a list of the module path starting from the top element (the entry module)
        $module_fullpaths = $this->getDataloadingModuleFullpaths($root_module, $root_props);

        $module_path_manager = ModulePathManagerFacade::getInstance();

        // The modules below are already included, so tell the filtermanager to not validate if they must be excluded or not
        $modulefilter_manager = ModuleFilterManagerFacade::getInstance();
        $modulefilter_manager->neverExclude(true);
        foreach ($module_fullpaths as $module_path) {
            // The module is the last element in the path.
            // Notice that the module is removed from the path, providing the path to all its properties
            $module = array_pop($module_path);
            $moduleFullName = ModuleUtils::getModuleFullName($module);

            // Artificially set the current path on the path manager. It will be needed in getDatasetmeta, which calls getDataloadSource, which needs the current path
            $module_path_manager->setPropagationCurrentPath($module_path);

            // Data Properties: assign by reference, so that changes to this variable are also performed in the original variable
            $data_properties = &$root_data_properties;
            foreach ($module_path as $submodule) {
                $submoduleFullName = ModuleUtils::getModuleFullName($submodule);
                $data_properties = &$data_properties[$submoduleFullName][GD_JS_SUBMODULES];
            }
            $data_properties = &$data_properties[$moduleFullName][POP_CONSTANT_DATAPROPERTIES];
            $datasource = $data_properties[DataloadingConstants::DATASOURCE];

            // If we are only requesting data from the model alone, and this dataloading module depends on mutableonrequest, then skip it
            if ($datasources == GD_URLPARAM_DATASOURCES_ONLYMODEL && $datasource == POP_DATALOAD_DATASOURCE_MUTABLEONREQUEST) {
                continue;
            }

            // Load data if the property Skip Data Load is not true
            $load_data = !$data_properties[DataloadingConstants::SKIPDATALOAD];

            // ------------------------------------------
            // Checkpoint validation
            // ------------------------------------------
            // Load data if the checkpoint did not fail
            if ($load_data && $checkpoints = $data_properties[GD_DATALOAD_DATAACCESSCHECKPOINTS]) {
                // Check if the module fails checkpoint validation. If so, it must not load its data or execute the actionexecuter
                $dataaccess_checkpoint_validation = $this->validateCheckpoints($checkpoints);
                $load_data = !GeneralUtils::isError($dataaccess_checkpoint_validation);
            }

            // The $props is directly moving the array to the corresponding path
            $props = &$root_props;
            $model_props = &$root_model_props;
            foreach ($module_path as $submodule) {
                $submoduleFullName = ModuleUtils::getModuleFullName($submodule);
                $props = &$props[$submoduleFullName][POP_PROPS_SUBMODULES];
                $model_props = &$model_props[$submoduleFullName][POP_PROPS_SUBMODULES];
            }

            if (in_array(
                $datasource,
                array(
                    POP_DATALOAD_DATASOURCE_IMMUTABLE,
                    POP_DATALOAD_DATASOURCE_MUTABLEONMODEL,
                )
            )
            ) {
                $module_props = &$model_props;
            } elseif ($datasource == POP_DATALOAD_DATASOURCE_MUTABLEONREQUEST) {
                $module_props = &$props;
            }

            $processor = $moduleprocessor_manager->getProcessor($module);

            // The module path key is used for storing temporary results for later retrieval
            $module_path_key = $this->getModulePathKey($module_path, $module);

            // If data is not loaded, then an empty array will be saved for the dbobject ids
            $dataset_meta = $dbObjectIDs = $typeDBObjectIDs = array();
            $executed = $dbObjectIDOrIDs = $typeDBObjectIDOrIDs = $typeResolver_class = null;
            if ($load_data) {
                // ------------------------------------------
                // Action Executers
                // ------------------------------------------
                // Allow to plug-in functionality here (eg: form submission)
                // Execute at the very beginning, so the result of the execution can also be fetched later below
                // (Eg: creation of a new location => retrieving its data / Adding a new comment)
                // Pass data_properties so these can also be modified (eg: set id of newly created Location)
                if ($actionExecuterClass = $processor->getActionexecuterClass($module)) {
                    if ($processor->executeAction($module, $props)) {
                        // Validate that the actionexecution must be triggered through its own checkpoints
                        $execute = true;
                        if ($actionexecution_checkpoints = $data_properties[GD_DATALOAD_ACTIONEXECUTIONCHECKPOINTS]) {
                            // Check if the module fails checkpoint validation. If so, it must not load its data or execute the actionexecuter
                            $actionexecution_checkpoint_validation = $this->validateCheckpoints($actionexecution_checkpoints);
                            $execute = !GeneralUtils::isError($actionexecution_checkpoint_validation);
                        }

                        if ($execute) {
                            $instanceManager = InstanceManagerFacade::getInstance();
                            $actionexecuter = $instanceManager->getInstance($actionExecuterClass);
                            $executed = $actionexecuter->execute($data_properties);
                        }
                    }
                }

                // Allow modules to change their data_properties based on the actionexecution of previous modules.
                $processor->prepareDataPropertiesAfterActionexecution($module, $module_props, $data_properties);

                // Re-calculate $data_load, it may have been changed by `prepareDataPropertiesAfterActionexecution`
                $load_data = !$data_properties[DataloadingConstants::SKIPDATALOAD];
                if ($load_data) {
                    $typeResolver_class = $processor->getTypeResolverClass($module);
                    // ------------------------------------------
                    // Data Properties Query Args: add mutableonrequest data
                    // ------------------------------------------
                    // Execute and get the ids and the meta
                    $dbObjectIDOrIDs = $processor->getDBObjectIDOrIDs($module, $module_props, $data_properties);
                    // If the type is convertible, we must add the type to each object
                    if (!is_null($dbObjectIDOrIDs)) {
                        $typeDBObjectIDOrIDs = $this->maybeGetDBObjectIDOrIDsForConvertibleTypeResolver((string)$typeResolver_class, $dbObjectIDOrIDs) ?? $dbObjectIDOrIDs;
                    }

                    $dbObjectIDs = is_array($dbObjectIDOrIDs) ? $dbObjectIDOrIDs : array($dbObjectIDOrIDs);
                    $typeDBObjectIDs = is_array($typeDBObjectIDOrIDs) ? $typeDBObjectIDOrIDs : array($typeDBObjectIDOrIDs);

                    // Store the ids under $data under key dataload_name => id
                    $data_fields = $data_properties['data-fields'] ?? array();
                    $conditional_data_fields = $data_properties['conditional-data-fields'] ?? array();
                    $this->combineIdsDatafields($this->typeResolverClass_ids_data_fields, $typeResolver_class, $typeDBObjectIDs, $data_fields, $conditional_data_fields);

                    // Add the IDs to the possibly-already produced IDs for this typeResolver
                    $this->initializeTypeResolverEntry($this->dbdata, $typeResolver_class, $module_path_key);
                    $this->dbdata[$typeResolver_class][$module_path_key]['ids'] = array_merge(
                        $this->dbdata[$typeResolver_class][$module_path_key]['ids'],
                        $typeDBObjectIDs
                    );

                    // The supplementary dbobject data is independent of the typeResolver of the block.
                    // Even if it is STATIC, the extend ids must be loaded. That's why we load the extend now,
                    // Before checking below if the checkpoint failed or if the block content must not be loaded.
                    // Eg: Locations Map for the Create Individual Profile: it allows to pre-select locations,
                    // these ones must be fetched even if the block has a static typeResolver
                    // If it has extend, add those ids under its typeResolver_class
                    $dataload_extend_settings = $processor->getModelSupplementaryDbobjectdataModuletree($module, $model_props);
                    if ($datasource == POP_DATALOAD_DATASOURCE_MUTABLEONREQUEST) {
                        $dataload_extend_settings = array_merge_recursive(
                            $dataload_extend_settings,
                            $processor->getMutableonrequestSupplementaryDbobjectdataModuletree($module, $props)
                        );
                    }
                    foreach ($dataload_extend_settings as $extend_typeResolver_class => $extend_data_properties) {
                         // Get the info for the subcomponent typeResolver
                        $extend_data_fields = $extend_data_properties['data-fields'] ? $extend_data_properties['data-fields'] : array();
                        $extend_conditional_data_fields = $extend_data_properties['conditional-data-fields'] ? $extend_data_properties['conditional-data-fields'] : array();
                        $extend_ids = $extend_data_properties['ids'];

                        $this->combineIdsDatafields($this->typeResolverClass_ids_data_fields, $extend_typeResolver_class, $extend_ids, $extend_data_fields, $extend_conditional_data_fields);

                        // This is needed to add the typeResolver-extend IDs, for if nobody else creates an entry for this typeResolver
                        $this->initializeTypeResolverEntry($this->dbdata, $extend_typeResolver_class, $module_path_key);
                    }

                    // Keep iterating for its subcomponents
                    $this->integrateSubcomponentDataProperties($this->dbdata, $data_properties, $typeResolver_class, $module_path_key);
                }
            }

            // Save the results on either the static or mutableonrequest branches
            if ($datasource == POP_DATALOAD_DATASOURCE_IMMUTABLE) {
                $datasetmoduledata = &$immutable_datasetmoduledata;
                if ($add_meta) {
                    $datasetmodulemeta = &$immutable_datasetmodulemeta;
                }
                $this->moduledata = &$immutable_moduledata;
            } elseif ($datasource == POP_DATALOAD_DATASOURCE_MUTABLEONMODEL) {
                $datasetmoduledata = &$mutableonmodel_datasetmoduledata;
                if ($add_meta) {
                    $datasetmodulemeta = &$mutableonmodel_datasetmodulemeta;
                }
                $this->moduledata = &$mutableonmodel_moduledata;
            } elseif ($datasource == POP_DATALOAD_DATASOURCE_MUTABLEONREQUEST) {
                $datasetmoduledata = &$mutableonrequest_datasetmoduledata;
                if ($add_meta) {
                    $datasetmodulemeta = &$mutableonrequest_datasetmodulemeta;
                }
                $this->moduledata = &$mutableonrequest_moduledata;
            }

            // Integrate the dbobjectids into $datasetmoduledata
            // ALWAYS print the $dbobjectids, even if its an empty array. This to indicate that this is a dataloading module, so the application in the webplatform knows if to load a new batch of dbobjectids, or reuse the ones from the previous module when iterating down
            if (!is_null($datasetmoduledata)) {
                $this->assignValueForModule($datasetmoduledata, $module_path, $module, POP_CONSTANT_DBOBJECTIDS, $typeDBObjectIDOrIDs);
            }

            // Save the meta into $datasetmodulemeta
            if ($add_meta) {
                if (!is_null($datasetmodulemeta)) {
                    if ($dataset_meta = $processor->getDatasetmeta($module, $module_props, $data_properties, $dataaccess_checkpoint_validation, $actionexecution_checkpoint_validation, $executed, $dbObjectIDOrIDs)) {
                        $this->assignValueForModule($datasetmodulemeta, $module_path, $module, POP_CONSTANT_META, $dataset_meta);
                    }
                }
            }

            // Integrate the feedback into $moduledata
            $this->processAndAddModuleData($module_path, $module, $module_props, $data_properties, $dataaccess_checkpoint_validation, $actionexecution_checkpoint_validation, $executed, $dbObjectIDs);

            // Allow other modules to produce their own feedback using this module's data results
            if ($referencer_modulefullpaths = $interreferenced_modulefullpaths[ModulePathHelpersFacade::getInstance()->stringifyModulePath(array_merge($module_path, array($module)))]) {
                foreach ($referencer_modulefullpaths as $referencer_modulepath) {
                    $referencer_module = array_pop($referencer_modulepath);

                    $referencer_props = &$root_props;
                    $referencer_model_props = &$root_model_props;
                    foreach ($referencer_modulepath as $submodule) {
                        $submoduleFullName = ModuleUtils::getModuleFullName($submodule);
                        $referencer_props = &$referencer_props[$submoduleFullName][POP_PROPS_SUBMODULES];
                        $referencer_model_props = &$referencer_model_props[$submoduleFullName][POP_PROPS_SUBMODULES];
                    }

                    if (in_array(
                        $datasource,
                        array(
                            POP_DATALOAD_DATASOURCE_IMMUTABLE,
                            POP_DATALOAD_DATASOURCE_MUTABLEONMODEL,
                        )
                    )
                    ) {
                        $referencer_module_props = &$referencer_model_props;
                    } elseif ($datasource == POP_DATALOAD_DATASOURCE_MUTABLEONREQUEST) {
                        $referencer_module_props = &$referencer_props;
                    }
                    $this->processAndAddModuleData($referencer_modulepath, $referencer_module, $referencer_module_props, $data_properties, $dataaccess_checkpoint_validation, $actionexecution_checkpoint_validation, $executed, $dbObjectIDs);
                }
            }

            // Incorporate the background URLs
            $this->backgroundload_urls = array_merge(
                $this->backgroundload_urls,
                $processor->getBackgroundurlsMergeddatasetmoduletree($module, $module_props, $data_properties, $dataaccess_checkpoint_validation, $actionexecution_checkpoint_validation, $executed, $dbObjectIDs)
            );

            // Allow PoP UserState to add the lazy-loaded userstate data triggers
            HooksAPIFacade::getInstance()->doAction(
                '\PoP\ComponentModel\Engine:getModuleData:dataloading-module',
                $module,
                array(&$module_props),
                array(&$data_properties),
                $dataaccess_checkpoint_validation,
                $actionexecution_checkpoint_validation,
                $executed,
                $dbObjectIDOrIDs,
                array(&$this->helperCalculations),
                $this
            );
        }

        // Reset the filtermanager state and the pathmanager current path
        $modulefilter_manager->neverExclude(false);
        $module_path_manager->setPropagationCurrentPath();

        $ret = array();

        if (in_array(GD_URLPARAM_DATAOUTPUTITEMS_MODULEDATA, $dataoutputitems)) {
            // If there are multiple URIs, then the results must be returned under the corresponding $model_instance_id for "mutableonmodel", and $url for "mutableonrequest"
            list($has_extra_routes, $model_instance_id, $current_uri) = $this->listExtraRouteVars();

            if ($dataoutputmode == GD_URLPARAM_DATAOUTPUTMODE_SPLITBYSOURCES) {
                if ($immutable_moduledata) {
                    $ret['moduledata']['immutable'] = $immutable_moduledata;
                }
                if ($mutableonmodel_moduledata) {
                    $ret['moduledata']['mutableonmodel'] = $has_extra_routes ? array($model_instance_id => $mutableonmodel_moduledata) : $mutableonmodel_moduledata;
                }
                if ($mutableonrequest_moduledata) {
                    $ret['moduledata']['mutableonrequest'] = $has_extra_routes ? array($current_uri => $mutableonrequest_moduledata) : $mutableonrequest_moduledata;
                }
                if ($immutable_datasetmoduledata) {
                    $ret['datasetmoduledata']['immutable'] = $immutable_datasetmoduledata;
                }
                if ($mutableonmodel_datasetmoduledata) {
                    $ret['datasetmoduledata']['mutableonmodel'] = $has_extra_routes ? array($model_instance_id => $mutableonmodel_datasetmoduledata) : $mutableonmodel_datasetmoduledata;
                }
                if ($mutableonrequest_datasetmoduledata) {
                    $ret['datasetmoduledata']['mutableonrequest'] = $has_extra_routes ? array($current_uri => $mutableonrequest_datasetmoduledata) : $mutableonrequest_datasetmoduledata;
                }

                if ($add_meta) {
                    if ($immutable_datasetmodulemeta) {
                        $ret['datasetmodulemeta']['immutable'] = $immutable_datasetmodulemeta;
                    }
                    if ($mutableonmodel_datasetmodulemeta) {
                        $ret['datasetmodulemeta']['mutableonmodel'] = $has_extra_routes ? array($model_instance_id => $mutableonmodel_datasetmodulemeta) : $mutableonmodel_datasetmodulemeta;
                    }
                    if ($mutableonrequest_datasetmodulemeta) {
                        $ret['datasetmodulemeta']['mutableonrequest'] = $has_extra_routes ? array($current_uri => $mutableonrequest_datasetmodulemeta) : $mutableonrequest_datasetmodulemeta;
                    }
                }
            } elseif ($dataoutputmode == GD_URLPARAM_DATAOUTPUTMODE_COMBINED) {
                // If everything is combined, then it belongs under "mutableonrequest"
                if ($combined_moduledata = array_merge_recursive(
                    $immutable_moduledata ?? array(),
                    $mutableonmodel_moduledata ?? array(),
                    $mutableonrequest_moduledata ?? array()
                )
                ) {
                    $ret['moduledata'] = $has_extra_routes ? array($current_uri => $combined_moduledata) : $combined_moduledata;
                }
                if ($combined_datasetmoduledata = array_merge_recursive(
                    $immutable_datasetmoduledata ?? array(),
                    $mutableonmodel_datasetmoduledata ?? array(),
                    $mutableonrequest_datasetmoduledata ?? array()
                )
                ) {
                    $ret['datasetmoduledata'] = $has_extra_routes ? array($current_uri => $combined_datasetmoduledata) : $combined_datasetmoduledata;
                }
                if ($add_meta) {
                    if ($combined_datasetmodulemeta = array_merge_recursive(
                        $immutable_datasetmodulemeta ?? array(),
                        $mutableonmodel_datasetmodulemeta ?? array(),
                        $mutableonrequest_datasetmodulemeta ?? array()
                    )
                    ) {
                        $ret['datasetmodulemeta'] = $has_extra_routes ? array($current_uri => $combined_datasetmodulemeta) : $combined_datasetmodulemeta;
                    }
                }
            }
        }

        // Allow PoP UserState to add the lazy-loaded userstate data triggers
        HooksAPIFacade::getInstance()->doAction(
            '\PoP\ComponentModel\Engine:getModuleData:end',
            $root_module,
            array(&$root_model_props),
            array(&$root_props),
            array(&$this->helperCalculations),
            $this
        );

        return $ret;
    }

    public function moveEntriesUnderDBName(array $entries, bool $entryHasId, $typeResolver): array
    {
        $dbname_entries = [];
        if ($entries) {
            // By default place everything under "primary"
            $dbname_entries['primary'] = $entries;

            // Allow to inject what data fields must be placed under what dbNames
            // Array of key: dbName, values: data-fields
            $dbname_datafields = HooksAPIFacade::getInstance()->applyFilters(
                'PoP\ComponentModel\Engine:moveEntriesUnderDBName:dbName-dataFields',
                [],
                $typeResolver
            );
            foreach ($dbname_datafields as $dbname => $data_fields) {
                // Move these data fields under "meta" DB name
                if ($entryHasId) {
                    foreach ($dbname_entries['primary'] as $id => $dbObject) {
                        $entry_data_fields_to_move = array_intersect(
                            array_keys($dbObject),
                            $data_fields
                        );
                        foreach ($entry_data_fields_to_move as $data_field) {
                            $dbname_entries[$dbname][$id][$data_field] = $dbname_entries['primary'][$id][$data_field];
                            unset($dbname_entries['primary'][$id][$data_field]);
                        }
                    }
                } else {
                    $entry_data_fields_to_move = array_intersect(
                        array_keys($dbname_entries['primary']),
                        $data_fields
                    );
                    foreach ($entry_data_fields_to_move as $data_field) {
                        $dbname_entries[$dbname][$data_field] = $dbname_entries['primary'][$data_field];
                        unset($dbname_entries['primary'][$data_field]);
                    }
                }
            }
        }

        return $dbname_entries;
    }

    public function getDatabases()
    {
        $instanceManager = InstanceManagerFacade::getInstance();
        // $dataquery_manager = DataQueryManagerFactory::getInstance();
        $fieldQueryInterpreter = FieldQueryInterpreterFacade::getInstance();

        $vars = Engine_Vars::getVars();

        // Save all database elements here, under typeResolver
        $databases = $convertibleDBKeyIDs = $combinedConvertibleDBKeyIDs = $previousDBItems = $dbErrors = $dbWarnings = $schemaErrors = $schemaWarnings = $schemaDeprecations = array();
        $this->nocache_fields = array();
        // $format = $vars['format'];
        // $route = $vars['route'];

        // Keep an object with all fetched IDs/fields for each typeResolver. Then, we can keep using the same typeResolver as subcomponent,
        // but we need to avoid fetching those DB objects that were already fetched in a previous iteration
        $already_loaded_ids_data_fields = array();
        $subcomponent_data_fields = array();

        // The variables come from the request
        $variables = $fieldQueryInterpreter->getVariablesFromRequest();
        // Initiate a new $messages interchange across directives
        $messages = [];

        // Iterate while there are dataloaders with data to be processed
        while (!empty($this->typeResolverClass_ids_data_fields)) {
            // Move the pointer to the first element, and get it
            reset($this->typeResolverClass_ids_data_fields);
            $typeResolver_class = key($this->typeResolverClass_ids_data_fields);
            $ids_data_fields = $this->typeResolverClass_ids_data_fields[$typeResolver_class];

            // Remove the typeResolver element from the array, so it doesn't process it anymore
            // Do it immediately, so that subcomponents can load new IDs for this current typeResolver (eg: posts => related)
            unset($this->typeResolverClass_ids_data_fields[$typeResolver_class]);

            // If no ids to execute, then skip
            if (empty($ids_data_fields)) {
                continue;
            }

            // Store the loaded IDs/fields in an object, to avoid fetching them again in later iterations on the same typeResolver
            $already_loaded_ids_data_fields[$typeResolver_class] = $already_loaded_ids_data_fields[$typeResolver_class] ?? array();
            foreach ($ids_data_fields as $id => $data_fields) {
                $already_loaded_ids_data_fields[$typeResolver_class][(string)$id] = array_merge(
                    $already_loaded_ids_data_fields[$typeResolver_class][(string)$id] ?? [],
                    $data_fields['direct'],
                    array_keys($data_fields['conditional'])
                );
            }

            $typeResolver = $instanceManager->getInstance((string)$typeResolver_class);
            $database_key = $typeResolver->getTypeName();
            $isConvertibleTypeResolver = false;

            // Execute the typeResolver for all combined ids
            $iterationDBItems = $iterationDBErrors = $iterationDBWarnings = $iterationSchemaErrors = $iterationSchemaWarnings = $iterationSchemaDeprecations = array();
            $isConvertibleTypeResolver = $typeResolver instanceof ConvertibleTypeResolverInterface;
            $typeResolver->fillResultItems($ids_data_fields, $combinedConvertibleDBKeyIDs, $iterationDBItems, $previousDBItems, $variables, $messages, $iterationDBErrors, $iterationDBWarnings, $iterationSchemaErrors, $iterationSchemaWarnings, $iterationSchemaDeprecations);

            // Save in the database under the corresponding database-key (this way, different dataloaders, like 'list-users' and 'author',
            // can both save their results under database key 'users'
            // Plugin PoP User Login: Also save those results which depend on the logged-in user. These are treated separately because:
            // 1: They contain personal information, so it must be erased from the front-end as soon as the user logs out
            // 2: These results make the page state-full, so this page is not cacheable
            // By splitting the results into state-full and state-less, we can split all functionality into cacheable and non-cacheable,
            // thus caching most of the website even for logged-in users
            if ($iterationDBItems) {
                // Conditional data fields: Store the loaded IDs/fields in an object, to avoid fetching them again in later iterations on the same typeResolver
                // To find out if they were loaded, validate against the DBObject, to see if it has those properties
                foreach ($ids_data_fields as $id => $data_fields) {
                    foreach ($data_fields['conditional'] as $conditionDataField => $conditionalDataFields) {
                        $already_loaded_ids_data_fields[$typeResolver_class][(string)$id] = array_merge(
                            $already_loaded_ids_data_fields[$typeResolver_class][(string)$id] ?? [],
                            array_intersect(
                                $conditionalDataFields,
                                array_keys($iterationDBItems[(string)$id])
                            )
                        );
                    }
                }

                // If the type is convertible, then add the type corresponding to each object on its ID
                $dbItems = $this->moveEntriesUnderDBName($iterationDBItems, true, $typeResolver);
                foreach ($dbItems as $dbname => $entries) {
                    $this->addDatasetToDatabase($databases[$dbname], $typeResolver, $database_key, $entries);

                    // Populate the $previousDBItems, pointing to the newly fetched dbItems (but without the dbname!)
                    // Save the reference to the values, instead of the values, to save memory
                    // Passing $previousDBItems instead of $databases makes it read-only: Directives can only read the values... if they want to modify them,
                    // the modification is done on $previousDBItems, so it carries no risks
                    foreach ($entries as $id => $fieldValues) {
                        foreach ($fieldValues as $field => &$entryFieldValues) {
                            $previousDBItems[$database_key][$id][$field] = &$entryFieldValues;
                        }
                    }
                }
            }
            if ($iterationDBErrors) {
                $dbNameErrorEntries = $this->moveEntriesUnderDBName($iterationDBErrors, true, $typeResolver);
                foreach ($dbNameErrorEntries as $dbname => $entries) {
                    $this->addDatasetToDatabase($dbErrors[$dbname], $typeResolver, $database_key, $entries);
                }
            }
            if ($iterationDBWarnings) {
                $dbNameWarningEntries = $this->moveEntriesUnderDBName($iterationDBWarnings, true, $typeResolver);
                foreach ($dbNameWarningEntries as $dbname => $entries) {
                    $this->addDatasetToDatabase($dbWarnings[$dbname], $typeResolver, $database_key, $entries);
                }
            }

            $feedbackMessageStore = FeedbackMessageStoreFacade::getInstance();
            $storeSchemaErrors = $feedbackMessageStore->retrieveAndClearSchemaErrors();
            if (!empty($iterationSchemaErrors) || !empty($storeSchemaErrors)) {
                $dbNameSchemaErrorEntries = $this->moveEntriesUnderDBName($iterationSchemaErrors, false, $typeResolver);
                foreach ($dbNameSchemaErrorEntries as $dbname => $entries) {
                    $schemaErrors[$dbname][$database_key] = array_merge(
                        $schemaErrors[$dbname][$database_key] ?? [],
                        $entries
                    );
                }
                $dbNameStoreSchemaErrors = $this->moveEntriesUnderDBName($storeSchemaErrors, false, $typeResolver);
                $schemaErrors = array_merge_recursive(
                    $schemaErrors,
                    $dbNameStoreSchemaErrors
                );
            }
            if ($storeSchemaWarnings = $feedbackMessageStore->retrieveAndClearSchemaWarnings()) {
                $iterationSchemaWarnings = array_merge(
                    $iterationSchemaWarnings ?? [],
                    $storeSchemaWarnings
                );
            }
            if ($iterationSchemaWarnings) {
                $iterationSchemaWarnings = array_intersect_key($iterationSchemaWarnings, array_unique(array_map('serialize', $iterationSchemaWarnings)));
                $dbNameSchemaWarningEntries = $this->moveEntriesUnderDBName($iterationSchemaWarnings, false, $typeResolver);
                foreach ($dbNameSchemaWarningEntries as $dbname => $entries) {
                    $schemaWarnings[$dbname][$database_key] = array_merge(
                        $schemaWarnings[$dbname][$database_key] ?? [],
                        $entries
                    );
                }
            }
            if ($iterationSchemaDeprecations) {
                $iterationSchemaDeprecations = array_intersect_key($iterationSchemaDeprecations, array_unique(array_map('serialize', $iterationSchemaDeprecations)));
                $dbNameSchemaDeprecationEntries = $this->moveEntriesUnderDBName($iterationSchemaDeprecations, false, $typeResolver);
                foreach ($dbNameSchemaDeprecationEntries as $dbname => $entries) {
                    $schemaDeprecations[$dbname][$database_key] = array_merge(
                        $schemaDeprecations[$dbname][$database_key] ?? [],
                        $entries
                    );
                }
            }

            // Comment Leo 21/10/2019: This code will soon be deleted, so I can momentarily comment it
            // ------------------------------------------------------------
            /*
            // Keep the list of elements that must be retrieved once again from the server
            if ($dataquery_name = $typeDataLoader->getDataquery()) {
                $dataquery = $dataquery_manager->get($dataquery_name);
                $objectid_fieldname = $dataquery->getObjectidFieldname();

                // Force retrieval of data from the server. Eg: recommendpost-count
                $forceserverload_fields = $dataquery->getNoCacheFields();

                // Lazy fields. Eg: comments
                $lazylayouts = $dataquery->getLazyLayouts();
                $lazyload_fields = array_keys($lazylayouts);

                // Store the intersected fields and the corresponding ids
                $forceserverload = array(
                    'ids' => array(),
                    'fields' => array()
                );
                $lazyload = array(
                    'ids' => array(),
                    'layouts' => array()
                );

                // Compare the fields in the result dbobjectids, with the dataquery's specified list of fields that must always be retrieved from the server
                // (eg: comment-count, since adding a comment doesn't delete the cache)
                foreach ($ids as $dataitem_id) {
                    // Get the fields requested to that dataitem, for both the database and user database
                    $dataitem_fields = [];
                    foreach ($iterationDBItems as $dbname => $dbItems) {
                        $dataitem_fields = array_merge(
                            $dataitem_fields,
                            array_keys($dbItems[$dataitem_id] ?? array())
                        );
                    }
                    $dataitem_fields = array_unique($dataitem_fields);

                    // Intersect these with the fields that must be loaded from server
                    // Comment Leo 31/03/2017: do it only if we are not currently in the noncacheable_page
                    // If we are, then we came here loading a backgroundload-url, and we don't need to load it again
                    // Otherwise, it would create an infinite loop, since the fields loaded here are, exactly, those defined in the noncacheable_fields
                    // Eg: https://www.mesym.com/en/loaders/posts/data/?pid[0]=21636&pid[1]=21632&pid[2]=21630&pid[3]=21628&pid[4]=21624&pid[5]=21622&fields[0]=recommendpost-count&fields[1]=recommendpost-count-plus1&fields[2]=userpostactivity-count&format=updatedata
                    if ($route != $dataquery->getNonCacheableRoute()) {
                        if ($intersect = array_values(array_intersect($dataitem_fields, $forceserverload_fields))) {
                            $forceserverload['ids'][] = $dataitem_id;
                            $forceserverload['fields'] = array_merge(
                                $forceserverload['fields'],
                                $intersect
                            );
                        }
                    }

                    // Intersect these with the lazyload fields
                    if ($intersect = array_values(array_intersect($dataitem_fields, $lazyload_fields))) {
                        $lazyload['ids'][] = $dataitem_id;
                        foreach ($intersect as $field) {
                            // Get the layout for the current format, if it exists, or the default one if not
                            $lazyload['layouts'][] = $lazylayouts[$field][$format] ?? $lazylayouts[$field]['default'];
                        }
                    }
                }
                if ($forceserverload['ids']) {
                    $forceserverload['fields'] = array_unique($forceserverload['fields']);

                    $url = RouteUtils::getRouteURL($dataquery->getNonCacheableRoute());
                    $url = GeneralUtils::addQueryArgs([
                        $objectid_fieldname => $forceserverload['ids'],
                        GD_URLPARAM_FIELDS => $forceserverload['fields'],
                        GD_URLPARAM_FORMAT => POP_FORMAT_FIELDS,
                    ], $url);
                    $this->backgroundload_urls[urldecode($url)] = array(POP_TARGET_MAIN);

                    // Keep the nocache fields to remove those from the code when generating the ETag
                    $this->nocache_fields = array_merge(
                        $this->nocache_fields,
                        $forceserverload['fields']
                    );
                }
                if ($lazyload['ids']) {
                    $lazyload['layouts'] = array_unique(
                        $lazyload['layouts'],
                        SORT_REGULAR
                    );

                    $url = RouteUtils::getRouteURL($dataquery->getCacheableRoute());
                    $url = GeneralUtils::addQueryArgs([
                        $objectid_fieldname => $lazyload['ids'],
                        // Convert from module to moduleFullName
                        GD_URLPARAM_LAYOUTS => array_map(
                            [ModuleUtils::class, 'getModuleOutputName'],
                            $lazyload['layouts']
                        ),
                        GD_URLPARAM_FORMAT => POP_FORMAT_LAYOUTS,
                    ], $url);
                    $this->backgroundload_urls[urldecode($url)] = array(POP_TARGET_MAIN);
                }
            }
            */
            // ------------------------------------------------------------

            // Important: query like this: obtain keys first instead of iterating directly on array, because it will keep adding elements
            $typeResolver_dbdata = $this->dbdata[$typeResolver_class];
            foreach (array_keys($typeResolver_dbdata) as $module_path_key) {
                $typeResolver_data = &$this->dbdata[$typeResolver_class][$module_path_key];

                unset($this->dbdata[$typeResolver_class][$module_path_key]);

                // Check if it has subcomponents, and then bring this data
                if ($subcomponents_data_properties = $typeResolver_data['subcomponents']) {
                    $typeResolver_ids = $typeResolver_data['ids'];
                    foreach ($subcomponents_data_properties as $subcomponent_data_field => $subcomponent_data_properties) {
                        // Retrieve the subcomponent typeResolver from the current typeResolver
                        if ($subcomponent_typeResolver_class = DataloadUtils::getTypeResolverClassFromSubcomponentDataField($typeResolver, $subcomponent_data_field)) {
                            $subcomponent_data_field_outputkey = FieldQueryInterpreterFacade::getInstance()->getFieldOutputKey($subcomponent_data_field);
                            // The array_merge_recursive when there are at least 2 levels will make the data_fields to be duplicated, so remove duplicates now
                            $subcomponent_data_fields = array_unique($subcomponent_data_properties['data-fields'] ?? []);
                            $subcomponent_conditional_data_fields = $subcomponent_data_properties['conditional-data-fields'] ?? [];
                            if ($subcomponent_data_fields || $subcomponent_conditional_data_fields) {

                                $subcomponentTypeResolver = $instanceManager->getInstance($subcomponent_typeResolver_class);
                                $subcomponentIsConvertibleTypeResolver = $subcomponentTypeResolver instanceof ConvertibleTypeResolverInterface;

                                $subcomponent_already_loaded_ids_data_fields = array();
                                if ($already_loaded_ids_data_fields && $already_loaded_ids_data_fields[$subcomponent_typeResolver_class]) {
                                    $subcomponent_already_loaded_ids_data_fields = $already_loaded_ids_data_fields[$subcomponent_typeResolver_class];
                                }
                                foreach ($typeResolver_ids as $id) {
                                    // If the type data resolver is convertible, the dbKey where the value is stored is contained in the ID itself,
                                    // with format dbKey/ID. We must extract this information: assign the dbKey to $database_key, and remove the dbKey from the ID
                                    if ($isConvertibleTypeResolver) {
                                        list(
                                            $database_key,
                                            $id
                                        ) = ConvertibleTypeHelpers::extractDBObjectTypeAndID($id);
                                    }
                                    // $databases may contain more the 1 DB shipped by pop-engine/ ("primary"). Eg: PoP User Login adds db "userstate"
                                    // Fetch the field_ids from all these DBs
                                    $field_ids = array();
                                    foreach ($databases as $dbname => $database) {
                                        if ($database_field_ids = $database[$database_key][(string)$id][$subcomponent_data_field_outputkey]) {
                                            // We don't want to store the dbKey/ID inside the relationalID, because that can lead to problems when dealing with the relations in the application (better keep it only to the ID)
                                            // So, instead, we store the dbKey/ID values in another object "$convertibleDBKeyIDs"
                                            // Then, whenever it's a convertible type data resolver, we obtain the values for the relationship under this other object
                                            if ($subcomponentIsConvertibleTypeResolver) {
                                                $isArray = is_array($database_field_ids);
                                                $database_field_ids = array_map(
                                                    function($id) use($subcomponentTypeResolver) {
                                                        return $subcomponentTypeResolver->addTypeToID($id);
                                                    },
                                                    $isArray ? $database_field_ids : [$database_field_ids]
                                                );
                                                if ($isArray) {
                                                    $convertibleDBKeyIDs[$dbname][$database_key][(string)$id][$subcomponent_data_field_outputkey] = $database_field_ids;
                                                    $combinedConvertibleDBKeyIDs[$database_key][(string)$id][$subcomponent_data_field_outputkey] = $database_field_ids;
                                                } else {
                                                    $convertibleDBKeyIDs[$dbname][$database_key][(string)$id][$subcomponent_data_field_outputkey] = $database_field_ids[0];
                                                    $combinedConvertibleDBKeyIDs[$database_key][(string)$id][$subcomponent_data_field_outputkey] = $database_field_ids[0];
                                                }
                                            }

                                            $field_ids = array_merge(
                                                $field_ids,
                                                is_array($database_field_ids) ? $database_field_ids : array($database_field_ids)
                                            );
                                        }
                                    }
                                    if ($field_ids) {
                                        foreach ($field_ids as $field_id) {
                                            // Do not add again the IDs/Fields already loaded
                                            if ($subcomponent_already_loaded_data_fields = $subcomponent_already_loaded_ids_data_fields[$field_id]) {
                                                $id_subcomponent_data_fields = array_values(
                                                    array_diff(
                                                        $subcomponent_data_fields,
                                                        $subcomponent_already_loaded_data_fields
                                                    )
                                                );
                                                $id_subcomponent_conditional_data_fields = [];
                                                foreach ($subcomponent_conditional_data_fields as $conditionField => $conditionalFields) {
                                                    $id_subcomponent_conditional_data_fields[$conditionField] = array_values(
                                                        array_diff(
                                                            $conditionalFields,
                                                            $subcomponent_already_loaded_data_fields
                                                        )
                                                    );
                                                }
                                            } else {
                                                $id_subcomponent_data_fields = $subcomponent_data_fields;
                                                $id_subcomponent_conditional_data_fields = $subcomponent_conditional_data_fields;
                                            }
                                            // Important: do ALWAYS execute the lines below, even if $id_subcomponent_data_fields is empty
                                            // That is because we can load additional data for an object that was already loaded in a previous iteration
                                            // Eg: /api/?query=posts(id:1).author.posts.comments.post.author.posts.title
                                            // In this case, property "title" at the end would not be fetched otherwise (that post was already loaded at the beginning)
                                            // if ($id_subcomponent_data_fields) {
                                            $this->combineIdsDatafields($this->typeResolverClass_ids_data_fields, $subcomponent_typeResolver_class, array($field_id), $id_subcomponent_data_fields, $id_subcomponent_conditional_data_fields);
                                            // }
                                        }
                                        $this->initializeTypeResolverEntry($this->dbdata, $subcomponent_typeResolver_class, $module_path_key);
                                        $this->dbdata[$subcomponent_typeResolver_class][$module_path_key]['ids'] = array_merge(
                                            $this->dbdata[$subcomponent_typeResolver_class][$module_path_key]['ids'] ?? [],
                                            $field_ids
                                        );
                                        $this->integrateSubcomponentDataProperties($this->dbdata, $subcomponent_data_properties, $subcomponent_typeResolver_class, $module_path_key);
                                    }
                                }

                                if ($this->dbdata[$subcomponent_typeResolver_class][$module_path_key]) {
                                    $this->dbdata[$subcomponent_typeResolver_class][$module_path_key]['ids'] = array_unique($this->dbdata[$subcomponent_typeResolver_class][$module_path_key]['ids']);
                                    $this->dbdata[$subcomponent_typeResolver_class][$module_path_key]['data-fields'] = array_unique($this->dbdata[$subcomponent_typeResolver_class][$module_path_key]['data-fields']);
                                }
                            }
                        }
                    }
                }
            }
            // }
        }

        $ret = array();

        // Executing the following query will produce duplicates on SchemaWarnings:
        // ?query=posts(limit:3.5).title,posts(limit:extract(posts(limit:4.5),saraza)).title
        // This is unavoidable, since add schemaWarnings (and, correspondingly, errors and deprecations) in functions
        // `resolveSchemaValidationWarningDescriptions` and `resolveValue` from the AbstractTypeResolver
        // Ideally, doing it in `resolveValue` is not needed, since it already went through the validation in `resolveSchemaValidationWarningDescriptions`, so it's a duplication
        // However, when doing nested fields, the warnings are caught only in `resolveValue`, hence we need to add it there too
        // Then, we will certainly have duplicates. Remove them now
        // Because these are arrays of arrays, we use the method taken from https://stackoverflow.com/a/2561283
        foreach ($schemaErrors as $dbname => &$entries) {
            foreach ($entries as $dbKey => $errors) {
                $entries[$dbKey] = array_intersect_key($errors, array_unique(array_map('serialize', $errors)));
            }
        }

        // Add the feedback (errors, warnings, deprecations) into the output
        $feedbackMessageStore = FeedbackMessageStoreFacade::getInstance();
        if ($queryErrors = $feedbackMessageStore->getQueryErrors()) {
            $ret['queryErrors'] = $queryErrors;
        }
        $this->maybeCombineAndAddDatabaseEntries($ret, 'dbErrors', $dbErrors);
        $this->maybeCombineAndAddDatabaseEntries($ret, 'dbWarnings', $dbWarnings);
        $this->maybeCombineAndAddSchemaEntries($ret, 'schemaErrors', $schemaErrors);
        $this->maybeCombineAndAddSchemaEntries($ret, 'schemaWarnings', $schemaWarnings);
        $this->maybeCombineAndAddSchemaEntries($ret, 'schemaDeprecations', $schemaDeprecations);
        if (ServerUtils::enableShowLogs()) {
            if (in_array(POP_ACTION_SHOW_LOGS, $vars['actions'])) {
                $ret['logEntries'] = $feedbackMessageStore->getLogEntries();
            }
        }
        $this->maybeCombineAndAddDatabaseEntries($ret, 'dbData', $databases);
        $this->maybeCombineAndAddDatabaseEntries($ret, 'convertibleDBKeyIDs', $convertibleDBKeyIDs);

        return $ret;
    }

    protected function maybeCombineAndAddDatabaseEntries(&$ret, $name, $entries) {

        // Do not add the "database", "userstatedatabase" entries unless there are values in them
        // Otherwise, it messes up integrating the current databases in the webplatform with those from the response when deep merging them
        if ($entries) {
            $vars = Engine_Vars::getVars();
            $dboutputmode = $vars['dboutputmode'];

            // Combine all the databases or send them separate
            if ($dboutputmode == GD_URLPARAM_DATABASESOUTPUTMODE_SPLITBYDATABASES) {
                $ret[$name] = $entries;
            } elseif ($dboutputmode == GD_URLPARAM_DATABASESOUTPUTMODE_COMBINED) {
                // Filter to make sure there are entries
                if ($entries = array_filter($entries)) {
                    $combined_databases = array();
                    foreach ($entries as $database_name => $database) {
                        // Combine them on an ID by ID basis, because doing [2 => [...], 3 => [...]]), which is wrong
                        foreach ($database as $database_key => $dbItems) {
                            foreach ($dbItems as $dbobject_id => $dbobject_values) {
                                $combined_databases[$database_key][(string)$dbobject_id] = array_merge(
                                    $combined_databases[$database_key][(string)$dbobject_id] ?? [],
                                    $dbobject_values
                                );
                            }
                        }
                    }
                    $ret[$name] = $combined_databases;
                }
            }
        }
    }

    protected function maybeCombineAndAddSchemaEntries(&$ret, $name, $entries) {

        if ($entries) {
            $vars = Engine_Vars::getVars();
            $dboutputmode = $vars['dboutputmode'];

            // Combine all the databases or send them separate
            if ($dboutputmode == GD_URLPARAM_DATABASESOUTPUTMODE_SPLITBYDATABASES) {
                $ret[$name] = $entries;
            } elseif ($dboutputmode == GD_URLPARAM_DATABASESOUTPUTMODE_COMBINED) {
                // Filter to make sure there are entries
                if ($entries = array_filter($entries)) {
                    $combined_databases = array();
                    foreach ($entries as $database_name => $database) {
                        $combined_databases = array_merge_recursive(
                            $combined_databases,
                            $database
                        );
                    }
                    $ret[$name] = $combined_databases;
                }
            }
        }
    }

    protected function processAndAddModuleData($module_path, array $module, array &$props, array $data_properties, $dataaccess_checkpoint_validation, $actionexecution_checkpoint_validation, $executed, $dbObjectIDs)
    {
        $moduleprocessor_manager = ModuleProcessorManagerFacade::getInstance();
        $processor = $moduleprocessor_manager->getProcessor($module);

        // Integrate the feedback into $moduledata
        if (!is_null($this->moduledata)) {
            $moduledata = &$this->moduledata;

            // Add the feedback into the object
            if ($feedback = $processor->getDataFeedbackDatasetmoduletree($module, $props, $data_properties, $dataaccess_checkpoint_validation, $actionexecution_checkpoint_validation, $executed, $dbObjectIDs)) {
                // Advance the position of the array into the current module
                foreach ($module_path as $submodule) {
                    $submoduleOutputName = ModuleUtils::getModuleOutputName($submodule);
                    $moduledata[$submoduleOutputName][GD_JS_SUBMODULES] = $moduledata[$submoduleOutputName][GD_JS_SUBMODULES] ?? array();
                    $moduledata = &$moduledata[$submoduleOutputName][GD_JS_SUBMODULES];
                }
                // Merge the feedback in
                $moduledata = array_merge_recursive(
                    $moduledata,
                    $feedback
                );
            }
        }
    }

    private function initializeTypeResolverEntry(&$dbdata, $typeResolver_class, $module_path_key)
    {
        if (is_null($dbdata[$typeResolver_class][$module_path_key])) {
            $dbdata[$typeResolver_class][$module_path_key] = array(
                'ids' => array(),
                'data-fields' => array(),
                'subcomponents' => array(),
            );
        }
    }

    private function integrateSubcomponentDataProperties(&$dbdata, array $data_properties, $typeResolver_class, $module_path_key)
    {
        // Process the subcomponents
        // If it has subcomponents, bring its data to, after executing getData on the primary typeResolver, execute getData also on the subcomponent typeResolver
        if ($subcomponents_data_properties = $data_properties['subcomponents']) {
            // Merge them into the data
            $dbdata[$typeResolver_class][$module_path_key]['subcomponents'] = array_merge_recursive(
                $dbdata[$typeResolver_class][$module_path_key]['subcomponents'] ?? array(),
                $subcomponents_data_properties
            );
        }
    }
}

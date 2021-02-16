<?php


namespace Pimcore\Bundle\DataHubBatchImportBundle\Processing;


use Pimcore\Bundle\DataHubBatchImportBundle\Cleanup\CleanupStrategyFactory;
use Pimcore\Bundle\DataHubBatchImportBundle\Exception\InvalidConfigurationException;
use Pimcore\Bundle\DataHubBatchImportBundle\Mapping\MappingConfiguration;
use Pimcore\Bundle\DataHubBatchImportBundle\Mapping\MappingConfigurationFactory;
use Pimcore\Bundle\DataHubBatchImportBundle\Mapping\Type\TransformationDataTypeService;
use Pimcore\Bundle\DataHubBatchImportBundle\PimcoreDataHubBatchImportBundle;
use Pimcore\Bundle\DataHubBatchImportBundle\Processing\Cron\CronExecutionService;
use Pimcore\Bundle\DataHubBatchImportBundle\Queue\QueueService;
use Pimcore\Bundle\DataHubBatchImportBundle\Resolver\Resolver;
use Pimcore\Bundle\DataHubBatchImportBundle\Resolver\ResolverFactory;
use Pimcore\Bundle\DataHubBatchImportBundle\Settings\ConfigurationPreparationService;
use Pimcore\DataObject\Import\Resolver\ResolverInterface;
use Pimcore\Log\ApplicationLogger;
use Pimcore\Log\FileObject;
use Pimcore\Model\Tool\TmpStore;
use Psr\Log\LoggerAwareTrait;

class ImportProcessingService
{
    use LoggerAwareTrait;

    const JOB_TYPE_PROCESS = 'process';
    const JOB_TYPE_CLEANUP = 'cleanup';

    const EXECUTION_TYPE_SEQUENTIAL = 'sequential';
    const EXECUTION_TYPE_PARALLEL = 'parallel';

    const INFO_ENTRY_ID_PREFIX = 'datahub_batchimport_';


    /**
     * @var QueueService
     */
    protected $queueService;

    /**
     * @var ConfigurationPreparationService
     */
    protected $configLoader;

    /**
     * @var MappingConfigurationFactory
     */
    protected $mappingConfigurationFactory;

    /**
     * @var ResolverFactory
     */
    protected $resolverFactory;

    /**
     * @var CleanupStrategyFactory
     */
    protected $cleanupStrategyFactory;

    /**
     * @var ApplicationLogger
     */
    protected $applicationLogger;

    /**
     * @var ResolverInterface[]
     */
    protected $resolverCache = [];

    /**
     * @var MappingConfiguration[]
     */
    protected $mappingConfigurationCache = [];

    /**
     * ImportProcessingService constructor.
     * @param QueueService $queueService
     * @param MappingConfigurationFactory $mappingConfigurationFactory
     * @param ResolverFactory $resolverFactory
     * @param CleanupStrategyFactory $cleanupStrategyFactory
     * @param ApplicationLogger $applicationLogger
     */
    public function __construct(QueueService $queueService, MappingConfigurationFactory $mappingConfigurationFactory, ResolverFactory $resolverFactory, CleanupStrategyFactory $cleanupStrategyFactory, ApplicationLogger $applicationLogger)
    {
        $this->queueService = $queueService;
        $this->mappingConfigurationFactory = $mappingConfigurationFactory;
        $this->resolverFactory = $resolverFactory;
        $this->cleanupStrategyFactory = $cleanupStrategyFactory;
        $this->applicationLogger = $applicationLogger;

        $this->configLoader = new ConfigurationPreparationService();
    }


    public function processQueueItem(int $id) {

        //get queue item
        $queueItem = $this->queueService->getQueueEntryById($id);
        if(empty($queueItem)) {
            return;
        }

        //get config
        $configName = $queueItem['configName'];
        $config = $this->configLoader->prepareConfiguration($configName);

        //init resolver and mapping
        if(empty($this->mappingConfigurationCache[$configName])) {
            $this->mappingConfigurationCache[$configName] = $this->mappingConfigurationFactory->loadMappingConfiguration($configName, $config['mappingConfig']);
        }
        $mapping = $this->mappingConfigurationCache[$configName];

        if(empty($this->resolverCache[$configName])) {
            $this->resolverCache[$configName] = $this->resolverFactory->loadResolver($config['resolverConfig']);
        }
        $resolver = $this->resolverCache[$configName];

        //process element
        if($queueItem['jobType'] === self::JOB_TYPE_PROCESS) {
            $data = json_decode($queueItem['data'], true);
            $this->processElement($configName, $data, $resolver, $mapping);
        } elseif($queueItem['jobType'] === self::JOB_TYPE_CLEANUP) {
            $this->cleanupElement($configName, $queueItem['data'], $resolver, $config['interpreterConfig']['cleanup'] ?? []);
        } else {
            throw new InvalidConfigurationException('Unknown job type ' . $queueItem['jobType']);
        }

        $this->queueService->markQueueEntryAsProcessed($id);
    }


    /**
     * @param string $configName
     * @param array $importDataRow
     * @param Resolver $resolver
     * @param MappingConfiguration[] $mapping
     */
    protected function processElement(string $configName, array $importDataRow, Resolver $resolver, array $mapping) {

        $element = null;
        try {
            //resolve data object
            $element = $resolver->loadOrCreateAndPrepareElement($importDataRow);

            foreach($mapping as $mappingConfiguration) {

                // extract raw data
                $data = null;
                if(is_array($mappingConfiguration->getDataSourceIndex())) {
                    $data = [];
                    foreach($mappingConfiguration->getDataSourceIndex() as $index) {
                        $data[] = $importDataRow[$index] ?? null;
                    }

                    if(count($data) === 1) {
                        $data = $data[0];
                    }

                } else {
                    $data = $importDataRow[$mappingConfiguration->getDataSourceIndex()] ?? null;
                }

                // process pipeline
                foreach($mappingConfiguration->getTransformationPipeline() as $operator) {
                    $data = $operator->process($data);
                }

                $dataTarget = $mappingConfiguration->getDataTarget();
                $dataTarget->assignData($element, $data);
            }

            $element->save();

            $message = "Element {$element->getId()} imported successfully.";
            $this->logger->info($message);
            $this->applicationLogger->info($message, [
                'component' => PimcoreDataHubBatchImportBundle::LOGGER_COMPONENT_PREFIX . $configName,
                'fileObject' => new FileObject(json_encode($importDataRow)),
                'relatedObject' => $element
            ]);

        } catch (\Exception $e) {
            $message = "Error processing element: ";
            $this->logger->error($message . $e);

            $this->applicationLogger->error($message . $e->getMessage(), [
                'component' => PimcoreDataHubBatchImportBundle::LOGGER_COMPONENT_PREFIX . $configName,
                'fileObject' => new FileObject(json_encode($importDataRow)),
                'relatedObject' => $element,
            ]);

        }

    }

    protected function cleanupElement(string $configName, string $identifier, Resolver $resolver, array $cleanupConfig) {

        if($cleanupConfig['doCleanup'] ?? false) {

            $element = null;

            try {
                $element = $resolver->loadElementByIdentifier($identifier);
                if($element) {
                    $cleanupStrategy = $this->cleanupStrategyFactory->loadCleanupStrategy($cleanupConfig['strategy']);
                    $cleanupStrategy->doCleanup($element);

                    $message = "Element {$identifier} cleaned up ({$cleanupConfig['strategy']}) successfully.";
                    $this->logger->info($message);
                    $this->applicationLogger->info($message, [
                        'component' => PimcoreDataHubBatchImportBundle::LOGGER_COMPONENT_PREFIX . $configName,
                        'relatedObject' => $element
                    ]);

                }

            } catch (\Exception $e) {
                $message = "Error cleaning up element: ";
                $this->logger->error($message . $e);
                $this->applicationLogger->error($message . $e->getMessage(), [
                    'component' => PimcoreDataHubBatchImportBundle::LOGGER_COMPONENT_PREFIX . $configName,
                    'relatedObject' => $element,
                ]);

            }

        }

    }


    /**
     * @param MappingConfiguration $mappingConfiguration
     * @return string
     */
    public function evaluateTransformationResultDataType(MappingConfiguration $mappingConfiguration): string {

        // extract raw data
        $transformationDataType = TransformationDataTypeService::DEFAULT_TYPE;
        $dataSourceIndex = $mappingConfiguration->getDataSourceIndex();
        if(is_array($dataSourceIndex) && count($dataSourceIndex) > 1) {
            $transformationDataType = TransformationDataTypeService::DEFAULT_ARRAY;
        }

        // process pipeline
        foreach($mappingConfiguration->getTransformationPipeline() as $index => $operator) {
            $transformationDataType = $operator->evaluateReturnType($transformationDataType, $index + 1);
        }

        return $transformationDataType;

    }

    /**
     * @param array $importDataRow
     * @param MappingConfiguration $mappingConfiguration
     * @return string
     */
    public function generateTransformationResultPreview(array $importDataRow, MappingConfiguration $mappingConfiguration): string {

        // extract raw data
        $data = null;
        if(is_array($mappingConfiguration->getDataSourceIndex())) {
            $data = [];
            foreach($mappingConfiguration->getDataSourceIndex() as $index) {
                $data[] = $importDataRow[$index] ?? null;
            }

            if(count($data) === 1) {
                $data = $data[0];
            }

        } else {
            $data = $importDataRow[$mappingConfiguration->getDataSourceIndex()] ?? null;
        }

        // process pipeline
        $operator = null;
        foreach($mappingConfiguration->getTransformationPipeline() as $index => $operator) {
            $data = $operator->process($data, true);
        }

        if($operator) {
            $data = $operator->generateResultPreview($data);
        }

        if(empty($data)) {
            return '-- EMPTY --';
        } else if(is_string($data)) {
            return $data;
        } else if(is_array($data)) {

            $dataStrings = [];
            foreach($data as $key => $dataEntry) {
                if(is_string($dataEntry)) {
                    $dataStrings[] = $key . ' => ' . $dataEntry;
                } else {
                    $dataStrings[] = $key . ' => ' . json_encode($dataEntry);
                }
            }

            return '[ ' . implode(' | ', $dataStrings) . ' ]';

        } else {
            return json_encode($data);
        }

    }

    /**
     * @param string $configName
     * @return array
     */
    public function getImportStatus(string $configName): array {

        $currentQueueItems = $this->queueService->getQueueItemCount($configName);
        $infoEntry = TmpStore::get(ImportProcessingService::INFO_ENTRY_ID_PREFIX . $configName);
        if($infoEntry) {
            $totalItems = $infoEntry->getData();
            $processedItems = $totalItems - $currentQueueItems;
            $progress = $totalItems > 0 ? round($processedItems/$totalItems, 2) : 1;
        } else {
            $totalItems = $currentQueueItems;
            $processedItems = 0;
            $progress = 0;
        }

        return [
            'isRunning' => $currentQueueItems > 0,
            'totalItems' => $totalItems,
            'processedItems' => $processedItems,
            'progress' => $progress
        ];

    }

    /**
     * @param string $configName
     * @throws \Doctrine\DBAL\DBALException
     */
    public function cancelImportAndCleanupQueue(string $configName): void {

        $infoEntryId = ImportProcessingService::INFO_ENTRY_ID_PREFIX . $configName;
        TmpStore::delete($infoEntryId);
        $this->queueService->cleanupQueueItems($configName);

    }

}

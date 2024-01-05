<?php
/**
 * MuckiSearchPlugin plugin
 *
 *
 * @category   Muckiware
 * @package    MuckiSearch
 * @copyright  Copyright (c) 2023 by Muckiware
 *
 * @author     Muckiware
 *
 */

namespace MuckiSearchPlugin\Search\Elasticsearch;

use Http\Promise\Promise;
use Shopware\Core\Framework\Context;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\MissingParameterException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Psr\Log\LoggerInterface;
use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Elasticsearch\ClientInterface;
use Elastic\Elasticsearch\Exception\AuthenticationException;
use Elastic\Elasticsearch\Response\Elasticsearch;

use MuckiSearchPlugin\Core\Defaults;
use MuckiSearchPlugin\Search\SearchClientInterface;
use MuckiSearchPlugin\Services\Settings as PluginSettings;
use MuckiSearchPlugin\Services\Content\IndexStructure;
use MuckiSearchPlugin\Core\Content\IndexStructure\IndexStructureTranslation\IndexStructureTranslationEntity;
use MuckiSearchPlugin\Entities\CreateIndicesBody;
use MuckiSearchPlugin\Entities\IndicesMappingProperty;
use MuckiSearchPlugin\Entities\Indices;
use MuckiSearchPlugin\Services\IndicesSettings;

class Client implements SearchClientInterface
{
    public function __construct(
        protected PluginSettings $settings,
        protected LoggerInterface $logger,
        protected IndexStructure $indexStructure,
        protected IndicesSettings $indicesSettings
    )
    {}

    public function getClient(): ?ClientInterface
    {
        try {
            return ClientBuilder::create()
                ->setHosts([$this->settings->getServerConnectionString()])
                ->build();
        } catch (AuthenticationException $exception) {
            $this->logger->error('Problem for to get server connection');
            $this->logger->error($exception->getMessage());
        }

        return null;
    }

    public function searching(array $params): ?array
    {
        try {

            return $this->getClient()
                ->search($params)
                ->asArray();

        } catch (ClientResponseException $clientEx) {
            $this->logger->error($clientEx->getMessage());
        } catch (ServerResponseException $resEx) {
            $this->logger->error($resEx->getMessage());
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }

        return null;
    }

    public function deleting(array $params): bool
    {
        $response['acknowledge'] = 0;
        try {
            $response = $this->getClient()->delete($params);
        } catch (ClientResponseException $clientEx) {

            if ($clientEx->getCode() === 404) {
                $this->logger->warning('item not found');
            }
            $this->logger->error($clientEx->getMessage());
        } catch (MissingParameterException $exMiss) {
            $this->logger->error($exMiss->getMessage());
        } catch (ServerResponseException $exRes) {
            $this->logger->error($exRes->getMessage());
        }

        if($response['acknowledge'] === 1) {
            return true;
        }

        return false;
    }

    public function getServerInfoAsString(): ?string
    {
        try {
            return $this->getClient()->info()->asString();
        } catch (ClientResponseException $clientEx) {
            $this->logger->error($clientEx->getMessage());
        } catch (ServerResponseException $resEx) {
            $this->logger->error($resEx->getMessage());
        }
        return null;
    }

    public function getServerInfoAsObject(): ?object
    {
        try {
            return $this->getClient()->info()->asObject();
        } catch (ClientResponseException $clientEx) {
            $this->logger->error($clientEx->getMessage());
        } catch (ServerResponseException $resEx) {
            $this->logger->error($resEx->getMessage());
        }
        return null;
    }

    public function indexing(array $params): ?array
    {
        try {
            return $this->getClient()->index($params)->asArray();
        } catch (ClientResponseException $clientEx) {
            $this->logger->error($clientEx->getMessage());
        } catch (ServerResponseException $resEx) {
            $this->logger->error($resEx->getMessage());
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }

        return null;
    }

    public function getIndices(): ?array
    {
        try {
            $indices = $this->getClient()
                ->cat()
                ->indices(array(
                    'expand_wildcards'=> 'open',
                    'format' => 'JSON',
                    'pri' => true,
                    'v' => true,
                    's' => 'index'
                ))->asString()
            ;

            return json_decode($indices, true);

        } catch (ClientResponseException $clientEx) {
            $this->logger->error($clientEx->getMessage());
        } catch (ServerResponseException $resEx) {
            $this->logger->error($resEx->getMessage());
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }

        return null;
    }

    public function saveIndicesByIndexStructureId(string $indexStructureId, string $languageId, Context $context)
    {
        $indexStructure = $this->indexStructure->getIndexStructureById($indexStructureId, $languageId, $context);
        /** @var IndexStructureTranslationEntity $indexStructureTranslation */
        foreach ($indexStructure->get('translations') as $indexStructureTranslation) {

            $this->indicesSettings->setTemplateVariable('salesChannelId', $indexStructure->getSalesChannelId());
            $this->indicesSettings->setTemplateVariable('languageId', $indexStructureTranslation->getLanguageId());
            $this->indicesSettings->setTemplateVariable('entity', $indexStructure->getEntity());

            $indexName = $this->indicesSettings->getIndexNameByTemplate();
            $indexId = $this->indicesSettings->getIndexId();

            $createBody = new CreateIndicesBody($this->settings);
            $createBody->setIndexName($indexName);
            $createBody->setIndexId($indexId);
            $this->setIndicesSettings($indexStructureTranslation->get('settings'), $createBody);
            $this->setIndicesMappings($indexStructureTranslation->get('mappings'), $createBody);

            if(!$this->checkIndicesExists($indexName)) {
                $this->createNewIndices($createBody);
            } else {
                $this->updateIndices($createBody);
            }
        }

        return null;
    }

    public function removeIndicesByIndexStructureId(string $indexStructureId, string $languageId, Context $context)
    {
        $indexStructure = $this->indexStructure->getIndexStructureById($indexStructureId, $languageId, $context);
        /** @var IndexStructureTranslationEntity $indexStructureTranslation */
        foreach ($indexStructure->get('translations') as $indexStructureTranslation) {

            $this->indicesSettings->setTemplateVariable('salesChannelId', $indexStructure->getSalesChannelId());
            $this->indicesSettings->setTemplateVariable('languageId', $indexStructureTranslation->getLanguageId());
            $this->indicesSettings->setTemplateVariable('entity', $indexStructure->getEntity());

            $indexName = $this->indicesSettings->getIndexNameByTemplate();

            if($this->checkIndicesExists($indexName)) {
                $this->removeIndices($indexName);
            }
        }

        $this->indexStructure->removeIndexStructureById($indexStructureId, $context);
    }

    protected function setIndicesSettings(array $settings, CreateIndicesBody $createBody): void
    {
        foreach ($settings as $setting) {

            if (array_key_exists('settingKey', $setting) && array_key_exists('settingValue', $setting)) {

                switch ($setting['settingKey']) {

                    case Defaults::INDICES_SETTINGS_NUMBER_SHARDS:
                        $createBody->setNumberOfShards($setting['settingValue']);
                        break;
                    case Defaults::INDICES_SETTINGS_NUMBER_REPLICAS:
                        $createBody->setNumberOfReplicas($setting['settingValue']);
                        break;
                }
            }
        }
    }

    protected function setIndicesMappings(array $mappings, CreateIndicesBody $createBody): void
    {
        $indicesMappings = array();
        foreach ($mappings as $mapping) {

            $indicesMappingProperty = new IndicesMappingProperty();
            $indicesMappingProperty->setPropertyName($mapping['key']);
            $indicesMappingProperty->setPropertyType($mapping['dataType']);

            $indicesMappings[] = $indicesMappingProperty->getProperty();
        }

        $createBody->setMappings($indicesMappings);
    }

    protected function checkIndicesExists(string $indexName): bool
    {
        try {
            return $this->getClient()->indices()->exists(array(
                'index' => $indexName
            ))->asBool();

        } catch (ClientResponseException $clientEx) {
            $this->logger->error($clientEx->getMessage());
        } catch (ServerResponseException $resEx) {
            $this->logger->error($resEx->getMessage());
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }

        return false;
    }

    protected function createNewIndices(CreateIndicesBody $createBody): array | null
    {
        try {
            $indices = $this->getClient()->create($createBody->getCreateBody());

            return json_decode($indices, true);

        } catch (ClientResponseException $clientEx) {
            $this->logger->error($clientEx->getMessage());
        } catch (ServerResponseException $resEx) {
            $this->logger->error($resEx->getMessage());
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }

        return null;
    }

    protected function updateIndices(CreateIndicesBody $createBody): array| null
    {
        try {
            $indices = $this->getClient()->update($createBody->getCreateBody());

            return json_decode($indices, true);

        } catch (ClientResponseException $clientEx) {
            $this->logger->error($clientEx->getMessage());
        } catch (ServerResponseException $resEx) {
            $this->logger->error($resEx->getMessage());
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }

        return null;
    }

    protected function removeIndices(string $indexName): bool
    {
        try {
            return $this->getClient()->indices()->delete(array(
                'index' => $indexName
            ))->asBool();

        } catch (ClientResponseException $clientEx) {
            $this->logger->error($clientEx->getMessage());
        } catch (ServerResponseException $resEx) {
            $this->logger->error($resEx->getMessage());
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }

        return false;
    }
}

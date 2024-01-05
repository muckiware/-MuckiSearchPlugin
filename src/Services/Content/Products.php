<?php

namespace MuckiSearchPlugin\Services\Content;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

class Products
{

    final const ENTITY_NAME = 'product';

    public function __construct(
        protected LoggerInterface $logger,
        protected EntityRepository $productRepository
    )
    {
    }

    public function getAllActiveProduct(string $salesChannelId): EntitySearchResult
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addAssociation('translations');
        $criteria->addAssociation('visibilities');
        $criteria->addFilter(new EqualsFilter('visibilities.salesChannelId', $salesChannelId));
        $criteria->addFilter(new MultiFilter(
            MultiFilter::CONNECTION_OR, [
                new EqualsFilter('visibilities.visibility', 20),
                new EqualsFilter('visibilities.visibility', 30)
            ]
        ));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        $criteria->addSorting(new FieldSorting('updatedAt', FieldSorting::DESCENDING));

        return $this->productRepository->search($criteria, Context::createDefaultContext());
    }

    public function getProductByProductNumber(string $productNumber, Context $context): ?ProductEntity
    {
        if (!$context) {
            $context = Context::createDefaultContext();
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('productNumber', [$productNumber]));

        $product = $this->productRepository->search($criteria, $context);
        if ($product->count() >= 1) {
            return $product->first();
        } else {
            return null;
        }
    }
}


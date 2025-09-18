<?php
declare(strict_types=1);

namespace WildDonkey\Bestsellers\Block;

use Magento\Catalog\Block\Product\AbstractProduct;
use Magento\Catalog\Block\Product\Context;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogInventory\Helper\Stock;
use Magento\Sales\Model\ResourceModel\Report\Bestsellers\CollectionFactory as BestsellersCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Zend_Db_Expr as DbExpr;

class HomeBestsellers extends AbstractProduct
{
    public function __construct(
        Context $context,
        private BestsellersCollectionFactory $bestsellersCollectionFactory,
        private ProductCollectionFactory $productCollectionFactory,
        private StoreManagerInterface $storeManager,
        private Stock $stockHelper,
        private LoggerInterface $logger,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _construct()
    {
        parent::_construct();
        $this->addData([
            'cache_lifetime' => 3600,
            'cache_tags'     => [Product::CACHE_TAG],
        ]);
    }

    public function getCacheKeyInfo(): array
    {
        $sections = (array)($this->getData('sections') ?? []);
        // Make a stable cache key from section config
        $sectionKey = json_encode($sections, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'WILDDONKEY_HOME_BESTSELLERS',
            $sectionKey,
            (string)($this->getData('period') ?? 'month'),
            (int)($this->getData('limit') ?? 8),
            (int)((bool)$this->getData('hide_out_of_stock')),
            (int)$this->storeManager->getStore()->getId(),
        ];
    }

    /**
     * Returns an array of sections:
     * [
     *   ['title' => '...', 'collection' => ProductCollection],
     *   ...
     * ]
     */
    public function getSections(): array
    {
        $sections = (array)($this->getData('sections') ?? []);
        $period   = (string)($this->getData('period') ?? 'month');
        $limit    = (int)($this->getData('limit') ?? 8);
        $hideOos  = (bool)$this->getData('hide_out_of_stock');

        $storeId = (int)$this->storeManager->getStore()->getId();
        $result  = [];

        foreach ($sections as $code => $cfg) {
            $title      = (string)($cfg['title'] ?? '');
            $categoryId = (int)($cfg['category_id'] ?? 0);
            if ($categoryId <= 0) {
                continue;
            }

            $collection = $this->loadBestsellersForCategory($categoryId, $period, $limit, $hideOos, $storeId);
            $result[] = [
                'title'      => $title,
                'categoryId' => $categoryId,
                'collection' => $collection,
            ];
        }

        return $result;
    }

    private function loadBestsellersForCategory(
        int $categoryId,
        string $period,
        int $limit,
        bool $hideOutOfStock,
        int $storeId
    ): ProductCollection {
        $productIds = [];

        try {
            // 1) Get bestseller product IDs using direct database query
            $bestsellersCollection = $this->bestsellersCollectionFactory->create();
            $resource = $bestsellersCollection->getResource();
            $connection = $resource->getConnection();

            // Get the appropriate aggregation table based on period
            $aggregationTable = $period === 'year' ? 'sales_bestsellers_aggregated_yearly' :
                               ($period === 'month' ? 'sales_bestsellers_aggregated_monthly' : 'sales_bestsellers_aggregated_daily');
            $tableName = $resource->getTable($aggregationTable);

            // Query to get bestseller product IDs for the specific category
            $sql = $connection->select()
                ->from(['main' => $tableName], ['product_id', 'qty_ordered'])
                ->join(
                    ['ccp' => $resource->getTable('catalog_category_product')],
                    'ccp.product_id = main.product_id',
                    []
                )
                ->where('main.store_id IN (?)', [0, $storeId])
                ->where('ccp.category_id = ?', $categoryId)
                ->order('main.qty_ordered DESC')
                ->limit($limit);

            $bestsellersData = $connection->fetchPairs($sql);
            $productIds = $bestsellersData ? array_keys($bestsellersData) : [];

        } catch (\Exception $e) {
            // Log the error for debugging
            $this->logger->error('Error fetching bestsellers data: ' . $e->getMessage(), [
                'category_id' => $categoryId,
                'period' => $period,
                'limit' => $limit,
                'store_id' => $storeId
            ]);

            // Return empty array to gracefully handle the error
            $productIds = [];
        }

        // 2) Load the products in the same order
        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId($storeId)
            ->addAttributeToSelect(['name', 'small_image', 'thumbnail', 'url_key'])
            ->addMinimalPrice()
            ->addFinalPrice()
            ->addTaxPercents()
            ->addUrlRewrite()
            ->addAttributeToFilter('status', Status::STATUS_ENABLED);

        $collection->setVisibility([
            Visibility::VISIBILITY_BOTH,
            Visibility::VISIBILITY_IN_CATALOG,
            Visibility::VISIBILITY_IN_SEARCH
        ]);

        if ($hideOutOfStock) {
            // For MSI-aware setups, replace with MSI joins if you need per-source logic.
            $this->stockHelper->addInStockFilterToCollection($collection);
        }

        if ($productIds) {
            $collection->addIdFilter($productIds);
            $collection->getSelect()->order(
                new DbExpr('FIELD(e.entity_id,' . implode(',', $productIds) . ')')
            );
        } else {
            // Keep template logic simple if no data yet
            $collection->addFieldToFilter('entity_id', -1);
        }

        return $collection;
    }

    public function getIdentities(): array
    {
        $ids = [Product::CACHE_TAG];
        foreach ($this->getSections() as $section) {
            /** @var ProductCollection $col */
            $col = $section['collection'];
            foreach ($col as $product) {
                $ids = array_merge($ids, $product->getIdentities());
            }
        }
        return array_values(array_unique($ids));
    }
}

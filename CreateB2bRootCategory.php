<?php
declare(strict_types=1);

namespace Ooredoo\B2bCore\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\WebsiteFactory;
use Magento\Store\Model\GroupFactory;
use Magento\Store\Model\StoreFactory;
use Magento\Store\Model\ResourceModel\Website as WebsiteResource;
use Magento\Store\Model\ResourceModel\Group as GroupResource;
use Magento\Store\Model\ResourceModel\Store as StoreResource;
use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;

class CreateB2bRootCategory implements DataPatchInterface
{
    private ModuleDataSetupInterface $moduleDataSetup;
    private CategoryFactory $categoryFactory;
    private WebsiteFactory $websiteFactory;
    private GroupFactory $groupFactory;
    private StoreFactory $storeFactory;
    private WebsiteResource $websiteResource;
    private GroupResource $groupResource;
    private StoreResource $storeResource;
    private CategoryResource $categoryResource;

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        CategoryFactory $categoryFactory,
        WebsiteFactory $websiteFactory,
        GroupFactory $groupFactory,
        StoreFactory $storeFactory,
        WebsiteResource $websiteResource,
        GroupResource $groupResource,
        StoreResource $storeResource,
        CategoryResource $categoryResource
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->categoryFactory = $categoryFactory;
        $this->websiteFactory = $websiteFactory;
        $this->groupFactory = $groupFactory;
        $this->storeFactory = $storeFactory;
        $this->websiteResource = $websiteResource;
        $this->groupResource = $groupResource;
        $this->storeResource = $storeResource;
        $this->categoryResource = $categoryResource;
    }

    public function apply()
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        try {
            // Step 1: Create Root Category FIRST
            $rootCategory = $this->createRootCategory();
            if (!$rootCategory || !$rootCategory->getId()) {
                throw new \Exception('Failed to create or load root category');
            }
            
            // Step 2: Create Website
            $website = $this->createWebsite();
            if (!$website || !$website->getId()) {
                throw new \Exception('Failed to create or load website');
            }
            
            // Step 3: Create Store Group
            $storeGroup = $this->createStoreGroup($website->getId(), $rootCategory->getId());
            if (!$storeGroup || !$storeGroup->getId()) {
                throw new \Exception('Failed to create or load store group');
            }
            
            // Step 4: Create Stores
            $this->createStores($website->getId(), $storeGroup->getId());
            
        } catch (\Exception $e) {
            throw new \Exception('Failed to create B2B website structure: ' . $e->getMessage());
        }

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    private function createWebsite()
    {
        $website = $this->websiteFactory->create();
        
        // Check if website already exists
        $this->websiteResource->load($website, 'b2b', 'code');
        
        if (!$website->getId()) {
            $website->setCode('b2b')
                ->setName('B2B')
                ->setIsDefault(0)
                ->setSortOrder(1);
            
            $this->websiteResource->save($website);
        }
        
        return $website;
    }

    private function createRootCategory()
    {
        $rootCategory = $this->categoryFactory->create();
        
        // Check if category already exists by name
        $collection = $rootCategory->getCollection()
            ->addAttributeToFilter('name', 'B2B Root Category')
            ->addAttributeToFilter('level', 1);
        
        if ($collection->getSize() > 0) {
            return $collection->getFirstItem();
        }

        // Set basic category data
        $rootCategory->setName('B2B Root Category')
            ->setIsActive(1) // Changed from true to 1
            ->setParentId(1) // Default root category ID
            ->setIncludeInMenu(1) // Changed from true to 1
            ->setLevel(1)
            ->setPosition(2)
            ->setAvailableSortBy('position,name') // Changed from array to string
            ->setDefaultSortBy('position')
            ->setDisplayMode('PRODUCTS')
            ->setIsAnchor(0)
            ->setStoreId(0); // Changed from Store::DEFAULT_STORE_ID to 0

        // Save the category first to get the ID
        $this->categoryResource->save($rootCategory);
        
        // Update the path after getting the ID
        $path = '1/' . $rootCategory->getId();
        $rootCategory->setPath($path);
        $this->categoryResource->save($rootCategory);
        
        return $rootCategory;
    }

    private function createStoreGroup($websiteId, $rootCategoryId)
    {
        $storeGroup = $this->groupFactory->create();
        
        // Check if store group already exists
        $collection = $storeGroup->getCollection()
            ->addFieldToFilter('name', 'B2B Store')
            ->addFieldToFilter('website_id', $websiteId);
        
        if ($collection->getSize() > 0) {
            return $collection->getFirstItem();
        }

        $storeGroup->setName('B2B Store')
            ->setWebsiteId($websiteId)
            ->setRootCategoryId($rootCategoryId);
            // Removed setDefaultStoreId(0) - will be set later when stores are created

        $this->groupResource->save($storeGroup);
        
        return $storeGroup;
    }

    private function createStores($websiteId, $storeGroupId)
    {
        $stores = [
            [
                'code' => 'b2b_ar',
                'name' => 'B2B Arabic Store',
                'is_active' => 1,
                'sort_order' => 1
            ],
            [
                'code' => 'b2b_en', 
                'name' => 'B2B English Store',
                'is_active' => 1,
                'sort_order' => 2
            ]
        ];

        $defaultStoreId = null;

        foreach ($stores as $storeData) {
            $store = $this->storeFactory->create();
            
            // Check if store already exists
            $this->storeResource->load($store, $storeData['code'], 'code');
            
            if (!$store->getId()) {
                $store->setCode($storeData['code'])
                    ->setName($storeData['name'])
                    ->setWebsiteId($websiteId)
                    ->setGroupId($storeGroupId)
                    ->setIsActive($storeData['is_active'])
                    ->setSortOrder($storeData['sort_order']);
                
                $this->storeResource->save($store);
            }
            
            // Set first store as default for the group (whether new or existing)
            if ($defaultStoreId === null) {
                $defaultStoreId = $store->getId();
            }
        }

        // Update store group with default store
        if ($defaultStoreId) {
            $storeGroup = $this->groupFactory->create();
            $this->groupResource->load($storeGroup, $storeGroupId);
            $storeGroup->setDefaultStoreId($defaultStoreId);
            $this->groupResource->save($storeGroup);
        }
    }

    public function getAliases(): array
    {
        return [];
    }

    public static function getDependencies(): array
    {
        return [];
    }
}
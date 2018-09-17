<?php


/**
 * @category     K2service
 * @package      K2service_Patch
 * @author       K2-Service.com <support@k2-service.com>
 * @copyright    2016 K2-Service
 */

class K2service_Patch_Model_Resource_Catalog_Product_Indexer_Eav_Source
    extends Mage_Catalog_Model_Resource_Product_Indexer_Eav_Source
{
    /**
     * Prepare data index for indexable multiply select attributes
     *
     * @param array $entityIds   the entity ids limitation
     * @param int   $attributeId the attribute id limitation
     *
     * @return Mage_Catalog_Model_Resource_Product_Indexer_Eav_Source
     */
    protected function _prepareMultiselectIndex($entityIds = null, $attributeId = null)
    {
       $adapter = $this->_getWriteAdapter();

        // prepare multiselect attributes
        if (is_null($attributeId)) {
            $attrIds = $this->_getIndexableAttributes(true);
        } else {
            $attrIds = [$attributeId];
        }

        if (!$attrIds) {
            return $this;
        }

        // load attribute options
        $options = [];
        $select = $adapter->select()
            ->from($this->getTable('eav/attribute_option'), ['attribute_id', 'option_id'])
            ->where('attribute_id IN(?)', $attrIds);
        $query = $select->query();
        while ($row = $query->fetch()) {
            $options[$row['attribute_id']][$row['option_id']] = true;
        }


        // prepare get multiselect values query
        $productValueExpression = $adapter->getCheckSql('pvs.value_id > 0', 'pvs.value', 'pvd.value');
        $select = $adapter->select()
            ->from(
                ['pvd' => $this->getValueTable('catalog/product', 'text')],
                ['entity_id', 'attribute_id'])
            ->join(
                ['cs' => $this->getTable('core/store')],
                '',
                ['store_id'])
            ->joinLeft(
                ['pvs' => $this->getValueTable('catalog/product', 'text')],
                'pvs.entity_id = pvd.entity_id AND pvs.attribute_id = pvd.attribute_id'
                . ' AND pvs.store_id=cs.store_id',
                ['value' => $productValueExpression])
            ->where('pvd.store_id=?',
                $adapter->getIfNullSql('pvs.store_id', Mage_Catalog_Model_Abstract::DEFAULT_STORE_ID))
            ->where('cs.store_id!=?', Mage_Catalog_Model_Abstract::DEFAULT_STORE_ID)
            ->where('pvd.attribute_id IN(?)', $attrIds);

        $statusCond = $adapter->quoteInto('=?', Mage_Catalog_Model_Product_Status::STATUS_ENABLED);
        $this->_addAttributeToSelect($select, 'status', 'pvd.entity_id', 'cs.store_id', $statusCond);

        if (!is_null($entityIds)) {
            $select->where('pvd.entity_id IN(?)', $entityIds);
        }

        /**
         * Add additional external limitation
         */
        Mage::dispatchEvent('prepare_catalog_product_index_select', [
            'select'        => $select,
            'entity_field'  => new Zend_Db_Expr('pvd.entity_id'),
            'website_field' => new Zend_Db_Expr('cs.website_id'),
            'store_field'   => new Zend_Db_Expr('cs.store_id')
        ]);

        /** CODE FOR FIX ISSUE WITH MULTI SELECT ATTRIBUTES WITH CUSTOM SOURCE MODELS  */
        /** @var array $customOptions */
        $options += $this->_getMultiSelectAttributeWithSourceModels($attrIds);

        /** CODE FOR FIX ISSUE WITH MULTI SELECT ATTRIBUTES WITH CUSTOM SOURCE MODELS END  */

        $i = 0;
        $data = [];
        $query = $select->query();

        while ($row = $query->fetch()) {
            $values = array_unique(explode(',', $row['value']));
            foreach ($values as $valueId) {
                //skip not numeric values
                if(!is_numeric($valueId)){
                    continue;
                }
                if (isset($options[$row['attribute_id']][$valueId])) {
                    $data[] = [
                        $row['entity_id'],
                        $row['attribute_id'],
                        $row['store_id'],
                        $valueId
                    ];

                    $i++;
                    if ($i % 10000 == 0) {
                        $this->_saveIndexData($data);
                        $data = [];
                    }
                }
            }
        }
        $this->_saveIndexData($data);
        unset($options);
        unset($data);

        return $this;
    }

    /**
     * Get all possible options for multiselect attributes with custom source models.
     *
     * @param $attrIds
     *
     * @return array
     */
    protected function _getMultiSelectAttributeWithSourceModels($attrIds)
    {
        $attributes = [];

        $defaultSourceModel = Mage::getResourceModel('catalog/product')->getDefaultAttributeSourceModel();

        $adapter = $this->_getReadAdapter();
        /** Select all multi select attributes with custom source model */
        $select = $adapter->select()
            ->from(
                $this->getTable('eav/attribute'),
                ['attribute_id', 'source_model']
            )
            ->where('attribute_id IN(?)', $attrIds)
            ->where('frontend_input = ?', 'multiselect')
            ->where('source_model != ?', $defaultSourceModel);

        $query = $select->query();
        while ($row = $query->fetch()) {
            if (empty($row['source_model'])) {
                continue;
            }
            /** @var Mage_Eav_Model_Entity_Attribute_Source_Abstract $sourceModel */
            $sourceModel = Mage::getModel($row['source_model']);
            $options = $sourceModel->getAllOptions();

            foreach ($options as $_option) {
                $attributes[$row['attribute_id']][$_option['value']] = true;
            }
        }

        return $attributes;
    }
}

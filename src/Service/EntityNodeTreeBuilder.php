<?php

namespace MightySyncer\Service;


use LogicException;
use MightySyncer\Entity\EntityNode;
use MightySyncer\Entity\EntityNodeCollection;
use MightySyncer\Importer\Options\EntityOptions;

/**
 * Class EntityNodeTreeBuilder builds nodes tree collection from entity options
 * @package MightySyncer\Service
 */
class EntityNodeTreeBuilder
{
    /**
     * Build EntityNodes tree for given entities
     * @param EntityOptions[] $entities
     * @return EntityNodeCollection
     */
    public function build(array $entities): EntityNodeCollection
    {
        $collection = new EntityNodeCollection();

        $this->addNodesToCollection($entities, $collection);
        $this->setNodeRelations($collection);

        return $collection;
    }

    /**
     * @param EntityNodeCollection $collection
     */
    protected function setNodeRelations(EntityNodeCollection $collection): void
    {
        /** @var EntityNode $node */
        foreach ($collection as $node) {
            if ($node->getData()->required) {
                foreach ($node->getData()->required as $refName) {
                    /** @var EntityNode $refNode */
                    $refNode = $collection->get($refName);

                    if ($refNode === null) {
                        throw new LogicException(sprintf(
                            'Failed to find referenced entity "%s" in "%s"',
                            $refName,
                            $node->getId()
                        ));
                    }

                    $node->addParent($refNode);
                }
            }
        }
    }

    /**
     * @param array $entities
     * @param EntityNodeCollection $collection
     */
    protected function addNodesToCollection(array $entities, EntityNodeCollection $collection): void
    {
        /** @var EntityOptions $entity */
        foreach ($entities as $entity) {
            $node = new EntityNode($entity);
            $node->setId($entity->name);

            $collection->set($node->getId(), $node);
        }
    }


}
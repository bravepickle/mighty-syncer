<?php

namespace MightySyncer\Entity;


use Doctrine\Common\Collections\ArrayCollection;

/**
 * Class EntityNodeCollection
 * @package MightySyncer\Entity
 */
class EntityNodeCollection extends ArrayCollection
{
    /**
     * Sort collection by dependencies
     */
    public function sortByRelations(): EntityNodeCollection
    {
        $elements = $this->toArray();
        usort($elements, static function(EntityNode $a, EntityNode $b) {
            if ($a->hasAncestor($b)) {
                return 1;
            }

            if ($b->hasAncestor($a)) {
                return -1;
            }

            return 0;
        });

        return new static($elements);
    }

    /**
     * Generate collection that has only nodes related to given keys
     * @param array $keys
     * @return EntityNodeCollection
     */
    public function filterKeysAndRelations(array $keys): EntityNodeCollection
    {
        return $this->filter(static function(EntityNode $node, $key) use ($keys) {
            if (in_array($key, $keys, true)) {
                return true;
            }

            foreach ($keys as $cmpKey) {
                 if ($node->isAncestorById($cmpKey)) {
                    return true;
                 }
            }

            return false;
        });
    }
}
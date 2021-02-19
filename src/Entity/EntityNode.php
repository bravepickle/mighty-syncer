<?php

namespace MightySyncer\Entity;


use Closure;
use MightySyncer\Exception\InvalidArgumentException;

/**
 * Class EntityNode
 * @package MightySyncer\Entity
 */
class EntityNode
{
    public const KEY_DEPTH = 'depth';
    public const KEY_CHAIN = 'chain';

    /**
     * @var mixed
     */
    protected $id;

    /**
     * @var mixed
     */
    protected $data;

    /**
     * @var EntityNode[]
     */
    protected $parents = [];

    /**
     * @var EntityNode[]
     */
    protected $children = [];

    /**
     * EntityNode constructor.
     * @param mixed $data
     * @param string|int|null $id
     */
    public function __construct($data = null, $id = null)
    {
        $this->data = $data;
        $this->id = $id;
    }

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param mixed $id
     * @return EntityNode
     */
    public function setId($id): EntityNode
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @return bool
     */
    public function isRoot(): bool
    {
        return !$this->parents;
    }

    /**
     * @return mixed
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * @param mixed $data
     * @return EntityNode
     */
    public function setData($data): EntityNode
    {
        $this->data = $data;

        return $this;
    }

    /**
     * @return EntityNode[]
     */
    public function getParents(): array
    {
        return $this->parents;
    }

    /**
     * @param EntityNode[] $parents
     * @return EntityNode
     */
    public function setParents(array $parents): EntityNode
    {
        $this->parents = [];
        foreach ($parents as $parent) {
            $this->addParent($parent);
        }

        return $this;
    }

    /**
     * @param EntityNode $node
     * @return $this
     */
    public function addParent(EntityNode $node): EntityNode
    {
        if (!$node->getId()) {
            throw new InvalidArgumentException('Node has undefined ID.');
        }

        if ($this->hasParent($node)) {
            return $this; // already added
        }

        $this->parents[$node->getId()] = $node;

        if (!$node->hasChild($this)) {
            $node->addChild($this);
        }

        return $this;
    }

    /**
     * @param EntityNode $node
     * @return bool
     */
    public function hasParent(EntityNode $node): bool
    {
        return isset($this->parents[$node->getId()]);
    }

    /**
     * @param EntityNode $node
     * @return bool
     */
    public function hasAncestor(EntityNode $node): bool
    {
        if ($node->getId() !== $this->getId()) { // not current node
            foreach ($this->getParents() as $parent) {
                if ($parent->getId() === $node->getId() || $parent->hasAncestor($node)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return EntityNode[]
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    /**
     * @param EntityNode[] $children
     * @return EntityNode
     */
    public function setChildren(array $children): EntityNode
    {
        $this->children = [];
        foreach ($children as $child) {
            $this->addChild($child);
        }

        return $this;
    }

    /**
     * @param EntityNode $node
     * @return $this
     */
    public function addChild(EntityNode $node): EntityNode
    {
        if (!$node->getId()) {
            throw new InvalidArgumentException('Node has undefined ID.');
        }

        if ($this->hasChild($node)) {
            return $this; // already added
        }

        $this->children[$node->getId()] = $node;

        if (!$node->hasParent($this)) {
            $node->addParent($this);
        }

        return $this;
    }

    /**
     * @param EntityNode $node
     * @return bool
     */
    public function hasChild(EntityNode $node): bool
    {
        return isset($this->children[$node->getId()]);
    }

    /**
     * @param mixed $id
     * @return bool
     */
    public function isAncestorById($id): bool
    {
        if ($id !== $this->getId()) { // not current node
            foreach ($this->getChildren() as $child) {
                if ($child->getId() === $id || $child->isAncestorById($id)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Nodes relate to each other as siblings - have common parents
     *
     * @param EntityNode $testNode
     * @return bool
     */
    public function isSibling(EntityNode $testNode): bool
    {
        foreach ($testNode->getParents() as $testParent) {
            if ($this->hasParent($testParent)) {
                return true; // nodes have one or more common direct parents
            }
        }

        return false;
    }

    /**
     * Get all assoc root nodes for node. If is root then return itself.
     * List cannot be empty unless recursion found
     *
     * @return EntityNode[]
     */
    public function getRoots(): array
    {
        if ($this->isRoot()) {
            return [$this->getId() => $this];
        }

        $roots = [];
        $this->walkAncestors(static function (EntityNode $node) use (&$roots) {
            if ($node->isRoot()) {
                $roots[$node->getId()] = $node;
            }
        });

        return $roots;
    }

    /**
     * Return list of flattened set of sequences of ancestors for current node
     *
     * Example:
     *   [
     *      ['mother', 'mother's grand father'],
     *      ['mother', 'mother's grand mother'],
     *      ['father', 'father's grand father'],
     *   ]
     * @return array
     */
    public function getAncestorTreeFlattened(): array
    {
        if ($this->isRoot()) {
            return [];
        }

        $tree = [];
        $this->walkAncestors(
            static function (EntityNode $node, array $sequence) use (&$tree) {
                $sequence[] = $node;

                if ($node->isRoot()) { // add sequence when root is reached
                    $tree[] = $sequence;
                }

                return $sequence;
            },
            []
        );

        return $tree;
    }

    /**
     * Get all node depths as assoc array based on available parents, where key is root
     *
     * @return EntityNode[]
     */
    public function getDepths(): array
    {
        $chains = $this->getAncestorTreeFlattened();
        $depths = [];

        foreach ($chains as $chain) {
            $ids = [];
            $depth = 0;
            /** @var EntityNode $node */
            foreach ($chain as $node) {
                $ids[] = $node->getId();
                ++$depth;
            }

            $depths[] = [self::KEY_CHAIN => $ids, self::KEY_DEPTH => $depth];
        }

        return $depths;
    }

    /**
     * Walk all ancestors back to root and execute function and pass previous results to function (similar to "reduce")
     *
     * @param Closure $fn
     * @param mixed $previousValue
     * @param bool $skipOnFalseReturn set to TRUE to skip running recursive calls when callable function returns FALSE
     */
    public function walkAncestors(Closure $fn, $previousValue = null, bool $skipOnFalseReturn = false): void
    {
        foreach ($this->getParents() as $parent) {
            $newValue = $fn($parent, $previousValue);

            if ($skipOnFalseReturn === true && $newValue === false) {
                continue;
            }

            $parent->walkAncestors($fn, $newValue, $skipOnFalseReturn); // pass function to grand parents to execute
        }
    }

    /**
     * Walk all children and execute function and pass previous results to function (similar to "reduce")
     *
     * @param Closure $fn
     * @param mixed $previousValue
     * @param bool $stopOnFalseReturn set to TRUE to stop running recursive calls when callable function returns FALSE
     */
    public function walkChildren(Closure $fn, $previousValue = null, bool $stopOnFalseReturn = false): void
    {
        foreach ($this->getChildren() as $child) {
            $newValue = $fn($child, $previousValue); // call function for given parent node

            if ($stopOnFalseReturn === true && $newValue === false) {
                return;
            }

            $child->walkChildren($fn, $newValue, $stopOnFalseReturn);
        }
    }

    /**
     * Walk all descendants and execute closure
     *
     * @param Closure $fn
     * @param mixed $previousValue
     * @param bool $skipOnFalseReturn set to TRUE to skip running recursive calls when callable function returns FALSE
     */
    public function walkDescendants(Closure $fn, $previousValue = null, bool $skipOnFalseReturn = false): void
    {
        foreach ($this->getChildren() as $child) {
            $newValue = $fn($child, $previousValue); // call function for given parent node

            if ($skipOnFalseReturn === true && $newValue === false) {
                continue;
            }

            $child->walkDescendants($fn, $newValue, $skipOnFalseReturn);
        }
    }
}
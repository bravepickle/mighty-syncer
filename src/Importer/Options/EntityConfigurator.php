<?php

namespace MightySyncer\Importer\Options;


use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EntityConfigurator extends AbstractConfigurator
{
    public function configure(): OptionsResolver
    {
        $resolver = new OptionsResolver();
        $resolver->setRequired(['name', 'mapping', 'dateCheckField', ]);
        $this->setDefaults($resolver);
        $this->setAllowedTypes($resolver);
        $this->setAllowedValues($resolver);
        $this->addNormalizers($resolver);

        return $resolver;
    }

    /**
     * @param OptionsResolver $resolver
     */
    protected function setDefaults(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'onDelete' => EntityOptions::ACTION_IGNORE,
            'onDeleteSet' => [],

            'onUpdate' => EntityOptions::ACTION_IGNORE,
            'onUpdateSet' => [],

            'onInsert' => EntityOptions::ACTION_UPDATE,
            'onInsertSet' => [],

            'onConflict' => EntityOptions::ACTION_IGNORE,
            'onConflictSet' => [],
            'conflictsField' => EntityOptions::DEFAULT_CONFLICTS_FIELD,

            'identifier' => EntityOptions::DEFAULT_IDENTIFIER,
            'dateCheckField' => EntityOptions::DEFAULT_DATE_CHECK_FIELD,

            'required' => [],
            'config' => [],
            'unique' => [],
            'sourceName' => null,

            'softDeletable' => false,
            'softDeleteField' => EntityOptions::DEFAULT_SOFT_DELETE_FIELD,
        ]);
    }

    /**
     * @param OptionsResolver $resolver
     */
    protected function setAllowedTypes(OptionsResolver $resolver): void
    {
        $resolver->setAllowedTypes('mapping', 'array');
        $resolver->setAllowedTypes('config', 'array');
        $resolver->setAllowedTypes('required', ['array', 'string']);
        $resolver->setAllowedTypes('unique', 'array');
        $resolver->setAllowedTypes('sourceName', ['null', 'string']);
        $resolver->setAllowedTypes('softDeletable', 'bool');
        $resolver->setAllowedTypes('softDeleteField', 'string');
        $resolver->setAllowedTypes('onUpdateSet', 'array');
        $resolver->setAllowedTypes('onInsertSet', 'array');
        $resolver->setAllowedTypes('onDeleteSet', 'array');
        $resolver->setAllowedTypes('onConflictSet', 'array');
        $resolver->setAllowedTypes( 'conflictsField', 'string');
        $resolver->setAllowedTypes( 'dateCheckField', 'string');
        $resolver->setAllowedTypes('identifier', 'string');
        $resolver->setAllowedTypes('name', 'string');
    }

    /**
     * @param OptionsResolver $resolver
     */
    protected function setAllowedValues(OptionsResolver $resolver): void
    {
        $resolver->setAllowedValues('onDelete', [
            EntityOptions::ACTION_IGNORE,
            EntityOptions::ACTION_UPDATE,
            EntityOptions::ACTION_DELETE,
        ]);
        $resolver->setAllowedValues('onConflict', [
            EntityOptions::ACTION_IGNORE,
            EntityOptions::ACTION_UPDATE,
            EntityOptions::ACTION_DELETE,
            EntityOptions::ACTION_ABORT,
        ]);
        $resolver->setAllowedValues('onUpdate', [EntityOptions::ACTION_IGNORE, EntityOptions::ACTION_UPDATE,]);
        $resolver->setAllowedValues('onInsert', [EntityOptions::ACTION_IGNORE, EntityOptions::ACTION_UPDATE,]);
    }

    /**
     * @param OptionsResolver $resolver
     */
    protected function addNormalizers(OptionsResolver $resolver): void
    {
        $resolver->addNormalizer('mapping', static function (Options $options, $mapping) {
            foreach ($mapping as $sourceField => $targetField) {
                if ($targetField === null) {
                    $mapping[$sourceField] = $sourceField; // target field has same name as source
                }
            }

            return $mapping;
        });

        $resolver->addNormalizer('onUpdateSet', static function (Options $options, $value) {
            if ($options['onUpdate'] !== EntityOptions::ACTION_UPDATE) {
                return []; // reset values
            }

            return $value;
        });

        $resolver->addNormalizer('onInsertSet', static function (Options $options, $value) {
            if ($options['onInsert'] !== EntityOptions::ACTION_UPDATE) {
                return []; // reset values
            }

            return $value;
        });

        $resolver->addNormalizer('required', static function (Options $options, $value) {
            return (array)$value;
        });

        $resolver->addNormalizer('sourceName', static function (Options $options, $value) {
            return $value ?? $options['name'];
        });
    }

}
<?php

namespace MightySyncer\Importer\Options;


use Symfony\Component\OptionsResolver\OptionsResolver;

abstract class AbstractConfigurator
{
    abstract public function configure(): OptionsResolver;
}
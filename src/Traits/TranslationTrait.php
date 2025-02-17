<?php

namespace UnzerPayment\Traits;

use Plenty\Plugin\Translation\Translator;

trait TranslationTrait
{
    protected Translator $translator;

    public function getTranslation(string $variable)
    {
        if (empty($this->translator)) {
            $this->translator = pluginApp(Translator::class);
        }
        return $this->translator->trans('UnzerPayment::' . $variable);
    }
}
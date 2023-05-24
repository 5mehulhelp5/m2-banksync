<?php

namespace Ibertrand\BankSync\Controller\Adminhtml\TempTransaction;

class AutoBook extends MassBook
{
    protected function getIds(): array|null
    {
        return !empty($this->getRequest()->getParam('id'))
            ? [$this->getRequest()->getParam('id')]
            : null;
    }
}

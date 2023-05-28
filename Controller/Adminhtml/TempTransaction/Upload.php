<?php

namespace Ibertrand\BankSync\Controller\Adminhtml\TempTransaction;

use Exception;
use Magento\Backend\App\Action;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\MediaStorage\Model\File\UploaderFactory;

class Upload extends Action
{
    protected UploaderFactory $fileUploaderFactory;
    protected WriteInterface $varDirectory;

    public function __construct(
        Action\Context  $context,
        Filesystem      $filesystem,
        UploaderFactory $fileUploaderFactory,
    ) {
        $this->varDirectory = $filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $this->fileUploaderFactory = $fileUploaderFactory;
        parent::__construct($context);
    }

    public function execute()
    {
        $target = $this->varDirectory->getAbsolutePath('tmp/banksync');
        try {
            $uploader = $this->fileUploaderFactory->create(['fileId' => 'import_file']);
            $uploader->setAllowedExtensions(['csv']); // Set allowed file extensions
            $uploader->setAllowRenameFiles(true);
            $uploader->setFilesDispersion(false);
            $result = $uploader->save($target);
            return $this->resultFactory->create(ResultFactory::TYPE_JSON)
                ->setData($result);
        } catch (Exception $e) {
            $resultJson = $this->resultFactory->create(ResultFactory::TYPE_JSON);
            $resultJson->setData(['error' => $e->getMessage(), 'errorcode' => $e->getCode()]);
            return $resultJson;
        }
    }
}

<?php

namespace Ibertrand\BankSync\Model;

use Exception;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Class AbstractRepository
 */
class AbstractRepository
{
    protected object $objectFactory;
    protected object $objectResourceModel;
    protected object $collectionFactory;

    /**
     * @param $object
     *
     * @return mixed
     * @throws CouldNotSaveException
     */
    public function save($object)
    {
        try {
            $this->objectResourceModel->save($object);
        } catch (Exception $e) {
            throw new CouldNotSaveException(__($e->getMessage()));
        }
        return $object;
    }

    /**
     * @param int $id
     *
     * @return mixed
     * @throws NoSuchEntityException
     */
    public function getById(int $id)
    {
        $object = $this->objectFactory->create();
        $this->objectResourceModel->load($object, $id);
        if (!$object->getId()) {
            throw new NoSuchEntityException(__('Object with id "%1" does not exist.', $id));
        }
        return $object;
    }

    /**
     * @param $object
     *
     * @return bool
     * @throws CouldNotDeleteException
     */
    public function delete($object): bool
    {
        try {
            $this->objectResourceModel->delete($object);
        } catch (Exception $exception) {
            throw new CouldNotDeleteException(__($exception->getMessage()));
        }
        return true;
    }

    /**
     * @param $id
     *
     * @return bool
     * @throws CouldNotDeleteException
     * @throws NoSuchEntityException
     */
    public function deleteById($id): bool
    {
        return $this->delete($this->getById($id));
    }

    /**
     * @return void
     * @throws CouldNotDeleteException
     */
    public function deleteAll()
    {
        $collection = $this->collectionFactory->create();
        foreach ($collection as $item) {
            $this->delete($item);
        }
    }
}

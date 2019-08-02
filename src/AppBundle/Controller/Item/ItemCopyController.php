<?php

namespace AppBundle\Controller\Item;

use AppBundle\Entity\FileAttachment;
use AppBundle\Entity\Image;
use AppBundle\Entity\InventoryItem;
use AppBundle\Entity\ItemMovement;
use AppBundle\Entity\Note;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Doctrine\ORM\EntityRepository;

class ItemCopyController extends Controller
{
    protected $id;

    /**
     * @Route("admin/item/{id}/copy", name="item_copy", requirements={"id": "\d+"})
     */
    public function copyProductAction(Request $request, $id)
    {
        $newItemId = null;
        $em = $this->getDoctrine()->getManager();

        /** @var $oldProduct \AppBundle\Entity\InventoryItem */
        $oldProduct = $em->getRepository('AppBundle:InventoryItem')->find($id);

        $copies = $request->get('numberOfCopies');
        if (!$copies) {
            $copies = 1;
        }
        for ($n=0; $n<$copies; $n++) {
            $newItemId = $this->copyProduct($oldProduct);
        }

        if ($newItemId) {
            $this->addFlash("success", "Item copied OK");
            return $this->redirectToRoute('item', array('id' => $newItemId));
        } else {
            $this->addFlash("error", "Item failed to copy");
            return $this->redirectToRoute('item', array('id' => $oldProduct->getId()));
        }
    }

    /**
     * @param InventoryItem $oldProduct
     * @return bool|int
     */
    private function copyProduct(InventoryItem $oldProduct) {

        $user = $this->getUser();
        $em   = $this->getDoctrine()->getManager();

        try {

            /** @var $newProduct \AppBundle\Entity\InventoryItem */
            $newProduct = clone $oldProduct;

            $em->detach($newProduct);
            $newProduct->setId(null);

            $newProduct->setCreatedBy($user);

            /** @var \AppBundle\Entity\InventoryLocation $location */
            $locationRepo = $em->getRepository('AppBundle:InventoryLocation');
            $location = $locationRepo->find(2);

            $transactionRow = new ItemMovement();
            $transactionRow->setInventoryItem($newProduct);
            $transactionRow->setInventoryLocation($location);
            $transactionRow->setCreatedBy($user);

            $em->persist($transactionRow);

            $newProduct->setInventoryLocation($location);
            $newProduct->setImageName(null);

            $note = new Note();
            $note->setCreatedBy($user);
            $note->setText('Added item to <strong>'.$location->getName().'</strong>');
            $note->setInventoryItem($newProduct);
            $em->persist($note);

            // Set initial field value if auto-sku is turned on
            $skuStub = $this->get('service.tenant')->getCodeStub();
            if ($skuStub) {
                $sku = $this->generateAutoSku($skuStub);
                $newProduct->setSku($sku);
            }

            $newProduct->setImageName($oldProduct->getImageName());

            // Clear the serial number
            $newProduct->setSerial('');

            // Copy all images (referencing the same file on S3)
            $newProduct->setImages(new ArrayCollection());
            foreach($oldProduct->getImages() AS $image) {
                /** @var $image \AppBundle\Entity\Image */
                $newImage = new Image();
                $newImage->setImageName($image->getImageName());
                $newImage->setInventoryItem($newProduct);
                // Images are set to cascade persist when we save an item
                $newProduct->addImage($newImage);
            }

            // Copy all attachments (referencing the same file on S3)
            foreach($oldProduct->getFileAttachments() AS $file) {
                /** @var $image \AppBundle\Entity\FileAttachment */
                $newFileAttachment = new FileAttachment();
                $newFileAttachment->setInventoryItem($newProduct);
                $newFileAttachment->setFileName($file->getFileName());
                $newFileAttachment->setFileSize($file->getFileSize());
                $newFileAttachment->setSendToMemberOnCheckout($file->getSendToMemberOnCheckout());
                $newProduct->addFileAttachment($newFileAttachment);
            }

            /** @var \AppBundle\Entity\ProductFieldValue $fieldValue */
            $newProduct->setFieldValues(new ArrayCollection());
            foreach ($oldProduct->getFieldValues() AS $fieldValue) {
                $newFieldValue = clone($fieldValue);
                $em->detach($newFieldValue);
                $newFieldValue->setInventoryItem($newProduct);
                $newProduct->addFieldValue($fieldValue);
                $em->persist($newFieldValue);
            }

            $em->persist($newProduct);
            $em->flush();
            return $newProduct->getId();
        } catch (\Exception $generalException) {
            return false;
        }

    }

    /**
     *
     * THIS IS REPLICATED IN ItemController
     * @todo move to inventory or item service
     *
     * This won't work at high throughput; it's not transactional
     * Also assumes that user has got all 4-digit SKUs; will break with 3 digits
     * unless we add the REGEX doctrine extension to only search for latest 4-digit code
     * @param $stub
     * @return string
     */
    private function generateAutoSku($stub)
    {
        $lastSku = $stub;

        /** @var \AppBundle\Repository\InventoryItemRepository $itemRepo */
        $itemRepo = $this->getDoctrine()->getRepository('AppBundle:InventoryItem');
        $builder = $itemRepo->createQueryBuilder('i');
        $builder->add('select', 'MAX(i.sku) AS sku');
        $builder->where("i.sku like '{$stub}%' AND i.isActive = 1");
        $query = $builder->getQuery();
        if ( $results = $query->getResult() ) {
            $lastSku = $results[0]['sku'];
        }
        $id = (int)str_replace($stub, '', $lastSku);
        $id++;
        $newSku = $stub.str_pad($id, 4, '0', STR_PAD_LEFT);
        return $newSku;
    }

}
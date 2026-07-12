<?php

namespace App\EventListener;

use App\Entity\ForumImage;
use App\Service\ForumImageUploader;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Events;

/**
 * Supprime le fichier physique quand une ForumImage est retirée (directement ou en cascade
 * lors de la suppression d'une discussion/réponse) → pas de fichiers orphelins sur le disque.
 */
#[AsEntityListener(event: Events::postRemove, method: 'postRemove', entity: ForumImage::class)]
final class ForumImageListener
{
    public function __construct(private readonly ForumImageUploader $uploader)
    {
    }

    public function postRemove(ForumImage $image, PostRemoveEventArgs $args): void
    {
        if ($image->getFilename() !== null) {
            $this->uploader->delete($image->getFilename());
        }
    }
}

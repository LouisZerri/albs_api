<?php

namespace App\Service;

use App\Entity\ForumImage;
use App\Entity\Line;
use App\Entity\LineDiscussion;
use App\Entity\LineDiscussionReply;
use App\Entity\User;
use App\Repository\LineDiscussionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Forum : discussions par ligne (ou générales) + réponses + images.
 * Réponses construites à la main pour n'exposer que le pseudo/avatar de l'auteur.
 * Modération (épingler/verrouiller) = web. Images stockées dans le dossier du web.
 */
final class ForumService
{
    public function __construct(
        private readonly LineDiscussionRepository $discussions,
        private readonly ForumImageUploader $uploader,
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(WEB_PUBLIC_URL)%')]
        private readonly string $webPublicUrl,
    ) {
    }

    /**
     * @return array{items:list<array<string,mixed>>, total:int, page:int, perPage:int}
     */
    public function lineDiscussions(Line $line, int $page = 1): array
    {
        return $this->paginated($this->discussions->findByLineOrdered($line, $page), $this->discussions->countByLine($line), $page);
    }

    /**
     * @return array{items:list<array<string,mixed>>, total:int, page:int, perPage:int}
     */
    public function generalDiscussions(int $page = 1): array
    {
        return $this->paginated($this->discussions->findGeneralOrdered($page), $this->discussions->countGeneral(), $page);
    }

    /**
     * Assemble une page. Les compteurs de réponses sont récupérés en UNE requête (pas de N+1).
     *
     * @param LineDiscussion[] $discussions
     * @return array{items:list<array<string,mixed>>, total:int, page:int, perPage:int}
     */
    private function paginated(array $discussions, int $total, int $page): array
    {
        $ids = array_map(static fn (LineDiscussion $d) => $d->getId(), $discussions);
        $counts = $this->discussions->replyCounts($ids);

        return [
            'items' => array_map(fn (LineDiscussion $d) => $this->summary($d, $counts[$d->getId()] ?? 0), $discussions),
            'total' => $total,
            'page' => max(1, $page),
            'perPage' => LineDiscussionRepository::PER_PAGE,
        ];
    }

    /**
     * Détail d'une discussion + réponses + images. Incrémente les vues par défaut.
     *
     * @return array<string, mixed>
     */
    public function detail(LineDiscussion $discussion, bool $countView = true): array
    {
        if ($countView) {
            $discussion->incrementViewCount();
            $this->em->flush();
        }

        $data = $this->summary($discussion, $discussion->getReplies()->count());
        $data['content'] = $discussion->getContent();
        $data['images'] = $this->imagesOf($discussion->getImages());
        $data['replies'] = array_map($this->replyArray(...), $discussion->getReplies()->toArray());

        return $data;
    }

    /**
     * @param UploadedFile[] $files
     */
    public function createDiscussion(User $author, ?Line $line, string $title, string $content, array $files = []): LineDiscussion
    {
        $now = new \DateTimeImmutable();
        $discussion = (new LineDiscussion())
            ->setAuthor($author)
            ->setLine($line)
            ->setTitle($title)
            ->setContent($content)
            ->setUpdatedAt($now);

        $this->em->persist($discussion);
        $this->attachImages($files, $author, static fn (ForumImage $img) => $discussion->addImage($img));
        $this->em->flush();

        return $discussion;
    }

    /**
     * @param UploadedFile[] $files
     */
    public function reply(User $author, LineDiscussion $discussion, string $content, array $files = []): LineDiscussionReply
    {
        $now = new \DateTimeImmutable();
        $reply = (new LineDiscussionReply())
            ->setDiscussion($discussion)
            ->setAuthor($author)
            ->setContent($content)
            ->setUpdatedAt($now);

        // Une nouvelle réponse fait remonter le fil.
        $discussion->setUpdatedAt($now);

        $this->em->persist($reply);
        $this->attachImages($files, $author, static fn (ForumImage $img) => $reply->addImage($img));
        $this->em->flush();

        return $reply;
    }

    public function updateDiscussion(LineDiscussion $discussion, string $title, string $content): void
    {
        $discussion->setTitle($title)->setContent($content)->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    public function deleteDiscussion(LineDiscussion $discussion): void
    {
        // Cascade : réponses + images (les fichiers sont supprimés par ForumImageListener).
        $this->em->remove($discussion);
        $this->em->flush();
    }

    public function updateReply(LineDiscussionReply $reply, string $content): void
    {
        $reply->setContent($content)->setIsEdited(true)->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    public function deleteReply(LineDiscussionReply $reply): void
    {
        $this->em->remove($reply);
        $this->em->flush();
    }

    /**
     * Upload + rattache jusqu'à MAX_IMAGES_PER_POST images (les fichiers invalides sont ignorés).
     *
     * @param UploadedFile[] $files
     * @param callable(ForumImage): void $link
     */
    private function attachImages(array $files, User $user, callable $link): void
    {
        $count = 0;
        foreach ($files as $file) {
            if ($count >= ForumImageUploader::MAX_IMAGES_PER_POST) {
                break;
            }
            if (!$file instanceof UploadedFile) {
                continue;
            }

            $result = $this->uploader->upload($file);
            if (($result['success'] ?? false) !== true) {
                continue;
            }

            $image = (new ForumImage())
                ->setFilename($result['filename'])
                ->setOriginalFilename($result['originalFilename'])
                ->setFileSize($result['fileSize'])
                ->setUploadedBy($user);
            $link($image);

            $this->em->persist($image);
            $count++;
        }
    }

    /**
     * @param iterable<ForumImage> $images
     * @return list<array{id:?int, url:string, originalFilename:?string}>
     */
    private function imagesOf(iterable $images): array
    {
        $out = [];
        foreach ($images as $img) {
            $out[] = [
                'id' => $img->getId(),
                'url' => rtrim($this->webPublicUrl, '/') . '/uploads/forum/' . $img->getFilename(),
                'originalFilename' => $img->getOriginalFilename(),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(LineDiscussion $d, int $replyCount): array
    {
        return [
            'id' => $d->getId(),
            'title' => $d->getTitle(),
            'lineId' => $d->getLine()?->getId(),
            'lineNumber' => $d->getLine()?->getNumber(),
            'author' => $this->author($d->getAuthor()),
            'replyCount' => $replyCount,
            'isPinned' => $d->isPinned(),
            'isLocked' => $d->isLocked(),
            'viewCount' => $d->getViewCount(),
            'createdAt' => $d->getCreatedAt()?->format(\DATE_ATOM),
            'updatedAt' => $d->getUpdatedAt()?->format(\DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function replyArray(LineDiscussionReply $r): array
    {
        return [
            'id' => $r->getId(),
            'content' => $r->getContent(),
            'author' => $this->author($r->getAuthor()),
            'isEdited' => $r->isEdited(),
            'images' => $this->imagesOf($r->getImages()),
            'createdAt' => $r->getCreatedAt()?->format(\DATE_ATOM),
            'updatedAt' => $r->getUpdatedAt()?->format(\DATE_ATOM),
        ];
    }

    /**
     * @return array{username:?string, avatarUrl:?string}|null
     */
    private function author(?User $user): ?array
    {
        return $user === null
            ? null
            : ['username' => $user->getUsername(), 'avatarUrl' => $user->getAvatarUrl()];
    }
}

<?php

namespace App\Controller;

use App\Entity\Line;
use App\Entity\LineDiscussion;
use App\Entity\LineDiscussionReply;
use App\Entity\User;
use App\Repository\LineRepository;
use App\Service\ForumService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class ForumController extends ApiController
{
    public function __construct(private readonly ForumService $forum)
    {
    }

    #[Route('/api/lines/{id}/discussions', name: 'api_line_discussions', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function lineDiscussions(Line $line, Request $request): JsonResponse
    {
        return $this->json($this->forum->lineDiscussions($line, $request->query->getInt('page', 1)));
    }

    #[Route('/api/discussions', name: 'api_discussions_general', methods: ['GET'])]
    public function generalDiscussions(Request $request): JsonResponse
    {
        return $this->json($this->forum->generalDiscussions($request->query->getInt('page', 1)));
    }

    #[Route('/api/discussions/{id}', name: 'api_discussion_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(LineDiscussion $discussion): JsonResponse
    {
        return $this->json($this->forum->detail($discussion));
    }

    /**
     * Crée une discussion. multipart ou JSON : { title, content, lineId? } + images[] (max 3).
     */
    #[Route('/api/discussions', name: 'api_discussion_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user, LineRepository $lines): JsonResponse
    {
        $data = $this->payload($request);
        $title = $this->requireString($data, 'title');
        $content = $this->requireString($data, 'content');

        $line = null;
        if (!empty($data['lineId'])) {
            $line = $lines->find((int) $data['lineId']);
            if ($line === null) {
                throw new BadRequestHttpException('Ligne inconnue.');
            }
        }

        $discussion = $this->forum->createDiscussion($user, $line, $title, $content, $this->uploadedImages($request));

        return $this->json($this->forum->detail($discussion, false), Response::HTTP_CREATED);
    }

    /**
     * Répond à une discussion. multipart ou JSON : { content } + images[] (max 3).
     */
    #[Route('/api/discussions/{id}/replies', name: 'api_discussion_reply', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reply(LineDiscussion $discussion, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if ($discussion->isLocked()) {
            throw new AccessDeniedHttpException('Cette discussion est verrouillée.');
        }

        $data = $this->payload($request);
        $content = $this->requireString($data, 'content');

        $this->forum->reply($user, $discussion, $content, $this->uploadedImages($request));

        return $this->json($this->forum->detail($discussion, false), Response::HTTP_CREATED);
    }

    /**
     * Édite sa propre discussion. Body : { title, content }.
     */
    #[Route('/api/discussions/{id}', name: 'api_discussion_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function updateDiscussion(LineDiscussion $discussion, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyUnlessOwner($discussion->getAuthor(), $user);
        if ($discussion->isLocked()) {
            throw new AccessDeniedHttpException('Cette discussion est verrouillée.');
        }

        $data = $this->payload($request);
        $this->forum->updateDiscussion($discussion, $this->requireString($data, 'title'), $this->requireString($data, 'content'));

        return $this->json($this->forum->detail($discussion, false));
    }

    /**
     * Supprime sa propre discussion (avec ses réponses et images).
     */
    #[Route('/api/discussions/{id}', name: 'api_discussion_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteDiscussion(LineDiscussion $discussion, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyUnlessOwner($discussion->getAuthor(), $user);
        $this->forum->deleteDiscussion($discussion);

        return $this->json(['message' => 'Discussion supprimée.']);
    }

    /**
     * Édite sa propre réponse. Body : { content }.
     */
    #[Route('/api/replies/{id}', name: 'api_reply_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function updateReply(LineDiscussionReply $reply, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyUnlessOwner($reply->getAuthor(), $user);

        $data = $this->payload($request);
        $this->forum->updateReply($reply, $this->requireString($data, 'content'));

        return $this->json($this->forum->detail($reply->getDiscussion(), false));
    }

    /**
     * Supprime sa propre réponse.
     */
    #[Route('/api/replies/{id}', name: 'api_reply_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteReply(LineDiscussionReply $reply, #[CurrentUser] User $user): JsonResponse
    {
        $this->denyUnlessOwner($reply->getAuthor(), $user);
        $discussion = $reply->getDiscussion();
        $this->forum->deleteReply($reply);

        return $this->json($this->forum->detail($discussion, false));
    }
}


<?php

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Formate toutes les erreurs des routes /api en JSON uniforme : { message, status }.
 * (L'auth JWT garde le format de Lexik ; la doc API Platform est exclue.)
 */
#[AsEventListener(event: 'kernel.exception')]
final class ApiExceptionListener
{
    private const SKIP_PREFIXES = ['/api/docs', '/api/contexts', '/api/.well-known'];

    public function __construct(
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        // API Platform gère ses propres réponses d'erreur (format hydra/problem) → on n'y touche pas.
        if ($request->attributes->has('_api_resource_class')) {
            return;
        }

        $path = $request->getPathInfo();
        if (!str_starts_with($path, '/api')) {
            return;
        }
        foreach (self::SKIP_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        $throwable = $event->getThrowable();

        // Erreurs de validation (Validator) -> 422 + détail par champ.
        if ($throwable instanceof ValidationFailedException) {
            $errors = [];
            foreach ($throwable->getViolations() as $violation) {
                $errors[] = ['field' => $violation->getPropertyPath(), 'message' => (string) $violation->getMessage()];
            }
            $event->setResponse(new JsonResponse(['message' => 'Données invalides.', 'errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY));

            return;
        }

        $status = $throwable instanceof HttpExceptionInterface
            ? $throwable->getStatusCode()
            : Response::HTTP_INTERNAL_SERVER_ERROR;

        $message = match (true) {
            $status === Response::HTTP_NOT_FOUND => 'Ressource introuvable.',
            $throwable instanceof HttpExceptionInterface => $throwable->getMessage(),
            $this->debug => $throwable->getMessage(),
            default => 'Une erreur est survenue.',
        };

        // En-têtes portés par l'exception (ex. Retry-After d'un 429).
        $headers = $throwable instanceof HttpExceptionInterface ? $throwable->getHeaders() : [];

        $event->setResponse(new JsonResponse(['message' => $message, 'status' => $status], $status, $headers));
    }
}

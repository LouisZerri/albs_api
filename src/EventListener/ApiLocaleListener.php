<?php

namespace App\EventListener;

use App\I18n\Translator;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Traduit les messages des réponses de l'API dans la langue de l'app (`Accept-Language`).
 *
 * Fait ICI, en un seul endroit, plutôt que dans chaque contrôleur : toute réponse JSON portant un
 * `message` (erreur ou confirmation), des `violations` (validation API Platform) ou des `errors`
 * est traduite, quelle que soit sa provenance. Le contenu éditorial, lui, est traduit là où il
 * est produit (services), car il fait partie de grosses réponses qu'on ne veut pas réanalyser.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final class ApiLocaleListener
{
    /** Au-delà, ce n'est pas un simple message : on ne réanalyse pas une grosse réponse. */
    private const MAX_BYTES = 8192;

    public function __construct(private readonly Translator $translator)
    {
    }

    public function __invoke(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        $locale = $this->translator->locale();
        if ($event->isMainRequest() && str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            $response->headers->set('Content-Language', $locale);
            $response->setVary('Accept-Language', false);
        }

        $content = $response->getContent();
        if (
            $locale === 'fr'
            || !$event->isMainRequest()
            || !is_string($content) || $content === '' || strlen($content) > self::MAX_BYTES
            || !str_contains((string) $response->headers->get('Content-Type'), 'json')
        ) {
            return;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return;
        }

        $translated = $this->translate($data);
        if ($translated !== $data) {
            $response->setContent((string) json_encode($translated, JsonResponse::DEFAULT_ENCODING_OPTIONS));
        }
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private function translate(array $data): array
    {
        foreach (['message', 'detail'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = $this->translator->message($data[$key]);
            }
        }

        foreach (['violations', 'errors'] as $list) {
            foreach (is_array($data[$list] ?? null) ? $data[$list] : [] as $i => $item) {
                if (is_array($item) && isset($item['message']) && is_string($item['message'])) {
                    $data[$list][$i]['message'] = $this->translator->message($item['message']);
                }
            }
        }

        return $data;
    }
}

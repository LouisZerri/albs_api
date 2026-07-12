<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use App\Service\AccountMailer;
use App\Service\BadgeService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Inscription (API Platform) : hash le mot de passe, génère le token de vérification,
 * persiste, débloque les badges (« account_created »), puis envoie l'email.
 * La validation est faite en amont par API Platform (groupes Default + user:create).
 * L'échec d'envoi de l'email ne bloque pas l'inscription.
 *
 * @implements ProcessorInterface<User, User>
 */
final class UserRegisterProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly BadgeService $badges,
        private readonly AccountMailer $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof User && $data->getPlainPassword() !== null) {
            $data->setPassword($this->hasher->hashPassword($data, $data->getPlainPassword()));
            $data->eraseCredentials();
            $data->generateEmailVerificationToken();
        }

        $result = $this->persistProcessor->process($data, $operation, $uriVariables, $context);

        if ($result instanceof User) {
            $this->badges->checkAndAwardBadges($result);

            try {
                $this->mailer->sendVerification($result);
            } catch (\Throwable $e) {
                $this->logger->error('Envoi email de vérification échoué : ' . $e->getMessage());
            }
        }

        return $result;
    }
}

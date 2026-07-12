<?php

namespace App\Service;

use App\Entity\Bonplan;
use App\Entity\BonplanFavorite;
use App\Entity\Station;
use App\Entity\User;
use App\Repository\BonplanFavoriteRepository;
use App\Repository\BonplanRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Bons plans (spots) autour des stations + favoris de l'utilisateur.
 * Seuls les bons plans APPROUVÉS sont exposés (la modération reste sur le web).
 */
final class BonplanService
{
    public function __construct(
        private readonly BonplanRepository $bonplans,
        private readonly BonplanFavoriteRepository $favorites,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Bons plans approuvés d'une station, avec le flag `isFavorite` pour l'utilisateur.
     *
     * @return list<array<string, mixed>>
     */
    public function forStation(Station $station, User $user): array
    {
        $favSet = array_flip($this->favorites->favoritedBonplanIdsForStation($user, $station));

        return array_map(
            fn (Bonplan $b) => $this->toArray($b, isset($favSet[$b->getId()])),
            $this->bonplans->findApprovedForStation($station),
        );
    }

    /**
     * Bons plans favoris de l'utilisateur.
     *
     * @return list<array<string, mixed>>
     */
    public function favoritesOf(User $user): array
    {
        return array_map(
            fn (BonplanFavorite $f) => $this->toArray($f->getBonplan(), true),
            $this->favorites->findByUserWithDetails($user),
        );
    }

    public function addFavorite(User $user, Bonplan $bonplan): void
    {
        if ($this->favorites->findOneByUserAndBonplan($user, $bonplan) === null) {
            $this->em->persist((new BonplanFavorite())->setUser($user)->setBonplan($bonplan));
            $this->em->flush();
        }
    }

    public function removeFavorite(User $user, Bonplan $bonplan): void
    {
        $fav = $this->favorites->findOneByUserAndBonplan($user, $bonplan);
        if ($fav !== null) {
            $this->em->remove($fav);
            $this->em->flush();
        }
    }

    /**
     * Soumission d'un bon plan par un utilisateur : créé en `pending` (source community),
     * il n'apparaîtra qu'après modération (sur le web).
     *
     * @param array<string, mixed> $data
     */
    public function submit(User $user, Station $station, array $data): Bonplan
    {
        $category = (string) ($data['category'] ?? 'other');
        if (!in_array($category, Bonplan::CATEGORIES, true)) {
            $category = 'other';
        }

        // source=community + status=pending sont les valeurs par défaut de l'entité.
        $bonplan = (new Bonplan())
            ->setStation($station)
            ->setTitle(trim((string) $data['title']))
            ->setDescription(isset($data['description']) ? trim((string) $data['description']) : null)
            ->setCategory($category)
            ->setAddress(isset($data['address']) ? trim((string) $data['address']) : null)
            ->setSubmittedBy($user);

        if (isset($data['latitude']) && is_numeric($data['latitude'])) {
            $bonplan->setLatitude((float) $data['latitude']);
        }
        if (isset($data['longitude']) && is_numeric($data['longitude'])) {
            $bonplan->setLongitude((float) $data['longitude']);
        }

        $this->em->persist($bonplan);
        $this->em->flush();

        return $bonplan;
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(Bonplan $b, bool $isFavorite): array
    {
        return [
            'id' => $b->getId(),
            'stationId' => $b->getStation()?->getId(),
            'title' => $b->getTitle(),
            'description' => $b->getDescription(),
            'category' => $b->getCategory(),
            'categoryIcon' => Bonplan::CATEGORY_ICONS[$b->getCategory()] ?? '📍',
            'address' => $b->getAddress(),
            'latitude' => $b->getLatitude(),
            'longitude' => $b->getLongitude(),
            'source' => $b->getSource(),
            'isFavorite' => $isFavorite,
        ];
    }
}

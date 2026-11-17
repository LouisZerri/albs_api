<?php

namespace App\Service;

use App\Content\StationFacts;
use App\Entity\Bonplan;
use App\Entity\BonplanFavorite;
use App\Entity\Station;
use App\Entity\User;
use App\Repository\BonplanFavoriteRepository;
use App\Repository\BonplanRepository;
use App\Repository\StationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Bons plans (spots) autour des stations + favoris de l'utilisateur.
 * Seuls les bons plans APPROUVÉS sont exposés (la modération reste sur le web).
 */
final class BonplanService
{
    private const MAX_TITLE = 150;
    private const MAX_ADDRESS = 255;
    private const MAX_DESCRIPTION = 1000;

    public function __construct(
        private readonly BonplanRepository $bonplans,
        private readonly BonplanFavoriteRepository $favorites,
        private readonly StationRepository $stations,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Stations physiques ayant au moins un bon plan approuvé (accueil de l'onglet Bons plans).
     * Regroupées par NOM exact (même règle que les correspondances) : une entrée par station,
     * avec toutes ses lignes. `id` = un quai représentatif, à passer à `forStation`.
     *
     * @return list<array{id: int, name: string, count: int, lines: list<array<string, mixed>>}>
     */
    public function stationsWithBonplans(): array
    {
        $linesByName = $this->stations->linesByStationName();

        return array_map(static fn (array $r) => [
            'id' => $r['id'],
            'name' => $r['name'],
            'count' => $r['total'],
            'lines' => $linesByName[$r['name']] ?? [],
        ], $this->bonplans->stationsWithCounts());
    }

    /**
     * Écran d'une station PHYSIQUE : tous les quais de même nom sont fusionnés (position =
     * barycentre des quais, toutes les lignes, bons plans dédoublonnés), avec `isFavorite`.
     *
     * @return array{station: array<string, mixed>, bonplans: list<array<string, mixed>>}
     */
    public function forStation(Station $station, User $user): array
    {
        $platforms = $this->stations->findPlatforms($station);
        $favSet = array_flip($this->favorites->favoritedBonplanIdsForStations($user, $platforms));

        $lines = [];
        $lats = [];
        $lngs = [];
        foreach ($platforms as $p) {
            $line = $p->getLine();
            if ($line !== null) {
                $lines[$line->getId()] = [
                    'lineId' => $line->getId(),
                    'number' => $line->getNumber(),
                    'color' => $line->getColor(),
                    'textColor' => $line->getTextColor(),
                ];
            }
            if ($p->getLatitude() !== null && $p->getLongitude() !== null) {
                $lats[] = $p->getLatitude();
                $lngs[] = $p->getLongitude();
            }
        }

        return [
            'station' => [
                'id' => $station->getId(),
                'name' => $station->getName(),
                'lat' => $lats !== [] ? array_sum($lats) / count($lats) : null,
                'lng' => $lngs !== [] ? array_sum($lngs) / count($lngs) : null,
                'lines' => array_values($lines),
                // « Pourquoi descendre ici ? » (null si la station n'a pas de texte).
                'fact' => StationFacts::for((string) $station->getName()),
            ],
            'bonplans' => array_map(
                fn (Bonplan $b) => $this->toArray($b, isset($favSet[$b->getId()])),
                $this->uniquePlaces($this->bonplans->findApprovedForStations($platforms), $favSet),
            ),
        ];
    }

    /**
     * Un même lieu OpenStreetMap est importé une fois par quai : on n'en garde qu'un par station.
     * On garde en priorité l'exemplaire mis en favori (pour que l'étoile reflète et pilote le bon).
     *
     * @param Bonplan[]       $bonplans
     * @param array<int, int> $favSet   ids favoris (en clés)
     *
     * @return list<Bonplan>
     */
    private function uniquePlaces(array $bonplans, array $favSet): array
    {
        $byPlace = [];
        foreach ($bonplans as $b) {
            $place = $b->getOsmId() ?? '#'.$b->getId();
            if (!isset($byPlace[$place]) || isset($favSet[$b->getId()])) {
                $byPlace[$place] = $b;
            }
        }

        return array_values($byPlace);
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
        $title = trim((string) $data['title']);
        $address = isset($data['address']) ? trim((string) $data['address']) : '';
        $description = isset($data['description']) ? trim((string) $data['description']) : '';
        // Garde-fous alignés sur les colonnes (le formulaire mobile valide déjà, ceci protège l'API).
        if (mb_strlen($title) > self::MAX_TITLE) {
            throw new BadRequestHttpException(sprintf('Le nom ne peut pas dépasser %d caractères.', self::MAX_TITLE));
        }
        if (mb_strlen($address) > self::MAX_ADDRESS) {
            throw new BadRequestHttpException(sprintf("L'adresse ne peut pas dépasser %d caractères.", self::MAX_ADDRESS));
        }
        if (mb_strlen($description) > self::MAX_DESCRIPTION) {
            throw new BadRequestHttpException(sprintf('La description ne peut pas dépasser %d caractères.', self::MAX_DESCRIPTION));
        }

        $category = (string) ($data['category'] ?? 'other');
        if (!in_array($category, Bonplan::CATEGORIES, true)) {
            $category = 'other';
        }

        // source=community + status=pending sont les valeurs par défaut de l'entité.
        $bonplan = (new Bonplan())
            ->setStation($station)
            ->setTitle($title)
            ->setDescription($description !== '' ? $description : null)
            ->setCategory($category)
            ->setAddress($address !== '' ? $address : null)
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
            'stationName' => $b->getStation()?->getName(),
            'title' => $b->getTitle(),
            'description' => $b->getDescription(),
            'category' => $b->getCategory(),
            'categoryIcon' => Bonplan::CATEGORY_ICONS[$b->getCategory()] ?? '📍',
            'address' => $b->getAddress(),
            'latitude' => $b->getLatitude(),
            'longitude' => $b->getLongitude(),
            'source' => $b->getSource(),
            'author' => $b->getSubmittedBy()?->getUsername(),
            'isFavorite' => $isFavorite,
        ];
    }
}

<?php

namespace App\Entity;

use App\Repository\BonplanRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BonplanRepository::class)]
#[ORM\Index(columns: ['station_id', 'status'], name: 'idx_bonplan_station_status')]
#[ORM\UniqueConstraint(name: 'uniq_bonplan_station_osm', columns: ['station_id', 'osm_id'])]
class Bonplan
{
    // Catégories (clé technique => sert aussi de clé de traduction bonplan.category.<key>)
    public const CATEGORIES = ['restaurant', 'bar', 'cafe', 'monument', 'shop', 'culture', 'park', 'other'];

    public const CATEGORY_ICONS = [
        'restaurant' => '🍽️',
        'bar' => '🍺',
        'cafe' => '☕',
        'monument' => '🏛️',
        'shop' => '🛍️',
        'culture' => '🎭',
        'park' => '🌳',
        'other' => '📍',
    ];

    // Provenance
    public const SOURCE_OSM = 'osm';
    public const SOURCE_COMMUNITY = 'community';

    // Statut de modération
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Station $station = null;

    #[ORM\Column(length: 150)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 30)]
    private string $category = 'other';

    #[ORM\Column(length: 20)]
    private string $source = self::SOURCE_COMMUNITY;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $longitude = null;

    /**
     * Identifiant OpenStreetMap (ex. "node/123456"), pour dédupliquer les imports.
     */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $osmId = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $submittedBy = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStation(): ?Station
    {
        return $this->station;
    }

    public function setStation(?Station $station): static
    {
        $this->station = $station;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = in_array($category, self::CATEGORIES, true) ? $category : 'other';
        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;
        return $this;
    }

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function setLatitude(?float $latitude): static
    {
        $this->latitude = $latitude;
        return $this;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function setLongitude(?float $longitude): static
    {
        $this->longitude = $longitude;
        return $this;
    }

    public function getOsmId(): ?string
    {
        return $this->osmId;
    }

    public function setOsmId(?string $osmId): static
    {
        $this->osmId = $osmId;
        return $this;
    }

    public function getSubmittedBy(): ?User
    {
        return $this->submittedBy;
    }

    public function setSubmittedBy(?User $submittedBy): static
    {
        $this->submittedBy = $submittedBy;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }
}

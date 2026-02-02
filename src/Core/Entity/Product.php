<?php

namespace App\Core\Entity;

use DateTime;
use Doctrine\ORM\Mapping as ORM;
use App\Core\Trait\ProductEntityTrait;
use App\Core\Contract\ProductInterface;
use App\Core\Enum\ProductPriceTypeEnum;
use App\Core\Enum\ProductPriceUnitEnum;
use Doctrine\Common\Collections\Collection;
use App\Core\Contract\ProductPriceInterface;
use App\Core\Service\Server\ServerSlotConfigurationService;
use Symfony\Component\HttpFoundation\File\File;
use Doctrine\Common\Collections\ArrayCollection;
use Vich\UploaderBundle\Mapping\Annotation as Vich;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: "App\Core\Repository\ProductRepository")]
#[ORM\HasLifecycleCallbacks]
#[Vich\Uploadable]
class Product extends AbstractEntity implements ProductInterface
{
    use ProductEntityTrait;

    #[ORM\Column(type: "text", nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: "boolean")]
    private bool $isActive = false;

    #[ORM\Column(type: "integer")]
    private int $priority = 0;

    #[ORM\Column(type: "boolean", options: ["default" => false])]
    private bool $featured = false;

    #[ORM\Column(type: "string", length: 255, nullable: true)]
    private ?string $imagePath = null;

    #[Vich\UploadableField(mapping: 'category_images', fileNameProperty: 'imagePath')]
    private ?File $imageFile = null;

    #[ORM\Column(type: "string", length: 255, nullable: true)]
    private ?string $bannerPath = null;

    #[Vich\UploadableField(mapping: 'category_banners', fileNameProperty: 'bannerPath')]
    private ?File $bannerFile = null;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Category $category;

    #[ORM\OneToMany(targetEntity: ProductPrice::class, mappedBy: 'product', cascade: ['persist', 'remove'], orphanRemoval: false)]
    private Collection $prices;

    #[ORM\ManyToMany(targetEntity: Product::class)]
    #[ORM\JoinTable(name: 'product_variant')]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'variant_product_id', referencedColumnName: 'id')]
    private Collection $variantProducts;

    #[ORM\Column(type: "datetime")]
    private DateTime $createdAt;

    #[ORM\Column(type: "datetime", nullable: true)]
    private ?DateTime $updatedAt = null;

    #[ORM\Column(type: "datetime", nullable: true)]
    private ?DateTime $deletedAt = null;

    public function __construct()
    {
        $this->prices = new ArrayCollection();
        $this->variantProducts = new ArrayCollection();
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getIsActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): self
    {
        $this->priority = $priority;
        return $this;
    }

    public function isFeatured(): bool
    {
        return $this->featured;
    }

    public function setFeatured(bool $featured): self
    {
        $this->featured = $featured;
        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): self
    {
        $this->category = $category;
        return $this;
    }

    public function getImagePath(): ?string
    {
        return $this->imagePath;
    }

    public function setImagePath(?string $imagePath): self
    {
        $this->imagePath = $imagePath;
        return $this;
    }

    public function setImageFile(?File $imageFile = null): void
    {
        $this->imageFile = $imageFile;
        if (null !== $imageFile) {
            $this->updatedAt = new DateTime();
        }
    }

    public function getImageFile(): ?File
    {
        return $this->imageFile;
    }

    public function getBannerPath(): ?string
    {
        return $this->bannerPath;
    }

    public function setBannerPath(?string $bannerPath): self
    {
        $this->bannerPath = $bannerPath;
        return $this;
    }

    public function setBannerFile(?File $bannerFile = null): void
    {
        $this->bannerFile = $bannerFile;
        if (null !== $bannerFile) {
            $this->updatedAt = new DateTime();
        }
    }

    public function getBannerFile(): ?File
    {
        return $this->bannerFile;
    }

    #[ORM\PrePersist]
    public function setCreatedAtValue(): void
    {
        $this->createdAt = new DateTime();
    }

    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new DateTime();
    }

    public function getUpdatedAt(): ?DateTime
    {
        return $this->updatedAt;
    }

    public function setDeletedAtValue(): void
    {
        $this->deletedAt = new DateTime();
        $this->setIsActive(false);

        foreach ($this->getPrices() as $price) {
            $price->setDeletedAt($this->deletedAt);
        }
    }

    public function getDeletedAt(): ?DateTime
    {
        return $this->deletedAt;
    }

    public function getPrices(): Collection
    {
        $filtered = $this->prices->filter(function (ProductPriceInterface $price) {
            return $price->getDeletedAt() === null;
        });

        $iterator = $filtered->getIterator();
        $iterator->uasort(function (ProductPriceInterface $a, ProductPriceInterface $b) {
            // Logical sorting: type → value → id
            $typePriority = [
                ProductPriceTypeEnum::STATIC->value => 1,
                ProductPriceTypeEnum::ON_DEMAND->value => 2,
                ProductPriceTypeEnum::SLOT->value => 3,
            ];

            // First compare by type
            $typeComparison = ($typePriority[$a->getType()->value] ?? 99) <=> ($typePriority[$b->getType()->value] ?? 99);
            if ($typeComparison !== 0) {
                return $typeComparison;
            }

            // Then by value (ascending)
            $valueComparison = $a->getValue() <=> $b->getValue();
            if ($valueComparison !== 0) {
                return $valueComparison;
            }

            // Finally by ID
            return $a->getId() <=> $b->getId();
        });

        return new ArrayCollection(iterator_to_array($iterator));
    }

    public function setStaticPrices(iterable $incomingPrices): self
    {
        foreach ($this->getStaticPrices() as $existingPrice) {
            if (!in_array($existingPrice, $incomingPrices->toArray() ?? [], true)) {
                $existingPrice->setDeletedAt(new DateTime());
            }
        }

        $this->syncPrices($this->getStaticPrices(), $incomingPrices);

        return $this;
    }

    public function getStaticPrices(): Collection
    {
        return $this->prices->filter(fn(ProductPrice $price) => !$price->getDeletedAt() && $price->getType() === ProductPriceTypeEnum::STATIC);
    }

    public function setDynamicPrices(iterable $prices): self
    {
        foreach ($this->getDynamicPrices() as $existingPrice) {
            if (!in_array($existingPrice, $prices->toArray() ?? [], true)) {
                $existingPrice->setDeletedAt(new DateTime());
            }
        }

        $this->syncPrices($this->getDynamicPrices(), $prices);

        return $this;
    }

    public function getDynamicPrices(): Collection
    {
        return $this->prices->filter(fn(ProductPrice $price) => !$price->getDeletedAt() && $price->getType() === ProductPriceTypeEnum::ON_DEMAND);
    }

    public function setSlotPrices(iterable $prices): self
    {
        foreach ($this->getSlotPrices() as $existingPrice) {
            if (!in_array($existingPrice, $prices->toArray() ?? [], true)) {
                $existingPrice->setDeletedAt(new DateTime());
            }
        }

        $this->syncPrices($this->getSlotPrices(), $prices);

        return $this;
    }

    public function getSlotPrices(): Collection
    {
        return $this->prices->filter(fn(ProductPrice $price) => !$price->getDeletedAt() && $price->getType() === ProductPriceTypeEnum::SLOT);
    }

    public function addPrice(ProductPriceInterface $price): self
    {
        if (!$this->prices->contains($price) && $price instanceof ProductPrice) {
            $this->prices[] = $price;
            $price->setProduct($this);
        }

        return $this;
    }

    public function removePrice(ProductPriceInterface $price): self
    {
        if ($this->prices->removeElement($price) && $price instanceof ProductPrice) {
            if ($price->getProduct() === $this) {
                $price->setDeletedAt(new DateTime());
            }
        }

        return $this;
    }

    public function getVariantProducts(): Collection
    {
        return $this->variantProducts;
    }

    public function addVariantProduct(Product $product): self
    {
        if (!$this->variantProducts->contains($product) && $product !== $this) {
            $this->variantProducts[] = $product;
        }

        return $this;
    }

    public function removeVariantProduct(Product $product): self
    {
        $this->variantProducts->removeElement($product);

        return $this;
    }

    #[Assert\Callback]
    public function validatePrices(ExecutionContextInterface $context): void
    {
        if (count($this->getPrices()) === 0) {
            $context->buildViolation('pteroca.crud.product.at_least_one_price_required')
                ->setTranslationDomain('messages')
                ->atPath('prices')
                ->addViolation();
        }

        ServerSlotConfigurationService::validateSlotVariablesConfiguration(
            $this->getSlotPrices()->toArray(),
            $this->getEggsConfiguration(),
            $context
        );
    }

    public function getLowestPriceObject(): ?ProductPrice
    {
        $prices = $this->getPrices();

        if ($prices->isEmpty()) {
            return null;
        }

        $lowestPriceObj = null;
        foreach ($prices as $price) {
            if ($lowestPriceObj === null || $price->getPrice() < $lowestPriceObj->getPrice()) {
                $lowestPriceObj = $price;
            }
        }

        return $lowestPriceObj;
    }

    public function __toString(): string
    {
        return $this->name;
    }

    private function syncPrices(iterable $existingPrices, iterable $prices): void
    {
        foreach ($existingPrices as $existingPrice) {
            $found = false;

            foreach ($prices as $submittedPrice) {
                if (
                    $submittedPrice instanceof ProductPrice &&
                    $existingPrice->getId() &&
                    $submittedPrice->getId() === $existingPrice->getId()
                ) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $this->removePrice($existingPrice);
            }
        }

        foreach ($prices as $submittedPrice) {
            if (!$this->prices->contains($submittedPrice)) {
                if ($submittedPrice instanceof ProductPrice) {
                    if ($submittedPrice->getType() === ProductPriceTypeEnum::ON_DEMAND) {
                        $submittedPrice->setValue(1);
                        $submittedPrice->setUnit(ProductPriceUnitEnum::MINUTES);
                    }

                    $this->addPrice($submittedPrice);
                }
            }
        }
    }
}

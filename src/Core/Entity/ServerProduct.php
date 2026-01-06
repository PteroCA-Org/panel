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
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: "App\Core\Repository\ServerProductRepository")]
class ServerProduct extends AbstractEntity implements ProductInterface
{
    use ProductEntityTrait;

    #[ORM\OneToOne(targetEntity: Server::class, inversedBy: 'serverProduct')]
    #[ORM\JoinColumn(nullable: false)]
    private Server $server;

    #[ORM\OneToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Product $originalProduct;

    #[ORM\Column(type: "integer", nullable: true)]
    private ?int $selectedNodeId = null;

    #[ORM\OneToMany(targetEntity: ServerProductPrice::class, mappedBy: 'serverProduct', cascade: ['persist', 'remove'], orphanRemoval: false)]
    #[Groups(['server_product:read'])]
    private Collection $prices;

    public function __construct()
    {
        $this->prices = new ArrayCollection();
    }

    public function getServer(): Server
    {
        return $this->server;
    }

    public function setServer(Server $server): self
    {
        $this->server = $server;
        return $this;
    }

    public function getOriginalProduct(): ?Product
    {
        return $this->originalProduct;
    }

    public function setOriginalProduct(?Product $originalProduct): self
    {
        $this->originalProduct = $originalProduct;
        return $this;
    }

    public function getSelectedNodeId(): ?int
    {
        return $this->selectedNodeId;
    }

    public function setSelectedNodeId(?int $selectedNodeId): self
    {
        $this->selectedNodeId = $selectedNodeId;
        return $this;
    }

    public function getSelectedPrice(): ?ServerProductPrice
    {
        return $this->prices->filter(fn(ServerProductPrice $price) => !$price->getDeletedAt() && $price->isSelected())->first() ?: null;
    }

    public function setStaticPrices(iterable $incomingPrices): self
    {
        foreach ($this->getStaticPrices() as $existingPrice) {
            if (!in_array($existingPrice, $incomingPrices->toArray() ?? [], true)) {
                $existingPrice->setDeletedAt(new \DateTime());
            }
        }

        $this->syncPrices($this->getStaticPrices(), $incomingPrices);

        return $this;
    }

    public function getStaticPrices(): Collection
    {
        return $this->prices->filter(fn(ServerProductPrice $price) => !$price->getDeletedAt() && $price->getType() === ProductPriceTypeEnum::STATIC);
    }

    public function setDynamicPrices(iterable $prices): self
    {
        foreach ($this->getDynamicPrices() as $existingPrice) {
            if (!in_array($existingPrice, $prices->toArray() ?? [], true)) {
                $existingPrice->setDeletedAt(new \DateTime());
            }
        }

        $this->syncPrices($this->getDynamicPrices(), $prices);

        return $this;
    }

    public function getDynamicPrices(): Collection
    {
        return $this->prices->filter(fn(ServerProductPrice $price) => !$price->getDeletedAt() && $price->getType() === ProductPriceTypeEnum::ON_DEMAND);
    }

    public function setSlotPrices(iterable $prices): self
    {
        foreach ($this->getSlotPrices() as $existingPrice) {
            if (!in_array($existingPrice, $prices->toArray() ?? [], true)) {
                $existingPrice->setDeletedAt(new \DateTime());
            }
        }

        $this->syncPrices($this->getSlotPrices(), $prices);

        return $this;
    }

    public function getSlotPrices(): Collection
    {
        return $this->prices->filter(fn(ServerProductPrice $price) => !$price->getDeletedAt() && $price->getType() === ProductPriceTypeEnum::SLOT);
    }

    public function addPrice(ProductPriceInterface $price): self
    {
        if (!$this->prices->contains($price) && $price instanceof ServerProductPrice) {
            $this->prices[] = $price;
            $price->setServerProduct($this);
        }

        return $this;
    }

    public function removePrice(ProductPriceInterface $price): self
    {
        if ($this->prices->removeElement($price) && $price instanceof ServerProductPrice) {
            if ($price->getServerProduct() === $this) {
                $price->setDeletedAt(new DateTime());
            }
        }

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

        if (empty($this->getSelectedPrice())) {
            $context->buildViolation('pteroca.crud.product.at_least_one_selected_price_required')
                ->setTranslationDomain('messages')
                ->atPath('prices')
                ->addViolation();
        }

        $selectedPrices = $this->getPrices()->filter(fn(ServerProductPrice $price) => !$price->getDeletedAt() && $price->isSelected());
        if (count($selectedPrices) > 1) {
            $context->buildViolation('pteroca.crud.product.only_one_selected_price_allowed')
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
                    $submittedPrice instanceof ServerProductPrice &&
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
                if ($submittedPrice instanceof ServerProductPrice) {
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

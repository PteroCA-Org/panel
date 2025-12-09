<?php

namespace App\Core\Entity;

use App\Core\Contract\ProductPriceInterface;
use App\Core\Trait\ProductPriceEntityTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: "App\Core\Repository\ServerProductPriceRepository")]
class ServerProductPrice extends AbstractEntity implements ProductPriceInterface
{
    use ProductPriceEntityTrait;

    #[ORM\ManyToOne(targetEntity: ServerProduct::class, inversedBy: 'prices')]
    #[ORM\JoinColumn(nullable: false)]
    private ServerProduct $serverProduct;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isSelected = false;

    public function getServerProduct(): ServerProduct
    {
        return $this->serverProduct;
    }

    public function setServerProduct(ServerProduct $serverProduct): self
    {
        $this->serverProduct = $serverProduct;

        return $this;
    }

    public function isSelected(): bool
    {
        return $this->isSelected;
    }

    public function setIsSelected(bool $isSelected): self
    {
        $this->isSelected = $isSelected;
        return $this;
    }
}

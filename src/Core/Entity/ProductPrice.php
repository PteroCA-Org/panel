<?php

namespace App\Core\Entity;

use App\Core\Contract\ProductPriceInterface;
use App\Core\Trait\ProductPriceEntityTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: "App\Core\Repository\ProductPriceRepository")]
class ProductPrice extends AbstractEntity implements ProductPriceInterface
{
    use ProductPriceEntityTrait;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'prices')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Product $product = null;

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): self
    {
        $this->product = $product;
        return $this;
    }
}

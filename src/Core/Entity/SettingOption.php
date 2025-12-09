<?php

namespace App\Core\Entity;

use App\Core\Repository\SettingOptionRepository;
use DateTime;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SettingOptionRepository::class)]
#[ORM\Table(name: 'setting_option')]
class SettingOption extends AbstractEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private int $id;

    #[ORM\Column(length: 255)]
    private string $settingName;

    #[ORM\Column(length: 255)]
    private string $optionKey;

    #[ORM\Column(length: 255)]
    private string $optionValue;

    #[ORM\Column(type: "integer", options: ["default" => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(type: "datetime")]
    private DateTime $createdAt;

    #[ORM\Column(type: "datetime")]
    private DateTime $updatedAt;

    public function __construct()
    {
        $this->createdAt = new DateTime();
        $this->updatedAt = new DateTime();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getSettingName(): string
    {
        return $this->settingName;
    }

    public function setSettingName(string $settingName): self
    {
        $this->settingName = $settingName;
        return $this;
    }

    public function getOptionKey(): string
    {
        return $this->optionKey;
    }

    public function setOptionKey(string $optionKey): self
    {
        $this->optionKey = $optionKey;
        return $this;
    }

    public function getOptionValue(): string
    {
        return $this->optionValue;
    }

    public function setOptionValue(string $optionValue): self
    {
        $this->optionValue = $optionValue;
        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }

    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTime $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): DateTime
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTime $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function __toString(): string
    {
        return $this->optionValue;
    }
}

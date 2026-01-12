<?php

namespace App\Core\Trait;

use Doctrine\ORM\Mapping as ORM;

trait ProductEntityTrait
{
    use ProductPricesManagerTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private int $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: "integer")]
    private int $diskSpace;

    #[ORM\Column(type: "integer")]
    private int $memory;

    #[ORM\Column(type: "integer")]
    private int $io = 500;

    #[ORM\Column(type: "integer")]
    private int $cpu;

    #[ORM\Column(type: "string", length: 255, nullable: true)]
    private ?string $threads = null;

    #[ORM\Column(type: "integer")]
    private int $dbCount;

    #[ORM\Column(type: "integer")]
    private int $swap;

    #[ORM\Column(type: "integer")]
    private int $backups;

    #[ORM\Column(type: "integer")]
    private int $ports;

    #[ORM\Column(type: "integer")]
    private int $schedules = 10;

    #[ORM\Column(type: "json", nullable: true)]
    private array $nodes = [];

    #[ORM\Column(type: "integer", nullable: true)]
    private ?int $nest = null;

    #[ORM\Column(type: "json", nullable: true)]
    private array $eggs = [];

    #[ORM\Column(type: "string", length: 255, nullable: true)]
    private ?string $eggsConfiguration = null;

    #[ORM\Column(type: "boolean")]
    private bool $allowChangeEgg = false;

    #[ORM\Column(type: "boolean")]
    private bool $allowAutoRenewal = true;

    #[ORM\Column(type: "boolean")]
    private bool $allowUserSelectLocation = false;

    private ?int $sanitizedEggsCount = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getDiskSpace(): int
    {
        return $this->diskSpace;
    }

    public function setDiskSpace(int $diskSpace): self
    {
        $this->diskSpace = $diskSpace;
        return $this;
    }

    public function getMemory(): int
    {
        return $this->memory;
    }

    public function setMemory(int $memory): self
    {
        $this->memory = $memory;
        return $this;
    }

    public function getIo(): int
    {
        return $this->io;
    }

    public function setIo(int $io): self
    {
        $this->io = $io;
        return $this;
    }

    public function getCpu(): int
    {
        return $this->cpu;
    }

    public function setCpu(int $cpu): self
    {
        $this->cpu = $cpu;
        return $this;
    }

    public function getThreads(): ?string
    {
        return $this->threads;
    }

    public function setThreads(?string $threads): self
    {
        $this->threads = $threads;
        return $this;
    }

    public function getDbCount(): int
    {
        return $this->dbCount;
    }

    public function setDbCount(int $dbCount): self
    {
        $this->dbCount = $dbCount;
        return $this;
    }

    public function getSwap(): int
    {
        return $this->swap;
    }

    public function setSwap(int $swap): self
    {
        $this->swap = $swap;
        return $this;
    }

    public function getBackups(): int
    {
        return $this->backups;
    }

    public function setBackups(int $backups): self
    {
        $this->backups = $backups;
        return $this;
    }

    public function getPorts(): int
    {
        return $this->ports;
    }

    public function setPorts(int $ports): self
    {
        $this->ports = $ports;
        return $this;
    }

    public function getSchedules(): int
    {
        return $this->schedules;
    }

    public function setSchedules(int $schedules): self
    {
        $this->schedules = $schedules;
        return $this;
    }

    public function getNodes(): array
    {
        return $this->nodes;
    }

    public function setNodes(array $nodes): self
    {
        $this->nodes = $nodes;
        return $this;
    }

    public function getNest(): ?int
    {
        return $this->nest;
    }

    public function setNest(?int $nest): self
    {
        $this->nest = $nest;
        return $this;
    }

    public function getEggs(): array
    {
        return $this->eggs;
    }

    public function setEggs(array $eggs): self
    {
        $this->eggs = $eggs;
        return $this;
    }

    public function getEggsConfiguration(): ?string
    {
        return $this->eggsConfiguration;
    }

    public function setEggsConfiguration(?string $eggsConfiguration): self
    {
        $this->eggsConfiguration = $eggsConfiguration;
        return $this;
    }

    public function getAllowChangeEgg(): bool
    {
        return $this->allowChangeEgg;
    }

    public function setAllowChangeEgg(bool $allowChangeEgg): self
    {
        $this->allowChangeEgg = $allowChangeEgg;
        return $this;
    }

    public function getAllowAutoRenewal(): bool
    {
        return $this->allowAutoRenewal;
    }

    public function setAllowAutoRenewal(bool $allowAutoRenewal): self
    {
        $this->allowAutoRenewal = $allowAutoRenewal;
        return $this;
    }

    public function getAllowUserSelectLocation(): bool
    {
        return $this->allowUserSelectLocation;
    }

    public function setAllowUserSelectLocation(bool $allowUserSelectLocation): self
    {
        $this->allowUserSelectLocation = $allowUserSelectLocation;
        return $this;
    }

    public function getSanitizedEggsCount(): ?int
    {
        return $this->sanitizedEggsCount;
    }

    public function setSanitizedEggsCount(?int $count): self
    {
        $this->sanitizedEggsCount = $count;
        return $this;
    }
}

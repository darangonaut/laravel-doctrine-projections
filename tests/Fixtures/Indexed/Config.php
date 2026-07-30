<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Indexed;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'configs')]
class Config
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string', length: 60)]
    public string $name = '';

    /**
     * Doctrine hands this back keyed by the setting's name, not by 0..n.
     *
     * @var Collection<string, Setting>
     */
    #[ORM\OneToMany(targetEntity: Setting::class, mappedBy: 'config', indexBy: 'key')]
    public Collection $settings;

    public function __construct()
    {
        $this->settings = new ArrayCollection;
    }
}

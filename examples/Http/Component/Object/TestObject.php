<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Examples\Http\Component\Object;

use Dleno\CommonCore\Traits\ObjectAttribute;

class TestObject
{
    use ObjectAttribute;

    private int|string|null $id = null;

    private ?string $attr1 = null;

    private ?string $attr2 = null;

    public function getId(): int|string|null
    {
        return $this->id;
    }

    public function setId(int|string|null $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getAttr1(): ?string
    {
        return $this->attr1;
    }

    public function setAttr1(?string $attr1): self
    {
        $this->attr1 = $attr1;
        return $this;
    }

    public function getAttr2(): ?string
    {
        return $this->attr2;
    }

    public function setAttr2(?string $attr2): self
    {
        $this->attr2 = $attr2;
        return $this;
    }
}

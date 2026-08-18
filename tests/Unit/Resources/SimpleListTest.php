<?php

namespace Univapay\Compat\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Resources\SimpleList;

class SimpleListTest extends TestCase
{
    public function testItemsIsPublicAndHoldsWhateverIsPassedIn()
    {
        $items = [(object) ['due_date' => '2026-09-01'], (object) ['due_date' => '2026-10-01']];

        $list = new SimpleList($items);

        $this->assertSame($items, $list->items);
    }

    public function testEmptyListIsAllowed()
    {
        $list = new SimpleList([]);

        $this->assertSame([], $list->items);
    }
}
